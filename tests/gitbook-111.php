<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/core/Update/OperationFiles.php';
foreach(['WorkspaceUrls','RichBlocks','Markdown','PageTree','BookStore','ImportFiles','MintlifyImport','MintlifyMdx','DocusaurusImport','DocusaurusMdx','GitBookImport','MarkdownImport'] as $name)require_once dirname(__DIR__).'/src/'.$name.'.php';
use CajeerBook\{GitBookImport,RichBlocks,MarkdownImport};
function check111(bool $pass,string $what):void{if(!$pass)throw new RuntimeException('FAIL: '.$what);echo "PASS $what\n";}
function deny111(callable $f,string $what):void{try{$f();}catch(InvalidArgumentException|DomainException){check111(true,$what);return;}throw new RuntimeException('Expected rejection: '.$what);}
$base=sys_get_temp_dir().'/book-gitbook-'.bin2hex(random_bytes(8));mkdir($base,0700);$files=[];
try{
    $fixture=[
        'export/guide/.gitbook.yaml'=>"root: ./docs\nstructure:\n  readme: README.md\n  summary: SUMMARY.md\nredirects:\n  /legacy: introduction.md\n",
        'export/guide/docs/SUMMARY.md'=>"# Summary\n\n## Basics\n* [Home](README.md)\n* [Intro](introduction.md)\n  * [Next](nested/install.md)\n",
        'export/guide/docs/README.md'=>"---\ntitle: Start here\ndescription: Entry point\n---\n# Welcome\n[Intro](introduction.md)\n![PNG](../.gitbook/assets/icon.png)\n",
        'export/guide/docs/introduction.md'=>"# Intro\n{% hint style=\"warning\" %}\nBe careful.\n{% endhint %}\n\n{% include \"/.gitbook/includes/note.md\" %}\n",
        'export/guide/docs/nested/install.md'=>"# Install\n{% tabs %}\n{% tab title=\"Linux\" %}\nRun apt\n{% endtab %}\n{% tab title=\"Windows\" %}\nRun exe\n{% endtab %}\n{% endtabs %}\n",
        'export/guide/.gitbook/includes/note.md'=>'Reusable **notice**.',
        'export/guide/.gitbook/assets/icon.png'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg=='),
        'export/reference/.gitbook.yaml'=>"root: ./\n",
        'export/reference/README.md'=>"# Reference\n",
        'export/reference/api.md'=>"# API\n",
    ];
    foreach($fixture as $path=>$source){$file=$base.'/'.count($files);file_put_contents($file,$source);$files[$path]=$file;}
    $p=GitBookImport::prepare($files,'fallback','1.0');
    check111($p['gitbook']&&count($p['pages'])===5&&count($p['books'])===2,'multiple GitBook Spaces become separate Books');
    check111($p['pages']['export/guide/docs/README.md']['route']==='index'&&$p['pages']['export/reference/README.md']['book']==='reference','space routing and homepages');
    check111($p['nav']['export/guide/docs/introduction.md']['order']===20&&$p['nav']['export/guide/docs/nested/install.md']['section']==='Intro','GitBook SUMMARY order and nested sidebar');
    check111(count($p['redirects'])===1&&$p['redirects'][0]['old']==='legacy','GitBook redirects');
    check111(isset($p['files']['export/guide/.gitbook/assets/icon.png'])&&!isset($p['files']['export/guide/docs/SUMMARY.md']),'assets included; controls excluded');
    $warnings=[];$converted=GitBookImport::convert(file_get_contents($files['export/guide/docs/introduction.md']),'export/guide/docs/introduction.md',$p['original'],$warnings);
    check111(str_contains($converted,'book-callout')&&str_contains($converted,'Reusable **notice**'),'hints and reusable content converted');
    $converted=GitBookImport::convert(file_get_contents($files['export/guide/docs/nested/install.md']),'export/guide/docs/nested/install.md',$p['original'],$warnings);
    check111(str_contains($converted,'book-tabs'),'GitBook tabs converted');
    preg_match('/```book-tabs\n(.+?)\n```/s',$converted,$m);check111(RichBlocks::parse('book-tabs',$m[1]??'')!==null,'converted blocks are valid Book interactive blocks');
    deny111(fn()=>GitBookImport::convert('{% if visitor.claims.admin %} secret {% endif %}','example.md',$files,$warnings),'adaptive content cannot leak');
    deny111(fn()=>GitBookImport::convert('{% include "../../etc/passwd" %}','example.md',$files,$warnings),'path traversal rejected');
    $ignored=[];$rewritten=MarkdownImport::rewrite('[Intro](introduction.md) ![PNG](../.gitbook/assets/icon.png)','export/guide/docs/README.md',['export/guide/docs/introduction.md'=>'guide/1.0/introduction'],['export/guide/.gitbook/assets/icon.png'=>str_repeat('a',32)],$ignored,[]);
    check111(str_contains($rewritten,'/docs/guide/1.0/introduction')&&str_contains($rewritten,'/media/'.str_repeat('a',32)),'GitBook links and media rewrite to Book URLs');
    $cross=MarkdownImport::rewrite('[API](https://app.gitbook.com/s/reference/api) ','export/guide/docs/README.md',['https://app.gitbook.com/s/reference/api'=>'reference/1.0/api'],[],$ignored,[]);
    check111(str_contains($cross,'/docs/reference/1.0/api'),'cross-Space GitBook links resolve to imported Book URLs');
    deny111(fn()=>GitBookImport::convert("---\nif: visitor.claims.admin\n---\n# Secret",'example.md',$files,$warnings),'conditional page front matter is not imported');
    echo "GitBook 1.0.11 policy tests passed.\n";
}finally{foreach($files as $file)@unlink($file);@rmdir($base);}
