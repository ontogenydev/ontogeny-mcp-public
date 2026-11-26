<?php
// Copyright (C) 2026 ONTOGENY
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Plugin Name: ONTOGENY MCP
 * Description: First-party Model Context Protocol interface for public information and correspondence.
 * Version: 0.2.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires PHP: 8.1
 * Author: ONTOGENY
 */
if(!defined('ABSPATH'))exit;
// Optional deployment-only overlay, never included in source distributions.
if(is_file(__DIR__.'/config/local.php'))require_once __DIR__.'/config/local.php';
require_once __DIR__.'/src/Server.php';
require_once __DIR__.'/src/WordPressBackend.php';
use Ontogeny\Mcp\Server;
use Ontogeny\Mcp\WordPressBackend;
register_activation_hook(__FILE__,[WordPressBackend::class,'install']);
register_deactivation_hook(__FILE__,static function(){wp_clear_scheduled_hook('ontogeny_mcp_cleanup');});
add_action('ontogeny_mcp_cleanup',[WordPressBackend::class,'cleanup']);
add_action('admin_menu',static function(){add_options_page('ONTOGENY MCP','ONTOGENY MCP','manage_options','ontogeny-mcp',[WordPressBackend::class,'admin']);});
// Reject route modifiers before WordPress's REST server applies JSONP, method
// override or envelope processing. Keep the callback checks for direct dispatch.
add_action('parse_request',static function($wp){
    if(!WordPressBackend::isMcpRoute($wp->query_vars['rest_route']??null)||!WordPressBackend::hasRestModifiers($_SERVER,$_GET))return;
    status_header(400);
    foreach(['Access-Control-Allow-Origin','Access-Control-Allow-Credentials','Access-Control-Allow-Headers','Access-Control-Allow-Methods','Access-Control-Expose-Headers','Access-Control-Max-Age'] as $h)header_remove($h);
    header('Content-Type: application/json; charset=UTF-8',true);
    header('Cache-Control: no-store, private',true);
    header('X-Content-Type-Options: nosniff',true);
    echo '{"jsonrpc":"2.0","id":null,"error":{"code":-32000,"message":"Request boundary rejected"}}';
    exit;
},9);
add_action('rest_api_init',static function(){
    register_rest_route('ontogeny-mcp/v1','/mcp',[
        'methods'=>'GET, POST, DELETE',
        // The dispatcher deliberately exposes only the six allowlisted tools. Send authorizes independently.
        'permission_callback'=>'__return_true',
        'callback'=>static function(WP_REST_Request $request){
            $origin=WordPressBackend::canonicalOrigin(home_url('/', 'https'));
            $status=WordPressBackend::ingressStatus($_SERVER,$_GET,$request->get_method(),is_ssl(),$origin);
            if($status!==200)return new WP_REST_Response(['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32000,'message'=>'Request boundary rejected']],$status,['Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff']);
            $headers=[];foreach(['origin','content-type','accept','mcp-protocol-version'] as $key)$headers[$key]=$request->get_header($key);
            if(!$headers['mcp-protocol-version'])unset($headers['mcp-protocol-version']);
            $backend=new WordPressBackend();$server=new Server($backend,$origin);
            try{$r=$server->http($request->get_method(),$headers,$request->get_body());}
            catch(Throwable $e){$r=['status'=>500,'body'=>['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32603,'message'=>'Internal server error']],'headers'=>['Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff']];}
            $backend->observe($r);
            return new WP_REST_Response($r['body'],$r['status'],$r['headers']);
        }
    ]);
});
// WP normally serializes null REST results; transport requires an empty 202 notification response.
add_filter('rest_pre_serve_request',static function($served,$result,$request){
    if(!WordPressBackend::isMcpRoute($request->get_route()))return $served;
    // No cross-origin browser access from arbitrary websites; MCP uses direct HTTP.
    if(!headers_sent()){foreach(['Access-Control-Allow-Origin','Access-Control-Allow-Credentials','Access-Control-Allow-Headers','Access-Control-Allow-Methods','Access-Control-Expose-Headers','Access-Control-Max-Age'] as $h)header_remove($h);header('Cache-Control: no-store, private',true);header('X-Content-Type-Options: nosniff',true);}
    return $served||in_array($result->get_status(),[202,405],true);
},99,3);
// Human documentation lives on the dedicated WordPress page, independent of the active theme.
add_filter('template_include',static function($template){return is_page('mcp')?__DIR__.'/docs-template.php':$template;},99);
