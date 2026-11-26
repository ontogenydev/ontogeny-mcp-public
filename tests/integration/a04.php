<?php
// Copyright (C) 2026 ONTOGENY
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);
// Local-only deterministic credential-administration race harness. No mail transport.
use Ontogeny\Mcp\WordPressBackend;
const ROOT='/opt/ontogeny';
const PREFIX='ontogeny_mcp_credential_';
const INDEX='ontogeny_mcp_credentials';
const CANDIDATE='7a1a4ff5bd0add70f9a094616a99f37dd7fa0094';
function ok(bool $b,string $message):void {if(!$b)throw new RuntimeException($message);}
function blob(string $path):string {$s=file_get_contents(ROOT.'/'.$path);ok(is_string($s),'Source read failed');return sha1('blob '.strlen($s)."\0".$s);}
function boot(string $mode):void {
    ok(PHP_SAPI==='cli'&&getenv('ONTOGENY_A03_LAB')==='1'&&getenv('WORDPRESS_DB_HOST')==='db:3306'&&getenv('WORDPRESS_DB_NAME')==='ontogeny_a03','Refusing non-lab environment');
    ok(blob('src/Server.php')==='0b9400cb805e3d5343cc7ec04215d1a3a24158a9'&&blob('ontogeny-mcp.php')==='c845c5c24947e4ad219b0fc7f0fb885ca23ea817'&&blob('src/WordPressBackend.php')===CANDIDATE,'Pinned source mismatch');
    define('ONTOGENY_MCP_MAIL_RECIPIENT', 'recipient@example.org');
    $_SERVER['HTTP_HOST']='wordpress';$_SERVER['REQUEST_URI']='/';
    require_once '/var/www/html/wp-load.php';
    global $wpdb;
    ok(DB_NAME==='ontogeny_a03'&&DB_HOST==='db:3306'&&$wpdb->prefix==='a03_'&&wp_get_environment_type()==='local'&&WP_HTTP_BLOCK_EXTERNAL&&DISABLE_WP_CRON&&is_blog_installed(),'Refusing non-lab WordPress/database');
    $wpdb->suppress_errors(true);
    add_filter('pre_wp_mail',static function(){throw new RuntimeException('Unexpected mail call in A04');},PHP_INT_MAX);
    require_once ROOT.'/src/Server.php';require_once ROOT.'/src/WordPressBackend.php';
    ok(get_user_by('login','audit-admin') instanceof WP_User,'A03 fixture not installed');
}
function reset_lab():void {
    global $wpdb;
    ok($wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like(PREFIX).'%'))!==false,'Fixture reset failed');
    wp_cache_flush();delete_option(INDEX);wp_cache_flush();
}
function seed(bool $expired=false,bool $indexed=true):array {
    $token='omcp_'.bin2hex(random_bytes(32));$id=hash('sha256',$token);
    $row=['email'=>'fixture@example.org','expires'=>time()+($expired?-60:3600),'verified'=>true];
    ok(add_option(PREFIX.$id,$row,'',false),'Seed failed');
    if($indexed){$index=get_option(INDEX,[]);$index[$id]=$row;update_option(INDEX,$index,false);}
    return ['token'=>$token,'id'=>$id];
}
function admin_html(array $post=[]):string {
    $u=get_user_by('login','audit-admin');wp_set_current_user($u->ID);
    $_SERVER['REQUEST_METHOD']=$post?'POST':'GET';$_POST=$post;
    if($post)$_POST['_wpnonce']=wp_create_nonce('ontogeny_mcp_admin');
    $_REQUEST=$_POST;ob_start();
    try {WordPressBackend::admin();return (string)ob_get_contents();}
    finally {ob_end_clean();$_POST=[];$_REQUEST=[];$_SERVER['REQUEST_METHOD']='GET';wp_set_current_user(0);}
}
function issue_post():array {return ['issue'=>'1','email'=>'fixture@example.org','verified'=>'1'];}
function visible(string $html,string $id):bool {return str_contains($html,'name="revoke" value="'.$id.'"');}
function alive(string $token):bool {return (new WordPressBackend())->identity($token)!==null;}
function issued(string $html):array {
    ok(preg_match_all('/omcp_[a-f0-9]{64}/D',$html,$m)===1,'Expected exactly one issued token');
    return ['token'=>$m[0][0],'id'=>hash('sha256',$m[0][0])];
}
function raw_exists(string $id):bool {global $wpdb;return $wpdb->get_var($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name=%s",PREFIX.$id))!==null;}
function pause_at_write(string $gate,int $n):void {
    ok(file_put_contents($gate.'/ready-'.$n,'ready')!==false,'Barrier creation failed');
    $end=microtime(true)+40;while(!is_file($gate.'/go-'.$n)){ok(microtime(true)<$end,'Barrier timeout');usleep(10000);}
}
function worker(string $mode):void {
    $p=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    ok(is_array($p)&&is_dir($p['gate']),'Bad worker fixture');$paused=false;
        add_filter('query',static function($sql)use($p,&$paused){global $wpdb;
            if(!$paused&&preg_match('/^\s*(?:INSERT INTO|DELETE FROM)\b/i',$sql)&&str_contains($sql,$wpdb->options)&&str_contains($sql,PREFIX)){$paused=true;pause_at_write($p['gate'],$p['n']);}return $sql;});
    $result=['ok'=>true];
    if($p['op']==='issue')$result=issued(admin_html(issue_post()));
    elseif($p['op']==='revoke'){ $html=admin_html(['revoke'=>$p['id']]);ok(str_contains($html,'Credential revoked.')||str_contains($html,'Credential already absent.'),'Revocation did not report success');}
    elseif($p['op']==='cleanup')WordPressBackend::cleanup();else throw new RuntimeException('Unknown fixture operation');
    ok($paused,'Worker did not reach intended mutation barrier');echo json_encode($result,JSON_THROW_ON_ERROR);
}
// Force all operations to reach their mutation hook before permitting either write.
// Release them in a fixed order so the legacy last-writer-wins defect is reproducible.
function ordered(string $mode,array $ops,?callable $between=null):array {
    $gate=sys_get_temp_dir().'/a04-'.bin2hex(random_bytes(12));ok(mkdir($gate,0700),'Barrier directory failed');$workers=[];
    try {
        foreach($ops as $i=>$op){$proc=proc_open([PHP_BINARY,__FILE__,$mode,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);ok(is_resource($proc),'Worker launch failed');$workers[]=['proc'=>$proc,'pipes'=>$pipes,'done'=>false];$x=json_encode($op+['gate'=>$gate,'n'=>$i],JSON_THROW_ON_ERROR);ok(fwrite($pipes[0],$x)===strlen($x),'Worker input failed');fclose($pipes[0]);}
        $end=microtime(true)+30;
        foreach($workers as $i=>$w)while(!is_file($gate.'/ready-'.$i)){ok(microtime(true)<$end,'Worker did not reach mutation barrier');usleep(10000);}
        if($between!==null)$between();$out=[];
        foreach($workers as $i=>&$w){ok(file_put_contents($gate.'/go-'.$i,'go')!==false,'Worker release failed');$s=stream_get_contents($w['pipes'][1]);$err=stream_get_contents($w['pipes'][2]);fclose($w['pipes'][1]);fclose($w['pipes'][2]);$exit=proc_close($w['proc']);$w['done']=true;ok($exit===0&&$err==='','Worker failed; no credential output');$r=json_decode($s,true,64,JSON_THROW_ON_ERROR);ok(is_array($r),'Invalid worker result');$out[]=$r;}
        unset($w);wp_cache_flush();return $out;
    }finally{foreach($workers as $w)if(!$w['done']){proc_terminate($w['proc']);foreach($w['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($w['proc']);}foreach(glob($gate.'/*')?:[] as $file)unlink($file);rmdir($gate);}
}
function race(string $mode,string $case,array $ops,callable $verify):void {
    $r=ordered($mode,$ops);$html=admin_html();$verify($r,$html);echo 'PASS '.$case."\n";
}
function races(string $mode):void {
    $fixed=$mode==='candidate';
    reset_lab();$a=seed();
    race($mode,'A04.1 issue/issue',[['op'=>'issue'],['op'=>'issue']],function($r,$h)use($fixed,$a){ok(alive($r[0]['token'])&&alive($r[1]['token'])&&visible($h,$a['id']),'Issued records not live');ok(visible($h,$r[0]['id'])===$fixed&&visible($h,$r[1]['id']),'Unexpected issue/issue listing');});
    reset_lab();$a=seed();
    race($mode,'A04.2 issue/revoke',[['op'=>'issue'],['op'=>'revoke','id'=>$a['id']]],function($r,$h)use($fixed,$a){ok(alive($r[0]['token'])&&!alive($a['token'])&&!raw_exists($a['id']),'Revoke authority mismatch');ok(visible($h,$r[0]['id'])===$fixed&&!visible($h,$a['id']),'Unexpected issue/revoke listing');});
    reset_lab();$a=seed();$e=seed(true);
    race($mode,'A04.3 issue/expiration cleanup',[['op'=>'issue'],['op'=>'cleanup']],function($r,$h)use($fixed,$a,$e){ok(alive($r[0]['token'])&&alive($a['token'])&&!alive($e['token'])&&!raw_exists($e['id']),'Cleanup authority mismatch');ok(visible($h,$r[0]['id'])===$fixed&&visible($h,$a['id'])&&!visible($h,$e['id']),'Unexpected issue/cleanup listing');});
    reset_lab();$a=seed();$b=seed();
    race($mode,'A04.4 revoke/revoke',[['op'=>'revoke','id'=>$a['id']],['op'=>'revoke','id'=>$b['id']]],function($r,$h)use($fixed,$a,$b){ok(!alive($a['token'])&&!alive($b['token'])&&!raw_exists($a['id'])&&!raw_exists($b['id']),'Revoked record remained live');ok(visible($h,$a['id'])===!$fixed&&!visible($h,$b['id']),'Unexpected revoke/revoke listing');});
}
function candidate_checks():void {
    global $wpdb;
    reset_lab();$a=seed(false,false);$h=admin_html();ok(alive($a['token'])&&visible($h,$a['id']),'Legacy orphan not discoverable');admin_html(['revoke'=>$a['id']]);ok(!alive($a['token'])&&!visible(admin_html(),$a['id']),'Orphan revoke failed');echo "PASS A04.5 legacy orphan recovery and revocation\n";
    reset_lab();$e=seed(true);ordered('candidate',[['op'=>'cleanup']],function()use($e,$wpdb){$record=['email'=>'fixture@example.org','expires'=>time()+3600,'verified'=>true];ok($wpdb->update($wpdb->options,['option_value'=>serialize($record)],['option_name'=>PREFIX.$e['id']])===1,'Fixture refresh failed');});ok(alive($e['token'])&&visible(admin_html(),$e['id']),'Cleanup deleted changed row');echo "PASS A04.6 stale cleanup snapshot cannot delete changed record\n";
    reset_lab();$a=seed();ok(is_array(get_option(PREFIX.$a['id'])),'Could not prime option cache');ok($wpdb->delete($wpdb->options,['option_name'=>PREFIX.$a['id']])===1,'Fixture SQL deletion failed');ok(!alive($a['token']),'Authorization used stale option cache');echo "PASS A04.7 direct authorization denies deleted cached credential\n";
    reset_lab();$a=seed();
    $fault=static function($sql)use($wpdb){if(str_contains($sql,$wpdb->options)&&(str_contains($sql,PREFIX)||(preg_match('/^\s*SELECT option_name, option_value\b/i',$sql)&&str_contains($sql,'option_name LIKE')))){if(preg_match('/^\s*SELECT option_value\b/i',$sql))return 'SELECT option_value FROM a03_nonexistent_a04_fault';if(preg_match('/^\s*SELECT option_name, option_value\b/i',$sql))return 'SELECT option_name, option_value FROM a03_nonexistent_a04_fault';if(preg_match('/^\s*INSERT INTO\b/i',$sql))return 'INSERT INTO a03_nonexistent_a04_fault (option_name) VALUES (\'fixture\')';if(preg_match('/^\s*DELETE FROM\b/i',$sql))return 'DELETE FROM a03_nonexistent_a04_fault';}return $sql;};
    add_filter('query',$fault);try{ok(!alive($a['token']),'DB read failure did not deny');$h=admin_html(issue_post());ok(str_contains($h,'Credential could not be stored.')&&!preg_match('/omcp_[a-f0-9]{64}/',$h),'Failed issuance exposed token');$h=admin_html(['revoke'=>$a['id']]);ok(str_contains($h,'Credential revocation failed.')&&!str_contains($h,'Credential revoked.')&&str_contains($h,'Credential storage unavailable.'),'Failed revoke/list misreported');}finally{remove_filter('query',$fault);}
    ok(alive($a['token'])&&raw_exists($a['id']),'Failed revocation fixture changed unexpectedly');echo "PASS A04.8 storage failures deny auth and report administrative failure accurately\n";
}
try{$mode=$argv[1]??'';ok($mode==='candidate','Use candidate');boot($mode);if(($argv[2]??'')==='worker'){worker($mode);exit(0);}try{races($mode);candidate_checks();echo "A04 RELEASE PASS — eight scoped checks; no real mail sent.\n";}finally{reset_lab();}}catch(Throwable $e){fwrite(STDERR,'A04 FAIL: '.($e instanceof RuntimeException?$e->getMessage():get_class($e).' at line '.$e->getLine())."\n");exit(1);}
