#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{CustomDomains,DataDirectory,IdentityStore};
try{
    $root=dirname(__DIR__);$dir=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');$s=new IdentityStore($dir);
    if(CustomDomains::primary()===null)throw new RuntimeException('Configure BOOK_PRIMARY_HOST.');
    if(($argv[1]??'')==='recheck'){$result=$s->recheckDomains();echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;exit($result['failed']?1:0);}
    if(($argv[1]??'')==='status'){$out=[];foreach($s->operatorWorkspaces() as $w)foreach($w['domains']??[] as $h=>$r)$out[]=['host'=>$h,'workspace'=>$w['id'],'status'=>CustomDomains::status($r),'verified_until'=>$r['verified']?$r['verified']+CustomDomains::LEASE:null];echo json_encode($out,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;exit(0);}
    throw new InvalidArgumentException('Usage: php bin/domains.php status|recheck');
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
