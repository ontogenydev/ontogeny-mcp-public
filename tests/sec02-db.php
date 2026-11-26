<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// SEC-02 database gate: run in an isolated WP-CLI process with plugins/themes skipped.
// Connection-local temporary table only. No mail, no changes to live quota rows.
namespace Ontogeny\Mcp {
    function time():int { return 1800001800; }
}
namespace {
    if(!defined('WP_CLI')||!WP_CLI)exit(1);
    $source=getenv('MCP020_SOURCE');
    if(!$source)throw new \RuntimeException('Missing fixture source path');
    if(class_exists('Ontogeny\\Mcp\\Server',false)||class_exists('Ontogeny\\Mcp\\WordPressBackend',false))throw new \RuntimeException('Runtime already loaded; stop');
    if(!defined('ONTOGENY_MCP_MAIL_RECIPIENT'))define('ONTOGENY_MCP_MAIL_RECIPIENT','recipient@example.org');
    if(!defined('ONTOGENY_MCP_SIGNING_KEY'))define('ONTOGENY_MCP_SIGNING_KEY',str_repeat('ab',32));
    require_once $source.'/src/Server.php';require_once $source.'/src/WordPressBackend.php';
    $b=new \Ontogeny\Mcp\WordPressBackend();
    if(!$b->sendEnabled())throw new \RuntimeException('Configure a valid test destination and enable correspondence for this fixture');
    global $wpdb,$table,$n,$i;$prefix=$wpdb->prefix;$testprefix='omcpsec02_'.bin2hex(random_bytes(6)).'_';$table=$testprefix.'ontogeny_mcp_limits';
    if(false===$wpdb->query("CREATE TEMPORARY TABLE `$table` (bucket varchar(64) NOT NULL PRIMARY KEY, expires bigint unsigned NOT NULL, counter int unsigned NOT NULL DEFAULT 0, state varchar(32) NOT NULL DEFAULT '', KEY expires(expires))"))throw new \RuntimeException('Temporary table unavailable');
    $wpdb->prefix=$testprefix;
    $n=0;$i=0;
    function expect(bool $ok,string $label):void {global $n;if(!$ok)throw new \RuntimeException('FAIL: '.$label);echo 'PASS '.(++$n).': '.$label."\n";}
    function attempt():string {global $i;return hash('sha256','sec02-db-'.(++$i));}
    function sharedBudget():int {
        global $wpdb,$table;
        $key=hash('sha256','public-attempts-day-v020|'.intdiv(\Ontogeny\Mcp\time(),86400));
        $v=$wpdb->get_var($wpdb->prepare("SELECT counter FROM `$table` WHERE bucket=%s",$key));
        if($wpdb->last_error!=='')throw new \RuntimeException('Budget read failed');
        return (int)$v;
    }
    function slot(string $ip):string {global $b;return substr(hash_hmac('sha256','admission-source|'.hash_hmac('sha256','source|'.bin2hex(inet_pton($ip)),wp_salt('auth').'|ontogeny-mcp-v0.2.0'),wp_salt('auth').'|ontogeny-mcp-v0.2.0'),0,2);}
    try {
        $_SERVER['REMOTE_ADDR']='192.0.2.17';$a=attempt();
        expect($b->reserve('first@example.org',$a)==='reserved','first reservation');$b->finish($a,true);
        for($j=1;$j<5;$j++)expect($b->reserve("test$j@example.org",attempt())==='reserved','source admission');
        expect(sharedBudget()===5,'initial shared counter');
        $before=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`");
        for($j=0;$j<25;$j++)if($b->reserve('denied@example.org',attempt())!=='rate_limited')throw new \RuntimeException('Exhausted source admitted');
        expect(sharedBudget()===5,'source-denied attempts leave shared counter unchanged');
        expect((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")===$before,'source-denied attempts leave row count unchanged');
        expect($b->reserve('first@example.org',$a)==='already_accepted'&&sharedBudget()===5,'replay bypasses admission');
        $first=slot('192.0.2.17');$other=null;
        for($j=1;$j<255;$j++){ $ip='198.51.100.'.$j; if(slot($ip)!==$first){$other=$ip;break;} }
        if($other===null)throw new \RuntimeException('Independent fixture source unavailable');
        $_SERVER['REMOTE_ADDR']=$other;
        expect($b->reserve('other@example.org',attempt())==='reserved'&&sharedBudget()===6,'independent source remains available');
        echo "$n SEC-02 real database checks passed. No mail sent.\n";
    } finally {$wpdb->prefix=$prefix;$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");}
}
