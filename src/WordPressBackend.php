<?php
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
    /** Deployment-owned configuration; a public build must not send by default. */
    private function recipient():?string {
        $value=defined('ONTOGENY_MCP_MAIL_RECIPIENT')?constant('ONTOGENY_MCP_MAIL_RECIPIENT'):null;
        return is_string($value)&&!preg_match('/[\r\n]/',$value)&&is_email($value)?$value:null;
    }
    public function identity(string $token):?array {
        if($this->recipient()===null)return null;
        $id=hash('sha256',$token);$record=get_option(self::TOKEN_PREFIX.$id,false);
        if(!is_array($record)||($record['expires']??0)<=time()||!is_email($record['email']??'')||empty($record['verified']))return null;
        return ['id'=>$id,'email'=>$record['email']];
    }
    public function sign(string $payload):string{return hash_hmac('sha256',$payload,wp_salt('auth').'|ontogeny-mcp-v0.1.0');}
    private function quota(string $key,int $window,int $limit):bool {
        global $wpdb;$t=$this->table();$epoch=intdiv(time(),$window);$bucket=hash('sha256',$key.'|'.$epoch);$expiry=($epoch+1)*$window+86400;
        if(false===$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (bucket, expires, counter, state) VALUES (%s,%d,0,'quota')",$bucket,$expiry)))return false;
        return 1===$wpdb->query($wpdb->prepare("UPDATE $t SET counter=counter+1 WHERE bucket=%s AND counter<%d",$bucket,$limit));
    }
    public function reserve(string $credential,string $attempt):string {
        global $wpdb;$t=$this->table();
        // Reserve before mail so parallel calls cannot transmit the same prepared draft twice.
        $insert=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (bucket, expires, counter, state) VALUES (%s,%d,0,'pending_unknown')",$attempt,time()+86400));
        if($insert===false)return 'storage_unavailable';
        if($insert===0){$state=$wpdb->get_var($wpdb->prepare("SELECT state FROM $t WHERE bucket=%s",$attempt));return is_string($state)?$state:'pending_unknown';}
        if(!$this->quota('credential-hour-'.$credential,3600,2)||!$this->quota('credential-day-'.$credential,86400,5)||!$this->quota('global-day',86400,20)){
            $wpdb->update($t,['state'=>'rate_limited'],['bucket'=>$attempt]);return 'rate_limited';
        }
        return 'reserved';
    }
    public function mail(string $reply,string $subject,string $message):bool {
        if(!is_email($reply)||preg_match('/[\r\n]/',$reply))return false;
        $recipient=$this->recipient();if($recipient===null)return false;
        // Use the authenticated default From address, not the visitor's address.
        return (bool)wp_mail($recipient,$subject,"Reply email: $reply\nSource: ONTOGENY MCP\n\n$message",['Reply-To: '.$reply,'Content-Type: text/plain; charset=UTF-8']);
    }
    public function finish(string $attempt,bool $accepted):void {global $wpdb;$wpdb->update($this->table(),['state'=>$accepted?'already_accepted':'previous_handoff_failed'],['bucket'=>$attempt]);}
    public static function cleanup():void {
        global $wpdb;$t=$wpdb->prefix.'ontogeny_mcp_limits';$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE expires < %d",time()));
        $index=get_option('ontogeny_mcp_credentials',[]);if(!is_array($index))return;
        foreach($index as $id=>$row){if(($row['expires']??0)<=time()){delete_option(self::TOKEN_PREFIX.$id);unset($index[$id]);}}
        update_option('ontogeny_mcp_credentials',$index,false);
    }
    public static function admin():void {
        if(!current_user_can('manage_options'))return;
        $index=get_option('ontogeny_mcp_credentials',[]);if(!is_array($index))$index=[];$new='';$notice='';
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            check_admin_referer('ontogeny_mcp_admin');
            if(isset($_POST['issue'])){
                $email=sanitize_email(wp_unslash($_POST['email']??''));
                if(!is_email($email)||empty($_POST['verified']))$notice='Verify ownership of the reply email before issuing a credential.';
                else{ $token='omcp_'.bin2hex(random_bytes(32));$id=hash('sha256',$token);$record=['email'=>$email,'expires'=>time()+30*86400,'verified'=>true];
                    if(add_option(self::TOKEN_PREFIX.$id,$record,'',false)){$index[$id]=$record;update_option('ontogeny_mcp_credentials',$index,false);$new=$token;$notice='Credential issued. Copy it now and share it securely with the verified correspondent.';}else{$notice='Credential could not be stored.';}
                }
            }elseif(isset($_POST['revoke'])){$id=sanitize_text_field(wp_unslash($_POST['revoke']));if(preg_match('/^[a-f0-9]{64}$/D',$id)){delete_option(self::TOKEN_PREFIX.$id);unset($index[$id]);update_option('ontogeny_mcp_credentials',$index,false);$notice='Credential revoked.';}}
        }
        echo '<div class="wrap"><h1>ONTOGENY MCP / 0.1.0</h1><p>Restricted correspondence pilot. Public reads require no credential. Issue tokens only after independently verifying ownership of the reply address. Tokens expire after 30 days. No WordPress administration rights are granted.</p>';
        if($notice)echo '<p>'.esc_html($notice).'</p>';if($new)echo '<p><code>'.esc_html($new).'</code></p>';
        echo '<form method="post">';wp_nonce_field('ontogeny_mcp_admin');echo '<p><label>Verified reply address <input type="email" name="email" required maxlength="254"></label></p><p><label><input type="checkbox" name="verified" value="1" required> I independently verified ownership of this address.</label></p><button class="button button-primary" name="issue" value="1">Issue scoped credential</button></form><h2>Issued credentials</h2>';
        foreach($index as $id=>$row){echo '<form method="post"><p>'.esc_html($row['email']).' — expires '.esc_html(gmdate('Y-m-d',$row['expires'])).' ';wp_nonce_field('ontogeny_mcp_admin');echo '<button class="button" name="revoke" value="'.esc_attr($id).'">Revoke</button></p></form>';}
        echo '</div>';
    }
}
