#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Read-only GitBook API exporter. Upload the resulting ZIP in Studio → Import. */
require dirname(__DIR__).'/core/Update/OperationFiles.php';
foreach(['ImportFiles','MediaStore','GitBookApiClient'] as $class)require_once dirname(__DIR__).'/src/'.$class.'.php';
use CajeerBook\GitBookApiClient;
if(PHP_SAPI!=='cli')exit(1);
try{
    $args=getopt('',['organization::','spaces::','output:']);
    $token=getenv('GITBOOK_API_TOKEN');if(!is_string($token)||$token==='')throw new InvalidArgumentException('Set GITBOOK_API_TOKEN in the environment');
    $output=$args['output']??null;if(!is_string($output)||!str_ends_with(strtolower($output),'.zip'))throw new InvalidArgumentException('Specify --output=/secure/path/gitbook-export.zip');
    if(!class_exists(ZipArchive::class))throw new RuntimeException('The PHP ZIP extension is required');
    $client=new GitBookApiClient($token);
    if(isset($args['spaces'])){
        $spaces=$args['spaces'];if(!is_string($spaces)||$spaces==='')throw new InvalidArgumentException('Specify a comma-separated --spaces=id,id list');
        $ids=explode(',',$spaces);if(count($ids)!==count(array_unique($ids)))throw new InvalidArgumentException('Duplicate space identifier');
        $choices=array_fill_keys($ids,'');
    }elseif(isset($args['organization'])){
        $org=$args['organization'];if(!is_string($org)||$org==='')throw new InvalidArgumentException('Specify --organization=ID');
        $choices=$client->spaces($org);
    }else throw new InvalidArgumentException('Specify --spaces=id,id or --organization=ID');
    $bundle=$client->export($choices);$tmp=$output.'.tmp-'.bin2hex(random_bytes(8));
    try{
        $zip=new ZipArchive();if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create export ZIP');
        try{foreach($bundle->files() as $path=>$file)if(!$zip->addFile($file,$path))throw new RuntimeException('Cannot append GitBook export file');}finally{$zip->close();}
        if(!is_file($tmp)||filesize($tmp)>CajeerBook\ImportFiles::MAX_ARCHIVE)throw new DomainException('GitBook ZIP exceeds 20 MiB; export fewer spaces');
        if(is_file($output)||is_link($output))throw new DomainException('Output already exists; refusing to overwrite');
        if(!rename($tmp,$output))throw new RuntimeException('Cannot publish GitBook ZIP');
    }finally{if(is_file($tmp))@unlink($tmp);}
    echo 'Saved '.count($bundle->files()).' GitBook content files in '.$output.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'GitBook export error: '.$e->getMessage().PHP_EOL);exit(1);}
