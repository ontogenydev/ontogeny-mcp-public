<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// v0.3.0 contract and failure-path checks. All mail and database operations are simulated.
require_once __DIR__.'/../src/Server.php';
require_once __DIR__.'/../src/WordPressBackend.php';
use Ontogeny\Mcp\Backend;
use Ontogeny\Mcp\Server;
use Ontogeny\Mcp\WordPressBackend;

function expect030(bool $condition,string $label):void {
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.(++$GLOBALS['checks030'])." $label\n";
}
function rpc030(Server $server,string $method,array $params=[]):array {
    return $server->http('POST',['content-type'=>'application/json','accept'=>'application/json, text/event-stream','mcp-protocol-version'=>'2025-11-25'],json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>(object)$params],JSON_THROW_ON_ERROR));
}
function call030(Server $server,string $name,array $args):array {
    $response=rpc030($server,'tools/call',['name'=>$name,'arguments'=>(object)$args]);
    return json_decode($response['body']['result']['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
}
function prepared030(Server $server,array $draft):array {
    $preview=call030($server,'prepare_correspondence',$draft);
    if(!isset($preview['ticket']))throw new RuntimeException('Fixture could not prepare ticket');
    return $draft+['ticket'=>$preview['ticket']];
}
final class OutcomeBackend030 implements Backend {
    public array $states=[];
    public int $mailCalls=0;
    public int $simulatedAcceptances=0;
    public int $finishCalls=0;
    public string $mode='accept';
    public bool $failFinish=false;
    public function sendEnabled():bool{return true;}
    public function admit(string $kind):string{return 'allowed';}
    public function sign(string $payload):string{return hash_hmac('sha256',$payload,'fixture-only-key');}
    public function reserve(string $reply,string $attempt):string {
        if(isset($this->states[$attempt]))return $this->states[$attempt];
        $this->states[$attempt]='pending_unknown';return 'reserved';
    }
    public function mail(string $reply,string $subject,string $message):bool {
        $this->mailCalls++;
        if($this->mode==='throw_before')throw new RuntimeException('Fixture mail exception');
        if($this->mode==='reject')return false;
        $this->simulatedAcceptances++;
        if($this->mode==='throw_after')throw new RuntimeException('Fixture post-acceptance hook exception');
        return true;
    }
    public function finish(string $attempt,bool $accepted):void {
        $this->finishCalls++;
        if($this->failFinish)throw new RuntimeException('Fixture persistence exception');
        $this->states[$attempt]=$accepted?'already_accepted':'previous_handoff_failed';
    }
}
$GLOBALS['checks030']=0;
$draft=['subject'=>'Fixture subject','message'=>'Literal fixture body','reply_email'=>'visitor@example.org'];
$backend=new OutcomeBackend030();$server=new Server($backend);
$schema=array_column(Server::tools(),null,'name')['send_correspondence']['inputSchema'];
expect030($schema['required']===['subject','message','reply_email','ticket']&&!property_exists($schema['properties'],'approved')&&$schema['additionalProperties']===false,'send schema requires only the prepared draft, reply address and ticket');
$preview=call030($server,'prepare_correspondence',$draft);
expect030(!array_key_exists('human_approval_required',$preview)&&$preview['status']==='prepared_not_sent'&&$backend->mailCalls===0,'preparation has no human approval assertion and never sends');
$send=$draft+['ticket'=>$preview['ticket']];
foreach([true,false,'true'] as $legacy)expect030((call030($server,'send_correspondence',$send+['approved'=>$legacy])['error']??null)==='invalid_arguments'&&$backend->mailCalls===0,'legacy approval value rejected without handoff');
$expires=time()+300;$nonce=str_repeat('c',32);
$old=$expires.'.'.$nonce.'.'.$backend->sign(json_encode(['v0.2.1',$draft['reply_email'],$draft['subject'],$draft['message'],$nonce,$expires],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
expect030((call030($server,'send_correspondence',array_replace($send,['ticket'=>$old]))['error']??null)==='expired_or_modified_draft'&&$backend->mailCalls===0,'v0.2.1 ticket rejected even when otherwise valid');
$result=call030($server,'send_correspondence',$send);
expect030($result['status']==='accepted_by_mail_service'&&$result['outcome_recorded']===true&&$result['delivery_confirmed']===false&&$backend->mailCalls===1,'new contract sends without approval field and records acceptance');
$replay=call030($server,'send_correspondence',$send);
expect030($replay['status']==='already_accepted'&&$replay['receipt']===$result['receipt']&&$backend->mailCalls===1,'successful replay returns same receipt without another handoff');
foreach(['throw_before','throw_after'] as $mode){
    $backend=new OutcomeBackend030();$backend->mode=$mode;$server=new Server($backend);$send=prepared030($server,$draft);
    $result=call030($server,'send_correspondence',$send);
    expect030($result['status']==='pending_unknown'&&$result['delivery_confirmed']===false&&$backend->finishCalls===0&&$backend->states[$result['receipt']]==='pending_unknown',"$mode remains uncertain and does not record false failure");
    $replay=call030($server,'send_correspondence',$send);
    expect030($replay['status']==='pending_unknown'&&$replay['receipt']===$result['receipt']&&$backend->mailCalls===1&&$backend->simulatedAcceptances===($mode==='throw_after'?1:0),"$mode replay cannot repeat handoff");
}
expect030(WordPressBackend::event(['status'=>200,'body'=>['result'=>['structuredContent'=>(object)$result]]])==='mail_uncertain','initial uncertain handoff has its own telemetry category');
expect030(WordPressBackend::event(['status'=>200,'body'=>['result'=>['structuredContent'=>(object)$replay]]])==='replay_blocked','uncertain reservation replay remains a replay telemetry event');
foreach(['accept','reject'] as $mode){
    $backend=new OutcomeBackend030();$backend->mode=$mode;$backend->failFinish=true;$server=new Server($backend);$send=prepared030($server,$draft);
    $result=call030($server,'send_correspondence',$send);
    expect030($result['status']===($mode==='accept'?'accepted_by_mail_service':'mail_handoff_failed')&&$result['outcome_recorded']===false&&$result['delivery_confirmed']===false&&$backend->states[$result['receipt']]==='pending_unknown',"$mode result and receipt survive outcome persistence failure");
    $replay=call030($server,'send_correspondence',$send);
    expect030($replay['status']==='pending_unknown'&&$replay['receipt']===$result['receipt']&&$backend->mailCalls===1,"$mode persistence failure replay cannot repeat handoff");
}
$backend=new OutcomeBackend030();$server=new Server($backend);
expect030($server->http('GET',[],'')['headers']['Allow']==='POST','unsupported GET advertises only POST');

// Fault injection distinguishes SQL failure from legitimate quota exhaustion.
define('ONTOGENY_MCP_MAIL_RECIPIENT','recipient@example.org');
define('ONTOGENY_MCP_SIGNING_KEY',str_repeat('ab',32));
function wp_salt($scheme){return 'fixture-only-salt';}
// Deliberately model WordPress rejecting quoted local parts and IP-literal domains.
// Reply addresses are body-only data and must use Server's shared validator instead.
function is_email($value){return is_string($value)&&!strpbrk($value,'"[]')?filter_var($value,FILTER_VALIDATE_EMAIL):false;}
function wp_mail($to,$subject,$body,$headers){$GLOBALS['mail030'][]=[$to,$subject,$body,$headers];return true;}
final class FaultDb030 {
    public string $prefix='fixture_';public string $last_error='';public array $rows=[];
    public string $failAt='';public string $limitAt='';public bool $failStateWrite=false;
    private array $seen=[];
    public function prepare($query,...$args){return [$query,$args];}
    private function fault(string $stage):bool {
        $index=($this->seen[$stage]??0)+1;$this->seen[$stage]=$index;
        $this->last_error=($this->failAt===$stage.':'.$index)?'Fixture SQL failure':'';
        return $this->last_error!=='';
    }
    public function get_var($prepared){
        [$query,$args]=$prepared;
        if($this->fault('select'))return null;
        return $this->rows[$args[0]]['state']??null;
    }
    public function query($prepared){
        [$query,$args]=$prepared;
        if(str_starts_with($query,'INSERT IGNORE')){
            $state=str_contains($query,"'quota'")?'quota':(str_contains($query,"'throttle'")?'throttle':'pending_unknown');
            if($this->fault($state.'_insert'))return false;
            [$key,$expires]=$args;if(isset($this->rows[$key]))return 0;
            $this->rows[$key]=['state'=>$state,'counter'=>0,'expires'=>$expires];return 1;
        }
        if(str_contains($query,'SET counter=CASE')){
            if($this->fault('throttle_update'))return false;
            [$now,$same,$end,$key,$check,$limit]=$args;$row=&$this->rows[$key];
            if($row['expires']<=$now){$row['counter']=1;$row['expires']=$end;return 1;}
            if($row['counter']>=$limit)return 0;$row['counter']++;return 1;
        }
        if(str_contains($query,'SET counter=counter+1')){
            if($this->fault('quota_update'))return false;
            if($this->limitAt==='quota_update:'.$this->seen['quota_update'])return 0;
            [$key,$limit]=$args;if(!isset($this->rows[$key])||$this->rows[$key]['counter']>=$limit)return 0;
            $this->rows[$key]['counter']++;return 1;
        }
        throw new LogicException('Unrecognized fixture SQL');
    }
    public function update($table,$fields,$where){
        $this->last_error=$this->failStateWrite?'Fixture state failure':'';
        if($this->failStateWrite)return false;
        $key=$where['bucket'];if(!isset($this->rows[$key]))return 0;
        foreach($where as $field=>$value)if($field!=='bucket'&&($this->rows[$key][$field]??null)!==$value)return 0;
        $this->rows[$key]=array_replace($this->rows[$key],$fields);return 1;
    }
}
$_SERVER['REMOTE_ADDR']='192.0.2.30';
foreach(['select:1','quota_insert:1','quota_update:1','quota_insert:3','quota_update:3','pending_unknown_insert:1','quota_insert:4','quota_update:4','quota_update:8'] as $stage){
    $wpdb=new FaultDb030();$wpdb->failAt=$stage;$GLOBALS['mail030']=[];$server=new Server(new WordPressBackend());
    $send=prepared030($server,$draft);$result=call030($server,'send_correspondence',$send);
    expect030($result['status']==='storage_unavailable'&&$GLOBALS['mail030']===[],"$stage SQL failure is reported as storage failure with no mail");
}
foreach(['quota_update:1','quota_update:4'] as $stage){
    $wpdb=new FaultDb030();$wpdb->limitAt=$stage;$GLOBALS['mail030']=[];$server=new Server(new WordPressBackend());
    $result=call030($server,'send_correspondence',prepared030($server,$draft));
    expect030($result['status']==='rate_limited'&&$GLOBALS['mail030']===[],"$stage exhausted quota remains a distinct no-mail result");
}
$wpdb=new FaultDb030();$wpdb->limitAt='quota_update:4';$wpdb->failStateWrite=true;$GLOBALS['mail030']=[];$server=new Server(new WordPressBackend());
$send=prepared030($server,$draft);$result=call030($server,'send_correspondence',$send);
expect030($result['status']==='storage_unavailable'&&$GLOBALS['mail030']===[],'failure to persist quota denial remains fail closed');
$wpdb->failStateWrite=false;$wpdb->limitAt='';$replay=call030($server,'send_correspondence',$send);
expect030($replay['status']==='pending_unknown'&&$GLOBALS['mail030']===[],'failed quota-state write retains replay reservation');
$wpdb=new FaultDb030();$GLOBALS['mail030']=[];$server=new Server(new WordPressBackend());$send=prepared030($server,$draft);$wpdb->failStateWrite=true;
$result=call030($server,'send_correspondence',$send);
expect030($result['status']==='accepted_by_mail_service'&&$result['outcome_recorded']===false&&count($GLOBALS['mail030'])===1,'actual backend detects failed outcome update');
$wpdb->failStateWrite=false;$replay=call030($server,'send_correspondence',$send);
expect030($replay['status']==='pending_unknown'&&count($GLOBALS['mail030'])===1,'actual backend persistence failure blocks duplicate mail');
$backend=new WordPressBackend();$threw=false;
try{$backend->finish(hash('sha256','missing-fixture-attempt'),true);}catch(RuntimeException){$threw=true;}
expect030($threw,'missing outcome row is not reported as persisted');
$attempt=$result['receipt'];$backend->finish($attempt,true);$threw=false;
try{$backend->finish($attempt,false);}catch(RuntimeException){$threw=true;}
expect030($threw&&$wpdb->rows[$attempt]['state']==='already_accepted','final outcome cannot be overwritten by a later finish call');
foreach(['visitor@example.org','"visitor"@example.org','visitor@[192.0.2.42]'] as $reply){
    $wpdb=new FaultDb030();$GLOBALS['mail030']=[];$server=new Server(new WordPressBackend());$case=array_replace($draft,['reply_email'=>$reply]);
    $result=call030($server,'send_correspondence',prepared030($server,$case));
    expect030($result['status']==='accepted_by_mail_service'&&count($GLOBALS['mail030'])===1&&$GLOBALS['mail030'][0][0]==='recipient@example.org'&&str_contains($GLOBALS['mail030'][0][2],$reply)&&$GLOBALS['mail030'][0][3]===['Content-Type: text/plain; charset=UTF-8'],'prepared reply address reaches fixed recipient as body-only data');
}
foreach(['invalid',"visitor@example.org\r\nBcc: other@example.org",' visitor@example.org'] as $reply){
    $GLOBALS['mail030']=[];
    expect030(!Server::validReplyEmail($reply)&&!(new WordPressBackend())->mail($reply,'Fixture','Body')&&$GLOBALS['mail030']===[],'shared reply validator rejects malformed or injected addresses before handoff');
}
echo $GLOBALS['checks030']." v0.3.0 focused checks passed; no real database, network or mail.\n";
