<?php
// Copyright (C) 2026 ONTOGENY
// SPDX-License-Identifier: GPL-2.0-or-later
namespace Ontogeny\Mcp;

/** JSON-only Streamable HTTP, protocol revision 2025-11-25 (and 2025-06-18). */
interface Backend {
    /** A configured fixed destination and emergency switch determine availability. */
    public function sendEnabled(): bool;
    public function sign(string $payload): string;
    /** Atomically reserve an attempt and enforce source, claimed reply-address and global quotas. */
    public function reserve(string $reply, string $attempt): string;
    /** Call the site mail service, returning acceptance, not delivery. */
    public function mail(string $reply, string $subject, string $message): bool;
    public function finish(string $attempt, bool $accepted): void;
}
final class Server {
    public const VERSION = '0.2.0';
    public const ENDPOINT = 'https://ontogenyai.com/wp-json/ontogeny-mcp/v1/mcp';
    public const TARGET = 'https://mcp.ontogenyai.com/mcp';
    public const DOCS = 'https://ontogenyai.com/mcp/';
    private const VERSIONS = ['2025-11-25','2025-06-18','2025-03-26'];
    private const ORIGINS = ['https://ontogenyai.com','https://www.ontogenyai.com','https://mcp.ontogenyai.com'];
    private const DEFINITION = 'StrataDOME is a cross-sovereign epistemic protocol for distributed cognition across autonomous domains—enabling the formation, recursive evaluation, and revision of shared knowledge through bounded evidence exchange, without subordinating participating domains to a common authority.';
    private const CONTACT = 'contact@ontogenyai.com';
    public function __construct(private Backend $backend) {}
    private static function schema(array $properties=[],array $required=[]): array {return ['type'=>'object','properties'=>(object)$properties,'required'=>$required,'additionalProperties'=>false];}
    public static function tools(): array {
        $subject=['type'=>'string','minLength'=>1,'maxLength'=>160]; $message=['type'=>'string','minLength'=>1,'maxLength'=>10000];
        $fields=['subject'=>$subject,'message'=>$message,'reply_email'=>['type'=>'string','minLength'=>3,'maxLength'=>254,'description'=>'Your reply address, supplied by you and not independently verified.']];
        $read=['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false];
        $write=['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>true];
        return [
            ['name'=>'get_organization','description'=>'Read ONTOGENY public organizational information.','inputSchema'=>self::schema(),'annotations'=>$read],
            ['name'=>'list_systems','description'=>'List only systems publicly documented on ONTOGENY’s website.','inputSchema'=>self::schema(),'annotations'=>$read],
            ['name'=>'get_system','description'=>'Read the public StrataDOME dossier. Does not expose protected implementation or imply a completed demo.','inputSchema'=>self::schema(['system_id'=>['type'=>'string','enum'=>['stratadome']]],['system_id']),'annotations'=>$read],
            ['name'=>'get_contact_channels','description'=>'Read ONTOGENY public correspondence options.','inputSchema'=>self::schema(),'annotations'=>$read],
            ['name'=>'prepare_correspondence','description'=>'Prepare a draft without sending mail. Supply a reply_email to automatically obtain a ten-minute, single-use ticket when transmission is available. No registration or credential required. Present the full draft and reply address to the human.','inputSchema'=>self::schema($fields,['subject','message']),'annotations'=>$read],
            ['name'=>'send_correspondence','description'=>'Submit exactly the prepared correspondence to ONTOGENY, including the reply_email and ticket. No registration or credential required. The client must obtain explicit human approval before calling. Rate limits apply. Acceptance does not mean inbox delivery.','inputSchema'=>self::schema($fields+['ticket'=>['type'=>'string','minLength'=>20,'maxLength'=>256],'approved'=>['type'=>'boolean','const'=>true]],['subject','message','reply_email','ticket','approved']),'annotations'=>$write],
        ];
    }
    private function response(int $status, ?array $body, array $headers=[]): array {return ['status'=>$status,'body'=>$body,'headers'=>['Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff']+$headers];}
    private function error(int $code,string $message,mixed $id=null,int $status=200):array{return $this->response($status,['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]]);}
    private function result(mixed $id,mixed $result):array{return $this->response(200,['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);}
    private function tool(mixed $id,array $value,bool $error=false):array{return $this->result($id,['content'=>[['type'=>'text','text'=>json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]],'isError'=>$error]);}
    public function http(string $method,array $headers,string $body):array {
        $headers=array_change_key_case($headers,CASE_LOWER);
        $origin=$headers['origin']??'';
        if($origin!=='' && !in_array($origin,self::ORIGINS,true))return $this->error(-32000,'Origin not permitted',null,403);
        if($method!=='POST')return $this->response(405,null,['Allow'=>'POST, GET']);
        if(strlen($body)>32768)return $this->error(-32600,'Request exceeds 32 KiB',null,413);
        if(strtolower(trim(explode(';',$headers['content-type']??'')[0]))!=='application/json')return $this->error(-32600,'Content-Type must be application/json',null,415);
        $accept=strtolower($headers['accept']??'');
        if(!str_contains($accept,'application/json')||!str_contains($accept,'text/event-stream'))return $this->error(-32600,'Accept must include application/json and text/event-stream',null,406);
        try{$m=json_decode($body,false,64,JSON_THROW_ON_ERROR);}catch(\JsonException){return $this->error(-32700,'Parse error',null,400);}
        if(!is_object($m)||($m->jsonrpc??null)!=='2.0'||!is_string($m->method??null))return $this->error(-32600,'Invalid request',null,400);
        $has_id=property_exists($m,'id');$id=$m->id??null;
        if($has_id&&!is_int($id)&&!is_string($id))return $this->error(-32600,'Invalid request ID',null,400);
        if(isset($m->params)&&!is_object($m->params))return $this->error(-32602,'Parameters must be an object',$id,400);
        $p=(array)($m->params??new \stdClass());
        $version=$headers['mcp-protocol-version']??'2025-03-26';
        if($m->method!=='initialize'&&!in_array($version,self::VERSIONS,true))return $this->error(-32600,'Unsupported MCP protocol version',$id,400);
        // Notifications are never allowed to invoke a tool or another side effect.
        if(!$has_id){if(in_array($m->method,['notifications/initialized','notifications/cancelled'],true))return $this->response(202,null);return $this->response(400,null);}
        if($m->method==='initialize'){
            if(!is_string($p['protocolVersion']??null)||!is_object($p['capabilities']??null)||!is_object($p['clientInfo']??null))return $this->error(-32602,'Invalid initialize parameters',$id);
            $negotiated=in_array($p['protocolVersion'],self::VERSIONS,true)?$p['protocolVersion']:self::VERSIONS[0];
            return $this->result($id,['protocolVersion'=>$negotiated,'capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'ontogeny-mcp','version'=>self::VERSION],'instructions'=>'Public information and correspondence tools. No registration or manual credentials required. Prepare, show the exact draft and reply address, obtain explicit human approval in the client, then send. Rate limits apply.']);
        }
        if($m->method==='ping')return $this->result($id,(object)[]);
        if($m->method==='tools/list')return $this->result($id,['tools'=>self::tools()]);
        if($m->method!=='tools/call')return $this->error(-32601,'Method not found',$id);
        $name=$p['name']??null;$args=$p['arguments']??new \stdClass();
        if(!is_string($name)||!is_object($args))return $this->error(-32602,'Invalid tool call',$id);
        $a=(array)$args;
        $tools=array_column(self::tools(),null,'name');if(!isset($tools[$name]))return $this->error(-32602,'Unknown tool',$id);
        $s=$tools[$name]['inputSchema'];$props=(array)$s['properties'];
        if(array_diff_key($a,$props)||array_diff($s['required'],array_keys($a)))return $this->tool($id,['error'=>'invalid_arguments'],true);
        foreach($a as $k=>$v){$rule=$props[$k];if($rule['type']==='string'&&(!is_string($v)||strlen($v)>($rule['maxLength']??256)*4||preg_match('//u',$v)!==1))return $this->tool($id,['error'=>'invalid_'.$k],true);if($rule['type']==='boolean'&&!is_bool($v))return $this->tool($id,['error'=>'invalid_'.$k],true);if(isset($rule['enum'])&&!in_array($v,$rule['enum'],true))return $this->tool($id,['error'=>'invalid_'.$k],true);}
        switch($name){
        case 'get_organization':return $this->tool($id,['name'=>'ONTOGENY','descriptor'=>'Cybernetic infrastructure','tagline'=>'Cross-sovereign','website'=>'https://ontogenyai.com/','contact'=>self::CONTACT,'documentation'=>self::DOCS]);
        case 'list_systems':return $this->tool($id,['systems'=>[['id'=>'stratadome','name'=>'StrataDOME','public_dossier'=>'https://ontogenyai.com/#stratadome']]]);
        case 'get_system':return $this->tool($id,['id'=>'stratadome','name'=>'StrataDOME','definition'=>self::DEFINITION,'principles'=>['Independent domain authority and protected source state','Authorized bounded evidence exchange','Recursive reevaluation and preservation of evidentiary provenance','Local epistemic execution decoupled from inter-domain transport'],'public_dossier'=>'https://ontogenyai.com/#stratadome','public_documentation'=>'https://github.com/ontogenydev/StrataDOME-public','note'=>'Public design documentation; this response does not certify production readiness or completed end-to-end validation.']);
        case 'get_contact_channels':return $this->tool($id,['email'=>self::CONTACT,'contact_page'=>'https://ontogenyai.com/contact/','categories'=>['Research & engineering','Institutional collaboration','Commercial & strategic partnerships','General correspondence'],'mcp_send'=>($this->backend->sendEnabled()?'Available without registration; prepare with your reply email and obtain explicit human approval before sending.':'Temporarily unavailable; use the contact page.')]);
        }
        // Plain text only, no header injection, no automatic recipient selection.
        $subject=$a['subject'];$message=$a['message'];
        if($subject!==trim($subject)||trim($subject)===''||trim($message)===''||strlen($subject)>160||strlen($message)>10000||preg_match('/[\x00-\x1f\x7f]/',$subject)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$message))return $this->tool($id,['error'=>'invalid_draft','limits'=>'Subject: 160 UTF-8 bytes; message: 10000 UTF-8 bytes. No control characters except message line breaks/tabs.'],true);
        $reply=$a['reply_email']??null;
        if($reply!==null&&(strlen($reply)>254||$reply!==trim($reply)||preg_match('/[\x00-\x20\x7f]/',$reply)||filter_var($reply,FILTER_VALIDATE_EMAIL)===false))return $this->tool($id,['error'=>'invalid_reply_email'],true);
        if($name==='prepare_correspondence'){
            $preview=['to'=>self::CONTACT,'reply_to'=>$reply,'reply_email_verified'=>false,'subject'=>$subject,'message'=>$message,'status'=>'prepared_not_sent','human_approval_required'=>true,'send_available'=>$reply!==null&&$this->backend->sendEnabled()];
            if($preview['send_available']){$nonce=bin2hex(random_bytes(16));$expires=time()+600;$preview['ticket']=$expires.'.'.$nonce.'.'.$this->backend->sign($this->payload($reply,$subject,$message,$nonce,$expires));$preview['expires_at']=gmdate('c',$expires);}
            else{$preview['note']=$reply===null?'Supply your reply_email to prepare for transmission.':'Correspondence is temporarily unavailable; use the contact page.';}
            return $this->tool($id,$preview);
        }
        if(($a['approved']??false)!==true)return $this->tool($id,['error'=>'human_approval_required'],true);
        if(!$this->backend->sendEnabled())return $this->tool($id,['error'=>'correspondence_unavailable'],true);
        if(!preg_match('/^(\d{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D',$a['ticket'],$parts))return $this->tool($id,['error'=>'invalid_ticket'],true);
        $expires=(int)$parts[1];$nonce=$parts[2];
        if($expires<=time()||$expires>time()+600||!hash_equals($this->backend->sign($this->payload($reply,$subject,$message,$nonce,$expires)),$parts[3]))return $this->tool($id,['error'=>'expired_or_modified_draft'],true);
        $attempt=hash('sha256','v0.2.0|'.$nonce);
        $reservation=$this->backend->reserve($reply,$attempt);
        if($reservation!=='reserved')return $this->tool($id,['status'=>$reservation,'receipt'=>$attempt,'note'=>'No new mail sent. Do not automatically retry an uncertain outcome.'],true);
        try{$accepted=$this->backend->mail($reply,$subject,$message);}catch(\Throwable){$accepted=false;}
        $this->backend->finish($attempt,$accepted);
        return $this->tool($id,['status'=>$accepted?'accepted_by_mail_service':'mail_handoff_failed','receipt'=>$attempt,'delivery_confirmed'=>false],!$accepted);
    }
    private function payload(string $reply,string $subject,string $message,string $nonce,int $expires):string{return json_encode(['v0.2.0',$reply,$subject,$message,$nonce,$expires],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
