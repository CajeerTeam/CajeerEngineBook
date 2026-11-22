<?php
declare(strict_types=1);
namespace CajeerBook;

/** Read-only, owner-facing discovery of signed GitHub releases. Never runs Docker or host commands. */
final class UpdateCenter
{
    private const REPO = 'CajeerTeam/CajeerEngineBook';
    private const PREFIX = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";
    private const MAX_API = 4194304;
    private const MAX_MANIFEST = 1048576;
    private const MAX_ASSETS = 40;
    private const BASE_FILES = ['source.zip','deployment.zip','gate-evidence.json','SHA256SUMS',
        'book-sbom.spdx.json','book-provenance.json','book-build-metadata.json','book-scan.json','book-smoke.log',
        'postgres-pitr-sbom.spdx.json','postgres-pitr-provenance.json','postgres-pitr-build-metadata.json','postgres-pitr-scan.json','postgres-pitr-smoke.log',
        'qualification.json','qualification-lifecycle.json','qualification-soak.json','qualification-candidate.json'];
    private readonly \Closure $http;
    public function __construct(private readonly string $root,private readonly string $dataDir,?\Closure $http=null,private readonly ?string $keyFile=null)
    {
        $this->http=$http??self::fetch(...);
    }
    private static function demand(bool $ok,string $message):void {if(!$ok)throw new \RuntimeException($message);}
    public function installed():string
    {
        $version=trim((string)@file_get_contents($this->root.'/VERSION'));
        self::demand(preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D',$version)===1,'Invalid local VERSION');
        return $version;
    }
    /** Trust anchor must be explicitly deployed by the operator, not supplied by GitHub. */
    private function key():array
    {
        self::demand(function_exists('sodium_crypto_sign_verify_detached'),'PHP sodium extension is required for release signature verification');
        $path=$this->keyFile??(getenv('BOOK_UPDATE_PUBLIC_KEY_FILE')?:'');
        self::demand(is_string($path)&&str_starts_with($path,'/')&&strlen($path)<1024,'Configure an absolute BOOK_UPDATE_PUBLIC_KEY_FILE');
        self::demand(!is_link($path)&&is_file($path)&&is_readable($path),'Pinned release public key is missing or unsafe');
        $body=@file_get_contents($path,false,null,0,2049);
        self::demand(is_string($body)&&strlen($body)<2049,'Invalid release key file');
        self::demand(preg_match('/\A-----BEGIN PUBLIC KEY-----\s+([A-Za-z0-9+\/=\s]+)-----END PUBLIC KEY-----\s*\z/D',$body,$m)===1,'Invalid release key encoding');
        $der=base64_decode(preg_replace('/\s+/','',$m[1]),true);
        self::demand(is_string($der)&&strlen($der)===44&&str_starts_with($der,self::PREFIX),'Only Ed25519 public keys are permitted');
        return [substr($der,12),hash('sha256',$der)];
    }
    /** GitHub sends release asset GETs to a separate signed asset CDN. Follow only pinned GitHub hosts. */
    public static function allowedDownloadUrl(string $url,bool $redirect=false):bool
    {
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||
           (isset($parts['port'])&&$parts['port']!==443)||!is_string($parts['path']??null))return false;
        return in_array($parts['host']??'', $redirect
            ?['github.com','release-assets.githubusercontent.com','objects.githubusercontent.com']
            :['api.github.com','github.com'],true);
    }
    private static function fetch(string $url,int $cap):string
    {
        self::demand(self::allowedDownloadUrl($url),'Untrusted GitHub URL');
        $api=(parse_url($url,PHP_URL_HOST)==='api.github.com');
        for($hop=0;$hop<=5;$hop++){
            $context=stream_context_create(['http'=>['method'=>'GET','timeout'=>15,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true,
                'header'=>"User-Agent: CajeerEngineBook-UpdateCenter/1\r\nAccept: application/vnd.github+json\r\nAccept-Encoding: identity\r\n"],
                'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
            $stream=@fopen($url,'rb',false,$context);
            self::demand(is_resource($stream),'GitHub unavailable');
            try{
                $meta=stream_get_meta_data($stream);$headers=$meta['wrapper_data']??[];
                self::demand(is_array($headers)&&preg_match('~\AHTTP/\S+ ([0-9]{3})(?:\s|$)~',($headers[0]??''),$m)===1,'Invalid GitHub HTTP status');
                $code=(int)$m[1];
                if(in_array($code,[301,302,303,307,308],true)){
                    self::demand(!$api&&$hop<5,'GitHub redirect refused');
                    $locations=[];
                    foreach($headers as $header)if(is_string($header)&&preg_match('/\ALocation:\s*(.+)\s*\z/iD',$header,$l))$locations[]=trim($l[1]);
                    self::demand(count($locations)===1&&self::allowedDownloadUrl($locations[0],true),'Unsafe GitHub asset redirect');
                    $url=$locations[0];continue;
                }
                self::demand($code===200,'GitHub responded with non-200 status');
                foreach($headers as $header)if(is_string($header)&&preg_match('/\AContent-Encoding:\s*(\S+)/i',$header,$v))
                    self::demand(strtolower($v[1])==='identity','Compressed GitHub response refused');
                $bytes=stream_get_contents($stream,$cap+1);
                self::demand(is_string($bytes)&&strlen($bytes)<=$cap,'GitHub response exceeds size limit');
                return $bytes;
            }finally{fclose($stream);}
        }
        throw new \RuntimeException('Too many GitHub redirects');
    }
    private function get(string $url,int $cap):string
    {
        $result=($this->http)($url,$cap);
        self::demand(is_string($result)&&strlen($result)<=$cap,'Invalid GitHub response');
        return $result;
    }
    private static function names():array
    {
        return [...self::BASE_FILES,'release.json','release.json.sig','source.zip.sig','deployment.zip.sig'];
    }
    private static function json(string $body):array
    {
        $obj=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        self::demand(is_array($obj),'Invalid GitHub JSON');
        return $obj;
    }
    private static function validVersion(mixed $v):bool {return is_string($v)&&preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D',$v)===1;}
    private static function assets(array $release):array
    {
        $list=$release['assets']??null;
        self::demand(is_array($list)&&count($list)<=self::MAX_ASSETS,'Missing or excessive GitHub assets');
        $out=[];
        foreach($list as $asset){
            self::demand(is_array($asset),'Invalid release asset');$name=$asset['name']??null;
            self::demand(is_string($name)&&in_array($name,self::names(),true)&&!isset($out[$name])&&($asset['state']??'')==='uploaded','Unexpected release asset');
            $size=$asset['size']??null;
            self::demand(is_int($size)&&$size>0&&$size<=($name==='release.json'?self::MAX_MANIFEST:($name==='release.json.sig'||str_ends_with($name,'.zip.sig')?64:268435456)),'Invalid GitHub asset size');
            $digest=$asset['digest']??null;
            self::demand($digest===null||(is_string($digest)&&preg_match('/\Asha256:[a-f0-9]{64}\z/D',$digest)===1),'Invalid GitHub asset digest');
            $out[$name]=['size'=>$size,'digest'=>$digest];
        }
        $expected=self::names();sort($expected);$actual=array_keys($out);sort($actual);
        self::demand($actual===$expected,'Incomplete GitHub release asset set');
        return $out;
    }
    private function verify(array $release,array $key):array
    {
        $v=substr((string)$release['tag_name'],1);
        $assets=self::assets($release);
        $base='https://github.com/'.self::REPO.'/releases/download/v'.$v.'/';
        $body=$this->get($base.'release.json',self::MAX_MANIFEST);
        $sig=$this->get($base.'release.json.sig',64);
        self::demand(strlen($sig)===64&&strlen($body)===$assets['release.json']['size']&&strlen($sig)===$assets['release.json.sig']['size'],'Signed manifest size mismatch');
        foreach(['release.json'=>$body,'release.json.sig'=>$sig] as $name=>$data)if($assets[$name]['digest']!==null)self::demand(hash_equals(substr($assets[$name]['digest'],7),hash('sha256',$data)),'Manifest asset checksum mismatch');
        self::demand(sodium_crypto_sign_verify_detached($sig,$body,$key[0]),'Ed25519 release signature verification failed');
        $m=self::json($body);
        self::demand(($m['format']??null)===1&&($m['product']??null)==='CajeerEngineBook'&&($m['repository']??null)===self::REPO&&($m['version']??null)===$v&&($m['signing_key_sha256']??null)===$key[1]&&preg_match('/\A[a-f0-9]{40}\z/D',$m['commit']??'')===1,'Signed release identity mismatch');
        self::demand(($m['platform']??null)==='linux/amd64'&&is_int($m['schema_version']??null)&&$m['schema_version']>=1,'Incompatible release platform/schema');
        self::demand($m['schema_version']>=DatabaseMigrations::VERSION,'Release would downgrade the database schema');
        self::demand(is_array($m['images']??null)&&count($m['images'])===2,'Missing signed OCI image references');
        foreach(['book','postgres-pitr'] as $role){
            $reference=$m['images'][$role]??null;
            $registry='ghcr.io/'.strtolower(self::REPO).($role==='book'?'':'-postgres-pitr').'@sha256:';
            self::demand(is_string($reference)&&preg_match('/\A'.preg_quote($registry,'/').'[a-f0-9]{64}\z/D',$reference)===1,'Invalid signed image reference');
        }
        self::demand(is_array($m['artifacts']??null),'Signed release artifacts missing');
        $signedNames=array_keys($m['artifacts']);sort($signedNames);$expected=array_diff(self::names(),['release.json','release.json.sig','source.zip.sig','deployment.zip.sig']);sort($expected);
        self::demand($signedNames===$expected,'Signed release artifact set mismatch');
        foreach($m['artifacts'] as $name=>$item){
            self::demand(is_array($item)&&is_int($item['size']??null)&&$item['size']===$assets[$name]['size']&&preg_match('/\A[a-f0-9]{64}\z/D',$item['sha256']??'')===1,'Signed artifact metadata mismatch');
            if($assets[$name]['digest']!==null)self::demand(hash_equals(substr($assets[$name]['digest'],7),$item['sha256']),'Published artifact digest differs from signed manifest');
        }
        self::demand($assets['source.zip.sig']['size']===64&&$assets['deployment.zip.sig']['size']===64,'Invalid ZIP signatures');
        return ['version'=>$v,'tag'=>'v'.$v,'commit'=>$m['commit'],'schema'=>$m['schema_version'],
            'requires_schema_upgrade'=>$m['schema_version']>DatabaseMigrations::VERSION,
            'manifest_sha256'=>hash('sha256',$body),'published_at'=>$release['published_at'],
            'name'=>substr((string)($release['name']??''),0,160),'changelog'=>substr((string)($release['body']??''),0,5000),
            'url'=>'https://github.com/'.self::REPO.'/releases/tag/v'.$v];
    }
    /** Only manifest and asset metadata are checked. Final ZIP/OCI verification belongs to host update.py. */
    public function check(string $channel='stable'):array
    {
        self::demand(in_array($channel,['stable','beta'],true),'Unknown release channel');
        $key=$this->key();$current=$this->installed();
        $raw=$this->get('https://api.github.com/repos/'.self::REPO.'/releases?per_page=100&page=1',self::MAX_API);
        $releases=self::json($raw);
        self::demand(array_is_list($releases)&&count($releases)<=100,'Invalid GitHub release inventory');
        $incomplete=count($releases)===100;$eligible=[];$rejected=[];$beta=[];
        foreach($releases as $r){
            if(!is_array($r)||($r['draft']??true)!==false||!is_string($r['tag_name']??null)||!str_starts_with($r['tag_name'],'v')||!is_string($r['published_at']??null))continue;
            $v=substr($r['tag_name'],1);
            if(preg_match('/\A\d+\.\d+\.\d+-[0-9A-Za-z.-]+\z/D',$v)===1){
                if($channel==='beta'&&($r['prerelease']??false)===true&&version_compare($v,$current,'>'))$beta[]=['version'=>$v,'name'=>substr((string)($r['name']??''),0,160)];
                continue;
            }
            if(!self::validVersion($v)||($r['prerelease']??false)!==false||version_compare($v,$current,'<='))continue;
            $eligible[]=$r;
        }
        usort($eligible,static fn($a,$b)=>version_compare(substr($b['tag_name'],1),substr($a['tag_name'],1)));
        $candidate=null;
        foreach($eligible as $r){
            try{$candidate=$this->verify($r,$key);break;}
            catch(\Throwable $e){$rejected[]=['tag'=>substr($r['tag_name'],0,60),'reason'=>substr($e->getMessage(),0,160)];if(count($rejected)>=8)break;}
        }
        $status=$candidate!==null?'available':($incomplete?'incomplete':($eligible!==[]?'rejected':($channel==='beta'&&$beta?'unsupported_prerelease':'up_to_date')));
        return ['status'=>$status,'repository'=>self::REPO,'installed'=>$current,'channel'=>$channel,
            'candidate'=>$candidate,'unsupported'=>$beta,'rejected'=>$rejected,'checked_releases'=>count($releases),
            'pagination_truncated'=>$incomplete,'checked_at'=>gmdate('c'),
            'scope'=>'Signed metadata only; host preflight and signed ZIP/OCI verification are still required'];
    }
    /** Record a verified candidate for an operator; never treat it as an installed or downloaded bundle. */
    public function save(array $result,bool $prepared):void
    {
        self::demand(!$prepared||($result['status']??'')==='available','No signed update available to prepare');
        $dir=$this->dataDir.'/update-center';
        if(!is_dir($dir)){self::demand(@mkdir($dir,0700,true)||is_dir($dir),'Cannot create update state directory');}
        self::demand(!is_link($dir)&&is_dir($dir),'Unsafe update state directory');
        $file=$dir.'/'.($prepared?'prepared':'latest').'.json';
        self::demand(!is_link($file),'Unsafe update state file');
        $result['prepared']=$prepared;
        $value=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        self::demand(strlen($value)<20000,'Update state exceeds limit');
        $temp=tempnam($dir,'.update-');self::demand(is_string($temp),'Update state write failed');
        try{
            chmod($temp,0600);self::demand(file_put_contents($temp,$value,LOCK_EX)===strlen($value),'Update state write failed');
            self::demand(@rename($temp,$file),'Update state commit failed');
        }finally{if(is_file($temp))@unlink($temp);}
    }
    public function cached(bool $prepared=false):?array
    {
        $file=$this->dataDir.'/update-center/'.($prepared?'prepared':'latest').'.json';
        if(!is_file($file))return null;
        self::demand(!is_link($file)&&filesize($file)<20000,'Unsafe update state');
        $r=self::json((string)file_get_contents($file));
        self::demand(($r['repository']??null)===self::REPO&&($r['prepared']??null)===$prepared&&self::validVersion($r['installed']??null),'Invalid update state');
        if(isset($r['candidate'])&&$r['candidate']!==null){
            $c=$r['candidate'];
            self::demand(is_array($c)&&self::validVersion($c['version']??null)&&($c['tag']??null)==='v'.$c['version']&&
                is_string($c['manifest_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$c['manifest_sha256'])===1&&
                is_string($c['commit']??null)&&preg_match('/\A[a-f0-9]{40}\z/D',$c['commit'])===1,
                'Invalid stored candidate');
        }
        return $r;
    }
}
