<?php
declare(strict_types=1);
use CajeerBook\GitLabClient;
/** Controlled GitLab API v4 responses, never used by production transport. */
final class GitLabFixture{
    public array $files=[],$requests=[],$extra=[],$blobs=[],$proposal=[];public string $head='',$signal='';public int $downloads=0,$delay=0,$projectId=42;public bool $fail=false,$corrupt=false,$repeat=false,$mrFail=false;
    public function set(array $files):void{$this->files=$files;$this->head=sha1(serialize($files));$this->blobs=[];foreach($files as $body)$this->blobs[sha1('blob '.strlen($body)."\0".$body)]=$body;}
    public function client(string $token='',string $origin='https://gitlab.com',string $repo='group/sub/docs'):GitLabClient{
        return new GitLabClient($origin,$token,function($method,$path,$data,$missing,$raw)use($repo){
            $this->requests[]=[$method,$path,$data];$base='/projects/'.rawurlencode($repo);if($method==='GET'&&$path===$base)return ['id'=>$this->projectId,'path_with_namespace'=>$repo];if(str_starts_with($path,'/projects/'.$this->projectId.'/'))$base='/projects/'.$this->projectId;if(!str_starts_with($path,$base.'/'))throw new RuntimeException('Wrong encoded or numeric project');$path=substr($path,strlen($base));
            if($method==='GET'){
                if(str_starts_with($path,'/repository/branches/'))return ['commit'=>['id'=>$this->head]];
                if($path==='/repository/commits/'.$this->head)return ['id'=>$this->head,'author_name'=>'GitLab contributor'];
                if(str_starts_with($path,'/repository/tree?')){
                    if($this->fail)throw new CajeerBook\GitHubRemoteException('GitLab returned HTTP 503. Check project, token permissions, branch and rate limits.');
                    if($this->delay){if($this->signal!=='')file_put_contents($this->signal,'started');usleep($this->delay*1000);}
                    parse_str(parse_url($path,PHP_URL_QUERY),$q);if($q['ref']!==$this->head||$q['recursive']!=='true'||$q['per_page']!=='100')throw new RuntimeException('Tree must use exact commit and bounded pagination');$tree=[];foreach($this->files as $p=>$body)$tree[]=['path'=>$p,'mode'=>'100644','type'=>'blob','id'=>sha1('blob '.strlen($body)."\0".$body)];$tree=array_merge($tree,$this->extra);return array_slice($tree,$this->repeat?0:((int)$q['page']-1)*100,100);
                }
                if(preg_match('~^/repository/blobs/([a-f0-9]{40})/raw$~',$path,$m)){if(!$raw)throw new RuntimeException('Raw blob request required');$this->downloads++;return $this->corrupt?'tampered':$this->blobs[$m[1]];}
                if(str_starts_with($path,'/repository/files/')){[$p,$q]=explode('?',$path,2);if($q!=='ref='.$this->head||!$missing)throw new RuntimeException('File metadata must use exact commit');$p=rawurldecode(substr($p,strlen('/repository/files/')));return array_key_exists($p,$this->files)?['file_path'=>$p,'last_commit_id'=>$this->head]:null;}
            }elseif($path==='/repository/commits'){$this->proposal['commit']=$data;return ['id'=>str_repeat('a',40),'parent_ids'=>[$this->head]];}
            elseif($path==='/merge_requests'){if($this->mrFail)throw new CajeerBook\GitHubRemoteException('GitLab returned HTTP 503.');$this->proposal['mr']=$data;return ['iid'=>17,'web_url'=>'https://untrusted.example/ignored'];}
            throw new RuntimeException('Unexpected GitLab API operation: '.$method.' '.$path);
        });
    }
}
