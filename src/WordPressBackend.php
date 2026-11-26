<?php
// Copyright (C) 2026 ONTOGENY
// SPDX-License-Identifier: GPL-2.0-or-later
namespace Ontogeny\Mcp;
final class WordPressBackend implements Backend {
    private const TOKEN_PREFIX='ontogeny_mcp_credential_';
    private function table():string {global $wpdb;return $wpdb->prefix.'ontogeny_mcp_limits';}
    public static function install():void {
        global $wpdb;$table=$wpdb->prefix.'ontogeny_mcp_limits';$collation=$wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table (
            bucket varchar(64) NOT NULL,
            expires bigint unsigned NOT NULL,
            counter int unsigned NOT NULL DEFAULT 0,
            state varchar(32) NOT NULL DEFAULT '',
            PRIMARY KEY  (bucket),
            KEY expires (expires)
        ) $collation;");
        if(!wp_next_scheduled('ontogeny_mcp_cleanup'))wp_schedule_event(time()+3600,'daily','ontogeny_mcp_cleanup');
    }
    private function recipient():?string {
        $value=defined('ONTOGENY_MCP_MAIL_RECIPIENT')?constant('ONTOGENY_MCP_MAIL_RECIPIENT'):null;
        return is_string($value)&&!preg_match('/[\r\n]/',$value)&&is_email($value)?$value:null;
    }
    public function sendEnabled():bool {
        return $this->recipient()!==null && (!defined('ONTOGENY_MCP_PUBLIC_SEND_ENABLED')||constant('ONTOGENY_MCP_PUBLIC_SEND_ENABLED')===true);
    }
    public function sign(string $payload):string{return hash_hmac('sha256',$payload,wp_salt('auth').'|ontogeny-mcp-v0.2.0');}
    // Use server-provided REMOTE_ADDR only. Never trust caller-supplied forwarding headers.
    // Shared reverse proxies can conservatively share a quota. Missing source uses one bucket.
    private function source():string {
        $raw=$_SERVER['REMOTE_ADDR']??'';$bin=is_string($raw)?@inet_pton($raw):false;
        if($bin===false)return 'unknown';
        if(strlen($bin)===16 && substr($bin,0,12)===str_repeat("\0",10)."\xff\xff")$bin=substr($bin,12);
        elseif(strlen($bin)===16)$bin=substr($bin,0,8); // IPv6 /64
        return $this->sign('source|'.bin2hex($bin));
    }
    private function quota(string $key,int $window,int $limit):bool {
        global $wpdb;$t=$this->table();$epoch=intdiv(time(),$window);$bucket=hash('sha256',$key.'|'.$epoch);$expiry=($epoch+1)*$window+86400;
        if(false===$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (bucket, expires, counter, state) VALUES (%s,%d,0,'quota')",$bucket,$expiry)))return false;
        return 1===$wpdb->query($wpdb->prepare("UPDATE $t SET counter=counter+1 WHERE bucket=%s AND counter<%d",$bucket,$limit));
    }
    public function reserve(string $reply,string $attempt):string {
        if(!$this->sendEnabled())return 'correspondence_unavailable';
        global $wpdb;$t=$this->table();
        $state=$wpdb->get_var($wpdb->prepare("SELECT state FROM $t WHERE bucket=%s",$attempt));
        if($wpdb->last_error!=='')return 'storage_unavailable';
        if(is_string($state))return $state;
        // Bound persistent rows even if a hostile caller generates many valid tickets.
        // The global admission check precedes per-attempt rows. Parallel requests may consume extra admission quota,
        // but the unique reservation below guarantees at most one mail handoff per ticket.
        if(!$this->quota('public-attempts-day-v020',86400,200))return 'rate_limited';
        $insert=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (bucket, expires, counter, state) VALUES (%s,%d,0,'pending_unknown')",$attempt,time()+86400));
        if($insert===false)return 'storage_unavailable';
        if($insert!==1){$state=$wpdb->get_var($wpdb->prepare("SELECT state FROM $t WHERE bucket=%s",$attempt));return is_string($state)?$state:'pending_unknown';}
        $source=$this->source();$address=$this->sign('reply|'.strtolower($reply));
        if(!$this->quota('public-source-hour-'.$source,3600,5)||!$this->quota('public-source-day-'.$source,86400,10)||
           !$this->quota('public-address-hour-'.$address,3600,2)||!$this->quota('public-address-day-'.$address,86400,5)||
           !$this->quota('global-day',86400,20)){
            $wpdb->update($t,['state'=>'rate_limited'],['bucket'=>$attempt]);return 'rate_limited';
        }
        return 'reserved';
    }
    public function mail(string $reply,string $subject,string $message):bool {
        if(!$this->sendEnabled()||strlen($reply)>254||!is_email($reply)||preg_match('/[\x00-\x20\x7f]/',$reply))return false;
        $recipient=$this->recipient();if($recipient===null)return false;
        // Never send verification/confirmation to a visitor or put an unverified address in headers.
        return (bool)wp_mail($recipient,$subject,"Claimed reply email (unverified): $reply\nSource: ONTOGENY MCP\n\n$message",['Content-Type: text/plain; charset=UTF-8']);
    }
    public function finish(string $attempt,bool $accepted):void {global $wpdb;$wpdb->update($this->table(),['state'=>$accepted?'already_accepted':'previous_handoff_failed'],['bucket'=>$attempt]);}
    public static function cleanup():void {
        global $wpdb;$t=$wpdb->prefix.'ontogeny_mcp_limits';$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE expires < %d",time()));
        // Legacy credential records are inert; preserve the v0.1.1 compare-and-delete expiry
        // cleanup for rollback compatibility, without authorizing or issuing any credentials.
        $rows=$wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",$wpdb->esc_like(self::TOKEN_PREFIX).'%'),ARRAY_A);
        if($wpdb->last_error!==''||!is_array($rows))return;
        foreach($rows as $row){
            if(!is_string($row['option_name']??null)||!preg_match('/^'.self::TOKEN_PREFIX.'[a-f0-9]{64}$/D',$row['option_name'])||!is_string($row['option_value']??null))continue;
            $r=@unserialize($row['option_value'],['allowed_classes'=>false]);
            if(!is_array($r)||!is_int($r['expires']??null)||$r['expires']>time())continue;
            $deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s",$row['option_name'],$row['option_value']));
            if($deleted===1){wp_cache_delete($row['option_name'],'options');wp_cache_delete('notoptions','options');wp_cache_delete('alloptions','options');}
        }
        delete_option('ontogeny_mcp_credentials');
    }
    public static function admin():void {
        if(!current_user_can('manage_options'))return;
        echo '<div class="wrap"><h1>ONTOGENY MCP / 0.2.0</h1><p>Public correspondence: '.((new self())->sendEnabled()?'enabled':'disabled').'. No credentials are issued or accepted. Legacy records are inert in this version; retain them only for rollback. Disable public transmission through server configuration if necessary.</p></div>';
    }
}
