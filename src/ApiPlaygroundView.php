<?php
declare(strict_types=1);
namespace CajeerBook;

final class ApiPlaygroundView
{
    private static function e(string $s):string{return Markdown::e($s);}
    public static function binding(string $key,array $p,array $d):string{return hash('sha256',OpenApiDocument::json([$key,$p['id'],$p['body'],$d]));}
    public static function markup(string $key,array $p,array $d,?array $ctx,string $role):string{
        $browser=[];$proxy=[];$directOrigins=ApiPlayground::origins();$proxyOrigins=ApiPlayground::origins('BOOK_PLAYGROUND_PROXY_ORIGINS');
        $directCompatible=!array_filter($d['parameters'],fn($p)=>$p['in']==='cookie'&&$p['required'])&&array_filter($d['security'],fn($alt)=>!array_filter($alt,fn($a)=>$a['in']==='cookie'));
        foreach($d['servers'] as $i=>$s){$origin=ApiPlayground::origin($s);if($directCompatible&&in_array($origin,$directOrigins,true))$browser[]=$i;if($ctx&&Permissions::allows($role,'read')&&in_array($origin,$proxyOrigins,true))$proxy[]=$i;}
        $data=['definition'=>$d,'browser'=>$browser,'proxy'=>$proxy,'key'=>$key,'binding'=>self::binding($key,$p,$d),'csrf'=>$proxy?($ctx['session']['csrf']??''):'','endpoint'=>WorkspaceUrls::url('/api/v1/playground')];
        $out='<div class="eyebrow">API REFERENCE / PLAYGROUND</div><h1>API Playground</h1><p><a href="'.self::e(WorkspaceUrls::url('/docs/'.$key)).'">Return to operation</a> · <a href="'.self::e(WorkspaceUrls::url('/sdk?key='.rawurlencode($key))).'">SDK &amp; Code Examples</a></p><h2>'.self::e($d['method'].' '.$d['path']).'</h2><p>Requests use the published operation. API credentials are not stored. Only operator-approved HTTPS servers are enabled.</p>';
        if(!$browser&&!$proxy)return $out.'<p class="notice">No API server is enabled for this operation. Ask the installation operator to configure an allowed origin and compatible transport.</p>';
        return $out.'<form class="playground" data-api-playground="'.self::e(OpenApiDocument::json($data)).'" method="post" action="'.self::e(WorkspaceUrls::url('/playground')).'" autocomplete="off"><noscript>JavaScript is required to send requests.</noscript><fieldset><legend>Request</legend><label>Transport<select data-pg-mode></select></label><p data-pg-mode-note class="muted"></p><label>API server<select data-pg-server></select></label><div data-pg-parameters></div><label>Authentication<select data-pg-security></select></label><div data-pg-auth></div><div data-pg-body hidden><label>Content type<select data-pg-content-type></select></label><label>Request body<textarea data-pg-payload rows="8" spellcheck="false" maxlength="262144" placeholder="Enter JSON, URL-encoded form data or plain text"></textarea></label></div><label class="pg-confirm" data-pg-confirm-wrap hidden><input type="checkbox" data-pg-confirm> I understand this sends a real request that may modify API data.</label><div class="actions"><button type="submit" data-pg-send disabled>Send request</button><button type="button" data-pg-cancel disabled>Cancel</button><button type="button" data-pg-curl>Show cURL (redacted)</button><button type="button" data-pg-clear>Clear credentials</button></div></fieldset><p data-pg-status role="status" aria-live="polite"></p><div data-pg-command-wrap hidden><h3>cURL example</h3><pre data-pg-command></pre><button type="button" data-pg-copy-curl>Copy cURL</button><p>Credentials and header values are redacted; request body is represented by a local file.</p></div><section data-pg-response hidden><h2>Response</h2><p data-pg-result></p><h3>Headers</h3><pre data-pg-headers></pre><h3>Body</h3><pre data-pg-result-body></pre><div class="actions"><button type="button" data-pg-copy>Copy body</button><button type="button" data-pg-download>Download body</button></div></section></form><script src="/assets/api-playground.js" defer></script>';
    }
    /** CSP applies only to the isolated Playground view, not normal Reader or Studio. */
    public static function csp(array $d):string{
        $origins=[];$allowed=ApiPlayground::origins();foreach($d['servers'] as $s){$o=ApiPlayground::origin($s);if(in_array($o,$allowed,true))$origins[$o]=true;}
        return "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'".($origins?' '.implode(' ',array_keys($origins)):'')."; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
    }
}
