<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;

/** HTTPS transport: resolve once, reject non-global addresses, pin the TLS socket, never redirect. */
final class ApiPlaygroundTransport
{
    private const DEADLINE=15.0, MAX_HEADERS=65536;
    public static function publicIp(string $ip):bool {
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE)!==false && (int)explode('.',$ip)[0]<224;
    }
    public static function send(array $request):array {
        $p=parse_url($request['url']);if(!is_array($p)||($p['scheme']??null)!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||!is_string($p['host']??null)||strlen($request['url'])>8192)throw new \InvalidArgumentException('Invalid API proxy target.');
        $origin=ApiPlayground::origin('https://'.$p['host'].(isset($p['port'])?':'.$p['port']:''));
        if($origin!==$request['origin']||!in_array($origin,ApiPlayground::origins('BOOK_PLAYGROUND_PROXY_ORIGINS'),true))throw new \InvalidArgumentException('API proxy server is not enabled.');
        $host=parse_url($origin,PHP_URL_HOST);$port=parse_url($origin,PHP_URL_PORT)??443;
        // The OS resolver is trusted by deployment. Pin the checked IPv4 address for the actual connection.
        $addresses=@gethostbynamel($host);if(!$addresses||count($addresses)>32)throw new \RuntimeException('API DNS lookup failed.');
        foreach($addresses as $ip)if(!self::publicIp($ip))throw new \InvalidArgumentException('Managed proxy requires globally routable IPv4 addresses.');
        return self::sendPinned($request,$host,$port,$addresses[0]);
    }
    /** Only called by send after DNS policy. Private for transport tests using a local TLS fixture. */
    private static function sendPinned(array $request,string $host,int $port,string $ip):array {
        $started=hrtime(true);$deadline=microtime(true)+self::DEADLINE;
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true,'allow_self_signed'=>false,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $socket=@stream_socket_client('tls://'.$ip.':'.$port,$errno,$errstr,5,STREAM_CLIENT_CONNECT,$ctx);
        if(!$socket)throw new \RuntimeException('API TLS connection failed.');
        try{
            $parts=parse_url($request['url']);$target=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');
            if(!str_starts_with($target,'/')||preg_match('/[\x00-\x20\x7f]/',$target))throw new \InvalidArgumentException('Invalid API request target.');
            $body=$request['body'];$method=$request['method'];if(!in_array($method,['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'],true))throw new \InvalidArgumentException('Unsupported API method.');
            $wire=$method.' '.$target." HTTP/1.1\r\nHost: ".$host.($port===443?'':':'.$port)."\r\nConnection: close\r\nAccept-Encoding: identity\r\nUser-Agent: CajeerEngineBook-Playground/0.3.6\r\n";
            foreach($request['headers'] as $name=>$value){if(!preg_match('/\A[A-Za-z0-9!#$%&\x27*+.^_`|~-]{1,80}\z/D',$name)||preg_match('/[^\x20-\x7e]/',$value)||in_array(strtolower($name),['host','connection','content-length','transfer-encoding','accept-encoding','user-agent'],true))throw new \InvalidArgumentException('Invalid outbound API header.');$wire.=$name.': '.$value."\r\n";}
            if($body!==''||in_array($method,['POST','PUT','PATCH'],true))$wire.='Content-Length: '.strlen($body)."\r\n";
            $wire.="\r\n".$body;self::write($socket,$wire,$deadline);
            $totalHeaders=0;$interim=0;
            do{
                $line=self::line($socket,$deadline);$totalHeaders+=strlen($line);
                if(!preg_match('/\AHTTP\/1\.[01] ([1-5][0-9]{2})(?: [^\r\n]*)?\r\n\z/D',$line,$m))throw new \RuntimeException('Invalid API HTTP response.');$status=(int)$m[1];$headers=[];$lookup=[];
                while(($line=self::line($socket,$deadline))!=="\r\n"){
                    $totalHeaders+=strlen($line);if($totalHeaders>self::MAX_HEADERS||count($headers)>=200||!preg_match('/\A([A-Za-z0-9!#$%&\x27*+.^_`|~-]+):[ \t]*([^\r\n]*)\r\n\z/D',$line,$m)||preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$m[2]))throw new \RuntimeException('Invalid or oversized API response headers.');
                    $name=$m[1];$value=trim($m[2]);$lookup[strtolower($name)][]=$value;$headers[]=[$name,self::utf8($value)];
                }
                if($status===101||++$interim>5)throw new \RuntimeException('API protocol upgrade or excessive informational responses.');
            }while($status<200);
            $out='';$truncated=false;$max=ApiPlayground::MAX_RESPONSE;
            if($method!=='HEAD'&&!in_array($status,[204,304],true)){
                $te=$lookup['transfer-encoding']??[];$cl=$lookup['content-length']??[];
                if($te&&$cl)throw new \RuntimeException('Ambiguous API response framing.');
                if($te){if(count($te)!==1||strtolower($te[0])!=='chunked')throw new \RuntimeException('Unsupported API response encoding.');
                    while(true){$line=self::line($socket,$deadline);if(!preg_match('/\A([0-9a-fA-F]{1,8})(?:;[^\r\n]*)?\r\n\z/D',$line,$m))throw new \RuntimeException('Invalid API chunk framing.');$n=(int)hexdec($m[1]);if($n===0)break;$remain=$max-strlen($out);$out.=self::exact($socket,min($remain,$n),$deadline);if($n>$remain){$truncated=true;break;}if(self::exact($socket,2,$deadline)!=="\r\n")throw new \RuntimeException('Invalid API chunk terminator.');}
                }elseif($cl){if(count($cl)!==1||!preg_match('/\A[0-9]{1,16}\z/D',$cl[0]))throw new \RuntimeException('Invalid API content length.');$n=(int)$cl[0];$out=self::exact($socket,min($max,$n),$deadline);$truncated=$n>$max;
                }else{while(!feof($socket)&&strlen($out)<$max+1){self::timeout($socket,$deadline);$chunk=@fread($socket,min(8192,$max+1-strlen($out)));if($chunk===false||($chunk===''&&!feof($socket)))throw new \RuntimeException('API response interrupted or timed out.');$out.=$chunk;}if(strlen($out)>$max){$out=substr($out,0,$max);$truncated=true;}}
            }
            $encoding=preg_match('//u',$out)&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$out)?'utf8':'base64';
            return ['status'=>$status,'duration_ms'=>(int)((hrtime(true)-$started)/1000000),'headers'=>$headers,'body'=>$encoding==='utf8'?$out:base64_encode($out),'encoding'=>$encoding,'truncated'=>$truncated];
        }finally{fclose($socket);}
    }
    private static function utf8(string $s):string{return preg_match('//u',$s)?$s:json_decode(json_encode($s,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);}
    private static function timeout($s,float $deadline):void{$remaining=$deadline-microtime(true);if($remaining<=0)throw new \RuntimeException('API request timed out.');$sec=(int)$remaining;$us=(int)(($remaining-$sec)*1000000);stream_set_timeout($s,$sec,$us);}
    private static function line($s,float $deadline):string{self::timeout($s,$deadline);$line=@fgets($s,8193);if($line===false||!str_ends_with($line,"\r\n"))throw new \RuntimeException('API response interrupted or oversized header.');return $line;}
    private static function exact($s,int $n,float $deadline):string{$out='';while(strlen($out)<$n){self::timeout($s,$deadline);$part=@fread($s,min(8192,$n-strlen($out)));if($part===false||$part==='')throw new \RuntimeException('API response interrupted or timed out.');$out.=$part;}return $out;}
    private static function write($s,string $data,float $deadline):void{$pos=0;while($pos<strlen($data)){self::timeout($s,$deadline);$n=@fwrite($s,substr($data,$pos,8192));if(!$n)throw new \RuntimeException('API request interrupted or timed out.');$pos+=$n;}}
    /** Shared installation rate limit. Store counters only, never target, headers, credentials or response. */
    public static function limit(string $dir,string $user):bool{
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$user))return false;Files::dir($dir);$lock=Files::path($dir,'playground-rate.lock');$h=fopen($lock,'c+');if(!$h)throw new \RuntimeException('Rate limit unavailable.');
        try{if(!flock($h,LOCK_EX))throw new \RuntimeException('Rate limit unavailable.');chmod($lock,0600);$path=Files::path($dir,'playground-rate.json');$now=intdiv(time(),60);$state=is_file($path)?Files::read($path):[];
            if(($state['minute']??null)!==$now)$state=['minute'=>$now,'total'=>0,'users'=>[]];
            if(($state['total']??0)>=60||($state['users'][$user]??0)>=12)return false;$state['total']++;$state['users'][$user]=($state['users'][$user]??0)+1;Files::json($path,$state);return true;
        }finally{fclose($h);}
    }
}
