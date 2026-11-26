<?php
// Run ONLY via WP-CLI with all ordinary plugins and themes skipped. No mail calls.
// Uses a connection-local temporary table; never changes the production quota table.
if(!defined('WP_CLI')||!WP_CLI)exit(1);
$source=getenv('MCP020_SOURCE');$live=getenv('MCP020_LIVE');
if(!$source||!$live)throw new RuntimeException('Missing deployment paths');
if(class_exists('Ontogeny\\Mcp\\Server',false))throw new RuntimeException('Old runtime already loaded; stop');
if(is_file($live.'/config/local.php'))require_once $live.'/config/local.php';
require_once $source.'/src/Server.php';require_once $source.'/src/WordPressBackend.php';
$b=new \Ontogeny\Mcp\WordPressBackend();
if(!$b->sendEnabled())throw new RuntimeException('Production destination unavailable or public send disabled');
global $wpdb;$prefix=$wpdb->prefix;$testprefix='omcp020_'.bin2hex(random_bytes(6)).'_';$table=$testprefix.'ontogeny_mcp_limits';
if(false===$wpdb->query("CREATE TEMPORARY TABLE `$table` (bucket varchar(64) NOT NULL PRIMARY KEY, expires bigint unsigned NOT NULL, counter int unsigned NOT NULL DEFAULT 0, state varchar(32) NOT NULL DEFAULT '', KEY expires(expires))"))throw new RuntimeException('Temporary DB fixture unavailable; no production change');
$wpdb->prefix=$testprefix;
try {
 $reply='audit@example.org';$_SERVER['REMOTE_ADDR']='192.0.2.1';
 $a=hash('sha256','one');$d=hash('sha256','two');$e=hash('sha256','three');
 $checks=[[$b->reserve($reply,$a),'reserved'],[$b->reserve($reply,$a),'pending_unknown']];
 $b->finish($a,true);$checks[]=[$b->reserve($reply,$a),'already_accepted'];
 $checks[]=[$b->reserve($reply,$d),'reserved'];$b->finish($d,false);$checks[]=[$b->reserve($reply,$d),'previous_handoff_failed'];
 $checks[]=[$b->reserve($reply,$e),'rate_limited'];$checks[]=[$b->reserve($reply,$e),'rate_limited'];
 foreach($checks as [$actual,$expected])if($actual!==$expected)throw new RuntimeException('Real DB regression: expected '.$expected.'; got '.$actual);
 $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`");if($count<7)throw new RuntimeException('Expected temporary reservation and quota rows');
 echo "PASS: real database reservation, replay, failed-handoff state, quota denial, and configuration (7 state checks). No mail sent.\n";
}finally{$wpdb->prefix=$prefix;$wpdb->query("DROP TEMPORARY TABLE IF EXISTS `$table`");}
