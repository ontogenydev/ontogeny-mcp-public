<?php
// Isolated configuration tests: no WordPress instance, database, or mail transport.
require_once __DIR__.'/../src/Server.php';
require_once __DIR__.'/../src/WordPressBackend.php';
use Ontogeny\Mcp\WordPressBackend;
$mode=$argv[1]??'parent';
if($mode==='parent') {
    foreach(['unset','empty','invalid','newline','valid'] as $case){
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case),$status);
        if($status!==0)exit($status);
    }
    echo "10 configuration checks passed; no real mail sent.\n";exit(0);
}
$modes=['empty'=>'','invalid'=>'not-an-address','newline'=>"recipient@example.org\r\nBcc: other@example.org",'valid'=>'recipient@example.org'];
if(array_key_exists($mode,$modes))define('ONTOGENY_MCP_MAIL_RECIPIENT',$modes[$mode]);
function is_email($value){return is_string($value)&&filter_var($value,FILTER_VALIDATE_EMAIL)!==false?$value:false;}
function get_option($name,$default){return ['email'=>'verified@example.org','expires'=>time()+3600,'verified'=>true];}
function wp_mail($to,$subject,$body,$headers){$GLOBALS['handoffs'][]=[$to,$subject,$body,$headers];return true;}
function check_config($condition,$label){if(!$condition){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
$GLOBALS['handoffs']=[];$b=new WordPressBackend();$enabled=$mode==='valid';
check_config(($b->identity('test-only')!==null)===$enabled,"$mode credential gate");
$result=$b->mail('verified@example.org','Subject','Body');
check_config($result===$enabled&&count($GLOBALS['handoffs'])===($enabled?1:0)&&(!$enabled||$GLOBALS['handoffs'][0]===['recipient@example.org','Subject',"Reply email: verified@example.org\nSource: ONTOGENY MCP\n\nBody",['Reply-To: verified@example.org','Content-Type: text/plain; charset=UTF-8']]),"$mode fixed destination and mail gate");
