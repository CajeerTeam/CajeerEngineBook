<?php
declare(strict_types=1);
namespace CajeerBook;
require_once __DIR__.'/SecretKeys.php';

/** Operator-configured OIDC confidential client. Only a pinned HTTPS issuer is trusted. */
final class Oidc
{
    public const CALLBACK='/auth/oidc/callback';
    public static function required():bool{return getenv('BOOK_OIDC_REQUIRED')==='1';}
    public static function config():?array
    {
        $raw=getenv('BOOK_OIDC_CONFIG');if($raw===false||$raw==='')return null;
        if(strlen($raw)>16384)throw new \RuntimeException('Invalid OIDC configuration.');
        $c=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($c))throw new \RuntimeException('Invalid OIDC configuration.');
        foreach(['issuer','client_id','redirect_uri','organization','label'] as $k)if(!is_string($c[$k]??null)||$c[$k]===''||strlen($c[$k])>512||preg_match('/[\x00-\x20\x7f]/',$k==='label'?str_replace(' ','',$c[$k]):$c[$k]))throw new \RuntimeException('Invalid OIDC configuration.');
        self::url($c['issuer']);self::url($c['redirect_uri']);
        if(str_ends_with($c['issuer'],'/')||parse_url($c['issuer'],PHP_URL_QUERY)!==null||parse_url($c['redirect_uri'],PHP_URL_PATH)!==self::CALLBACK||parse_url($c['redirect_uri'],PHP_URL_QUERY)!==null||!preg_match('/\A[a-f0-9]{32}\z/D',$c['organization']))throw new \RuntimeException('Invalid OIDC issuer, callback or organization.');
        if(!is_array($c['groups']??null)||!$c['groups']||count($c['groups'])>100)throw new \RuntimeException('OIDC group mapping is required.');
        foreach($c['groups'] as $g=>$role)if(!is_string($g)||strlen($g)>256||$g===''||!in_array($role,['viewer','editor'],true))throw new \RuntimeException('Invalid OIDC group mapping.');
        $c['group_claim']??='groups';if(!is_string($c['group_claim'])||!preg_match('/\A[a-zA-Z0-9_.-]{1,64}\z/D',$c['group_claim']))throw new \RuntimeException('Invalid OIDC group claim.');
        $c['auth_method']??='client_secret_basic';if(!in_array($c['auth_method'],['client_secret_basic','client_secret_post'],true))throw new \RuntimeException('Invalid OIDC client authentication.');
        $c['secret']=SecretKeys::value('BOOK_OIDC_CLIENT_SECRET');if(isset($c['backchannel_logout'])&&!is_bool($c['backchannel_logout']))throw new \RuntimeException('Invalid OIDC logout configuration.');if($c['secret']===''||strlen($c['secret'])>4096)throw new \RuntimeException('Missing OIDC client secret.');
        return $c;
    }
    public static function fingerprint(array $c):string{return hash('sha256',json_encode($c,JSON_THROW_ON_ERROR));}
    private static function url(string $url):array
    {
        $p=parse_url($url);if(!is_array($p)||($p['scheme']??'')!=='https'||!isset($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||preg_match('/[\x00-\x20\x7f\\\\]/',$url)||!filter_var($url,FILTER_VALIDATE_URL))throw new \RuntimeException('OIDC requires HTTPS URLs.');return $p;
    }
    private static function endpoint(string $url,string $issuer):string
    {
        $a=self::url($url);$b=self::url($issuer);if(strtolower($a['host'])!==strtolower($b['host'])||($a['port']??443)!==($b['port']??443))throw new \RuntimeException('OIDC endpoint outside configured issuer origin.');return $url;
    }
    private static function request(string $url,?array $form=null,string $authorization=''):array
    {
        self::url($url);$headers=['Accept'=>'application/json'];
        if($form!==null)$headers['Content-Type']='application/x-www-form-urlencoded';
        if($authorization!=='')$headers['Authorization']=$authorization;
        $r=ApiPlaygroundTransport::integration($url,$form===null?'GET':'POST',$headers,$form===null?'':http_build_query($form,'','&',PHP_QUERY_RFC3986),1048576,8,false);
        if($r['status']!==200)throw new \RuntimeException('OIDC provider rejected request.');
        $data=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);if(!is_array($data)||array_is_list($data))throw new \RuntimeException('Invalid OIDC response.');return $data;
    }
    public static function discovery(array $c):array
    {
        $d=self::request($c['issuer'].'/.well-known/openid-configuration');
        if(($d['issuer']??null)!==$c['issuer']||!in_array('RS256',$d['id_token_signing_alg_values_supported']??[],true)||!in_array('code',$d['response_types_supported']??[],true))throw new \RuntimeException('OIDC discovery mismatch.');
        foreach(['authorization_endpoint','token_endpoint','jwks_uri'] as $k){if(!is_string($d[$k]??null))throw new \RuntimeException('Missing OIDC endpoint.');self::endpoint($d[$k],$c['issuer']);}
        if(isset($d['code_challenge_methods_supported'])&&!in_array('S256',$d['code_challenge_methods_supported'],true))throw new \RuntimeException('OIDC provider lacks PKCE S256.');
        if(isset($d['token_endpoint_auth_methods_supported'])&&!in_array($c['auth_method'],$d['token_endpoint_auth_methods_supported'],true))throw new \RuntimeException('OIDC client authentication unsupported.');
        return $d;
    }
    public static function b64(string $bytes):string{return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
    private static function decode(string $s):string
    {
        if($s===''||!preg_match('/\A[A-Za-z0-9_-]+\z/D',$s))throw new \RuntimeException('Invalid OIDC encoding.');$v=base64_decode(strtr($s,'-_','+/'),true);if($v===false||self::b64($v)!==$s)throw new \RuntimeException('Invalid OIDC encoding.');return $v;
    }
    public static function authorization(array $c,array $d,array $flow):string
    {
        return $d['authorization_endpoint'].(str_contains($d['authorization_endpoint'],'?')?'&':'?').http_build_query(['response_type'=>'code','client_id'=>$c['client_id'],'redirect_uri'=>$c['redirect_uri'],'scope'=>'openid profile email','state'=>$flow['state'],'nonce'=>$flow['nonce'],'code_challenge'=>self::b64(hash('sha256',$flow['verifier'],true)),'code_challenge_method'=>'S256','response_mode'=>'query'],'','&',PHP_QUERY_RFC3986);
    }
    public static function exchange(array $c,array $flow,string $code):array
    {
        if($code===''||strlen($code)>4096||preg_match('/[\x00-\x20\x7f]/',$code))throw new \RuntimeException('Invalid OIDC authorization code.');
        $d=self::discovery($c);$form=['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$c['redirect_uri'],'code_verifier'=>$flow['verifier'],'client_id'=>$c['client_id']];$auth='';
        if($c['auth_method']==='client_secret_basic'){$auth='Basic '.base64_encode(urlencode($c['client_id']).':'.urlencode($c['secret']));unset($form['client_id']);}else $form['client_secret']=$c['secret'];
        $r=self::request($d['token_endpoint'],$form,$auth);if(!is_string($r['id_token']??null))throw new \RuntimeException('Missing OIDC ID token.');
        return self::verify($r['id_token'],self::request($d['jwks_uri']),$c,$flow['nonce'],time());
    }
    private static function der(int $tag,string $s):string{$n=strlen($s);$len=$n<128?chr($n):($n<256?"\x81".chr($n):"\x82".pack('n',$n));return chr($tag).$len.$s;}
    private static function integer(string $s):string{$s=ltrim($s,"\0");if($s==='')$s="\0";if(ord($s[0])&128)$s="\0".$s;return self::der(2,$s);}
    private static function key(array $j):\OpenSSLAsymmetricKey
    {
        if(!is_string($j['n']??null)||!is_string($j['e']??null))throw new \RuntimeException('Invalid RSA JWK.');$n=self::decode($j['n']);$e=self::decode($j['e']);if(strlen($n)<256||strlen($n)>1024||strlen($e)>8)throw new \RuntimeException('Invalid RSA key size.');
        $rsa=self::der(48,self::integer($n).self::integer($e));$spki=self::der(48,hex2bin('300d06092a864886f70d0101010500').self::der(3,"\0".$rsa));$key=openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64,"\n")."-----END PUBLIC KEY-----\n");
        if(!$key||openssl_pkey_get_details($key)['bits']<2048)throw new \RuntimeException('Invalid RSA key.');return $key;
    }
    /** Verify signed claims before they reach identity storage. No JWT-supplied URLs or keys. */
    private static function signed(string $jwt,array $jwks,array $c):array
    {
        if(strlen($jwt)>65536||count($parts=explode('.',$jwt))!==3)throw new \RuntimeException('Invalid ID token.');
        [$h,$p,$s]=$parts;$headJson=self::decode($h);$claimsJson=self::decode($p);$head=json_decode($headJson,true,8,JSON_THROW_ON_ERROR);$claims=json_decode($claimsJson,true,16,JSON_THROW_ON_ERROR);
        $headObject=json_decode($headJson,false,8,JSON_THROW_ON_ERROR);$claimsObject=json_decode($claimsJson,false,16,JSON_THROW_ON_ERROR);
        if(!$headObject instanceof \stdClass||!$claimsObject instanceof \stdClass||(!is_string($claimsObject->aud??null)&&!is_array($claimsObject->aud??null)))throw new \RuntimeException('Invalid OIDC JSON types.');
        if(!is_array($head)||!is_array($claims)||($head['alg']??null)!=='RS256'||!is_string($head['kid']??null)||$head['kid']===''||strlen($head['kid'])>256||array_key_exists('crit',$head)||array_key_exists('jku',$head)||array_key_exists('jwk',$head)||array_key_exists('x5u',$head)||array_key_exists('b64',$head))throw new \RuntimeException('Unsupported ID token header.');
        $keys=$jwks['keys']??null;if(!is_array($keys)||!array_is_list($keys)||count($keys)>100)throw new \RuntimeException('Invalid OIDC key set.');
        $matches=[];foreach($keys as $j)if(is_array($j)&&($j['kid']??null)===$head['kid']&&($j['kty']??null)==='RSA'&&(!array_key_exists('use',$j)||$j['use']==='sig')&&(!array_key_exists('alg',$j)||$j['alg']==='RS256')&&(!array_key_exists('key_ops',$j)||(is_array($j['key_ops'])&&array_is_list($j['key_ops'])&&in_array('verify',$j['key_ops'],true))))$matches[]=$j;
        if(count($matches)!==1||openssl_verify($h.'.'.$p,self::decode($s),self::key($matches[0]),OPENSSL_ALGO_SHA256)!==1)throw new \RuntimeException('Invalid ID token signature.');
        $aud=$claims['aud']??null;$aud=is_string($aud)?[$aud]:$aud;
        if(isset($c['group_claim'])&&property_exists($claimsObject,$c['group_claim'])&&!is_array($claimsObject->{$c['group_claim']}))throw new \RuntimeException('Invalid OIDC group claim type.');
        if(($claims['iss']??null)!==$c['issuer']||!is_array($aud)||!array_is_list($aud)||count($aud)>32||count(array_filter($aud,'is_string'))!==count($aud)||!in_array($c['client_id'],$aud,true)||(count($aud)>1&&($claims['azp']??null)!==$c['client_id'])||(array_key_exists('azp',$claims)&&$claims['azp']!==$c['client_id'])||!is_string($claims['sub']??null)||$claims['sub']===''||strlen($claims['sub'])>255||!preg_match('/\A[\x21-\x7e]+\z/D',$claims['sub']))throw new \RuntimeException('Invalid ID token identity claims.');
        return $claims;
    }
    public static function verify(string $jwt,array $jwks,array $c,string $nonce,int $now):array
    {
        $claims=self::signed($jwt,$jwks,$c);
        if(($claims['nonce']??null)!==$nonce||array_key_exists('events',$claims))throw new \RuntimeException('Invalid ID token nonce or type.');
        if(!is_int($claims['exp']??null)||$claims['exp']<=$now||!is_int($claims['iat']??null)||$claims['iat']>$now+60||$claims['iat']<$now-600||$claims['exp']<=$claims['iat']||(array_key_exists('nbf',$claims)&&(!is_int($claims['nbf'])||$claims['nbf']>$now+60)))throw new \RuntimeException('Invalid ID token time claims.');
        return $claims;
    }
    /** Subject-wide OIDC back-channel logout. SID-only tokens are not supported. */
    public static function verifyLogout(string $jwt,array $jwks,array $c,int $now):array
    {
        $claims=self::signed($jwt,$jwks,$c);$event='http://schemas.openid.net/event/backchannel-logout';$object=json_decode(self::decode(explode('.',$jwt)[1]),false,16,JSON_THROW_ON_ERROR);
        if(!(($object->events??null) instanceof \stdClass)||!(($object->events->{$event}??null) instanceof \stdClass))throw new \RuntimeException('Invalid logout event object.');
        if(array_key_exists('nonce',$claims)||!is_array($claims['events']??null)||array_keys($claims['events'])!==[$event]||$claims['events'][$event]!==[]||!is_string($claims['jti']??null)||$claims['jti']===''||strlen($claims['jti'])>255||!is_int($claims['iat']??null)||$claims['iat']>$now||$claims['iat']<$now-300||(array_key_exists('exp',$claims)&&(!is_int($claims['exp'])||$claims['exp']<=$now||$claims['exp']<=$claims['iat']))||(array_key_exists('nbf',$claims)&&(!is_int($claims['nbf'])||$claims['nbf']>$now)))throw new \RuntimeException('Invalid logout token.');
        return $claims;
    }
    public static function logoutClaims(array $c,string $jwt):array
    {
        if(($c['backchannel_logout']??false)!==true||$jwt===''||strlen($jwt)>65536)throw new \RuntimeException('Back-channel logout unavailable.');
        $d=self::discovery($c);return self::verifyLogout($jwt,self::request($d['jwks_uri']),$c,time());
    }
    public static function role(array $c,array $claims):string
    {
        $groups=$claims[$c['group_claim']]??[];if(!is_array($groups)||!array_is_list($groups)||count($groups)>1000)return 'none';$role='none';
        foreach($groups as $g){if(!is_string($g))return 'none';$r=$c['groups'][$g]??'none';if($r==='editor')$role='editor';elseif($r==='viewer'&&$role==='none')$role='viewer';}return $role;
    }
}
