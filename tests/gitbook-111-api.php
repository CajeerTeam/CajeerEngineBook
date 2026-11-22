<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/core/Update/OperationFiles.php';
foreach(['ImportFiles','MediaStore','GitBookApiClient'] as $name)require_once dirname(__DIR__).'/src/'.$name.'.php';
use CajeerBook\GitBookApiClient;
$requests=[];
$transport=static function(string $url,string $token)use(&$requests):string{
    if($token!=='test123456')throw new RuntimeException('Missing token');$requests[]=$url;
    $path=(string)parse_url($url,PHP_URL_PATH);
    $response=match($path){
        '/v1/orgs/sample/spaces'=>['items'=>[['id'=>'space-one','title'=>'Main docs']], 'next'=>null],
        '/v1/spaces/space-one/content/pages'=>['pages'=>[['id'=>'p1','title'=>'Overview','slug'=>'index','type'=>'document','pages'=>[['id'=>'p2','title'=>'Setup','slug'=>'setup','type'=>'document']]]]],
        '/v1/spaces/space-one/content/page/p1'=>['id'=>'p1','markdown'=>'# Overview'],
        '/v1/spaces/space-one/content/page/p2'=>['id'=>'p2','markdown'=>'# Setup'],
        '/v1/spaces/space-one/content/files'=>['items'=>[],'next'=>null],
        default=>throw new RuntimeException('Unexpected URL '.$url),
    };
    return json_encode($response,JSON_THROW_ON_ERROR);
};
$client=new GitBookApiClient('test123456',$transport);
$spaces=$client->spaces('sample');if($spaces!==['space-one'=>'Main docs'])throw new RuntimeException('Space listing failed');
$stage=$client->export($spaces);$files=$stage->files();
foreach(['space-one/.gitbook.yaml','space-one/SUMMARY.md','space-one/README.md','space-one/setup.md'] as $key)if(!isset($files[$key]))throw new RuntimeException('API content missing: '.$key);
if(trim(file_get_contents($files['space-one/README.md']))!=='# Overview')throw new RuntimeException('API Markdown mismatch');
if(!str_contains(file_get_contents($files['space-one/SUMMARY.md']),'(setup.md)'))throw new RuntimeException('API sidebar mismatch');
if(count($requests)!==5)throw new RuntimeException('Unexpected number of requests');
echo "GitBook 1.0.11 API export integration passed.\n";
