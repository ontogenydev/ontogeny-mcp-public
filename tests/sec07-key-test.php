<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Separate process fixtures prove key rotation does not reset quota identities.
namespace Ontogeny\Mcp {function time():int{return 1800001800;}}
namespace {
    require __DIR__.'/../src/Server.php';require __DIR__.'/../src/WordPressBackend.php';
    use Ontogeny\Mcp\WordPressBackend;
    function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
    function wp_salt($kind){return 'fixture-only-salt';}
    $mode=$argv[1]??'parent';
    if($mode==='parent'){
        $probe=function($mode){$lines=[];$rc=0;exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($mode),$lines,$rc);if($rc!==0)throw new RuntimeException('Key fixture failed');return json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);};
        $a=$probe('first');$b=$probe('rotated');$c=$probe('destination');
        if($a['ticket']===$b['ticket'])throw new RuntimeException('Rotation did not invalidate signature');echo "PASS ticket-key rotation invalidates previous signature\n";
        if($a['bucket']!==$b['bucket'])throw new RuntimeException('Rotation changed quota bucket');echo "PASS ticket-key rotation preserves quota identity\n";
        if($a['ticket']===$c['ticket']||$a['bucket']!==$c['bucket'])throw new RuntimeException('Destination domain mismatch');echo "PASS destination change invalidates ticket without resetting quotas\n";
        echo "3 SEC-07 key lifecycle checks passed; fixture keys only.\n";exit;
    }
    if(!in_array($mode,['first','rotated','destination'],true))exit(1);
    define('ONTOGENY_MCP_SIGNING_KEY',str_repeat($mode==='rotated'?'cd':'ab',32));
    define('ONTOGENY_MCP_MAIL_RECIPIENT',$mode==='destination'?'other@example.org':'recipient@example.org');
    class CaptureDb {public string $prefix='fixture_';public string $last_error='';public array $queries=[];function prepare($q,...$a){return [$q,$a];}function query($q){$this->queries[]=$q;return 1;}}
    $wpdb=new CaptureDb();$_SERVER['REMOTE_ADDR']='192.0.2.17';$b=new WordPressBackend();
    if(!$b->sendEnabled()||$b->admit('request')!=='allowed')exit(1);
    echo json_encode(['ticket'=>$b->sign('fixed-fixture-payload'),'bucket'=>$wpdb->queries[0][1][0]],JSON_THROW_ON_ERROR),"\n";
}
