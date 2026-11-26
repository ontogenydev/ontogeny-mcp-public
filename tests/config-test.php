<?php
// Copyright (C) 2026 ONTOGENY
// SPDX-License-Identifier: GPL-2.0-or-later
require_once __DIR__.'/../src/Server.php';
require_once __DIR__.'/../src/WordPressBackend.php';
use Ontogeny\Mcp\WordPressBackend;
$mode=$argv[1]??'parent';
if($mode==='parent'){
 foreach(['unset','empty','invalid','newline','valid','disabled','invalid-switch'] as $case){passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case),$status);if($status!==0)exit($status);}
 echo "14 configuration checks passed; no real mail sent.\n";exit(0);
}
$modes=['empty'=>'','invalid'=>'not-an-address','newline'=>"recipient@example.org\r\nBcc: other@example.org",'valid'=>'recipient@example.org','disabled'=>'recipient@example.org','invalid-switch'=>'recipient@example.org'];
if(array_key_exists($mode,$modes))define('ONTOGENY_MCP_MAIL_RECIPIENT',$modes[$mode]);
if($mode==='disabled')define('ONTOGENY_MCP_PUBLIC_SEND_ENABLED',false);
if($mode==='invalid-switch')define('ONTOGENY_MCP_PUBLIC_SEND_ENABLED','true');
function is_email($value){return is_string($value)&&filter_var($value,FILTER_VALIDATE_EMAIL)!==false?$value:false;}
function wp_mail($to,$subject,$body,$headers){$GLOBALS['handoffs'][]=[$to,$subject,$body,$headers];return true;}
function check_config($condition,$label){if(!$condition){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
$GLOBALS['handoffs']=[];$b=new WordPressBackend();$enabled=$mode==='valid';
check_config($b->sendEnabled()===$enabled,"$mode availability gate");
$result=$b->mail('visitor@example.org','Subject','Body');
check_config($result===$enabled&&count($GLOBALS['handoffs'])===($enabled?1:0)&&(!$enabled||$GLOBALS['handoffs'][0]===['recipient@example.org','Subject',"Claimed reply email (unverified): visitor@example.org\nSource: ONTOGENY MCP\n\nBody",['Content-Type: text/plain; charset=UTF-8']]),"$mode fixed recipient, no unverified Reply-To");
