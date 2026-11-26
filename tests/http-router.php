<?php
// Local loopback only. No write credential or mail transport; never deploy as a web service.
require_once __DIR__.'/../src/Server.php';
use Ontogeny\Mcp\Server;use Ontogeny\Mcp\Backend;
final class LocalBackend implements Backend {
public function sendEnabled():bool{return false;}
public function admit(string $kind):string{return 'allowed';} // Loopback fixture only.
public function sign(string $payload):string{throw new LogicException('Disabled');}
public function reserve(string $credential,string $attempt):string{return 'disabled';}
public function mail(string $reply,string $subject,string $message):bool{return false;}
public function finish(string $attempt,bool $accepted):void{}
}
$r=(new Server(new LocalBackend()))->http($_SERVER['REQUEST_METHOD'],getallheaders(),file_get_contents('php://input'));http_response_code($r['status']);foreach($r['headers'] as $k=>$v)header("$k: $v");if($r['body']!==null){header('Content-Type: application/json');echo json_encode($r['body'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
