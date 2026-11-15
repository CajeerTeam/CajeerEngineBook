<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,CustomDomains,DataDirectory,IdentityStore,MediaStore,StaticExport,Visibility,Workspaces,WorkspaceUrls};
try{
    $root=dirname(__DIR__);$data=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');
    $target=$argv[1]??'';$scope='';$workspace='';$private=false;
    foreach(array_slice($argv,2) as $arg){
        if($arg==='--include-private')$private=true;
        elseif(str_starts_with($arg,'--scope=')&&$scope==='')$scope=substr($arg,8);
        elseif(str_starts_with($arg,'--workspace=')&&$workspace==='')$workspace=substr($arg,12);
        else throw new InvalidArgumentException('Unknown or repeated option: '.$arg);
    }
    if($target===''||!str_ends_with($target,'.zip')||($workspace!==''&&!Workspaces::id($workspace)))throw new InvalidArgumentException('Usage: php bin/static-export.php NEW_FILE.zip [--scope=BOOK/VERSION] [--workspace=ID] [--include-private]');
    $target=DataDirectory::resolve(dirname($target),$root.'/public').'/'.basename($target);
    $identity=new IdentityStore($data);$org='';
    if($workspace!==''){
        foreach($identity->operatorWorkspaces() as $w)if($w['id']===$workspace&&!$w['legacy']){$org=$w['organization'];break;}
        if($org==='')throw new InvalidArgumentException('Unknown or legacy workspace (omit --workspace for legacy)');
    }
    $result=$identity->automationWorkspace($org,$workspace,function($w,$admins)use($data,$root,$target,$scope,$private){
        if($w&&(($w['archived']??false)||!$admins))throw new InvalidArgumentException('Archived workspace cannot be exported');
        $dir=$w?Workspaces::directory($data,$w):$data;$store=new BookStore($dir,$w===null);
        WorkspaceUrls::set($w?Workspaces::prefix($w):'');
        try{return (new StaticExport($root,$store,new MediaStore($dir,$store),CustomDomains::publicOrigin($w)))->create($target,$w?Visibility::workspace($w):'public',$scope,$private);}finally{WorkspaceUrls::set('');}
    });
    if($result===null)throw new RuntimeException('Workspace unavailable');
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
