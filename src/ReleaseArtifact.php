<?php
declare(strict_types=1);
namespace CajeerBook;

/** Export only the public publication represented by an approved and deployed CI bundle. */
final class ReleaseArtifact
{
    public static function create(string $root,BookStore $store,MediaStore $media,array $admins,string $credential,string $visibility,string $id,string $digest,string $target,?string $sourceOrigin=null):array
    {
        $token=CiPublishing::authenticate($store->read(),$credential,$admins);
        if(!$token['publish'])throw new OrganizationAccessException('Release export requires a publish token.');
        $scope=$token['scope'];
        if($visibility!=='public')throw new OrganizationAccessException('CI release export is public-only.');
        return (new StaticExport($root,$store,$media,$sourceOrigin))->create($target,$visibility,$scope,false,
            function(array $db,array $pages)use($admins,$credential,$scope,$id,$digest){
                $token=CiPublishing::authenticate($db,$credential,$admins);
                if(!$token['publish']||$token['scope']!==$scope)throw new OrganizationAccessException('Release export denied.');
                return self::check($db,$pages,$scope,$id,$digest);
            });
    }

    /** Called under the same shared snapshot lock used to render and finalize the ZIP. */
    public static function check(array $db,array $pages,string $scope,string $id,string $digest):array
    {
        $build=CiPublishing::get($db,$id);
        if($build['scope']!==$scope)throw new OrganizationAccessException('Build outside token scope.');
        if($build['status']!=='published'||!hash_equals($build['digest'],$digest))throw new \DomainException('Published build and matching digest required.');
        $expected=[];
        foreach($build['pages'] as $key=>$p){
            // Match BookStore::applyPublication: content publish preserves the existing public tree.
            $publicKey=$p['published']===null?$key:$p['public_key'];
            $snapshot=$p['draft'];$snapshot['media']??=[];
            if($p['published']!==null){
                $snapshot['order']=$p['published']['order'];
                if($publicKey!==$key)$snapshot['link_base']??=$key;
            }
            $expected[$publicKey]=$snapshot+['id'=>$p['page']];
        }
        // A new draft does not invalidate a public artifact. A changed publication, moved public
        // path, additional published page or visibility change does; never export a different release.
        ksort($expected,SORT_STRING);ksort($pages,SORT_STRING);
        if($expected!==$pages)throw new \DomainException('Current public publication differs from this build. Publish a new bundle.');
        foreach($expected as $page)foreach($page['media'] as $mediaId){
            if(!isset($build['media'][$mediaId])||($db['media'][$mediaId]['sha256']??null)!==$build['media'][$mediaId])throw new \DomainException('Release media changed.');
        }
        return ['engine'=>trim((string)file_get_contents(dirname(__DIR__).'/VERSION')),'build'=>$id,'digest'=>$digest,'scope'=>$scope,'commit'=>$build['commit'],'published_at'=>$build['published_at'],'visibility'=>'public','pages'=>count($expected)];
    }
}
