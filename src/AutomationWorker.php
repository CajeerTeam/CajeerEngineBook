<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;

/** PostgreSQL workers serialize one workspace, not the installation. Network runs outside identity/store locks. */
final class AutomationWorker
{
    public function __construct(private readonly string $root,private readonly IdentityStore $identity,private readonly ?\Closure $gitFactory=null,private readonly ?\Closure $sender=null){}
    private function with(string $org,string $id,callable $fn):mixed{
        return $this->identity->automationWorkspace($org,$id,function($w,$admins)use($fn){$dir=$w?Workspaces::directory($this->root,$w):$this->root;return $fn(new BookStore($dir,$w===null),$dir,$admins);});
    }
    public function tick(array $preferred=[]):int{
        $binding=PostgresContentStore::identityBinding($this->root);
        if($binding!==null)return $this->distributedTick($binding,$preferred);
        Files::dir($this->root);$lock=Files::path($this->root,'automation-worker.lock');$h=fopen($lock,'c+');if(!$h)throw new \RuntimeException('Worker lock unavailable.');
        try{if(!flock($h,LOCK_EX|LOCK_NB))return 0;chmod($lock,0600);$count=0;foreach($this->identity->automationScopes() as $scope){try{if($this->process($scope['org'],$scope['id'],true))$count++;}catch(\Throwable $e){error_log('Automation workspace failed: '.get_class($e));}}return $count;}finally{fclose($h);}
    }
    private function distributedTick(array $binding,array $preferred):int
    {
        $registered=[];foreach($this->identity->automationScopes() as $scope)$registered[$scope['id']===''?'legacy':$scope['org'].'/'.$scope['id']]=$scope;
        // The existing automation_jobs partition is the durable inbox/outbox. No new queue schema.
        $pdo=PostgresContentStore::runtimeConnect();$q=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$binding[0]]);if($q->fetchColumn()!==$binding[1])throw new \RuntimeException('Worker installation binding mismatch.');
        $q=$pdo->prepare("SELECT DISTINCT scope FROM cajeer_book.automation_jobs WHERE installation=? AND ((value->>'state'='queued' AND (value->>'due')::bigint<=?) OR (value->>'state'='running' AND (value->>'lease_until')::bigint<=?)) ORDER BY scope");$now=time();$q->execute([$binding[0],$now,$now]);$due=$q->fetchAll(\PDO::FETCH_COLUMN);
        // A Redis notification is only a hint; untrusted, stale and foreign scopes cannot authorize work.
        $due=array_values(array_unique(array_merge(array_intersect($preferred,$due),$due)));$count=0;
        $lock=$pdo->prepare('SELECT CASE WHEN pg_try_advisory_lock(hashtextextended(CAST(? AS text),0)) THEN 1 ELSE 0 END');
        $unlock=$pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(CAST(? AS text),0))');
        foreach($due as $key){if(!isset($registered[$key]))continue;$scope=$registered[$key];$name='cajeer-book:worker:'.$binding[0].':'.$binding[1].':'.$key;
            $lock->execute([$name]);if((int)$lock->fetchColumn()!==1)continue;
            try{if($this->process($scope['org'],$scope['id'],false))$count++;}
            catch(\Throwable $e){error_log('Automation workspace failed: '.get_class($e));}
            finally{$unlock->execute([$name]);$unlock->fetchColumn();}
        }
        RedisRuntime::forBinding($binding[0],$binding[1])?->heartbeat($count);return $count;
    }
    private function process(string $org,string $id,bool $recover):bool{
        $capture=$this->with($org,$id,function($store,$dir,$admins)use($recover){
            $db=$store->read();if(!isset($db['automation']))return null;
            $now=time();$due=false;foreach($db['automation']['jobs'] as $j){
                // If another worker lost its SQL connection, leave its unexpired lease alone.
                if(!$recover&&$j['state']==='running'&&$j['lease_until']>$now)return null;
                if(($j['state']==='queued'&&$j['due']<=$now)||($j['state']==='running'&&($recover||$j['lease_until']<=$now)))$due=true;
            }if(!$due)return null;
            $job=null;$store->change(function(&$db)use(&$job,$now,$recover){$job=AutomationQueue::claim($db,$now,$recover);});if(!$job)return null;
            $db=$store->read();return ['job'=>$job,'db'=>$db,'dir'=>$dir,'actor'=>$admins[$job['actor']]??null];
        });if(!$capture)return false;$job=$capture['job'];$db=$capture['db'];$actor=$capture['actor'];
        try{
            $c=AutomationQueue::assertLease($db,$job);if(!$actor)throw new OrganizationAccessException('Automation administrator no longer authorized.');
            if($job['kind']==='delivery'){
                $request=AutomationQueue::delivery($c,$job);
                $response=$this->sender?($this->sender)($request):ApiPlaygroundTransport::send($request,'BOOK_WEBHOOK_ORIGINS');$status=$response['status'];
                if($status>=200&&$status<300)$this->finish($org,$id,$job,'succeeded');
                else $this->finish($org,$id,$job,'dead','HTTP '.$status,in_array($status,[408,425,429],true)||$status>=500);
            }else{
                if(($db['github']['id']??null)!==$job['payload']['git']||$c['git']!==$job['payload']['git'])throw new OrganizationAccessException('Git connection replaced.');
                $dir=$capture['dir'];$store=new BookStore($dir,false);$sync=new GitHubSync($store,new MarkdownImport($store,new MediaStore($dir,$store)),$this->gitFactory,$dir.'/github-cache');
                $secret=bin2hex(random_bytes(32));$prepared=$sync->download($db,$secret);
                $this->with($org,$id,function($store,$dir,$admins)use($job,$prepared,$secret){
                    $db=$store->read();AutomationQueue::assertLease($db,$job);$actor=$admins[$job['actor']]??null;if(!$actor)throw new OrganizationAccessException('Automation administrator no longer authorized.');
                    $store->setHistoryActor($actor);$sync=new GitHubSync($store,new MarkdownImport($store,new MediaStore($dir,$store)),$this->gitFactory,$dir.'/github-cache');
                    if(($db['github']['id']??null)!==$job['payload']['git']||$prepared['revision']!==($db['github']['revision']??null))throw new \DomainException('Git connection changed during synchronization.');
                    if($prepared['commit']===$db['github']['commit']){$store->change(fn(&$d)=>AutomationQueue::finish($d,$job,'succeeded','',false,0,$prepared['commit']));return;}
                    $preview=$sync->preview($secret,$prepared);if($preview['conflicts']||$preview['blocked'])throw new \DomainException('Git conflict requires manual Studio resolution.');
                    // Cursor and successful job commit together, eliminating duplicate local imports after a crash.
                    $sync->apply($secret,$preview['token'],$preview['commit'],'safe',$actor['username'],$prepared,[],fn(&$d)=>AutomationQueue::finish($d,$job,'succeeded','',false,0,$prepared['commit']));
                });
            }
        }catch(OrganizationAccessException $e){$this->finish($org,$id,$job,'cancelled','Authorization revoked or connection replaced.');}
        catch(GitHubRemoteException $e){$this->finish($org,$id,$job,'dead','Git provider request failed.',true);}
        catch(\DomainException|\InvalidArgumentException $e){$this->finish($org,$id,$job,'dead','Configuration, conflict or validation requires attention.');}
        catch(\Throwable $e){$this->finish($org,$id,$job,'dead','Network or worker failure.',true);}
        return true;
    }
    private function finish(string $org,string $id,array $job,string $state,string $error='',bool $retry=false):void{
        $this->with($org,$id,function($store,$dir,$admins)use($job,$state,$error,$retry){$store->change(function(&$db)use($job,$state,$error,$retry,$admins){
            $current=$db['automation']['jobs'][$job['id']]??null;if(!$current||$current['state']!=='running'||$current['lease']!==$job['lease'])return;
            if(!isset($admins[$job['actor']])){AutomationQueue::finish($db,$job,'cancelled','Authorization revoked.');return;}
            AutomationQueue::finish($db,$job,$state,$error,$retry);
        });});
    }
}
