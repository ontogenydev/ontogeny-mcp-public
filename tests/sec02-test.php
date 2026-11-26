<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Deterministic, non-mailing quota-order regression. No live endpoint or database.
namespace Ontogeny\Mcp {
    function time():int { return $GLOBALS['sec02_clock'] ?? 1800001800; }
}
namespace {
    require __DIR__.'/storage-test.php';
    $n=0;
    function budget():int {
        global $wpdb;
        $key=hash('sha256','public-attempts-day-v020|'.intdiv(\Ontogeny\Mcp\time(),86400));
        return $wpdb->rows[$key]['counter']??0;
    }
    function sourceSlot(string $ip):string {
        global $b;
        $bin=inet_pton($ip);
        if(strlen($bin)===16 && substr($bin,0,12)===str_repeat("\0",10)."\xff\xff")$bin=substr($bin,12);
        elseif(strlen($bin)===16)$bin=substr($bin,0,8);
        return substr(quotaSign('admission-source|'.quotaSign('source|'.bin2hex($bin))),0,2);
    }
    function useSource(string $ip):void {$_SERVER['REMOTE_ADDR']=$ip;}
    $wpdb=new MemoryDb();useSource('192.0.2.17');
    for($j=0;$j<5;$j++)ok($b->reserve("first$j@example.org",next_attempt())==='reserved','first source allowed');
    ok(budget()===5,'first five admissions counted');
    $count=count($wpdb->rows);
    for($j=0;$j<250;$j++){
        if($b->reserve('limited@example.org',next_attempt())!=='rate_limited')throw new \RuntimeException('Exhausted source admitted');
    }
    ok(budget()===5,'source-denied attempts do not consume shared admissions');
    ok(count($wpdb->rows)===$count,'source-denied attempts do not add reservation rows');
    $first=sourceSlot('192.0.2.17');
    for($j=1;$j<255;$j++){ $other='198.51.100.'.$j; if(sourceSlot($other)!==$first)break; }
    useSource($other);
    ok($b->reserve('independent@example.org',next_attempt())==='reserved','independent source remains available');
    ok(budget()===6,'independent source alone adds shared admission');
    // Replay must bypass admissions regardless of whether the source has since hit its limit.
    $wpdb=new MemoryDb();useSource('192.0.2.17');$a=next_attempt();
    ok($b->reserve('replay@example.org',$a)==='reserved','first ticket reserved');$b->finish($a,true);
    for($j=0;$j<4;$j++)if($b->reserve("another$j@example.org",next_attempt())!=='reserved')throw new \RuntimeException('fixture setup');
    $count=count($wpdb->rows);
    ok($b->reserve('replay@example.org',$a)==='already_accepted'&&budget()===5&&count($wpdb->rows)===$count,'accepted replay bypasses admissions');
    // A mapped IPv4 address must share the same admission bucket; forwarded headers are ignored.
    useSource('::ffff:192.0.2.17');$_SERVER['HTTP_X_FORWARDED_FOR']='203.0.113.123';
    ok($b->reserve('mapped@example.org',next_attempt())==='rate_limited'&&budget()===5,'IPv4-mapped and spoofed-forwarded-header admission');
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    // Fixed hourly rollover cannot circumvent the daily source ceiling.
    useSource('192.0.2.17');$GLOBALS['sec02_clock']=\Ontogeny\Mcp\time()+3600;
    for($j=0;$j<5;$j++)if($b->reserve("next$j@example.org",next_attempt())!=='reserved')throw new \RuntimeException('second window setup');
    ok(budget()===10,'next hour admits only remaining daily allowance');
    $GLOBALS['sec02_clock']+=3600;
    ok($b->reserve('daily@example.org',next_attempt())==='rate_limited'&&budget()===10,'daily source ceiling precedes shared admissions');
    // Deliberately collide two different fixture addresses into one bounded slot.
    $wpdb=new MemoryDb();$seen=[];$pair=null;
    for($j=1;$j<=512;$j++){
        $ip='198.18.'.intdiv($j,256).'.'.($j%256);$slot=sourceSlot($ip);
        if(isset($seen[$slot])){$pair=[$seen[$slot],$ip];break;}$seen[$slot]=$ip;
    }
    if(!$pair)throw new \RuntimeException('collision fixture unavailable');
    useSource($pair[0]);for($j=0;$j<5;$j++)if($b->reserve("collision$j@example.org",next_attempt())!=='reserved')throw new \RuntimeException('collision setup');
    useSource($pair[1]);ok($b->reserve('shared-slot@example.org',next_attempt())==='rate_limited'&&budget()===5,'slot collision fails conservatively without shared admission');
    // When the global budget is full, any number of new sources can create only fixed admission slots.
    $wpdb=new MemoryDb();$key=hash('sha256','public-attempts-day-v020|'.intdiv(\Ontogeny\Mcp\time(),86400));
    $wpdb->rows[$key]=['counter'=>200,'expires'=>\Ontogeny\Mcp\time()+86400,'state'=>'quota'];
    for($j=0;$j<2048;$j++){
        useSource('198.18.'.intdiv($j,256).'.'.($j%256));
        if($b->reserve('global-full@example.org',next_attempt())!=='rate_limited')throw new \RuntimeException('global cap bypass');
    }
    ok(budget()===200,'global admission cap retained');
    ok(count($wpdb->rows)<=513,'bounded pre-admission storage even across many sources');
    ok(count(array_filter($wpdb->rows,static fn($r)=>$r['state']!=='quota'))===0,'no reservation rows when shared admission full');
    $wpdb=new MemoryDb();$wpdb->fail=true;
    ok($b->reserve('unavailable@example.org',next_attempt())==='storage_unavailable'&&count($wpdb->rows)===0,'storage failure remains closed');
    echo "$n SEC-02 checks passed. No mail, live service or real database used.\n";
}
