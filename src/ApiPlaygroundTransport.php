<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;

/** HTTPS transport: resolve once, reject non-global addresses, pin the TLS socket, never redirect. */
final class ApiPlaygroundTransport
{
    private const DEADLINE=15.0, MAX_HEADERS=65536;
    private static function clock():float{return hrtime(true)/1e9;}
    public static function publicIp(string $ip):bool {
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE)!==false && (int)explode('.',$ip)[0]<224;
    }
    public static function send(array $request,string $allowlist='BOOK_PLAYGROUND_PROXY_ORIGINS'):array {
        $started=self::clock();$p=parse_url($request['url']);if(!is_array($p)||($p['scheme']??null)!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||!is_string($p['host']??null)||strlen($request['url'])>8192)throw new \InvalidArgumentException('Invalid API proxy target.');
        $origin=ApiPlayground::origin('https://'.$p['host'].(isset($p['port'])?':'.$p['port']:''));
        if($origin!==$request['origin']||!in_array($origin,ApiPlayground::origins($allowlist),true))throw new \InvalidArgumentException('API proxy server is not enabled.');
        $host=parse_url($origin,PHP_URL_HOST);$port=parse_url($origin,PHP_URL_PORT)??443;
        // The OS resolver is trusted by deployment. Pin the checked IPv4 address for the actual connection.
        $addresses=@gethostbynamel($host);if(!$addresses||count($addresses)>32)throw new \RuntimeException('API DNS lookup failed.');
        foreach($addresses as $ip)if(!self::publicIp($ip))throw new \InvalidArgumentException('Managed proxy requires globally routable IPv4 addresses.');
         $remaining=self::DEADLINE-(self::clock()-$started);if($remaining<=0)throw new \RuntimeException('API DNS deadline exceeded.');
        return self::sendPinned($request,$host,$port,$addresses[0],ApiPlayground::MAX_RESPONSE,$remaining);
    }
    /** Operator-trusted integrations: exact origin checked by caller; public providers cannot resolve privately.
     *  No HTTP wrapper, redirects, proxy environment, or automatic replay. */
    public static function integration(string $url,string $method,array $headers,string $body,int $limit,float $seconds,bool $publicOnly):array {
        if($seconds<=0||$seconds>60||$limit<1||$limit>32*1024*1024||strlen($url)>8192||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new \InvalidArgumentException('Invalid integration request.');
        $p=parse_url($url);if(!is_array($p)||($p['scheme']??'')!=='https'||!isset($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))throw new \InvalidArgumentException('HTTPS integration required.');
        $host=trim($p['host'],'[]');$port=$p['port']??443;$start=self::clock();
        $addresses=filter_var($host,FILTER_VALIDATE_IP)?[$host]:@gethostbynamel($host);
        if(!$addresses&&!$publicOnly){$records=@dns_get_record($host,DNS_AAAA);$addresses=array_column($records?:[],'ipv6');}
        if(!$addresses||count($addresses)>32)throw new \RuntimeException('Integration DNS lookup failed.');
        foreach($addresses as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP)||($publicOnly&&!self::publicIp($ip)))throw new \RuntimeException('Integration requires a public IPv4 address.');
        $remaining=$seconds-(self::clock()-$start);if($remaining<=0)throw new \RuntimeException('Integration deadline exceeded.');
        $r=self::sendPinned(['url'=>$url,'method'=>$method,'headers'=>$headers,'body'=>$body],$host,$port,$addresses[0],$limit,$remaining,true);
        if($r['truncated'])throw new \RuntimeException('Integration response exceeds limit.');
        return $r;
    }
    /** Only called after DNS policy. Private for transport tests using a local TLS fixture. */
    private static function sendPinned(array $request,string $host,int $port,string $ip,int $max=ApiPlayground::MAX_RESPONSE,float $seconds=self::DEADLINE,bool $raw=false):array {
        $started=hrtime(true);$deadline=self::clock()+$seconds;
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true,'allow_self_signed'=>false,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $address=str_contains($ip,':')?'['.$ip.']':$ip;
        $socket=@stream_socket_client('tcp://'.$address.':'.$port,$errno,$errstr,min(5,$seconds),STREAM_CLIENT_CONNECT,$ctx);
        if(!$socket)throw new \RuntimeException('API TLS connection failed.');
        try{
            if(!stream_set_blocking($socket,false))throw new \RuntimeException('Cannot configure TLS transport.');
            while(true){self::remaining($deadline);$handshake=@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);if($handshake===true)break;if($handshake===false)throw new \RuntimeException('API TLS connection failed.');self::wait($socket,$deadline,true,true);usleep(1000);}
            $buffer='';
            $parts=parse_url($request['url']);$target=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');
            if(!str_starts_with($target,'/')||str_starts_with($target,'//')||preg_match('/[\x00-\x20\x7f]/',$target))throw new \InvalidArgumentException('Invalid API request target.');
            $body=$request['body'];$method=$request['method'];if(!in_array($method,['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'],true))throw new \InvalidArgumentException('Unsupported API method.');
            $wire=$method.' '.$target." HTTP/1.1\r\nHost: ".(str_contains($host,':')?'['.$host.']':$host).($port===443?'':':'.$port)."\r\nConnection: close\r\nAccept-Encoding: identity\r\nUser-Agent: CajeerEngineBook/0.7.0\r\n";
            foreach($request['headers'] as $name=>$value){if(!preg_match('/\A[A-Za-z0-9!#$%&\x27*+.^_`|~-]{1,80}\z/D',$name)||preg_match('/[^\x20-\x7e]/',$value)||in_array(strtolower($name),['host','connection','content-length','transfer-encoding','accept-encoding','user-agent','expect','upgrade','proxy-authorization','proxy-connection','trailer','te'],true))throw new \InvalidArgumentException('Invalid outbound API header.');$wire.=$name.': '.$value."\r\n";}
            if($body!==''||in_array($method,['POST','PUT','PATCH'],true))$wire.='Content-Length: '.strlen($body)."\r\n";
            $wire.="\r\n".$body;self::write($socket,$wire,$deadline);
            $totalHeaders=0;$interim=0;
            do{
                $line=self::line($socket,$deadline,$buffer);$totalHeaders+=strlen($line);
                if(!preg_match('/\AHTTP\/1\.[01] ([1-5][0-9]{2})(?: [^\r\n]*)?\r\n\z/D',$line,$m))throw new \RuntimeException('Invalid API HTTP response.');$status=(int)$m[1];if($totalHeaders>self::MAX_HEADERS)throw new \RuntimeException('Oversized API response headers.');$headers=[];$lookup=[];
                while(($line=self::line($socket,$deadline,$buffer))!=="\r\n"){
                    $totalHeaders+=strlen($line);if($totalHeaders>self::MAX_HEADERS||count($headers)>=200||!preg_match('/\A([A-Za-z0-9!#$%&\x27*+.^_`|~-]+):[ \t]*([^\r\n]*)\r\n\z/D',$line,$m)||preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$m[2]))throw new \RuntimeException('Invalid or oversized API response headers.');
                    $name=$m[1];$value=trim($m[2]);$lookup[strtolower($name)][]=$value;$headers[]=[$name,self::utf8($value)];
                }
                if($status===101||++$interim>5)throw new \RuntimeException('API protocol upgrade or excessive informational responses.');
            }while($status<200);
            $out='';$truncated=false;
            if($raw&&isset($lookup['content-encoding'])&&$lookup['content-encoding']!==['identity'])throw new \RuntimeException('Unsupported integration content encoding.');
            $te=$lookup['transfer-encoding']??[];$cl=$lookup['content-length']??[];
            if(($te&&$cl)||count($cl)>1||($cl&&!preg_match('/\A[0-9]{1,16}\z/D',$cl[0]))||($te&&(count($te)!==1||strtolower($te[0])!=='chunked')))throw new \RuntimeException('Ambiguous or invalid API response framing.');
            if($method!=='HEAD'&&!in_array($status,[204,304],true)){
                if($te){if(count($te)!==1||strtolower($te[0])!=='chunked')throw new \RuntimeException('Unsupported API response encoding.');
                    while(true){$line=self::line($socket,$deadline,$buffer);if(!preg_match('/\A([0-9a-fA-F]{1,8})(?:;[^\r\n]*)?\r\n\z/D',$line,$m))throw new \RuntimeException('Invalid API chunk framing.');$n=(int)hexdec($m[1]);if($n===0){
                        // The terminal chunk is not complete until its trailer section ends.
                        $trailers=0;while(($line=self::line($socket,$deadline,$buffer))!=="\r\n"){
                            $totalHeaders+=strlen($line);if(++$trailers>200||$totalHeaders>self::MAX_HEADERS||!preg_match('/\A([A-Za-z0-9!#$%&\x27*+.^_`|~-]+):[ \t]*([^\r\n]*)\r\n\z/D',$line,$tr)||preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$tr[2])||in_array(strtolower($tr[1]),['content-length','transfer-encoding','host','authorization','content-encoding'],true))throw new \RuntimeException('Invalid API response trailer.');
                        }break;
                    }$remain=$max-strlen($out);$out.=self::exact($socket,min($remain,$n),$deadline,$buffer);if($n>$remain){$truncated=true;break;}if(self::exact($socket,2,$deadline,$buffer)!=="\r\n")throw new \RuntimeException('Invalid API chunk terminator.');}
                }elseif($cl){if(count($cl)!==1||!preg_match('/\A[0-9]{1,16}\z/D',$cl[0]))throw new \RuntimeException('Invalid API content length.');$n=(int)$cl[0];$out=self::exact($socket,min($max,$n),$deadline,$buffer);$truncated=$n>$max;
                }else{while(strlen($out)<$max+1){$chunk=self::read($socket,min(8192,$max+1-strlen($out)),$deadline,$buffer);if($chunk==='')break;$out.=$chunk;}if(strlen($out)>$max){$out=substr($out,0,$max);$truncated=true;}}
            }
            if($raw)return ['status'=>$status,'body'=>$out,'truncated'=>$truncated];
            $encoding=preg_match('//u',$out)&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$out)?'utf8':'base64';
            return ['status'=>$status,'duration_ms'=>(int)((hrtime(true)-$started)/1000000),'headers'=>$headers,'body'=>$encoding==='utf8'?$out:base64_encode($out),'encoding'=>$encoding,'truncated'=>$truncated];
        }finally{fclose($socket);}
    }
    private static function utf8(string $s):string{return preg_match('//u',$s)?$s:json_decode(json_encode($s,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);}
    private static function remaining(float $deadline):float{$remaining=$deadline-self::clock();if($remaining<=0)throw new \RuntimeException('API request timed out.');return $remaining;}
    private static function wait($s,float $deadline,bool $read,bool $write):void{
        $left=self::remaining($deadline);$r=$read?[$s]:[];$w=$write?[$s]:[];$e=[];
        $n=@stream_select($r,$w,$e,(int)$left,(int)(($left-(int)$left)*1000000));
        if(!$n)throw new \RuntimeException('API request interrupted or timed out.');
    }
    /** Nonblocking reads share one monotonic deadline, including fragmented header lines. */
    private static function read($s,int $n,float $deadline,string &$buffer):string{
        if($n===0)return '';self::remaining($deadline);
        if($buffer!==''){$out=substr($buffer,0,$n);$buffer=substr($buffer,strlen($out));return $out;}
        while(true){self::remaining($deadline);$part=@fread($s,$n);if($part===false)throw new \RuntimeException('API response interrupted.');if($part!==''||feof($s))return $part;self::wait($s,$deadline,true,false);}
    }
    private static function line($s,float $deadline,string &$buffer):string{
        while(true){self::remaining($deadline);$pos=strpos($buffer,"\n");if($pos!==false){$line=substr($buffer,0,$pos+1);$buffer=substr($buffer,$pos+1);if(strlen($line)>8192||!str_ends_with($line,"\r\n"))throw new \RuntimeException('Invalid API response line.');return $line;}
            if(strlen($buffer)>=8192)throw new \RuntimeException('Oversized API response line.');$empty='';$chunk=self::read($s,8192-strlen($buffer),$deadline,$empty);if($chunk==='')throw new \RuntimeException('API response interrupted.');$buffer.=$chunk;
        }
    }
    private static function exact($s,int $n,float $deadline,string &$buffer):string{$out='';while(strlen($out)<$n){$part=self::read($s,min(8192,$n-strlen($out)),$deadline,$buffer);if($part==='')throw new \RuntimeException('API response interrupted.');$out.=$part;}return $out;}
    private static function write($s,string $data,float $deadline):void{$pos=0;while($pos<strlen($data)){self::remaining($deadline);$n=@fwrite($s,substr($data,$pos,8192));if($n===false||feof($s))throw new \RuntimeException('API request interrupted.');if($n===0){self::wait($s,$deadline,false,true);continue;}$pos+=$n;}}
    /** Shared installation rate limit. Store counters only, never target, headers, credentials or response. */
    public static function limit(string $dir,string $user):bool{
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$user))return false;Files::dir($dir);$lock=Files::path($dir,'playground-rate.lock');$h=fopen($lock,'c+');if(!$h)throw new \RuntimeException('Rate limit unavailable.');
        try{if(!flock($h,LOCK_EX))throw new \RuntimeException('Rate limit unavailable.');chmod($lock,0600);$path=Files::path($dir,'playground-rate.json');$now=intdiv(time(),60);$state=is_file($path)?Files::read($path):[];
            if(($state['minute']??null)!==$now)$state=['minute'=>$now,'total'=>0,'users'=>[]];
            if(($state['total']??0)>=60||($state['users'][$user]??0)>=12)return false;$state['total']++;$state['users'][$user]=($state['users'][$user]??0)+1;Files::json($path,$state);return true;
        }finally{fclose($h);}
    }
}
