<?php
declare(strict_types=1);
namespace CajeerBook;

/** CI endpoint accepts only bearer credentials, never browser cookies. */
final class CiController
{
    public static function execute(BookStore $store,MediaStore $media,array $admins,string $credential,array $input):array{
        $str=static function(string $key)use($input):string{$v=$input[$key]??'';if(!is_string($v))throw new \InvalidArgumentException('Invalid field: '.$key);return $v;};
        $db=$store->read();$token=CiPublishing::authenticate($db,$credential,$admins);$actor=$admins[$token['actor']['id']];$scope=$token['scope'];$store->setHistoryActor($actor);$action=$str('action');$result=[];
        // Every mutation repeats token validation under the exclusive BookStore lock.
        $auth=static function(array $db)use($credential,$admins,$scope):array{$t=CiPublishing::authenticate($db,$credential,$admins);if($t['scope']!==$scope)throw new OrganizationAccessException('CI scope changed.');return $t;};
        if($action==='state')return ['scope'=>$scope,'fingerprint'=>CiPublishing::fingerprint($db,$scope),'commit'=>(($db['github']['book']??'').'/'.($db['github']['version']??''))===$scope?($db['github']['commit']??''):'','review_required'=>ReviewWorkflow::required($db)];
        if($action==='status'){$b=CiPublishing::get($db,$str('id'));if($b['scope']!==$scope)throw new OrganizationAccessException('Build outside token scope.');return CiPublishing::summary($b);}
        if($action==='import'){
            $files=$input['files']??null;if(!is_array($files)||!$files||count($files)>ImportFiles::MAX_FILES)throw new \InvalidArgumentException('Provide a map of paths to base64 files.');
            $stage=new ImportFiles();foreach($files as $path=>$encoded){if(!is_string($path)||!is_string($encoded))throw new \InvalidArgumentException('Invalid CI file.');$bytes=base64_decode($encoded,true);if($bytes===false)throw new \InvalidArgumentException('Invalid base64 file.');$stage->addBytes($path,$bytes);}
            [$book,$version]=explode('/',$scope);$import=new MarkdownImport($store,$media);$expected=$str('expected');$allowUpdate=($input['allow_update']??false)===true;
            $result=$import->commit($book,function($db)use($auth,$expected,$scope,$import,$stage,$book,$version,$allowUpdate){$auth($db);if(!hash_equals(CiPublishing::fingerprint($db,$scope),$expected))throw new \DomainException('Scope changed before import.');$plan=$import->plan($db,$stage->files(),$book,$version,'update');if(!$allowUpdate&&array_filter($plan['rows'],fn($row)=>$row['action']==='update'))throw new \DomainException('Import would overwrite existing drafts; explicitly allow updates.');if($plan['warnings'])throw new \DomainException('Unresolved local import links; fix the source.');return $plan;},null,'CI Markdown import');
            return ['scope'=>$scope,'fingerprint'=>CiPublishing::fingerprint($store->read(),$scope),'rows'=>$result['rows'],'media_new'=>$result['media_new']];
        }
        if($action==='build'){$store->change(function(&$db)use($auth,$actor,$scope,$str,$media,&$result){$auth($db);$result=CiPublishing::summary(CiPublishing::build($db,$actor,$scope,$str('request'),$str('commit'),$media,$str('expected')));});return $result;}
        if($action==='publish'){$store->change(function(&$db)use($auth,$actor,$scope,$str,$media,$admins,&$result){$t=$auth($db);if(!$t['publish'])throw new OrganizationAccessException('Token does not allow publication.');$b=CiPublishing::get($db,$str('id'));if($b['scope']!==$scope)throw new OrganizationAccessException('Build outside token scope.');$result=CiPublishing::summary(BookStore::publishBundle($db,$actor,$admins,$str('id'),$str('digest'),$media));},'publish','CI publication '.$str('id'));return $result;}
        throw new \InvalidArgumentException('Unknown CI action.');
    }
}
