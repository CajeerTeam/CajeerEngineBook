<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/core/Update/OperationFiles.php';
foreach(['WorkspaceUrls','RichBlocks','Markdown','PageTree','BookStore','ImportFiles','MintlifyImport','MintlifyMdx','DocusaurusImport','DocusaurusMdx','GitBookImport','ApiPlayground','OpenApiDocument','OpenApiReference','ReadMeImport','MarkdownImport'] as $class)require_once dirname(__DIR__).'/src/'.$class.'.php';
use CajeerBook\{ReadMeImport,MarkdownImport,RichBlocks};
function chk112(bool $okay,string $description):void{if(!$okay)throw new RuntimeException('FAIL: '.$description);echo "PASS $description\n";}
function denies112(callable $cb,string $description):void{try{$cb();}catch(InvalidArgumentException|DomainException $e){chk112(true,$description);return;}throw new RuntimeException('FAIL: '.$description);}
$dir=sys_get_temp_dir().'/book-readme-112-'.bin2hex(random_bytes(8));mkdir($dir,0700);$files=[];
try{
    $oas=['openapi'=>'3.1.0','info'=>['title'=>'Bird API','version'=>'1.0'],'paths'=>['/birds'=>['get'=>['operationId'=>'listBirds','summary'=>'List birds','responses'=>['200'=>['description'=>'OK']]]]]];
    $source=[
        'readme-export/branches/v1/docs/_order.yaml'=>"- getting-started\n- advanced\n",
        'readme-export/branches/v1/docs/getting-started.md'=>"---\ntitle: Get Started\nexcerpt: Quick tour\n---\n# Start\n\n[Advanced](advanced/index.md)\n![Icon](../images/icon.png)\n<Callout theme=\"warning\">Check your API key</Callout>\n",
        'readme-export/branches/v1/docs/advanced/index.md'=>"---\ntitle: Deep Dive\n---\n# Advanced\n",
        'readme-export/branches/v1/recipes/_order.yaml'=>"- how-to\n",
        'readme-export/branches/v1/recipes/how-to.mdx'=>"---\ntitle: Get birds\n---\n# Recipe\n<Tabs><Tab title=\"cURL\">curl api</Tab></Tabs>\n",
        'readme-export/branches/v1/reference/_order.yaml'=>"- overview\n- list-birds\n",
        'readme-export/branches/v1/reference/list-birds.md'=>"---\napi:\n  file: birds.json\n  operationId: listBirds\n---\n# Birds\nAdditional endpoint notes.\n",
        'readme-export/branches/v1/reference/overview.md'=>"---\ntitle: Overview\n---\n# API Reference\n",
        'readme-export/branches/v1/reference/birds.json'=>json_encode($oas,JSON_THROW_ON_ERROR),
        'readme-export/branches/v1/images/icon.png'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg=='),
        'readme-export/branches/v2/docs/_order.yaml'=>"- getting-started\n",
        'readme-export/branches/v2/docs/getting-started.md'=>"# V2 Quick Start\n",
    ];
    foreach($source as $name=>$data){$file=$dir.'/'.count($files);file_put_contents($file,$data);$files[$name]=$file;}
    $plan=ReadMeImport::prepare($files,'manual','default');
    chk112($plan['readme']&&count($plan['pages'])>=6,'ReadMe branch archive recognized with Guides, Reference and Recipes');
    chk112($plan['pages']['readme-export/branches/v1/docs/getting-started.md']['version']==='v1'&&$plan['pages']['readme-export/branches/v2/docs/getting-started.md']['version']==='v2','multiple ReadMe branches map to separate Book versions');
    chk112($plan['pages']['readme-export/branches/v1/docs/getting-started.md']['route']==='docs/getting-started'||$plan['pages']['readme-export/branches/v1/docs/getting-started.md']['route']==='guides/getting-started','Guides retain stable slugs');
    chk112($plan['nav']['readme-export/branches/v1/docs/getting-started.md']['order']<$plan['nav']['readme-export/branches/v1/docs/advanced/index.md']['order'],'ReadMe _order.yaml and category indexes control navigation');
    chk112(count($plan['virtual'])>=2&&array_filter($plan['pages'],fn($p)=>str_contains($p['route'],'/operations/')),'OpenAPI generates real operation and overview pages');
    chk112(isset($plan['files']['readme-export/branches/v1/images/icon.png']),'included local image is preserved');
    chk112(str_contains($plan['pages']['readme-export/branches/v1/reference/list-birds.md']['api_target']??'','/reference/birds/operations/get-'),'ReadMe operationId linked to generated OpenAPI reference');
    $warnings=[];$converted=ReadMeImport::convert($source['readme-export/branches/v1/docs/getting-started.md'],'getting-started.md',$warnings);
    chk112(str_contains($converted,'book-callout')&&str_contains($converted,'Get Started'),'ReadMe callouts converted and frontmatter retained');
    $converted=ReadMeImport::convert($source['readme-export/branches/v1/recipes/how-to.mdx'],'how-to.mdx',$warnings);
    chk112(str_contains($converted,'book-tabs'),'Recipes MDX tabs converted into Book rich blocks');
    denies112(fn()=>ReadMeImport::convert("# text\nexport const secret=fetch('https://evil.example')",'evil.mdx',$warnings),'dynamic ReadMe MDX refused');
    $bad=$files;$bad['readme-export/branches/v1/docs/_order.yaml']=$dir.'/invalid-order';file_put_contents($bad['readme-export/branches/v1/docs/_order.yaml'],"- missing-page\n");
    denies112(fn()=>ReadMeImport::prepare($bad,'manual','default'),'sidebar references to missing pages rejected');
    $others=['docs.json'=>$dir.'/other'];file_put_contents($others['docs.json'],'{}');chk112(!ReadMeImport::prepare($others,'manual','default')['readme'],'Mintlify imports not taken by ReadMe handler');
    $ignored=[];$rewritten=MarkdownImport::rewrite('[Birds](/reference/birds) ![image](../images/icon.png)','readme-export/branches/v1/docs/getting-started.md',['/reference/birds'=>'manual/v1/reference/birds'],['readme-export/branches/v1/images/icon.png'=>str_repeat('a',32)],$ignored,[]);
    chk112(str_contains($rewritten,'/docs/manual/v1/reference/birds')&&str_contains($rewritten,'/media/'.str_repeat('a',32)),'ReadMe local links and attachments rewrite to Book URLs');
    echo "ReadMe 1.0.12 conversion tests passed.\n";
}finally{foreach($files as $file)@unlink($file);@unlink($dir.'/invalid-order');@unlink($dir.'/other');@rmdir($dir);}
