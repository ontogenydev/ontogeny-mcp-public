<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Deterministic SQL adapter simulation, not a real database or concurrency proof.
require_once __DIR__.'/../src/Server.php';require_once __DIR__.'/../src/WordPressBackend.php';
use Ontogeny\Mcp\WordPressBackend;
define('ONTOGENY_MCP_MAIL_RECIPIENT','recipient@example.org');
define('ONTOGENY_MCP_SIGNING_KEY',str_repeat('ab',32));
function quotaSign(string $payload):string{return hash_hmac('sha256',$payload,wp_salt('auth').'|ontogeny-mcp-v0.2.0');}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
function wp_salt($s){return 'fixture-only-salt';}
final class MemoryDb {
 public string $prefix='fixture_';public string $last_error='';public array $rows=[];public bool $fail=false;
 function prepare($q,...$a){return [$q,$a];}
 function get_var($p){[$q,$a]=$p;$this->last_error=$this->fail?'fixture failure':'';return $this->fail?null:($this->rows[$a[0]]['state']??null);}
 function query($p){[$q,$a]=$p;if($this->fail){$this->last_error='fixture failure';return false;}$this->last_error='';
  if(str_starts_with($q,'INSERT IGNORE')){[$key,$exp]=$a;if(isset($this->rows[$key]))return 0;$this->rows[$key]=['state'=>str_contains($q,"'throttle'")?'throttle':(str_contains($q,"'quota'")?'quota':'pending_unknown'),'counter'=>0,'expires'=>$exp];return 1;}
  if(str_contains($q,'SET counter=CASE')){[$now,$same,$end,$key,$check,$max]=$a;if($now!==$same||$now!==$check)throw new LogicException('Inconsistent fixture clock');if(!isset($this->rows[$key])||$this->rows[$key]['state']!=='throttle')return 0;$row=&$this->rows[$key];if($row['expires']<=$now){$row['counter']=1;$row['expires']=$end;return 1;}if($row['counter']>=$max)return 0;$row['counter']++;return 1;}
  if(str_starts_with($q,'UPDATE')){[$key,$max]=$a;if(!isset($this->rows[$key])||$this->rows[$key]['counter']>=$max)return 0;$this->rows[$key]['counter']++;return 1;}
  throw new LogicException('Unknown SQL in fixture');
 }
 function update($t,$fields,$where){$k=$where['bucket'];if(!isset($this->rows[$k]))return 0;$this->rows[$k]=array_replace($this->rows[$k],$fields);return 1;}
}
$wpdb=new MemoryDb();$b=new WordPressBackend();$n=0;$i=0;
function ok($v,$s){global $n;if(!$v)throw new RuntimeException('FAIL '.$s);echo 'PASS '.(++$n)." $s\n";}
function next_attempt(){global $i;return hash('sha256','fixture-attempt-'.(++$i));}
$_SERVER['REMOTE_ADDR']='192.0.2.1';$reply='visitor@example.org';$a=next_attempt();
ok($b->reserve($reply,$a)==='reserved','first reservation');ok($b->reserve($reply,$a)==='pending_unknown','in-flight replay');$b->finish($a,true);ok($b->reserve($reply,$a)==='already_accepted','accepted replay');
$a=next_attempt();ok($b->reserve($reply,$a)==='reserved','second reply attempt');$b->finish($a,false);ok($b->reserve($reply,$a)==='previous_handoff_failed','failed replay');
$a=next_attempt();ok($b->reserve(strtoupper($reply),$a)==='rate_limited','case-insensitive address quota');ok($b->reserve($reply,$a)==='rate_limited','quota denial replay');
$_SERVER['REMOTE_ADDR']='192.0.2.2';ok($b->reserve($reply,next_attempt())==='rate_limited','changing IP does not reset reply quota');
$wpdb=new MemoryDb();$_SERVER['REMOTE_ADDR']='2001:db8:1::1';for($j=0;$j<5;$j++)ok($b->reserve("v$j@example.org",next_attempt())==='reserved','distinct address under source quota');
$_SERVER['REMOTE_ADDR']='2001:db8:1::2';$_SERVER['HTTP_X_FORWARDED_FOR']='192.0.2.99';ok($b->reserve('new@example.org',next_attempt())==='rate_limited','IPv6 /64 source quota; forwarded header ignored');
$wpdb=new MemoryDb();for($j=0;$j<20;$j++){$_SERVER['REMOTE_ADDR']='192.0.2.'.($j+1);$r=$b->reserve("v$j@example.org",next_attempt());if($r!=='reserved')throw new RuntimeException('global setup');}ok(true,'20 global admissions');
$_SERVER['REMOTE_ADDR']='192.0.2.99';ok($b->reserve('new@example.org',next_attempt())==='rate_limited','global mail quota');
for($j=0;$j<220;$j++){$b->reserve('new@example.org',next_attempt());} $count=count($wpdb->rows);for($j=0;$j<25;$j++)$b->reserve('new@example.org',next_attempt());ok(count($wpdb->rows)===$count,'global admission cap bounds row growth');
$wpdb=new MemoryDb();$wpdb->fail=true;ok($b->reserve('visitor@example.org',next_attempt())==='storage_unavailable','storage error fails closed');
echo "$n storage adapter checks passed; no database or mail used.\n";
