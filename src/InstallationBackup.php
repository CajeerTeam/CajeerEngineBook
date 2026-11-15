<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;

/** Operator-only portable directory bundle. No credentials or full-install exports in HTTP. */
final class InstallationBackup
{
    private const MAX_IDENTITY=4*1024*1024;
    private const MAX_MANIFEST=128*1024;
    private const MAX_TAR=MediaStore::MAX_TOTAL+BookStore::MAX_DB_BYTES+4*1024*1024;
    private static function path(string $path,string $public):string
    {
        $name=basename($path);if($name===''||$name==='.'||$name==='..'||preg_match('/[\x00-\x1f\x7f\\\\]/',$name))throw new \InvalidArgumentException('Invalid destination name');
        $parent=DataDirectory::resolve(dirname($path),$public);
        if(!is_dir($parent))throw new \InvalidArgumentException('Parent directory must exist');
        $result=$parent.'/'.$name;if(is_link($result))throw new \InvalidArgumentException('Symlink destination');
        return DataDirectory::resolve($result,$public);
    }
    private static function separate(string $a,string $b):void
    {
        if($a===$b||str_starts_with($a,$b.'/')||str_starts_with($b,$a.'/'))throw new \InvalidArgumentException('Source and destination must not overlap');
    }
    private static function stage(string $target):string
    {
        if(file_exists($target)||is_link($target))throw new \InvalidArgumentException('Destination must not exist');
        $stage=dirname($target).'/.installation-'.bin2hex(random_bytes(16));
        if(!mkdir($stage,0700))throw new \RuntimeException('Cannot create private staging directory');return $stage;
    }
    private static function clean(string $path):void
    {
        if(!is_dir($path)||is_link($path))return;
        foreach(new \DirectoryIterator($path) as $f){if($f->isDot())continue;$p=$f->getPathname();if($f->isDir()&&!$f->isLink())self::clean($p);else unlink($p);}rmdir($path);
    }
    private static function syncTree(string $dir):void
    {
        foreach(new \DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isLink())throw new \RuntimeException('Unexpected symlink in staging directory');if($f->isDir())self::syncTree($f->getPathname());}
        Files::syncDir($dir);
    }
    private static function publish(string $stage,string $target):void
    {
        self::syncTree($stage);if(file_exists($target)||is_link($target)||!rename($stage,$target))throw new \RuntimeException('Cannot publish installation directory');Files::syncDir(dirname($target));
    }
    private static function entry(string $file):array{return ['size'=>filesize($file),'sha256'=>Files::hash($file)];}
    private static function verifyFile(string $bundle,string $name,array $entry,int $max):string
    {
        $path=Files::path($bundle,$name);
        if(!is_file($path)||!is_int($entry['size']??null)||$entry['size']<1||$entry['size']>$max||!is_string($entry['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$entry['sha256'])||filesize($path)!==$entry['size']||!hash_equals($entry['sha256'],Files::hash($path)))throw new \RuntimeException('Backup integrity mismatch: '.$name);
        return $path;
    }
    /** Explicit recovery only for historical adoption of an untouched demo seed. */
    public static function materializeLegacySeed(string $root,string $public):void
    {
        $root=DataDirectory::resolve($root,$public);if(!is_dir($root))throw new \InvalidArgumentException('Installation does not exist');
        if(PostgresContentStore::marker($root)!==null||getenv('BOOK_PG_DSN'))throw new \RuntimeException('Seed materialization is only for legacy file storage.');
        (new IdentityStore($root))->installationSnapshot(function(array $identity)use($root){
            if(!Workspaces::legacy($identity)||file_exists($root.'/books.json')||is_link($root.'/books.json')||glob(Files::path($root,'media').'/*'))throw new \InvalidArgumentException('Not a seed-only adopted installation');
            (new BookStore($root))->materialize();
        });
    }
    /** Check all registered stores, including archived ones; refuse to repair implicitly. */
    public static function audit(string $root,string $public,bool $portable=false):array
    {
        $root=DataDirectory::resolve($root,$public);if(!is_dir($root))throw new \InvalidArgumentException('Installation does not exist');
        return (new IdentityStore($root,$portable))->installationSnapshot(function(array $identity)use($root,$portable){
            Workspaces::assertRegistryDirectories($root,$identity);if(!$portable)PostgresContentStore::assertRegistry($root,$identity);$out=[];$legacy=new BookStore($root,Workspaces::legacy($identity)===null,$portable);
            $out['legacy']=(new StoreAudit($legacy,new MediaStore($root,$legacy)))->run();
            foreach($identity['workspaces'] as $w){if($w['legacy'])continue;$dir=Workspaces::directory($root,$w);$books=new BookStore($dir,false,$portable);$out[$w['id']]=(new StoreAudit($books,new MediaStore($dir,$books)))->run();}
            foreach($out as $id=>$r)if($r['reference_mismatches']!==0)throw new \RuntimeException('Media index mismatch in '.$id.'; run verify-data.php with explicit repair before backup');
            return ['identity_schema'=>$identity['schema'],'identity_backend'=>!$portable&&getenv('BOOK_PG_DSN')?'postgresql':'file','users'=>count($identity['users']),'organizations'=>count($identity['organizations']),'stores'=>$out];
        });
    }
    public static function create(string $root,string $target,string $public):void
    {
        $root=DataDirectory::resolve($root,$public);$target=self::path($target,$public);self::separate($root,$target);
        if(!is_dir($root))throw new \InvalidArgumentException('Installation does not exist');
        $stage=self::stage($target);
        try{
            (new IdentityStore($root))->installationSnapshot(function(array $identity)use($root,$stage){
                Workspaces::assertRegistryDirectories($root,$identity);PostgresContentStore::assertRegistry($root,$identity);
                Files::write($stage.'/identity.json',json_encode($identity,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");$manifest=['format'=>1,'created'=>gmdate('c'),'files'=>['identity.json'=>self::entry($stage.'/identity.json')]];
                $stores=['legacy.tar'=>[$root,Workspaces::legacy($identity)===null]];
                foreach($identity['workspaces'] as $w)if(!$w['legacy'])$stores[$w['id'].'.tar']=[Workspaces::directory($root,$w),false];
                foreach($stores as $name=>[$dir,$seed]){
                    $books=new BookStore($dir,$seed);$media=new MediaStore($dir,$books);
                    if((new StoreAudit($books,$media))->run()['reference_mismatches']!==0)throw new \RuntimeException('Media index mismatch: '.$name);
                    (new MediaBackup($dir,$books,$media))->create($stage.'/'.$name);$manifest['files'][$name]=self::entry($stage.'/'.$name);
                }
                Files::json($stage.'/manifest.json',$manifest);
            });
            self::publish($stage,$target);
        }finally{self::clean($stage);}
    }
    /** Restore all components into staging; never write into a live installation. */
    public static function restore(string $bundle,string $target,string $public):void
    {
        $bundle=self::path($bundle,$public);$target=self::path($target,$public);self::separate($bundle,$target);
        $manifestFile=Files::path($bundle,'manifest.json');if(!is_file($manifestFile)||filesize($manifestFile)>self::MAX_MANIFEST)throw new \InvalidArgumentException('Invalid installation manifest');
        $m=Files::read($manifestFile);if(($m['format']??null)!==1||!is_array($m['files']??null)||count($m['files'])>Workspaces::LIMIT+2)throw new \InvalidArgumentException('Invalid installation manifest');
        $files=$m['files'];foreach($files as $name=>$entry)if(!is_string($name)||!is_array($entry)||!preg_match('/\A(?:identity\.json|legacy\.tar|[a-f0-9]{32}\.tar)\z/D',$name))throw new \InvalidArgumentException('Invalid bundle member');
        $identity=Files::read(self::verifyFile($bundle,'identity.json',$files['identity.json']??[],self::MAX_IDENTITY));
        $stage=self::stage($target);
        try{
            // Restore validates membership, roles, visibility and registry before using any path.
            $registry=new IdentityStore($stage,true);
            if(($identity['users']??null)===[]){
                if(($identity['schema']??null)!==4||($identity['organizations']??null)!==[]||($identity['workspaces']??null)!==[])throw new \InvalidArgumentException('Invalid uninitialized identity');
                $registry->checkReady();
            }else $registry->restore($identity);
            $workspaces=$registry->operatorWorkspaces();$expected=['identity.json'=>true,'legacy.tar'=>true];foreach($workspaces as $w)if(!$w['legacy'])$expected[$w['id'].'.tar']=true;
            if(array_diff_key($files,$expected)||array_diff_key($expected,$files))throw new \InvalidArgumentException('Bundle does not match workspace registry');
            // The root content TAR restores to its own staging directory, then merges only known content paths.
            $legacy=$stage.'/.legacy';MediaBackup::restore(self::verifyFile($bundle,'legacy.tar',$files['legacy.tar'],self::MAX_TAR),$legacy);
            foreach(['books.json','media'] as $name)if(file_exists($legacy.'/'.$name)&&!rename($legacy.'/'.$name,$stage.'/'.$name))throw new \RuntimeException('Cannot install legacy content');rmdir($legacy);
            foreach($workspaces as $w){if($w['legacy'])continue;$dir=Workspaces::directory($stage,$w);Files::dir(dirname($dir));$name=$w['id'].'.tar';MediaBackup::restore(self::verifyFile($bundle,$name,$files[$name],self::MAX_TAR),$dir);}
            self::audit($stage,$public,true);self::publish($stage,$target);
        }finally{self::clean($stage);}
    }
}
