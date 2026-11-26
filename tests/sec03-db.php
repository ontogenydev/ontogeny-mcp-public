<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SEC-03 database gate: isolated WP-CLI process; temporary table only; no mail.
namespace Ontogeny\Mcp {
    function time():int {return $GLOBALS['sec03_clock']??1800001800;}
}
namespace {
    if(!defined('WP_CLI')||!WP_CLI)exit(1);
    $source=getenv('MCP020_SOURCE');
    if(!$source)throw new \RuntimeException('Missing fixture source path');
    if(class_exists('Ontogeny\\Mcp\\Server',false)||class_exists('Ontogeny\\Mcp\\WordPressBackend',false))throw new \RuntimeException('Runtime already loaded; stop');
    require_once $source.'/src/Server.php';require_once $source.'/src/WordPressBackend.php';
    $b=new \Ontogeny\Mcp\WordPressBackend();
    global $wpdb,$table,$n;$prefix=$wpdb->prefix;$testprefix='omcpsec03_'.bin2hex(random_bytes(6)).'_';$table=$testprefix.'ontogeny_mcp_limits';
    if(false===$wpdb->query("CREATE TEMPORARY TABLE `$table` (bucket varchar(64) NOT NULL PRIMARY KEY, expires bigint unsigned NOT NULL, counter int unsigned NOT NULL DEFAULT 0, state varchar(32) NOT NULL DEFAULT '', KEY expires(expires))"))throw new \RuntimeException('Temporary table unavailable');
    $wpdb->prefix=$testprefix;$n=0;
    function expect(bool $ok,string $label):void {global $n;if(!$ok)throw new \RuntimeException('FAIL: '.$label);echo 'PASS '.(++$n).': '.$label."\n";}
    function rows():int {global $wpdb,$table;$v=$wpdb->get_var("SELECT COUNT(*) FROM `$table`");if($wpdb->last_error!=='')throw new \RuntimeException('Row count failed');return (int)$v;}
    try {
        $_SERVER['REMOTE_ADDR']='192.0.2.17';
        for($j=0;$j<10;$j++)if($b->admit('prepare')!=='allowed')throw new \RuntimeException('Initial preparation admission denied');
        expect($b->admit('prepare')==='rate_limited','preparation ceiling');
        for($j=0;$j<120;$j++)if($b->admit('request')!=='allowed')throw new \RuntimeException('Initial request admission denied');
        expect($b->admit('request')==='rate_limited','request ceiling');
        expect(rows()===2,'separate reusable admission rows');
        $_SERVER['REMOTE_ADDR']='::ffff:192.0.2.17';
        expect($b->admit('prepare')==='rate_limited'&&$b->admit('request')==='rate_limited','mapped IPv4 retains ceilings');
        $_SERVER['REMOTE_ADDR']='192.0.2.17';$GLOBALS['sec03_clock']=\Ontogeny\Mcp\time()+60;
        expect($b->admit('prepare')==='allowed'&&$b->admit('request')==='allowed','atomic expired-window reset');
        expect(rows()===2,'window reset reuses rows');
        expect($b->admit('invalid')==='storage_unavailable'&&rows()===2,'unknown class fails closed');
        echo "$n SEC-03 real database checks passed. No mail sent.\n";
    } finally {$wpdb->prefix=$prefix;$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");}
}
