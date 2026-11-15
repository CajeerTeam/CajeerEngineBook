<?php
declare(strict_types=1);
use CajeerBook\GitHubClient;
function blobSha(string $bytes):string{return sha1('blob '.strlen($bytes)."\0".$bytes);}
final class GitFixture{
    public array $files=[],$requests=[],$extra=[],$blobs=[];public bool $truncated=false,$corrupt=false;public string $head='';public string $tree='';public int $downloads=0;public array $proposal=[];public bool $fail=false;public int $delay=0;public string $signal='';
    public function set(array $files):void{$this->files=$files;$this->head=sha1(json_encode($files,JSON_THROW_ON_ERROR));$this->tree=sha1('tree'.$this->head);$this->blobs=[];foreach($files as $path=>$bytes)$this->blobs[blobSha($bytes)]=$bytes;}
    public function client(string $token=''):GitHubClient{return new GitHubClient($token,function($method,$path,$data){$this->requests[]=[$method,$path,$data];$path=substr($path,strlen('/repos/team/docs'));
        if($method==='GET'){
            if(str_starts_with($path,'/branches/'))return ['commit'=>['sha'=>$this->head]];
            if(str_starts_with($path,'/git/commits/')){if(substr($path,13)!==$this->head)throw new RuntimeException('Incorrect immutable commit');return ['tree'=>['sha'=>$this->tree],'author'=>['name'=>'Git contributor','email'=>'not-stored@example.org']];}
            if(str_starts_with($path,'/git/trees/')){if($this->fail)throw new CajeerBook\GitHubRemoteException('GitHub returned HTTP 503. Check repository, token permissions, branch and rate limits.');if($this->delay>0){if($this->signal!=='')file_put_contents($this->signal,'started');usleep($this->delay*1000);}if($path!=='/git/trees/'.$this->tree.'?recursive=1')throw new RuntimeException('Wrong tree');$tree=[];foreach($this->files as $p=>$body)$tree[]=['path'=>$p,'mode'=>'100644','type'=>'blob','sha'=>blobSha($body),'size'=>strlen($body)];return ['truncated'=>$this->truncated,'tree'=>array_merge($tree,$this->extra)];}
            if(str_starts_with($path,'/git/blobs/')){$this->downloads++;$sha=substr($path,11);return ['content'=>base64_encode($this->corrupt?'bad':$this->blobs[$sha]),'encoding'=>'base64'];}
        }else{
            if($path==='/git/blobs'){$body=base64_decode($data['content'],true);$sha=blobSha($body);$this->proposal[$sha]=$body;return ['sha'=>$sha];}
            if($path==='/git/trees'){$this->proposal['tree']=$data;return ['sha'=>str_repeat('a',40)];}
            if($path==='/git/commits'){$this->proposal['commit']=$data;return ['sha'=>str_repeat('b',40)];}
            if($path==='/git/refs'){$this->proposal['ref']=$data;return ['ref'=>$data['ref']];}
            if($path==='/pulls'){$this->proposal['pr']=$data;return ['number'=>17];}
        }throw new RuntimeException('Unexpected API operation: '.$method.' '.$path);
    });}
}
