<?php
declare(strict_types=1);
namespace CajeerBook;

require_once __DIR__.'/GitHubRemoteException.php';
require_once __DIR__.'/ApiPlayground.php';
require_once __DIR__.'/ApiPlaygroundTransport.php';
require_once __DIR__.'/GitProvider.php';
require_once __DIR__.'/GitBlobImport.php';

/** GitHub REST Git Database API. Only the fixed HTTPS API origin is contacted. */
final class GitHubClient implements GitProvider
{
    private float $deadline;
    private int $requests=0;
    public function __construct(private readonly string $token='', private readonly ?\Closure $transport=null){if($token!==''&&!preg_match('/\A[A-Za-z0-9_-]{10,512}\z/D',$token))throw new \InvalidArgumentException('Invalid GitHub token format.');$this->deadline=(hrtime(true)/1e9)+55;}
    public static function repository(string $s):string{
        if(!preg_match('~\A[A-Za-z0-9-]{1,39}/[A-Za-z0-9_.-]{1,100}\z~D',$s)||in_array(basename($s),['.','..'],true))throw new \InvalidArgumentException('Use GitHub owner/repository.');return $s;
    }
    public static function branch(string $s):string{
        if(!preg_match('~\A[A-Za-z0-9_][A-Za-z0-9_./-]{0,199}\z~D',$s)||str_contains($s,'..')||str_contains($s,'//')||str_ends_with($s,'/')||str_ends_with($s,'.'))throw new \InvalidArgumentException('Invalid Git branch.');foreach(explode('/',$s) as $p)if(str_starts_with($p,'.')||str_ends_with($p,'.lock'))throw new \InvalidArgumentException('Invalid Git branch.');return $s;
    }
    public static function sha(mixed $s):string{if(!is_string($s)||!preg_match('/\A[a-f0-9]{40}\z/D',$s))throw new \DomainException('Invalid Git object identifier.');return $s;}
    private function request(string $method,string $path,?array $payload=null,int $limit=4194304):array{
        if(++$this->requests>650||(hrtime(true)/1e9)>$this->deadline)throw new GitHubRemoteException('GitHub sync time/request budget exceeded. Reduce the documentation directory.');
        if($this->transport!==null)return ($this->transport)($method,$path,$payload);
        $headers=['Accept'=>'application/vnd.github+json','X-GitHub-Api-Version'=>'2022-11-28'];
        if($this->token!=='')$headers['Authorization']='Bearer '.$this->token;
        if($payload!==null)$headers['Content-Type']='application/json';
        try{$r=ApiPlaygroundTransport::integration('https://api.github.com'.$path,$method,$headers,$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR),$limit,min(8,$this->deadline-(hrtime(true)/1e9)),true);}
        catch(\Throwable){throw new GitHubRemoteException('GitHub connection failed. Check network and TLS configuration.');}
        $status=$r['status'];if($status<200||$status>=300)throw new GitHubRemoteException('GitHub returned HTTP '.$status.'. Check repository, token permissions, branch and rate limits.');
        try{$v=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new GitHubRemoteException('Invalid GitHub JSON response.');}if(!is_array($v))throw new GitHubRemoteException('Invalid GitHub response.');return $v;
    }
    private function base(string $repo):string{return '/repos/'.self::repository($repo);}
    public function head(string $repo,string $branch):string{return self::sha($this->request('GET',$this->base($repo).'/branches/'.rawurlencode(self::branch($branch)))['commit']['sha']??null);}
    public function commitTree(string $repo,string $sha):string{return self::sha($this->request('GET',$this->base($repo).'/git/commits/'.self::sha($sha))['tree']['sha']??null);}
    /** Download selected blobs at one immutable commit. Never execute repository files. */
    public function fetch(string $repo,string $commit,string $root,?string $cacheDir=null,bool $specifications=false):array{
        $base=$this->base($repo);$info=$this->request('GET',$base.'/git/commits/'.self::sha($commit));$tree=self::sha($info['tree']['sha']??null);$author=$info['author']['name']??'';if(!is_string($author)||strlen($author)>200||!preg_match('//u',$author)||preg_match('/[\x00-\x1f\x7f]/',$author))$author='';$list=$this->request('GET',$base.'/git/trees/'.$tree.'?recursive=1',null,16777216);
        if(($list['truncated']??true)!==false||!is_array($list['tree']??null)||count($list['tree'])>100000)throw new \DomainException('GitHub tree is truncated or too large.');
        $prefix=$root===''?'':$root.'/';$entries=[];$total=0;$ignored=0;
        foreach($list['tree'] as $e){$path=$e['path']??null;if(!is_string($path)||!str_starts_with($path,$prefix)||$path===$root)continue;$rel=substr($path,strlen($prefix));if($rel==='')continue;
            if(($e['type']??'')==='tree')continue;
            if(!in_array($e['mode']??'', ['100644','100755'],true)||($e['type']??'')!=='blob')throw new \DomainException('Documentation directory contains a symlink, submodule or special file.');
            if(!preg_match($specifications?'/\.(?:json|ya?ml)$/i':'/\.(?:md|markdown|png|jpe?g|gif|webp|pdf|zip|txt|csv)$/i',$rel)){$ignored++;continue;}
            ImportFiles::path($rel);$size=$e['size']??null;if(!is_int($size)||$size<0||$size>MediaStore::MAX_FILE||($total+=$size)>ImportFiles::MAX_TOTAL||count($entries)>=ImportFiles::MAX_FILES||isset($entries[$rel]))throw new \DomainException('GitHub documentation exceeds import limits.');
            $entries[$rel]=['sha'=>self::sha($e['sha']??null),'size'=>$size];if($specifications&&(count($entries)>OpenApiDocument::MAX_FILES||$total>OpenApiDocument::MAX_BYTES))throw new \DomainException('Git specification folder exceeds limits.');
        }
        return GitBlobImport::stage($entries,$cacheDir,function($sha)use($base){
            $blob=$this->request('GET',$base.'/git/blobs/'.$sha,null,30*1024*1024);
            $content=$blob['content']??null;
            return is_string($content)&&($blob['encoding']??'')==='base64'?base64_decode($content,true):false;
        },$specifications?OpenApiDocument::MAX_BYTES:ImportFiles::MAX_TOTAL)+['ignored'=>$ignored,'tree'=>$tree,'author'=>$author];
    }
    /** Create a new branch and PR; never write the configured base branch. */
    public function propose(string $repo,string $branch,string $commit,array $changes):string{
        if(!$changes||count($changes)>200)throw new \DomainException('No tracked local changes to propose.');$base=$this->base($repo);
        if($this->head($repo,$branch)!==$commit)throw new \DomainException('GitHub branch advanced. Synchronize before proposing changes.');
        $tree=$this->commitTree($repo,$commit);$entries=[];
        foreach($changes as $path=>$body){ImportFiles::path($path);$blob=$this->request('POST',$base.'/git/blobs',['content'=>base64_encode($body),'encoding'=>'base64']);$sha=self::sha($blob['sha']??null);if($sha!==sha1('blob '.strlen($body)."\0".$body))throw new \DomainException('GitHub returned unexpected blob hash.');$entries[]=['path'=>$path,'mode'=>'100644','type'=>'blob','sha'=>$sha];}
        $newTree=self::sha($this->request('POST',$base.'/git/trees',['base_tree'=>$tree,'tree'=>$entries])['sha']??null);
        $newCommit=self::sha($this->request('POST',$base.'/git/commits',['message'=>'Update documentation from CajeerEngine Book','tree'=>$newTree,'parents'=>[$commit]])['sha']??null);
        if($this->head($repo,$branch)!==$commit)throw new \DomainException('GitHub branch advanced during proposal. Synchronize and retry.');
        $newBranch='cajeerbook/docs-'.bin2hex(random_bytes(10));$this->request('POST',$base.'/git/refs',['ref'=>'refs/heads/'.$newBranch,'sha'=>$newCommit]);
        try{$pr=$this->request('POST',$base.'/pulls',['title'=>'Update documentation from CajeerEngine Book','head'=>$newBranch,'base'=>$branch,'body'=>'Proposed documentation changes. Review before merging.']);}catch(\DomainException $e){throw new GitHubRemoteException('Branch '.$newBranch.' was created, but pull request creation failed. Open the PR manually in GitHub.');}
        $number=$pr['number']??null;if(!is_int($number)||$number<1)throw new \DomainException('GitHub created a proposal branch but returned no pull request number.');return 'https://github.com/'.$repo.'/pull/'.$number;
    }
}
