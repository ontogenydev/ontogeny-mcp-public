<?php
// Copyright (C) 2026 ONTOGENY
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

/**
 * A03.3 release regression, pinned to the v0.1.1 runtime.
 * Real WordPress option APIs and wpdb/MariaDB; mail intercepted before transport.
 * Never run on a production system.
 */
use Ontogeny\Mcp\Server;
use Ontogeny\Mcp\WordPressBackend;

const ROOT = '/opt/ontogeny';
const DB_LAB = 'ontogeny_a03';
const PREFIX = 'a03_';
const CREDENTIAL_PREFIX = 'ontogeny_mcp_credential_';
const FINGERPRINTS = [
    'src/Server.php' => '0b9400cb805e3d5343cc7ec04215d1a3a24158a9',
    'src/WordPressBackend.php' => '7a1a4ff5bd0add70f9a094616a99f37dd7fa0094',
    'ontogeny-mcp.php' => 'c845c5c24947e4ad219b0fc7f0fb885ca23ea817',
];

final class LabFailure extends RuntimeException {}
function require_ok(bool $ok, string $description): void {
    if (!$ok) throw new LabFailure($description);
}
function pass(string $id, string $description): void {
    echo "PASS $id $description\n";
}
function git_blob(string $path): string {
    $data = file_get_contents($path);
    require_ok(is_string($data), 'Cannot read pinned source');
    return sha1('blob ' . strlen($data) . "\0" . $data);
}
function boot(bool $install): void {
    require_ok(PHP_SAPI === 'cli' && getenv('ONTOGENY_A03_LAB') === '1'
        && getenv('WORDPRESS_DB_HOST') === 'db:3306'
        && getenv('WORDPRESS_DB_NAME') === DB_LAB, 'Refusing non-lab environment');
    foreach (FINGERPRINTS as $path => $hash) {
        require_ok(git_blob(ROOT . '/' . $path) === $hash, 'Pinned baseline mismatch: ' . $path);
    }
    define('ONTOGENY_MCP_MAIL_RECIPIENT', 'recipient@example.org');
    if ($install) define('WP_INSTALLING', true);
    $_SERVER['HTTP_HOST'] = 'wordpress';
    $_SERVER['REQUEST_URI'] = '/';
    require_once '/var/www/html/wp-load.php';
    global $wpdb;
    require_ok(DB_NAME === DB_LAB && DB_HOST === 'db:3306'
        && $wpdb->prefix === PREFIX && wp_get_environment_type() === 'local'
        && defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL
        && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'Refusing non-lab WordPress/database');
    $wpdb->suppress_errors(true);
    // The only allowed mail path is this pre_wp_mail interception.
    add_filter('pre_wp_mail', static function ($previous, $atts) {
        $path = $GLOBALS['a03_mail_file'] ?? null;
        if ($path !== null) {
            require_ok(file_put_contents($path, "handoff\n", FILE_APPEND | LOCK_EX) !== false,
                'Could not record simulated handoff');
        }
        return (bool)($GLOBALS['a03_mail_accept'] ?? false);
    }, PHP_INT_MAX, 2);
    require_once ROOT . '/src/Server.php';
    require_once ROOT . '/src/WordPressBackend.php';
    if ($install) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        if (!is_blog_installed()) {
            wp_install('ONTOGENY A03 disposable lab', 'audit-admin',
                'admin@example.org', false, '', bin2hex(random_bytes(24)));
        }
        WordPressBackend::install();
    } else {
        require_ok(is_blog_installed(), 'Run install before tests');
    }
}
function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'ontogeny_mcp_limits';
}
function fresh_case(): string {
    global $wpdb;
    require_ok($wpdb->query('TRUNCATE TABLE ' . table()) !== false, 'Fixture reset failed');
    $path = tempnam(sys_get_temp_dir(), 'a03-mail-');
    require_ok(is_string($path), 'Could not create isolated handoff counter');
    $GLOBALS['a03_mail_file'] = $path;
    $GLOBALS['a03_mail_accept'] = true;
    $GLOBALS['a03_files'][] = $path;
    return $path;
}
function handoffs(): int {
    $data = file($GLOBALS['a03_mail_file'], FILE_IGNORE_NEW_LINES);
    require_ok(is_array($data), 'Missing intercepted mail counter');
    return count($data);
}
function credential(): string {
    $token = 'omcp_' . bin2hex(random_bytes(32));
    $id = hash('sha256', $token);
    require_ok(add_option(CREDENTIAL_PREFIX . $id,
        ['email'=>'fixture@example.org','expires'=>time()+3600,'verified'=>true], '', false),
        'Could not issue disposable fixture credential');
    $GLOBALS['a03_credentials'][] = $id;
    return $token;
}
function invoke_tool(string $name, array $args, string $token): array {
    $r = (new Server(new WordPressBackend()))->http('POST', [
        'content-type'=>'application/json', 'accept'=>'application/json, text/event-stream',
        'mcp-protocol-version'=>'2025-11-25', 'authorization'=>'Bearer '.$token
    ], json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call',
        'params'=>['name'=>$name,'arguments'=>(object)$args]], JSON_THROW_ON_ERROR));
    require_ok($r['status'] === 200 && isset($r['body']['result']['content'][0]['text']),
        'Unexpected local protocol result');
    $value = json_decode($r['body']['result']['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR);
    require_ok(is_array($value), 'Invalid tool result');
    return $value;
}
function prepare(string $token): array {
    $args = ['subject'=>'A03 fixture '.bin2hex(random_bytes(4)), 'message'=>'Simulated local mail only.'];
    $p = invoke_tool('prepare_correspondence', $args, $token);
    require_ok(($p['status']??null)==='prepared_not_sent' && !empty($p['ticket'])
        && ($p['reply_to']??null)==='fixture@example.org', 'Fixture preparation failed');
    return $args + ['ticket'=>$p['ticket'],'approved'=>true];
}
function send_draft(string $token, array $draft): array {
    return invoke_tool('send_correspondence', $draft, $token);
}
function status(array $r): string { return (string)($r['status'] ?? $r['error'] ?? 'unexpected'); }
function seed(string $key, int $window, int $counter): string {
    global $wpdb;
    $epoch = intdiv(time(), $window);
    $bucket = hash('sha256', $key.'|'.$epoch);
    require_ok($wpdb->insert(table(), ['bucket'=>$bucket, 'expires'=>($epoch+1)*$window+86400,
        'counter'=>$counter, 'state'=>'quota'], ['%s','%d','%d','%s']) === 1, 'Quota fixture seed failed');
    return $bucket;
}
function count_bucket(string $bucket): int {
    global $wpdb;
    $n = $wpdb->get_var($wpdb->prepare('SELECT counter FROM '.table().' WHERE bucket=%s', $bucket));
    require_ok($n !== null, 'Quota bucket missing');
    return (int)$n;
}
function state(string $receipt): string {
    global $wpdb;
    return (string)$wpdb->get_var($wpdb->prepare('SELECT state FROM '.table().' WHERE bucket=%s', $receipt));
}
function same_window(int $started): void {
    require_ok(intdiv($started,3600)===intdiv(time(),3600)
        && intdiv($started,86400)===intdiv(time(),86400),
        'UTC quota window rolled during test; rerun in a fresh fixture');
}
function worker(): void {
    $x=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    require_ok(is_array($x) && is_dir($x['gate']) && is_file($x['mail']), 'Invalid local worker fixture');
    $GLOBALS['a03_mail_file']=$x['mail'];
    $GLOBALS['a03_mail_accept']=true;
    require_ok(file_put_contents($x['gate'].'/ready-'.$x['worker'],'ready')!==false,'Worker readiness failed');
    $until=microtime(true)+30;
    while(!is_file($x['gate'].'/go')) {
        if(microtime(true)>$until)throw new LabFailure('Worker barrier timeout');
        usleep(10000);
    }
    $r=send_draft($x['token'],$x['draft']);
    echo json_encode(['status'=>status($r),'receipt'=>$r['receipt']??null],JSON_THROW_ON_ERROR);
}
function paired(array $inputs): array {
    $gate=sys_get_temp_dir().'/a03-gate-'.bin2hex(random_bytes(12));
    require_ok(mkdir($gate,0700),'Could not create worker barrier');
    $workers=[];
    try {
        foreach($inputs as $i=>$input) {
            $proc=proc_open([PHP_BINARY,__FILE__,'worker'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            require_ok(is_resource($proc),'Could not start worker');
            $workers[]=['proc'=>$proc,'pipes'=>$pipes,'done'=>false];
            $payload=$input+['gate'=>$gate,'mail'=>$GLOBALS['a03_mail_file'],'worker'=>$i];
            $encoded=json_encode($payload,JSON_THROW_ON_ERROR);
            require_ok(fwrite($pipes[0],$encoded)===strlen($encoded),'Worker input failed');
            fclose($pipes[0]);
        }
        $until=microtime(true)+25;
        while(!is_file($gate.'/ready-0') || !is_file($gate.'/ready-1')) {
            if(microtime(true)>$until)throw new LabFailure('Workers not ready; no requests released');
            usleep(10000);
        }
        require_ok(file_put_contents($gate.'/go','go')!==false,'Worker release failed');
        $out=[];
        foreach($workers as &$w) {
            $stdout=stream_get_contents($w['pipes'][1]);
            $stderr=stream_get_contents($w['pipes'][2]);
            fclose($w['pipes'][1]); fclose($w['pipes'][2]);
            $code=proc_close($w['proc']);
            $w['done']=true;
            require_ok($code===0 && $stderr==='','Worker failed; inspect local fixture only');
            $v=json_decode($stdout,true,64,JSON_THROW_ON_ERROR);
            require_ok(is_array($v),'Invalid worker response');
            $out[]=$v;
        }
        unset($w);
        return $out;
    } finally {
        foreach($workers as $w) {
            if(!$w['done']) {
                proc_terminate($w['proc']);
                foreach($w['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);
                proc_close($w['proc']);
            }
        }
        foreach(glob($gate.'/*')?:[] as $path)unlink($path);
        rmdir($gate);
    }
}
function quota_case(string $name, int $window, int $limit, bool $global=false): void {
    fresh_case();
    $start=time();
    $a=credential();$b=$global?credential():$a;
    $key=$global?'global-day':'credential-'.$name.'-'.hash('sha256',$a);
    $bucket=seed($key,$window,$limit-1);
    $r=paired([['token'=>$a,'draft'=>prepare($a)],['token'=>$b,'draft'=>prepare($b)]]);
    same_window($start);
    $statuses=array_column($r,'status');sort($statuses);
    require_ok($statuses===['accepted_by_mail_service','rate_limited']
        && count_bucket($bucket)===$limit && handoffs()===1, 'Quota boundary failed: '.$name);
}
function run(): void {
    global $wpdb;
    $GLOBALS['a03_files']=[];$GLOBALS['a03_credentials']=[];
    $backend=new WordPressBackend();
    $primary=$wpdb->get_results('SHOW INDEX FROM '.table()." WHERE Key_name='PRIMARY'",ARRAY_A);
    $engine=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',table()),ARRAY_A);
    require_ok(count($primary)===1 && ($primary[0]['Column_name']??'')==='bucket'
        && ($engine['Engine']??'')==='InnoDB','Expected real InnoDB unique reservation key');
    echo "A03.3 BASELINE: ".Server::VERSION." / PHP ".PHP_VERSION." / WordPress ".get_bloginfo('version')
        ." / DB ".$wpdb->get_var('SELECT VERSION()')."\n";
    pass('A03.3.0','Pinned runtime and real InnoDB primary key');
    try {
        // Same ticket, independent PHP processes/connections, synchronized launch.
        fresh_case();$t=credential();$draft=prepare($t);
        $r=paired([['token'=>$t,'draft'=>$draft],['token'=>$t,'draft'=>$draft]]);
        $statuses=array_column($r,'status');
        require_ok(count(array_filter($statuses,fn($x)=>$x==='accepted_by_mail_service'))===1
            && count(array_filter($statuses,fn($x)=>in_array($x,['already_accepted','pending_unknown'],true)))===1
            && !empty($r[0]['receipt']) && $r[0]['receipt']===$r[1]['receipt']
            && handoffs()===1 && state($r[0]['receipt'])==='already_accepted',
            'Same-ticket concurrent replay failed');
        pass('A03.3.R','Real SQL paired replay: one intercepted handoff');

        quota_case('hour',3600,2);
        pass('A03.3.1','Concurrent hourly boundary: counter=2, one blocked');
        quota_case('day',86400,5);
        pass('A03.3.2','Concurrent daily boundary: counter=5, one blocked');
        quota_case('day',86400,20,true);
        pass('A03.3.3','Concurrent global boundary across credentials: counter=20, one blocked');

        // Real database error: remove the reservation table temporarily in this lab only.
        fresh_case();$t=credential();$d=prepare($t);
        $table=table();$away=$table.'_fault';
        require_ok($wpdb->query("RENAME TABLE $table TO $away")!==false,'Could not inject storage outage');
        try { $r=send_draft($t,$d); }
        finally { require_ok($wpdb->query("RENAME TABLE $away TO $table")!==false,'Could not restore lab table'); }
        require_ok(status($r)==='storage_unavailable' && handoffs()===0,'Storage outage did not fail closed');
        // Reservation succeeds but a quota UPDATE fails against the real database.
        fresh_case();$t=credential();$d=prepare($t);
        $fault=static function(string $sql):string {
            return str_contains($sql,'SET counter=counter+1 WHERE bucket=') ?
                'UPDATE a03_nonexistent_fault_table SET counter=counter+1' : $sql;
        };
        add_filter('query',$fault);
        try { $r=send_draft($t,$d); }
        finally { remove_filter('query',$fault); }
        require_ok(status($r)==='rate_limited' && handoffs()===0,'Quota SQL error did not fail closed');
        require_ok(status(send_draft($t,$d))==='rate_limited' && handoffs()===0,'Failed quota ticket replay not blocked');
        pass('A03.3.4','Reservation and quota SQL failures both prevent mail');

        // A real WordPress wp_mail short-circuit simulates a transport failure.
        fresh_case();$GLOBALS['a03_mail_accept']=false;$t=credential();$d=prepare($t);
        $r=send_draft($t,$d);
        require_ok(status($r)==='mail_handoff_failed' && ($r['delivery_confirmed']??null)===false
            && handoffs()===1 && !empty($r['receipt']) && state($r['receipt'])==='previous_handoff_failed',
            'Mail failure state not persisted');
        require_ok(status(send_draft($t,$d))==='previous_handoff_failed' && handoffs()===1,
            'Failed handoff replay attempted another mail');
        pass('A03.3.5','Failed mail handoff persisted; same ticket blocked');

        // Invoke the actual sequential administrator revocation path with a valid nonce.
        fresh_case();$t=credential();$id=hash('sha256',$t);$d=prepare($t);
        $record=get_option(CREDENTIAL_PREFIX.$id);
        update_option('ontogeny_mcp_credentials',[$id=>$record],false);
        $admin=get_user_by('login','audit-admin');
        require_ok($admin instanceof WP_User,'Missing disposable administrator');
        wp_set_current_user($admin->ID);
        require_ok(current_user_can('manage_options'),'Fixture administrator capability absent');
        $_SERVER['REQUEST_METHOD']='POST';
        $_POST=['revoke'=>$id,'_wpnonce'=>wp_create_nonce('ontogeny_mcp_admin')];
        $_REQUEST=$_POST;
        ob_start();
        try { WordPressBackend::admin(); }
        finally { ob_end_clean();$_POST=[];$_REQUEST=[];$_SERVER['REQUEST_METHOD']='GET';wp_set_current_user(0); }
        require_ok(get_option(CREDENTIAL_PREFIX.$id,false)===false && $backend->identity($t)===null
            && !isset(get_option('ontogeny_mcp_credentials',[])[$id]),'Sequential revocation did not remove credential');
        $preview=invoke_tool('prepare_correspondence',['subject'=>'Revoked test','message'=>'Fixture'], $t);
        require_ok(!isset($preview['ticket']) && status(send_draft($t,$d))==='authorization_required'
            && handoffs()===0,'Revoked credential retained send authority');
        pass('A03.3.6','Actual admin revocation; old ticket denied, no new ticket');
        echo "A03.3 PASS — all scoped integration checks passed; no real mail sent.\n";
    } finally {
        foreach($GLOBALS['a03_credentials'] as $id)delete_option(CREDENTIAL_PREFIX.$id);
        delete_option('ontogeny_mcp_credentials');
        foreach($GLOBALS['a03_files'] as $path)if(is_file($path))unlink($path);
    }
}
try {
    $mode=$argv[1]??'';
    require_ok(in_array($mode,['install','run','worker'],true),'Use install or run in the local Compose container');
    boot($mode==='install');
    if($mode==='install')echo "A03 fixture installed; mail intercepted, private source pinned.\n";
    elseif($mode==='worker')worker();
    else run();
} catch(Throwable $e) {
    fwrite(STDERR,"A03.3 FAIL: ".($e instanceof LabFailure?$e->getMessage():get_class($e).' at line '.$e->getLine())."\n");
    exit(1);
}
