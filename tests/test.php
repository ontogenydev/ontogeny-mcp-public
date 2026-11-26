<?php
// Copyright (C) 2026 ONTOGENY
// SPDX-License-Identifier: GPL-2.0-or-later
require_once __DIR__.'/../src/Server.php';
use Ontogeny\Mcp\Server;use Ontogeny\Mcp\Backend;
final class Fake implements Backend {
    public array $states=[];public array $sent=[];public bool $fail=false;public int $limit=2;
    public bool $enabled=true;
    public function sendEnabled():bool{return $this->enabled;}
    public function admit(string $kind):string{return 'allowed';}
    public function sign(string $payload):string{return hash_hmac('sha256',$payload,'test-only-key');}
    public function reserve(string $id,string $attempt):string{if(isset($this->states[$attempt]))return $this->states[$attempt];if(count($this->sent)>=$this->limit)return $this->states[$attempt]='rate_limited';$this->states[$attempt]='pending_unknown';return 'reserved';}
    public function mail(string $reply,string $subject,string $message):bool{$this->sent[]=[$reply,$subject,$message];return !$this->fail;}
    public function finish(string $attempt,bool $accepted):void{$this->states[$attempt]=$accepted?'already_accepted':'previous_handoff_failed';}
}
$b=new Fake();$s=new Server($b);$h=['content-type'=>'application/json','accept'=>'application/json, text/event-stream','mcp-protocol-version'=>'2025-11-25'];$auth=['authorization'=>'Bearer omcp_'.str_repeat('a',64)];$authB=['authorization'=>'Bearer omcp_'.str_repeat('b',64)];$draft=['subject'=>'Test subject','message'=>'Test body','reply_email'=>'visitor@example.org'];$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
function rpc(string $method,array $p=[],array $headers=[]):array{global $s,$h;return $s->http('POST',$headers+$h,json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>(object)$p]));}
function calltool(string $name,array $args=[],array $headers=[]):array{$r=rpc('tools/call',['name'=>$name,'arguments'=>(object)$args],$headers);$out=$r['body']['result']??[];return isset($out['content'])?json_decode($out['content'][0]['text'],true):$r['body'];}
$r=rpc('initialize',['protocolVersion'=>'2025-11-25','capabilities'=>(object)[],'clientInfo'=>(object)['name'=>'test','version'=>'1']]);check($r['body']['result']['serverInfo']['version']==='0.3.0','initialize version');
$r=rpc('initialize',['protocolVersion'=>'2026-07-28','capabilities'=>(object)[],'clientInfo'=>(object)['name'=>'test']]);check($r['body']['result']['protocolVersion']==='2025-11-25','negotiate supported revision');
$r=$s->http('POST',$h,'{"jsonrpc":"2.0","method":"notifications/initialized"}');check($r['status']===202&&$r['body']===null,'notification empty 202');
check($s->http('GET',$h,'')['status']===405,'GET no SSE');check($s->http('DELETE',$h,'')['status']===405,'DELETE unsupported');check($s->http('GET',['origin'=>'https://attacker.example'],'')['status']===403,'reject foreign origin');check($s->http('POST',['origin'=>'null']+$h,'{}')['status']===403,'reject null origin');check($s->http('POST',['origin'=>'https://ontogenyai.com']+$h,'{')['status']===400,'parse error');check($s->http('POST',$h,str_repeat('x',32769))['status']===413,'size limit');check($s->http('POST',['content-type'=>'text/plain']+$h,'{}')['status']===415,'content type');check($s->http('POST',['accept'=>'application/json']+$h,'{}')['status']===406,'accept validation');check(rpc('ping',[],['mcp-protocol-version'=>'invalid'])['status']===400,'revision validation');check(rpc('ping')['body']['result'] instanceof stdClass,'ping');check($s->http('POST',$h,'[]')['status']===400,'reject batch');check(rpc('admin/delete')['body']['error']['code']===-32601,'reject administrative method');
$names=array_column(rpc('tools/list')['body']['result']['tools'],'name');check($names===['get_organization','list_systems','get_system','get_contact_channels','prepare_correspondence','send_correspondence'],'six exact tools');check(calltool('get_organization')['name']==='ONTOGENY','organization');check(count(calltool('list_systems')['systems'])===1,'only current public system');check(calltool('get_system',['system_id'=>'stratadome'])['id']==='stratadome','dossier');check(calltool('get_system',['system_id'=>'private'])['error']==='invalid_system_id','reject unknown system');check(calltool('get_contact_channels')['email']==='contact@ontogenyai.com','public contact');check(calltool('get_organization',['admin'=>true])['error']==='invalid_arguments','reject extra args');check(calltool('prepare_correspondence',['subject'=>"Bad\nBcc: x",'message'=>'x'])['error']==='invalid_draft','header injection');check(calltool('prepare_correspondence',['subject'=>'a','message'=>str_repeat('x',10001)])['error']==='invalid_draft','message limit');check(calltool('prepare_correspondence',['subject'=>'a','message'=>' '])['error']==='invalid_draft','blank message');
$p=calltool('prepare_correspondence',['subject'=>'Test subject','message'=>'Test body']);check($p['status']==='prepared_not_sent'&&!isset($p['ticket'])&&!$p['send_available']&&count($b->sent)===0,'legacy preview without reply address');
$p=calltool('prepare_correspondence',$draft);check(isset($p['ticket'])&&$p['reply_to']==='visitor@example.org'&&!$p['reply_email_verified']&&$p['send_available']&&count($b->sent)===0,'public preparation without credential or mail');
$send=$draft+['ticket'=>$p['ticket']];
check(calltool('send_correspondence',$send+['approved'=>false])['error']==='invalid_arguments','legacy false approval is rejected, never ignored');
check(calltool('send_correspondence',$send+['approved'=>'true'])['error']==='invalid_arguments','removed approval field is rejected');
check(calltool('send_correspondence',array_replace($send,['reply_email'=>'other@example.org']))['error']==='expired_or_modified_draft','ticket bound to reply address');
check(calltool('send_correspondence',array_replace($send,['message'=>'changed']))['error']==='expired_or_modified_draft','draft mutation blocked');
check(calltool('send_correspondence',array_replace($send,['subject'=>'changed']))['error']==='expired_or_modified_draft','subject mutation blocked');
check(calltool('send_correspondence',array_replace($send,['ticket'=>'bad']))['error']==='invalid_ticket','bad ticket blocked');
check(calltool('send_correspondence',$send+['to'=>'elsewhere@example.org'])['error']==='invalid_arguments','arbitrary recipient blocked');
check(calltool('prepare_correspondence',array_replace($draft,['reply_email'=>"x@example.org\r\nBcc: evil@example.org"]))['error']==='invalid_reply_email','reply header injection blocked');
check(calltool('prepare_correspondence',array_replace($draft,['reply_email'=>'nonsense']))['error']==='invalid_reply_email','invalid reply blocked');
check(calltool('prepare_correspondence',array_replace($draft,['reply_email'=>null]))['error']==='invalid_reply_email','null reply blocked');
$nonce=str_repeat('a',32);$expired=time()-1;$ticket=$expired.'.'.$nonce.'.'.$b->sign(json_encode(['v0.3.0',$draft['reply_email'],$draft['subject'],$draft['message'],$nonce,$expired],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
check(calltool('send_correspondence',array_replace($send,['ticket'=>$ticket]))['error']==='expired_or_modified_draft','expired signed ticket blocked');
$expiry=time()+500;$old=$expiry.'.'.$nonce.'.'.$b->sign(json_encode([hash('sha256','old'),$draft['reply_email'],$draft['subject'],$draft['message'],$nonce,$expiry],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
check(calltool('send_correspondence',array_replace($send,['ticket'=>$old]),$auth)['error']==='expired_or_modified_draft','old credential-bound ticket rejected');
$b->enabled=false;check(!isset(calltool('prepare_correspondence',$draft)['ticket']),'disabled service no ticket');check(calltool('send_correspondence',$send)['error']==='correspondence_unavailable','disabled service blocks send');$b->enabled=true;
check(count($b->sent)===0,'invalid paths no mail');
check(calltool('send_correspondence',$send)['status']==='accepted_by_mail_service','public send without authorization header');
check($b->sent[0]===['visitor@example.org','Test subject','Test body'],'exact prepared reply and draft');
check(calltool('send_correspondence',$send,$auth)['status']==='already_accepted'&&count($b->sent)===1,'replay no duplicate even with legacy bearer');
$b->fail=true;$p=calltool('prepare_correspondence',$draft);$send2=$draft+['ticket'=>$p['ticket']];$res=calltool('send_correspondence',$send2);check($res['status']==='mail_handoff_failed'&&$res['delivery_confirmed']===false,'mail failure reported');check(calltool('send_correspondence',$send2)['status']==='previous_handoff_failed','failure replay blocked');$p=calltool('prepare_correspondence',$draft);check(calltool('send_correspondence',$draft+['ticket'=>$p['ticket']])['status']==='rate_limited'&&count($b->sent)===2,'quota blocks further sends');
$r=$s->http('POST',$h,json_encode(['jsonrpc'=>'2.0','method'=>'tools/call','params'=>['name'=>'send_correspondence','arguments'=>$send]]));check($r['status']===400&&count($b->sent)===2,'notification cannot send');
check(!str_contains(json_encode(rpc('tools/list')),'recipient@example.org'),'deployment recipient not disclosed by discovery');echo "\n$n checks passed; no real mail sent.\n";
