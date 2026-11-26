<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Isolated WordPress only. PHPMailer is replaced by an in-memory transport.
if(!defined('WP_CLI')||!WP_CLI||getenv('ONTOGENY_A03_LAB')!=='1'||wp_get_environment_type()!=='local')throw new RuntimeException('Requires isolated local WordPress');
$source=getenv('MCP020_SOURCE');
if(!$source||class_exists('Ontogeny\\Mcp\\Server',false))throw new RuntimeException('Invalid fixture source/runtime');
define('ONTOGENY_MCP_MAIL_RECIPIENT','recipient@example.org');
define('ONTOGENY_MCP_SIGNING_KEY',str_repeat('ab',32));
require_once $source.'/src/Server.php';
require_once $source.'/src/WordPressBackend.php';
require_once ABSPATH.WPINC.'/PHPMailer/Exception.php';
require_once ABSPATH.WPINC.'/PHPMailer/PHPMailer.php';
require_once ABSPATH.WPINC.'/PHPMailer/SMTP.php';
final class InMemoryTransport030 extends \PHPMailer\PHPMailer\PHPMailer {
    public int $accepted=0;
    public array $lastMessage=[];
    public function send():bool {
        $this->accepted++;
        $this->lastMessage=['to'=>$this->getToAddresses(),'reply_to'=>$this->getReplyToAddresses(),'subject'=>$this->Subject,'body'=>$this->Body];
        return true; // No parent call, socket, PHP mail(), or external transport.
    }
}
global $wpdb,$phpmailer;
if(DB_NAME!=='ontogeny_a03'||DB_HOST!=='db:3306'||$wpdb->prefix!=='a03_'||!defined('WP_HTTP_BLOCK_EXTERNAL')||!WP_HTTP_BLOCK_EXTERNAL)throw new RuntimeException('Refusing non-lab database');
$oldMailer=$phpmailer;$phpmailer=new InMemoryTransport030(true);
// The lab uses a single-label hostname; supply a syntactically valid fixture sender.
$fixtureSender=static fn():string=>'fixture@example.org';
add_filter('wp_mail_from',$fixtureSender,PHP_INT_MAX);
$prefix=$wpdb->prefix;$table='omcp030_'.bin2hex(random_bytes(6)).'_ontogeny_mcp_limits';
if(false===$wpdb->query("CREATE TEMPORARY TABLE `$table` (bucket varchar(64) NOT NULL PRIMARY KEY, expires bigint unsigned NOT NULL, counter int unsigned NOT NULL DEFAULT 0, state varchar(32) NOT NULL DEFAULT '', KEY expires(expires)) ENGINE=InnoDB"))throw new RuntimeException('Temporary table unavailable');
$wpdb->prefix=substr($table,0,-strlen('ontogeny_mcp_limits'));
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.(++$checks).' '.$label."\n";};
$server=new \Ontogeny\Mcp\Server(new \Ontogeny\Mcp\WordPressBackend());
$call=static function(string $name,array $args)use($server):array {
    $r=$server->http('POST',['content-type'=>'application/json','accept'=>'application/json, text/event-stream','mcp-protocol-version'=>'2025-11-25'],json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>(object)$args]],JSON_THROW_ON_ERROR));
    return json_decode($r['body']['result']['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
};
$throwAfter=static function():void {throw new RuntimeException('Fixture post-acceptance hook');};
try {
    $_SERVER['REMOTE_ADDR']='192.0.2.30';
    foreach(['"visitor"@example.org','visitor@[192.0.2.42]'] as $reply){
        $check(\Ontogeny\Mcp\Server::validReplyEmail($reply)&&is_email($reply)===false,'real WordPress and PHP validation differ for body-only reply');
        $draft=['subject'=>'Fixture subject','message'=>'Fixture body','reply_email'=>$reply];
        $prepared=$call('prepare_correspondence',$draft);
        $result=$call('send_correspondence',$draft+['ticket'=>$prepared['ticket']]);
        $check($result['status']==='accepted_by_mail_service'&&$result['outcome_recorded']===true&&$phpmailer->lastMessage['to']===[['recipient@example.org','']]&&$phpmailer->lastMessage['reply_to']===[]&&str_contains($phpmailer->lastMessage['body'],$reply),'prepared reply reaches only fixed recipient through simulated WordPress mail');
    }
    add_action('wp_mail_succeeded',$throwAfter,PHP_INT_MAX);
    $draft=['subject'=>'Fixture uncertain outcome','message'=>'Fixture body','reply_email'=>'ordinary@example.org'];
    $prepared=$call('prepare_correspondence',$draft);$send=$draft+['ticket'=>$prepared['ticket']];
    $before=$phpmailer->accepted;$result=$call('send_correspondence',$send);
    $check($result['status']==='pending_unknown'&&$phpmailer->accepted===$before+1,'real post-send WordPress hook exception retains uncertain outcome');
    $check($wpdb->get_var($wpdb->prepare("SELECT state FROM `$table` WHERE bucket=%s",$result['receipt']))==='pending_unknown','database reservation survives post-send exception');
    $replay=$call('send_correspondence',$send);
    $check($replay['status']==='pending_unknown'&&$replay['receipt']===$result['receipt']&&$phpmailer->accepted===$before+1,'real database replay blocks a second simulated handoff');
    echo "$checks WordPress v0.3.0 checks passed; no external mail transport.\n";
}finally{
    remove_action('wp_mail_succeeded',$throwAfter,PHP_INT_MAX);
    remove_filter('wp_mail_from',$fixtureSender,PHP_INT_MAX);
    $phpmailer=$oldMailer;$wpdb->prefix=$prefix;
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
}
