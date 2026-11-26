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
    private static function authority(string $value):?string {
        if(!preg_match('/\A(?:[A-Za-z0-9][A-Za-z0-9.-]*|\[[0-9A-Fa-f:.]+\])(?::[0-9]{1,5})?\z/',$value))return null;
        $p=parse_url('https://'.$value);
        if(!is_array($p)||!isset($p['host']))return null;
        $host=strtolower($p['host']);$port=$p['port']??443;
        if($port<1||$port>65535||str_ends_with($host,'.')||str_contains($host,'..'))return null;
        if(str_starts_with($host,'[')&&filter_var(substr($host,1,-1),FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)===false)return null;
        return $host.($port===443?'':':'.$port);
    }
    public static function canonicalOrigin(string $url):?string {
        $p=parse_url($url);
        if(!is_array($p)||($p['scheme']??null)!=='https'||!isset($p['host'])||isset($p['user'])||isset($p['pass']))return null;
        $authority=self::authority($p['host'].(isset($p['port'])?':'.$p['port']:''));
        return $authority===null?null:'https://'.$authority;
    }
    public static function isMcpRoute(mixed $route):bool {
        return is_string($route)&&strcasecmp('/'.trim($route,'/'),'/ontogeny-mcp/v1/mcp')===0;
    }
    public static function hasRestModifiers(array $server,array $query):bool {
        if(array_key_exists('HTTP_X_HTTP_METHOD_OVERRIDE',$server))return true;
        foreach(['_method','_jsonp','_envelope','_embed','_fields','_pretty'] as $key)if(array_key_exists($key,$query))return true;
        return false;
    }
    /** Trust the host-managed TLS signal, not client-controlled forwarding headers. */
    public static function ingressStatus(array $server,array $query,string $method,bool $tls,?string $origin):int {
        if(!$tls||$origin===null)return 403;
        $host=$server['HTTP_HOST']??null;
        if(!is_string($host)||self::authority($host)!==substr($origin,8))return 403;
        if(($server['REQUEST_METHOD']??null)!==$method||self::hasRestModifiers($server,$query))return 400;
        return 200;
    }
    private function recipient():?string {
        $value=defined('ONTOGENY_MCP_MAIL_RECIPIENT')?constant('ONTOGENY_MCP_MAIL_RECIPIENT'):null;
        return is_string($value)&&!preg_match('/[\r\n]/',$value)&&is_email($value)?$value:null;
    }
    public function sendEnabled():bool {
        return $this->recipient()!==null && self::ticketKey()!==null && (!defined('ONTOGENY_MCP_PUBLIC_SEND_ENABLED')||constant('ONTOGENY_MCP_PUBLIC_SEND_ENABLED')===true);
    }
    private static function ticketKey():?string {
        $key=defined('ONTOGENY_MCP_SIGNING_KEY')?constant('ONTOGENY_MCP_SIGNING_KEY'):null;
        return is_string($key)&&preg_match('/\A[0-9a-f]{64}\z/i',$key)===1?hex2bin($key):null;
    }
    public function sign(string $payload):string {
        $key=self::ticketKey();$recipient=$this->recipient();
        if($key===null||$recipient===null)throw new \LogicException('Ticket signing unavailable');
        return hash_hmac('sha256',json_encode(['ontogeny-ticket-v021',$recipient,$payload],JSON_THROW_ON_ERROR),$key);
    }
    // Keep quota pseudonyms stable across ticket-key rotation and existing daily windows.
    private function quotaSign(string $payload):string{return hash_hmac('sha256',$payload,wp_salt('auth').'|ontogeny-mcp-v0.2.0');}
    // Use server-provided REMOTE_ADDR only. Never trust caller-supplied forwarding headers.
    // Shared reverse proxies can conservatively share a quota. Missing source uses one bucket.
    private function source():string {
        $raw=$_SERVER['REMOTE_ADDR']??'';$bin=is_string($raw)?@inet_pton($raw):false;
        if($bin===false)return 'unknown';
        if(strlen($bin)===16 && substr($bin,0,12)===str_repeat("\0",10)."\xff\xff")$bin=substr($bin,12);
        elseif(strlen($bin)===16)$bin=substr($bin,0,8); // IPv6 /64
        return $this->quotaSign('source|'.bin2hex($bin));
    }
    public function admit(string $kind):string {
        // Fixed keyed slots and reusable rows: no new keys per minute or per caller.
        // Collision sharing is conservative. Host-level limits are still required before PHP.
        $limit=match($kind){'request'=>120,'prepare'=>10,default=>0};
        if($limit===0)return 'storage_unavailable';
        global $wpdb;$t=$this->table();$now=time();$end=(intdiv($now,60)+1)*60;
        $slot=substr($this->quotaSign('request-slot|'.$kind.'|'.$this->source()),0,2);
        $bucket=hash('sha256','public-request-v021|'.$kind.'|'.$slot);
        $insert=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (bucket, expires, counter, state) VALUES (%s,%d,0,'throttle')",$bucket,$end));
        if($insert===false||$wpdb->last_error!=='')return 'storage_unavailable';
        // The conditional update resets an expired window or increments a current one atomically.
        // Evaluate the counter expression before changing expires (MySQL assignment order).
        $updated=$wpdb->query($wpdb->prepare("UPDATE $t SET counter=CASE WHEN expires<=%d THEN 1 ELSE counter+1 END, expires=CASE WHEN expires<=%d THEN %d ELSE expires END WHERE bucket=%s AND state='throttle' AND (expires<=%d OR counter<%d)",$now,$now,$end,$bucket,$now,$limit));
        if($updated===false||$wpdb->last_error!=='')return 'storage_unavailable';
        return $updated===1?'allowed':'rate_limited';
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
        // Limit new attempts before consuming the shared admission allowance. Use a
        // fixed set of keyed source slots so distributed callers cannot create unlimited
        // pre-admission rows. Collisions conservatively share a limit; no raw IP is stored.
        $source=$this->source();$slot=substr($this->quotaSign('admission-source|'.$source),0,2);
        if(!$this->quota('public-admission-hour-v021-'.$slot,3600,5)||
           !$this->quota('public-admission-day-v021-'.$slot,86400,10))return 'rate_limited';
        // The shared admission ceiling still bounds reservation and exact-source/address rows.
        // Existing ticket states bypass these counters. Parallel new requests may consume
        // extra admission quota, but unique reservation permits at most one mail handoff.
        if(!$this->quota('public-attempts-day-v020',86400,200))return 'rate_limited';
        $insert=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (bucket, expires, counter, state) VALUES (%s,%d,0,'pending_unknown')",$attempt,time()+86400));
        if($insert===false)return 'storage_unavailable';
        if($insert!==1){$state=$wpdb->get_var($wpdb->prepare("SELECT state FROM $t WHERE bucket=%s",$attempt));return is_string($state)?$state:'pending_unknown';}
        // Preserve existing exact-source and address quota counters across the upgrade.
        $address=$this->quotaSign('reply|'.strtolower($reply));
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
    // Only fixed categories are stored. No request IDs, payloads, credentials,
    // IP addresses or exceptions. High-volume rejection counters are sampled.
    private const EVENTS=['origin_rejected','request_limited','storage_unavailable','internal_error','mail_accepted','mail_failed','send_limited','replay_blocked'];
    public static function event(array $r):?string {
        $status=$r['status']??0;
        if($status===403)return 'origin_rejected';
        if($status===429)return 'request_limited';
        if($status===503)return 'storage_unavailable';
        if($status===500)return 'internal_error';
        $result=$r['body']['result']??null;
        if(!is_array($result))return null;
        $value=$result['structuredContent']??null;
        if(is_object($value))$value=(array)$value;
        if(!is_array($value)){
            $text=$result['content'][0]['text']??null;
            $value=is_string($text)?json_decode($text,true,16):null;
        }
        if(!is_array($value))return null;
        return match($value['status']??null){
            'accepted_by_mail_service'=>'mail_accepted','mail_handoff_failed'=>'mail_failed',
            'rate_limited'=>'send_limited','storage_unavailable'=>'storage_unavailable',
            'already_accepted','previous_handoff_failed','pending_unknown'=>'replay_blocked',default=>null};
    }
    private static function eventBucket(string $event,int $day):string{return hash('sha256','mcp-telemetry-v021|'.$event.'|'.$day);}
    public function observe(array $r):void {
        try {
            $event=self::event($r);
            if($event===null)return;
            if(!in_array($event,['mail_accepted','mail_failed'],true)&&random_int(1,64)!==1)return;
            global $wpdb;$t=$this->table();$day=intdiv(time(),86400);
            $key=self::eventBucket($event,$day);$expiry=($day+3)*86400;
            $wpdb->query($wpdb->prepare("INSERT INTO $t (bucket, expires, counter, state) VALUES (%s,%d,1,'telemetry') ON DUPLICATE KEY UPDATE counter=LEAST(counter+1,1000000000)",$key,$expiry));
        } catch(\Throwable) { /* Observability must not change the request outcome. */ }
    }
    private static function eventCounts():?array {
        global $wpdb;$t=$wpdb->prefix.'ontogeny_mcp_limits';$day=intdiv(time(),86400);$out=[];
        foreach(self::EVENTS as $event){
            $n=$wpdb->get_var($wpdb->prepare("SELECT counter FROM $t WHERE bucket=%s AND state='telemetry'",self::eventBucket($event,$day)));
            if($wpdb->last_error!=='')return null;
            $out[$event]=(int)$n;
        }
        return $out;
    }
    public static function admin():void {
        if(!current_user_can('manage_options'))return;
        echo '<div class="wrap"><h1>ONTOGENY MCP / '.esc_html(Server::VERSION).'</h1><p>Public correspondence: '.((new self())->sendEnabled()?'enabled':'disabled').'.</p><h2>Security counters — current UTC day</h2><p>Mail handoffs counted individually; other events sampled 1 in 64. Counters are best effort and not a delivery record.</p>';
        $counts=self::eventCounts();
        if($counts===null)echo '<p>Telemetry unavailable.</p>';
        else {echo '<ul>';foreach($counts as $event=>$count)echo '<li>'.esc_html($event).': '.esc_html((string)$count).'</li>';echo '</ul>';}
        echo '</div>';
    }
}
