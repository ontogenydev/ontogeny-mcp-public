<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Deterministic request-admission regression. No network, database or mail transport.
namespace Ontogeny\Mcp {
    function time():int {return $GLOBALS['sec03_clock']??1800001800;}
}
namespace {
    require __DIR__.'/storage-test.php';
    $n=0;$wpdb=new MemoryDb();
    $server=new \Ontogeny\Mcp\Server($b);
    $headers=['content-type'=>'application/json','accept'=>'application/json, text/event-stream','mcp-protocol-version'=>'2025-11-25'];
    function req(string $method,array $params=[]):array {
        global $server,$headers;
        return $server->http('POST',$headers,json_encode(['jsonrpc'=>'2.0','id'=>7,'method'=>$method,'params'=>(object)$params],JSON_THROW_ON_ERROR));
    }
    function prepareDraft():array {
        return req('tools/call',['name'=>'prepare_correspondence','arguments'=>['subject'=>'Fixture','message'=>'No mail','reply_email'=>'visitor@example.org']]);
    }
    function resultData(array $r):array {return json_decode($r['body']['result']['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);}
    function status(array $r,int $expected):bool {return $r['status']===$expected;}
    function sourceSlot(string $ip,string $kind):string {
        global $b;
        $bin=inet_pton($ip);
        if(strlen($bin)===16 && substr($bin,0,12)===str_repeat("\0",10)."\xff\xff")$bin=substr($bin,12);
        elseif(strlen($bin)===16)$bin=substr($bin,0,8);
        return substr(quotaSign('request-slot|'.$kind.'|'.quotaSign('source|'.bin2hex($bin))),0,2);
    }
    function throttleRows():array {global $wpdb;return array_filter($wpdb->rows,static fn($r)=>$r['state']==='throttle');}
    $_SERVER['REMOTE_ADDR']='192.0.2.17';
    for($j=0;$j<10;$j++){
        $r=prepareDraft();if(!status($r,200)||!is_string(resultData($r)['ticket']??null))throw new \RuntimeException('Initial preparation blocked');
    }
    $r=prepareDraft();
    ok(status($r,429)&&($r['headers']['Retry-After']??null)==='60'&&!isset($r['body']['result']),'preparation ceiling returns 429 without ticket');
    ok(status(req('tools/list'),200),'preparation limit preserves public discovery');
    ok(count($wpdb->rows)===2&&count(throttleRows())===2,'preparation only creates bounded limiter state');
    $_SERVER['REMOTE_ADDR']='::ffff:192.0.2.17';$_SERVER['HTTP_X_FORWARDED_FOR']='203.0.113.123';
    ok(status(prepareDraft(),429),'mapped IPv4 and forwarded header do not bypass preparation ceiling');
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    $_SERVER['REMOTE_ADDR']='192.0.2.17';$first=sourceSlot('192.0.2.17','prepare');$other=null;
    for($j=1;$j<255;$j++){ $ip='198.51.100.'.$j; if(sourceSlot($ip,'prepare')!==$first){$other=$ip;break;} }
    if($other===null)throw new \RuntimeException('Independent source fixture unavailable');
    $_SERVER['REMOTE_ADDR']=$other;
    ok(status(prepareDraft(),200),'independent source can prepare');
    $_SERVER['REMOTE_ADDR']='192.0.2.17';$GLOBALS['sec03_clock']=\Ontogeny\Mcp\time()+60;
    ok(status(prepareDraft(),200),'preparation resumes next window');
    $wpdb=new MemoryDb();
    for($j=0;$j<120;$j++)if(!status(req('ping'),200))throw new \RuntimeException('Initial request budget rejected');
    $r=req('ping');ok(status($r,429)&&($r['headers']['Retry-After']??null)==='60','general request ceiling returns 429');
    ok(status(req('tools/list'),429),'general request ceiling applies to discovery');
    ok(status(prepareDraft(),429),'general request ceiling precedes ticket preparation');
    ok(count($wpdb->rows)===1,'denied requests do not create preparation state');
    $GLOBALS['sec03_clock']+=60;
    ok(status(req('ping'),200)&&count($wpdb->rows)===1,'rollover reuses same limiter row');
    $wpdb=new MemoryDb();
    ok(status($server->http('POST',$headers+['origin'=>'https://foreign.example.org'],'{}'),403)&&count($wpdb->rows)===0,'foreign origin blocked before limiter');
    ok(status($server->http('POST',$headers,str_repeat('x',32769)),413)&&count($wpdb->rows)===0,'oversized request blocked before limiter');
    ok(status($server->http('POST',$headers,'{'),400)&&count($wpdb->rows)===1,'malformed JSON counts against request budget');
    $wpdb=new MemoryDb();
    // Fixed keys are reused across windows even when many distinct fixture sources appear.
    for($window=0;$window<4;$window++){
        $GLOBALS['sec03_clock']+=60;
        for($j=0;$j<1024;$j++){
            $_SERVER['REMOTE_ADDR']='198.18.'.intdiv($j,256).'.'.($j%256);
            $r=prepareDraft();if(!in_array($r['status'],[200,429],true))throw new \RuntimeException('Unexpected admission result');
        }
    }
    ok(count($wpdb->rows)<=512&&count($wpdb->rows)===count(throttleRows()),'bounded limiter storage across sources and windows');
    $wpdb=new MemoryDb();$wpdb->fail=true;
    $r=prepareDraft();ok(status($r,503)&&!isset($r['body']['result'])&&count($wpdb->rows)===0,'limiter storage failure fails closed');
    echo "$n SEC-03 checks passed. No mail, live service or real database used.\n";
}
