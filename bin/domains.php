#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{CustomDomains,DataDirectory,IdentityStore,Observability};
try{
    $root=dirname(__DIR__);$dir=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');$s=new IdentityStore($dir);
    if(CustomDomains::primary()===null)throw new RuntimeException('Configure BOOK_PRIMARY_HOST.');
    if(($argv[1]??'')==='recheck'){$start=hrtime(true);$ok=false;try{$result=$s->recheckDomains();$ok=$result['failed']===0;Observability::domainRecheck($ok,$result['failed']);echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;}catch(Throwable $e){Observability::domainRecheck(false,1);Observability::log('dns.failure',['error_type'=>get_class($e)]);throw $e;}finally{Observability::operation('dns_recheck',$start,$ok);Observability::flush();}exit($ok?0:1);}
    if(($argv[1]??'')==='status'){$out=[];foreach($s->operatorWorkspaces() as $w)foreach($w['domains']??[] as $h=>$r)$out[]=['host'=>$h,'workspace'=>$w['id'],'status'=>CustomDomains::status($r),'verified_until'=>$r['verified']?$r['verified']+CustomDomains::LEASE:null];echo json_encode($out,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;exit(0);}
    throw new InvalidArgumentException('Usage: php bin/domains.php status|recheck');
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
