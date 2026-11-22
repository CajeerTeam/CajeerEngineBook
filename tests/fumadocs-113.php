<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Update/OperationFiles.php';
foreach(['WorkspaceUrls','Locale','RichBlocks','Markdown','PageTree','BookStore','ImportFiles','MintlifyMdx','FumadocsMdx','FumadocsImport'] as $class)require_once dirname(__DIR__).'/src/'.$class.'.php';
use CajeerBook\{FumadocsImport,FumadocsMdx};
function check113(bool $okay,string $reason):void{if(!$okay)throw new RuntimeException($reason);echo "PASS: $reason\n";}
function reject113(callable $f,string $reason):void{try{$f();}catch(InvalidArgumentException|DomainException $e){echo "PASS: $reason\n";return;}throw new RuntimeException('Should reject: '.$reason);}
$root=sys_get_temp_dir().'/book-fuma-113-'.bin2hex(random_bytes(8));mkdir($root,0700);$files=[];
try{
    $fixture=[
        'fumadocs-repo/source.config.ts'=>'export default {}',
        'fumadocs-repo/content/docs/meta.json'=>json_encode(['title'=>'Docs','pages'=>['index','---Reference---','v1','v2','...']],JSON_THROW_ON_ERROR),
        'fumadocs-repo/content/docs/index.mdx'=>"---\ntitle: Home\nicon: House\ndescription: Main page\n---\n# Home\n[Guide](/docs/v1/start)\n",
        'fumadocs-repo/content/docs/index.ru.mdx'=>"---\ntitle: Главная\n---\n# Главная\n",
        'fumadocs-repo/content/docs/meta.ru.json'=>json_encode(['title'=>'Документы','pages'=>['index','v1','v2']],JSON_THROW_ON_ERROR),
        'fumadocs-repo/content/docs/v1/meta.json'=>json_encode(['title'=>'Legacy','root'=>'version','pages'=>['index','start']],JSON_THROW_ON_ERROR),
        'fumadocs-repo/content/docs/v1/index.mdx'=>"# V1 Index\n",
        'fumadocs-repo/content/docs/v1/start.mdx'=>"# English\n<Callout type=\"warn\">Beware</Callout>\n<Tabs items={[\"Linux\",\"Windows\"]}><Tab value=\"Linux\">Use bash</Tab><Tab value=\"Windows\">Use PowerShell</Tab></Tabs>\n![Icon](/images/logo.png)\n",
        'fumadocs-repo/content/docs/v1/start.ru.mdx'=>"# Русская версия\n[Overview](index.mdx)\n",
        'fumadocs-repo/content/docs/v2/meta.json'=>json_encode(['title'=>'Latest','root'=>'version','pages'=>['index','intro']],JSON_THROW_ON_ERROR),
        'fumadocs-repo/content/docs/v2/index.mdx'=>"# V2 Index\n",
        'fumadocs-repo/content/docs/v2/intro.mdx'=>"# V2 Intro\n<Cards><Card title=\"API\" href=\"/docs/v2/index\">Docs</Card></Cards>\n",
        'fumadocs-repo/public/images/logo.png'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==',true),
    ];
    foreach($fixture as $path=>$contents){$name=$root.'/'.count($files);file_put_contents($name,$contents);$files[$path]=$name;}
    $p=FumadocsImport::prepare($files,'current');
    check113($p['fumadocs']&&count($p['pages'])===7,'content collection pages and local media detected');
    check113(($p['pages']['fumadocs-repo/content/docs/v1/index.mdx']['route']??null)==='index'&&$p['pages']['fumadocs-repo/content/docs/v1/start.mdx']['version']==='v1','version root maps index and guide into distinct Book version');
    check113(($p['pages']['fumadocs-repo/content/docs/v1/start.ru.mdx']['route']??null)==='ru/start','locale suffix maps to localized path');
    check113($p['nav']['fumadocs-repo/content/docs/v1/index.mdx']['order']<$p['nav']['fumadocs-repo/content/docs/v1/start.mdx']['order'],'meta.json controls hierarchy and sidebar order');
    check113(isset($p['files']['fumadocs-repo/public/images/logo.png'])&&!isset($p['files']['fumadocs-repo/source.config.ts']),'public assets imported; source code excluded');
    $warnings=[];$mdx=FumadocsMdx::convert($fixture['fumadocs-repo/content/docs/v1/start.mdx'],$warnings);
    check113(str_contains($mdx,'book-callout')&&str_contains($mdx,'book-tabs')&&str_contains($mdx,'Linux'),'static Callout and Tabs converted to real Book rich blocks');
    $mdx=FumadocsMdx::convert($fixture['fumadocs-repo/content/docs/v2/intro.mdx'],$warnings);
    check113(str_contains($mdx,'book-cards'),'static Cards converted to Book cards');
    $code=FumadocsMdx::convert("# Code\n```mdx\n<Callout type=\"warn\">Literal</Callout>\n```\n",$warnings);
    check113(str_contains($code,'<Callout type="warn">Literal</Callout>'),'source code examples are preserved');
    reject113(fn()=>FumadocsMdx::convert('<Callout type={fetch("https://evil.example")}>secret</Callout>',$warnings),'dynamic component props refused');
    reject113(fn()=>FumadocsMdx::convert("import Secret from '../src/secret'\n<Secret />",$warnings),'runtime MDX imports refused');
    $dupe=$files;$dupe['fumadocs-repo/content/docs/v1/start.ru.md']=$root.'/dupe';file_put_contents($dupe['fumadocs-repo/content/docs/v1/start.ru.md'],'# Duplicate');
    reject113(fn()=>FumadocsImport::prepare($dupe,'current'),'duplicate canonical locale/route refused');
    $bad=$files;$bad['fumadocs-repo/content/docs/v1/meta.json']=$root.'/bad';file_put_contents($bad['fumadocs-repo/content/docs/v1/meta.json'],json_encode(['staticParams'=>[['name'=>'dynamic']]],JSON_THROW_ON_ERROR));
    reject113(fn()=>FumadocsImport::prepare($bad,'current'),'dynamic route parameters refused');
    echo "Fumadocs 1.0.13 converter tests passed.\n";
}finally{foreach($files as $name)@unlink($name);@unlink($root.'/dupe');@unlink($root.'/bad');@rmdir($root);}
