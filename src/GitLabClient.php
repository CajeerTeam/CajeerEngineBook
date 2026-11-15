<?php
declare(strict_types=1);
namespace CajeerBook;

require_once __DIR__.'/GitHubClient.php';

/** GitLab API v4. Self-managed origins are explicitly trusted by the installation operator. */
final class GitLabClient implements GitProvider
{
    private float $deadline;
    private int $requests=0;
    private readonly string $origin;
    private ?int $projectId=null;
    private ?string $projectPath=null;
    public function __construct(string $origin,private readonly string $token='',private readonly ?\Closure $transport=null){
        $this->origin=self::allowedOrigin($origin);
        if($token!==''&&!preg_match('/\A[A-Za-z0-9_-]{10,512}\z/D',$token))throw new \InvalidArgumentException('Invalid GitLab token format.');
        $this->deadline=microtime(true)+55;
    }
    public static function origin(string $value):string{
        $value=rtrim(trim($value),'/');
        if(!preg_match('~\Ahttps://([a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?)(?::([0-9]{1,5}))?\z~D',$value,$m))throw new \InvalidArgumentException('Use an HTTPS GitLab origin without a path, credentials or query.');
        $host=strtolower($m[1]);foreach(explode('.',$host) as $part)if(!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',$part))throw new \InvalidArgumentException('Invalid GitLab hostname.');
        $port=isset($m[2])?(int)$m[2]:443;if($port<1||$port>65535)throw new \InvalidArgumentException('Invalid GitLab port.');
        return 'https://'.$host.($port===443?'':':'.$port);
    }
    public static function allowedOrigin(string $value):string{
        $origin=self::origin($value);if($origin==='https://gitlab.com')return $origin;
        foreach(explode(',',getenv('BOOK_GITLAB_ORIGINS')?:'') as $allowed){if(trim($allowed)==='')continue;if(self::origin($allowed)===$origin)return $origin;}
        throw new \InvalidArgumentException('This GitLab origin is not enabled by the installation operator.');
    }
    public static function repository(string $repo):string{
        if(strlen($repo)>240||!preg_match('~\A[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)+\z~D',$repo))throw new \InvalidArgumentException('Use GitLab namespace/project (nested groups are supported).');
        foreach(explode('/',$repo) as $part)if(strlen($part)>100||str_ends_with($part,'.')||str_ends_with(strtolower($part),'.git'))throw new \InvalidArgumentException('Invalid GitLab project path.');return $repo;
    }
    /** Resolve the namespace once and bind subsequent requests to the numeric project ID. */
    public function identify(string $repo,?int $expected=null):int{
        $repo=self::repository($repo);$p=$this->request('GET','/projects/'.rawurlencode($repo));$id=$p['id']??null;
        if(!is_int($id)||$id<1||($p['path_with_namespace']??null)!==$repo||($expected!==null&&$id!==$expected))throw new \DomainException('GitLab project identity changed. Verify the project and reconnect deliberately.');
        $this->projectId=$id;$this->projectPath=$repo;return $id;
    }
    private function base(string $repo):string{
        $repo=self::repository($repo);if($this->projectId!==null){if($repo!==$this->projectPath)throw new \DomainException('GitLab client is bound to a different project.');return '/projects/'.$this->projectId;}return '/projects/'.rawurlencode($repo);
    }
    private function request(string $method,string $path,?array $payload=null,int $limit=4194304,bool $missing=false,bool $rawBody=false):array|string|null{
        if(++$this->requests>650||microtime(true)>$this->deadline)throw new GitHubRemoteException('GitLab sync time/request budget exceeded. Reduce the documentation directory.');
        if($this->transport!==null)return ($this->transport)($method,$path,$payload,$missing,$rawBody);
        $headers=($rawBody?"Accept: application/octet-stream\r\n":"Accept: application/json\r\n")."User-Agent: CajeerEngineBook/0.3.3\r\n";
        if($this->token!=='')$headers.='PRIVATE-TOKEN: '.$this->token."\r\n";
        if($payload!==null)$headers.="Content-Type: application/json\r\n";
        $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'timeout'=>min(8,max(0.1,$this->deadline-microtime(true)))],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
        $handle=@fopen($this->origin.'/api/v4'.$path,'rb',false,$ctx);
        if(!$handle)throw new GitHubRemoteException('GitLab connection failed. Check network and TLS configuration.');
        try{
            $meta=stream_get_meta_data($handle);$status=0;foreach($meta['wrapper_data']??[] as $line)if(preg_match('~^HTTP/\S+ (\d{3})~',$line,$m))$status=(int)$m[1];
            if($missing&&$status===404)return null;
            if($status<200||$status>=300)throw new GitHubRemoteException('GitLab returned HTTP '.$status.'. Check project, token permissions, branch and rate limits.');
            $raw='';while(!feof($handle)){if(microtime(true)>$this->deadline)throw new GitHubRemoteException('GitLab request deadline exceeded.');$chunk=fread($handle,min(65536,$limit+1-strlen($raw)));if($chunk===false||($chunk===''&&!feof($handle)))throw new GitHubRemoteException('GitLab response interrupted.');$raw.=$chunk;if(strlen($raw)>$limit)throw new GitHubRemoteException('GitLab response exceeds limit.');}
            if($rawBody)return $raw;
            try{$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new GitHubRemoteException('Invalid GitLab JSON response.');}if(!is_array($v))throw new GitHubRemoteException('Invalid GitLab response.');return $v;
        }finally{fclose($handle);}
    }
    public function head(string $repo,string $branch):string{return GitHubClient::sha($this->request('GET',$this->base($repo).'/repository/branches/'.rawurlencode(GitHubClient::branch($branch)))['commit']['id']??null);}
    public function fetch(string $repo,string $commit,string $root,?string $cacheDir=null):array{
        $base=$this->base($repo).'/repository';GitHubClient::sha($commit);if($root!=='')ImportFiles::path($root);
        $info=$this->request('GET',$base.'/commits/'.$commit);if(($info['id']??null)!==$commit)throw new \DomainException('GitLab returned an unexpected commit.');
        $author=$info['author_name']??'';if(!is_string($author)||strlen($author)>200||!preg_match('//u',$author)||preg_match('/[\x00-\x1f\x7f]/',$author))$author='';
        $prefix=$root===''?'':$root.'/';$entries=[];$seen=[];$ignored=0;
        // Enumerate exact-commit tree; do not follow provider-supplied pagination URLs.
        for($page=1;;$page++){
            if($page>400)throw new \DomainException('GitLab tree exceeds pagination limit.');
            $list=$this->request('GET',$base.'/tree?'.http_build_query(['ref'=>$commit,'recursive'=>'true','per_page'=>100,'page'=>$page],'','&',PHP_QUERY_RFC3986));
            if(!array_is_list($list)||count($list)>100)throw new \DomainException('Invalid GitLab tree page.');
            foreach($list as $e){
                $path=$e['path']??null;if(!is_string($path)||isset($seen[$path])||count($seen)>=40000)throw new \DomainException('Invalid or repeated GitLab tree entry.');$seen[$path]=true;
                if($path===$root&&(($e['type']??'')!=='tree'||($e['mode']??'')!=='040000'))throw new \DomainException('Documentation root is not a directory.');if(!str_starts_with($path,$prefix)||$path===$root)continue;$rel=substr($path,strlen($prefix));if($rel==='')continue;
                if(($e['type']??'')==='tree'&&($e['mode']??'')==='040000')continue;
                if(($e['type']??'')!=='blob'||!in_array($e['mode']??'',['100644','100755'],true))throw new \DomainException('Documentation directory contains a symlink, submodule or special file.');
                if(!preg_match('/\.(?:md|markdown|png|jpe?g|gif|webp|pdf|zip|txt|csv)$/i',$rel)){$ignored++;continue;}
                ImportFiles::path($rel);if(count($entries)>=ImportFiles::MAX_FILES)throw new \DomainException('GitLab documentation exceeds file limit.');$entries[$rel]=['sha'=>GitHubClient::sha($e['id']??null)];
            }
            if(count($list)<100)break;
        }
        return GitBlobImport::stage($entries,$cacheDir,function($sha)use($base){
            return $this->request('GET',$base.'/blobs/'.$sha.'/raw',null,MediaStore::MAX_FILE,false,true);
        })+['ignored'=>$ignored,'tree'=>$commit,'author'=>$author];
    }
    public function propose(string $repo,string $branch,string $commit,array $changes):string{
        if(!$changes||count($changes)>ImportFiles::MAX_FILES)throw new \DomainException('No tracked local changes to propose.');GitHubClient::sha($commit);if(strlen($this->origin.'/'.$repo)>440)throw new \DomainException('GitLab project URL is too long.');
        if($this->head($repo,$branch)!==$commit)throw new \DomainException('GitLab branch advanced. Synchronize before proposing changes.');
        $base=$this->base($repo);$actions=[];$total=0;
        foreach($changes as $path=>$body){ImportFiles::path($path);if(!is_string($body)||strlen($body)>Markdown::MAX_BYTES||($total+=strlen($body))>8*1024*1024)throw new \DomainException('GitLab proposal exceeds size limit.');
            $file=$this->request('GET',$base.'/repository/files/'.rawurlencode($path).'?ref='.$commit,null,1048576,true);
            $action=['action'=>$file===null?'create':'update','file_path'=>$path,'content'=>base64_encode($body),'encoding'=>'base64'];
            if($file!==null){if(($file['file_path']??null)!==$path)throw new \DomainException('GitLab returned unexpected file metadata.');$action['last_commit_id']=GitHubClient::sha($file['last_commit_id']??null);}$actions[]=$action;
        }
        if($this->head($repo,$branch)!==$commit)throw new \DomainException('GitLab branch advanced during proposal. Synchronize and retry.');
        $newBranch='cajeerbook/docs-'.bin2hex(random_bytes(10));
        try{$result=$this->request('POST',$base.'/repository/commits',['branch'=>$newBranch,'start_sha'=>$commit,'commit_message'=>'Update documentation from CajeerEngine Book','actions'=>$actions]);
        GitHubClient::sha($result['id']??null);if(($result['parent_ids']??null)!==[$commit])throw new \DomainException('Unexpected proposal parent.');
            $mr=$this->request('POST',$base.'/merge_requests',['source_branch'=>$newBranch,'target_branch'=>$branch,'title'=>'Update documentation from CajeerEngine Book','description'=>'Proposed documentation changes. Review before merging.','remove_source_branch'=>true]);
            $iid=$mr['iid']??null;if(!is_int($iid)||$iid<1)throw new \DomainException('No merge request identifier.');
        }catch(\DomainException $e){throw new GitHubRemoteException('Branch '.$newBranch.' may have been created, but merge request creation could not be confirmed. Check GitLab before retrying.');}
        return $this->origin.'/'.$repo.'/-/merge_requests/'.$iid;
    }
}
