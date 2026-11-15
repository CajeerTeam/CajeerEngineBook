<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;

/** Operator-configured remote repository. AWS CLI handles SigV4, multipart and credentials. */
final class RecoveryRemote
{
    private string $directory='';private string $s3='';private string $endpoint='';
    public function __construct(string $root,string $spool,string $public){
        $dir=getenv('BOOK_BACKUP_REMOTE_DIR')?:'';$s3=getenv('BOOK_BACKUP_S3_URI')?:'';
        if($dir!==''&&$s3!=='')throw new \InvalidArgumentException('Select one backup remote.');
        if($dir!==''){
            $this->directory=DataDirectory::resolve($dir,$public);self::separate($root,$this->directory);self::separate($spool,$this->directory);
            if(!is_dir($this->directory)||!is_writable($this->directory))throw new \RuntimeException('Remote backup directory unavailable.');
        }
        if($s3!==''){
            if(!preg_match('~\As3://[a-z0-9][a-z0-9.-]{1,61}[a-z0-9](?:/[a-zA-Z0-9][a-zA-Z0-9_-]{0,63})*/?\z~D',$s3))throw new \InvalidArgumentException('Invalid S3 backup URI.');
            $this->s3=rtrim($s3,'/');$this->endpoint=getenv('BOOK_BACKUP_S3_ENDPOINT')?:'';
            if($this->endpoint!==''&&(!preg_match('~\Ahttps://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z~D',$this->endpoint)||!filter_var($this->endpoint,FILTER_VALIDATE_URL)))throw new \InvalidArgumentException('S3 endpoint must be an HTTPS origin.');
        }
    }
    public static function separate(string $a,string $b):void{if($a===$b||str_starts_with($a,$b.'/')||str_starts_with($b,$a.'/'))throw new \InvalidArgumentException('Recovery directories must not overlap.');}
    public function mode():string{return $this->s3!==''?'s3':($this->directory!==''?'filesystem':'local');}
    public function identity():string{return hash('sha256',$this->mode()."\n".$this->directory."\n".$this->s3."\n".$this->endpoint);}
    private static function name(string $id):string{if(!preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{32}\z/D',$id))throw new \InvalidArgumentException('Invalid recovery ID.');return $id.'.cbk';}
    private function aws(string $from,string $to):void{
        $cmd=['aws','s3','cp',$from,$to,'--only-show-errors','--no-progress','--cli-connect-timeout','10','--cli-read-timeout','120'];
        if($this->endpoint!=='')array_push($cmd,'--endpoint-url',$this->endpoint);
        $env=getenv();$env['AWS_PAGER']='';$env['AWS_MAX_ATTEMPTS']='5';$env['AWS_RETRY_MODE']='standard';
        $p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,$env);
        if(!is_resource($p))throw new \RuntimeException('Cannot start S3 transfer.');
        $deadline=time()+21600;$exit=-1;
        try{do{$status=proc_get_status($p);if(!$status['running']){$exit=$status['exitcode'];break;}if(time()>$deadline){proc_terminate($p,9);throw new \RuntimeException('S3 transfer timeout.');}usleep(200000);}while(true);}finally{$closed=proc_close($p);}
        if(($exit===-1?$closed:$exit)!==0)throw new \RuntimeException('S3 transfer failed; check credentials, network and repository policy.');
    }
    public function upload(string $id,string $source):void{
        $name=self::name($id);
        if($this->directory!==''){
            if(!is_dir($this->directory)||is_link($this->directory))throw new \RuntimeException('Remote backup directory unavailable.');
            $target=Files::path($this->directory,$name);if(is_file($target)){if(!hash_equals(Files::hash($source),Files::hash($target)))throw new \RuntimeException('Remote object collision.');return;}
            $tmp=$this->directory.'/.upload-'.bin2hex(random_bytes(16));try{Files::copy($source,$tmp);if(!link($tmp,$target))throw new \RuntimeException('Cannot publish remote backup.');Files::syncDir($this->directory);}finally{if(is_file($tmp))unlink($tmp);}return;
        }
        if($this->s3!=='')$this->aws($source,$this->s3.'/'.$name);
    }
    public function download(string $id,string $target):void{
        $name=self::name($id);if(file_exists($target)||is_link($target))throw new \InvalidArgumentException('Download target exists.');
        if($this->directory!==''){if(!is_dir($this->directory)||is_link($this->directory))throw new \RuntimeException('Remote backup directory unavailable.');Files::copy(Files::path($this->directory,$name),$target);}
        elseif($this->s3!==''){$this->aws($this->s3.'/'.$name,$target);if(!is_file($target)||is_link($target)||!chmod($target,0600))throw new \RuntimeException('Remote backup missing.');}
        else throw new \RuntimeException('Remote backup storage is not configured.');
    }
}
