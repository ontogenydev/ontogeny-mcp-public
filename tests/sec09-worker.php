<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Explicit WP-CLI fixture only. No actual mail or production table access.
namespace Ontogeny\Mcp { function time():int {return 1800001800;} }
namespace {
    if(!defined('WP_CLI')||!WP_CLI)exit(1);
    $prefix=getenv('MCP021_DB_PREFIX');$dir=getenv('MCP021_DB_WORK');$source=getenv('MCP021_SOURCE');$mode=getenv('MCP021_MODE');$phase=getenv('MCP021_PHASE');
    if(!is_string($prefix)||!preg_match('/\Aomcpsec09_[0-9a-f]{24}_\z/',$prefix)||!is_string($dir)||!is_dir($dir)||!is_string($source)||!is_dir($source))throw new \RuntimeException('Invalid fixture environment');
    global $wpdb;$table=$prefix.'ontogeny_mcp_limits';$owned=$dir.'/owned';
    if($mode==='setup'){
        if(file_exists($owned)||$wpdb->query("CREATE TABLE `$table` (bucket varchar(64) NOT NULL PRIMARY KEY, expires bigint unsigned NOT NULL, counter int unsigned NOT NULL DEFAULT 0, state varchar(32) NOT NULL DEFAULT '', KEY expires(expires)) ENGINE=InnoDB")===false)throw new \RuntimeException('Could not create dedicated fixture table');
        if(file_put_contents($owned,$prefix,LOCK_EX)!==strlen($prefix)){ $wpdb->query("DROP TABLE `$table`");throw new \RuntimeException('Could not mark fixture ownership'); }
        echo "PASS fixture table created\n";exit;
    }
    if(!is_file($owned)||file_get_contents($owned)!==$prefix)throw new \RuntimeException('No matching fixture ownership marker');
    if($mode==='cleanup'){if($wpdb->query("DROP TABLE `$table`")===false)throw new \RuntimeException('Fixture drop failed');unlink($owned);echo "PASS fixture table dropped\n";exit;}
    if(!in_array($phase,['replay','source','global','throttle'],true))throw new \RuntimeException('Unknown fixture phase');
    $clock=\Ontogeny\Mcp\time();
    if($mode==='reset'){
        if($wpdb->query("DELETE FROM `$table`")===false)throw new \RuntimeException('Fixture reset failed');
        if($phase==='global'){
            $bucket=hash('sha256','global-day|'.intdiv($clock,86400));
            if($wpdb->query($wpdb->prepare("INSERT INTO `$table` (bucket,expires,counter,state) VALUES (%s,%d,19,'quota')",$bucket,$clock+86400))!==1)throw new \RuntimeException('Global fixture seed failed');
        }
        echo "PASS fixture phase reset\n";exit;
    }
    if(class_exists('Ontogeny\\Mcp\\Server',false)||class_exists('Ontogeny\\Mcp\\WordPressBackend',false))throw new \RuntimeException('Runtime already loaded; stop');
    if(!defined('ONTOGENY_MCP_MAIL_RECIPIENT'))define('ONTOGENY_MCP_MAIL_RECIPIENT','recipient@example.org');
    if(!defined('ONTOGENY_MCP_SIGNING_KEY'))define('ONTOGENY_MCP_SIGNING_KEY',str_repeat('ab',32));
    require_once $source.'/src/Server.php';require_once $source.'/src/WordPressBackend.php';
    $b=new \Ontogeny\Mcp\WordPressBackend();$previous=$wpdb->prefix;$wpdb->prefix=$prefix;
    try {
        if($mode==='worker'){
            $raw=getenv('MCP021_WORKER');if(!is_string($raw)||!preg_match('/\A(?:[1-9]|1[0-2])\z/',$raw))throw new \RuntimeException('Invalid worker');$id=(int)$raw;
            $_SERVER['REMOTE_ADDR']=$phase==='global'?'192.0.2.'.$id:'198.51.100.17';
            $attempt=hash('sha256',$phase==='replay'?'shared-ticket':$phase.'-'.$id);$reply=$phase==='replay'?'shared@example.org':'visitor'.$id.'@example.org';
            if(!touch($dir.'/ready.'.$id))throw new \RuntimeException('Barrier ready failed');$deadline=microtime(true)+45;
            while(!is_file($dir.'/go')){if(microtime(true)>$deadline)throw new \RuntimeException('Barrier timed out');usleep(20000);}
            $state=$phase==='throttle'?$b->admit('prepare'):$b->reserve($reply,$attempt);
            if($phase!=='throttle'&&$state==='reserved')$b->finish($attempt,true); // simulated outcome only; never call mail
            if(!in_array($state,['allowed','rate_limited','reserved','pending_unknown','already_accepted'],true))throw new \RuntimeException('Unexpected worker result: '.$state);
            if(file_put_contents($dir.'/result.'.$id,$state,LOCK_EX)!==strlen($state))throw new \RuntimeException('Worker result write failed');exit;
        }
        if($mode!=='check')throw new \RuntimeException('Unknown fixture operation');
        $count=$phase==='throttle'?12:6;$states=[];
        for($id=1;$id<=$count;$id++){if(!is_file($dir.'/result.'.$id))throw new \RuntimeException('Missing worker outcome');$states[]=file_get_contents($dir.'/result.'.$id);}
        $accepted=count(array_filter($states,fn($s)=>$s==='reserved'));$allowed=count(array_filter($states,fn($s)=>$s==='allowed'));
        $expected=match($phase){'replay'=>1,'source'=>5,'global'=>1,'throttle'=>10};$actual=$phase==='throttle'?$allowed:$accepted;
        if($actual!==$expected)throw new \RuntimeException('Concurrency ceiling/reservation mismatch: '.$actual.' expected '.$expected);
        if($phase==='replay'){
            $attempt=hash('sha256','shared-ticket');
            if($b->reserve('shared@example.org',$attempt)!=='already_accepted')throw new \RuntimeException('Accepted replay state not preserved');
        }
        if($phase==='global'){
            $bucket=hash('sha256','global-day|'.intdiv($clock,86400));$n=(int)$wpdb->get_var($wpdb->prepare("SELECT counter FROM `$table` WHERE bucket=%s",$bucket));
            if($wpdb->last_error!==''||$n!==20)throw new \RuntimeException('Global quota counter mismatch');
        }
        if($phase==='throttle'){
            $rows=$wpdb->get_results("SELECT counter,state FROM `$table`",ARRAY_A);
            if($wpdb->last_error!==''||count($rows)!==1||$rows[0]['state']!=='throttle'||(int)$rows[0]['counter']!==10)throw new \RuntimeException('Atomic throttle counter mismatch');
        }
        echo 'PASS concurrent '.$phase.' — '.$actual.'/'.$count." admissions; no mail sent\n";
    } finally {$wpdb->prefix=$previous;}
}
