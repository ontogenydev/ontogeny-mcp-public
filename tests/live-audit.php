<?php
// CLI-only HTTPS regression audit. Default mode never submits an approved valid draft.
if (PHP_SAPI !== 'cli') exit(1);
$url='https://ontogenyai.com/wp-json/ontogeny-mcp/v1/mcp';
$mode=$argv[1]??'';
// Explicit loopback-only override for testing this audit runner, never used by deployment.
if($mode==='--fixture'){
    $url=$argv[2]??'';
    if(!preg_match('~^http://127\.0\.0\.1:[0-9]{1,5}/[a-zA-Z0-9/_-]*$~D',$url))throw new RuntimeException('Invalid fixture URL');
} elseif(!in_array($mode,['','--preflight','--send-test'],true))throw new RuntimeException('Unknown audit mode');
$n=0;
function request(?array $payload=null,string $method='POST',array $extra=[]):array {
    global $url;
    $h=array_replace(['Content-Type'=>'application/json','Accept'=>'application/json, text/event-stream','MCP-Protocol-Version'=>'2025-11-25','User-Agent'=>'ONTOGENY-MCP-v020-release-audit/2','Connection'=>'close'],$extra);
    $headers=[];foreach($h as $k=>$v)$headers[]="$k: $v";
    $opts=['method'=>$method,'header'=>implode("\r\n",$headers),'timeout'=>20,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'protocol_version'=>1.1];
    if($payload!==null)$opts['content']=json_encode($payload,JSON_THROW_ON_ERROR);
    $body=@file_get_contents($url,false,stream_context_create(['http'=>$opts,'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]));
    $statusLine=$http_response_header[0]??'';
    if($body===false||!preg_match('~^HTTP/\S+ (\d{3})(?:\s|$)~',$statusLine,$m))throw new RuntimeException('HTTP transport failed (PHP HTTPS stream, TLS or host egress unavailable)');
    $status=(int)$m[1];
    if($status>=300&&$status<400)throw new RuntimeException('Unexpected HTTP redirect');
    return [$status,$body===''?null:json_decode($body,true,64,JSON_THROW_ON_ERROR)];
}
function check(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException('FAIL: '.$name);echo 'PASS '.(++$n).': '.$name."\n";}
function rpc(string $method,array $params=[]):array{[$status,$body]=request(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>(object)$params]);if($status!==200||!is_array($body)||($body['jsonrpc']??null)!=='2.0'||($body['id']??null)!==1)throw new RuntimeException('Unexpected RPC envelope: '.$method);return $body;}
function tool(string $name,array $args=[]):array{$r=rpc('tools/call',['name'=>$name,'arguments'=>(object)$args])['result'];return [json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR),$r['isError']];}
function initialize(string $revision):array{return rpc('initialize',['protocolVersion'=>$revision,'capabilities'=>(object)[],'clientInfo'=>['name'=>'release-audit','version'=>'2']])['result'];}
if($mode==='--preflight'){
    $r=initialize('2025-11-25');check($r['serverInfo']['version']==='0.1.1'&&$r['protocolVersion']==='2025-11-25','baseline HTTPS audit transport and version');exit(0);
}
foreach(['2025-11-25','2025-06-18','2025-03-26'] as $revision){$r=initialize($revision);check($r['serverInfo']['version']==='0.2.0'&&$r['protocolVersion']===$revision,'initialize '.$revision);}
check(request(['jsonrpc'=>'2.0','method'=>'notifications/initialized'])===[202,null],'empty initialized notification');
check(request(null,'GET')[0]===405,'GET no SSE');
check(request(null,'DELETE')[0]===405,'DELETE unsupported');
check(request(['jsonrpc'=>'2.0','id'=>1,'method'=>'ping'],'POST',['Origin'=>'https://audit.invalid'])[0]===403,'foreign origin rejected');
check(rpc('ping')['result']===[],'ping');
$expected=['get_organization','list_systems','get_system','get_contact_channels','prepare_correspondence','send_correspondence'];
check(array_column(rpc('tools/list')['result']['tools'],'name')===$expected,'six exact tools');
check(tool('get_organization')[0]['name']==='ONTOGENY','organization');
check(array_column(tool('list_systems')[0]['systems'],'id')===['stratadome'],'public system list');
check(tool('get_system',['system_id'=>'stratadome'])[0]['id']==='stratadome','StrataDOME dossier');
check(tool('get_system',['system_id'=>'private'])[1]===true,'protected system rejected');
check(tool('get_contact_channels')[0]['email']==='contact@ontogenyai.com','public contact');
check(rpc('admin/delete')['error']['code']===-32601,'administrative method rejected');
$draft=['subject'=>'Release regression — NOT FOR DELIVERY','message'=>'Non-mailing smoke test.','reply_email'=>'audit@example.org'];
[$p,$err]=tool('prepare_correspondence',$draft);
check(!$err&&$p['status']==='prepared_not_sent'&&$p['send_available']===true&&is_string($p['ticket']??null)&&$p['reply_email_verified']===false,'public ticket issued without credentials');
$send=$draft+['ticket'=>$p['ticket'],'approved'=>false];
check(tool('send_correspondence',$send)===[['error'=>'human_approval_required'],true],'missing approval blocked');
check(tool('send_correspondence',array_replace($send,['ticket'=>'invalid','approved'=>true]))===[['error'=>'invalid_ticket'],true],'malformed ticket blocked');
check(tool('send_correspondence',array_replace($send,['message'=>'Modified test draft','approved'=>true]))===[['error'=>'expired_or_modified_draft'],true],'modified draft blocked');
check(tool('send_correspondence',$send+['to'=>'audit@example.org'])[1]===true,'arbitrary destination blocked');
check(tool('prepare_correspondence',array_replace($draft,['subject'=>"Bad\nBcc: audit@example.org"]))[1]===true,'header injection blocked');
check(tool('prepare_correspondence',array_replace($draft,['reply_email'=>'invalid']))[1]===true,'invalid reply address blocked');
echo "$n live negative-path/read checks passed. No approved valid draft submitted.\n";
if($mode==='--send-test'){
    $draft=['subject'=>'ONTOGENY MCP v0.2.0 controlled release test '.gmdate('c'),'message'=>'Controlled ONTOGENY release verification. Please disregard.','reply_email'=>'contact@ontogenyai.com'];
    [$p,$err]=tool('prepare_correspondence',$draft);check(!$err&&!empty($p['ticket'])&&$p['send_available']===true,'controlled test draft prepared');
    echo 'Destination (public contact identity): '.$p['to']."\nClaimed reply: ".$draft['reply_email']."\nSubject: ".$draft['subject']."\nMessage: ".$draft['message']."\nType SEND ONE TEST to approve this exact message: ";
    if(trim((string)fgets(STDIN))!=='SEND ONE TEST'){echo "No test mail submitted.\n";exit(0);}
    $send=$draft+['ticket'=>$p['ticket'],'approved'=>true];[$r,$err]=tool('send_correspondence',$send);
    check(!$err&&$r['status']==='accepted_by_mail_service'&&$r['delivery_confirmed']===false,'single public mail accepted');
    [$r2,$err2]=tool('send_correspondence',$send);check($err2&&$r2['status']==='already_accepted'&&$r2['receipt']===$r['receipt'],'same-ticket replay blocked');
    echo "Check ONTOGENY inbox for exactly one matching message. Do not retry an uncertain handoff.\n";
}
