<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;

/** Cross-node operator test of the actual shared data volume, including POSIX lock semantics. */
final class StorageProbe
{
    public function __construct(private readonly string $root){}
    private function path(string $id,string $suffix):string
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new \InvalidArgumentException('Invalid storage probe ID.');
        return Files::path($this->root,'reliability-probes/'.$id.$suffix);
    }
    private function token(string $id):string{return hash('sha256',"book-storage-probe\n".$id."\n".json_encode(PostgresContentStore::identityBinding($this->root),JSON_THROW_ON_ERROR))."\n";}
    public function create():string
    {
        $id=bin2hex(random_bytes(16));Files::write($this->path($id,'.probe'),$this->token($id));Files::write($this->path($id,'.lock'),'');return $id;
    }
    public function verify(string $id):void
    {
        $p=$this->path($id,'.probe');clearstatcache(true,$p);if(!is_file($p)||filesize($p)!==65||!hash_equals($this->token($id),(string)file_get_contents($p)))throw new \RuntimeException('Shared storage probe missing or installation differs.');
    }
    public function lock(string $id,bool $expectBusy,int $seconds=0):void
    {
        $this->verify($id);if($seconds<0||$seconds>60)throw new \InvalidArgumentException('Lock test must be at most 60 seconds.');
        $p=$this->path($id,'.lock');if(!is_file($p))throw new \RuntimeException('Storage lock probe missing.');$h=fopen($p,'r+');if(!$h)throw new \RuntimeException('Cannot open storage lock probe.');
        try{$acquired=flock($h,LOCK_EX|LOCK_NB,$busy);if($expectBusy){if($acquired||!$busy)throw new \RuntimeException('Cross-node lock exclusion failed.');return;}if(!$acquired)throw new \RuntimeException('Storage lock unavailable.');
            echo "LOCKED\n";flush();if($seconds)sleep($seconds);
        }finally{fclose($h);}
    }
    public function remove(string $id):void{$this->verify($id);Files::remove($this->path($id,'.probe'));Files::remove($this->path($id,'.lock'));}
}
