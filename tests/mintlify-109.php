<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/core/Update/OperationFiles.php';
foreach(['WorkspaceUrls','RichBlocks','Markdown','PageTree','ImportFiles','MintlifyImport','MintlifyMdx','MarkdownImport'] as $file)require_once dirname(__DIR__).'/src/'.$file.'.php';
use CajeerBook\{MintlifyImport,MintlifyMdx,RichBlocks,MarkdownImport};
function check109(bool $okay,string $message):void{if(!$okay)throw new RuntimeException($message);echo "PASS $message\n";}
function rejects109(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException|DomainException){check109(true,$message);return;}throw new RuntimeException('Unexpected success: '.$message);}
$root=sys_get_temp_dir().'/book-mint-test-'.bin2hex(random_bytes(6));mkdir($root,0700);$created=[];
try{
    $config=['navigation'=>['tabs'=>[['tab'=>'Guides','groups'=>[['group'=>'Getting started','pages'=>['index','quickstart',['group'=>'Advanced','pages'=>['advanced/first']]]]]]]],'redirects'=>[['source'=>'/legacy','destination'=>'/quickstart']]];
    $payload=['repo/docs.json'=>json_encode($config,JSON_THROW_ON_ERROR),'repo/index.mdx'=>"---\ntitle: Intro\n---\n# Intro\n[Start](/quickstart)\n",'repo/quickstart.mdx'=>"---\ntitle: Quick start\nicon: rocket\n---\n<Note>Welcome!</Note>\n![Logo](/images/logo.png)\n",'repo/advanced/first.md'=>"# First\n",'repo/public/images/logo.png'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO9bFWEAAAAASUVORK5CYII=',true),'repo/components/Custom.jsx'=>'export default () => null'];
    $files=[];foreach($payload as $name=>$data){$f=$root.'/'.count($files);file_put_contents($f,$data);$created[]=$f;$files[$name]=$f;}
    $plan=MintlifyImport::prepare($files);
    check109($plan['mintlify']&&isset($plan['files']['quickstart.mdx'],$plan['files']['public/images/logo.png'])&&!isset($plan['files']['docs.json'],$plan['files']['components/Custom.jsx']),'repository root, docs.json and non-executable assets');
    check109($plan['nav']['index']['order']===10&&$plan['nav']['quickstart']['order']===20&&$plan['nav']['advanced/first']['section']==='Guides / Getting started / Advanced','recursive docs.json navigation, order and groups');
    check109($plan['redirects']['legacy']==='quickstart','redirect source and destination canonicalized');
    check109(MintlifyImport::find('index',$plan['files'])==='index.mdx'&&MintlifyImport::find('advanced/first',$plan['files'])==='advanced/first.md','navigation routes resolve to content files');
    $warnings=[];$mdx=MintlifyMdx::convert("---\ntitle: Introduction\ndescription: Intro text\nicon: rocket\n---\n# Intro\n\n<Note>Important</Note>\n<Tabs><Tab title=\"One\">Example</Tab><Tab title=\"Two\">Other</Tab></Tabs>\n<Steps><Step title=\"Start\">First</Step></Steps>\n<AccordionGroup><Accordion title=\"Why\">Reason</Accordion></AccordionGroup>\n<CardGroup cols={2}><Card title=\"Go\" href=\"/quickstart\" image=\"/images/logo.png\">Follow</Card></CardGroup>\n<CodeGroup>\n```bash\necho hi\n```\n</CodeGroup>\n<img src=\"/images/logo.png\" alt=\"Logo\" />\n",$warnings);
    check109(str_contains($mdx,'```book-callout')&&str_contains($mdx,'```book-tabs')&&str_contains($mdx,'```book-steps')&&str_contains($mdx,'```book-accordion')&&str_contains($mdx,'```book-cards')&&str_contains($mdx,'```book-code-tabs'),'built-in MDX components converted to validated Book rich blocks');
    check109(str_contains($mdx,'![Logo](</images/logo.png>)')&&!str_contains($mdx,'icon: rocket')&&count($warnings)===0,'image JSX and supported front matter sanitized');
    preg_match_all('/```(book-[a-z-]+)\n(.*?)\n```/s',$mdx,$blocks,PREG_SET_ORDER);
    foreach($blocks as $block)check109(RichBlocks::parse($block[1],$block[2])!==null,'rich block validates: '.$block[1]);
    $notes=[];$source=MintlifyMdx::convert("<Tabs><Tab title=\"A\">```js\nconst x = '<Note>';\n```\n</Tab></Tabs>",$notes);
    check109(str_contains($source,'book-tabs')&&str_contains($source,'const x')&&preg_match('/```book-tabs\n(.*?)\n```/s',$source,$single)===1&&RichBlocks::parse('book-tabs',$single[1])!==null,'nested source code retained, not evaluated');
    $notes=[];$inline=MintlifyMdx::convert('Inline `<Note>literal</Note>` followed by <Tip>Real</Tip>.',$notes);
    check109(str_contains($inline,'`<Note>literal</Note>`')&&str_contains($inline,'book-callout'),'inline JSX examples stay intact while real components convert');
    $notes=[];$custom=MintlifyMdx::convert('<CustomWidget name="inert" />',$notes);
    check109(str_contains($custom,'CustomWidget')&&count($notes)===1,'unknown custom components are reported and not executed');
    rejects109(fn()=>MintlifyMdx::convert('<CardGroup><Card title="bad" href="javascript:alert(1)">Go</Card></CardGroup>',$notes),'unsafe component link rejected');
    rejects109(fn()=>MintlifyImport::route('/../escape'),'traversal route blocked');
    rejects109(fn()=>MintlifyImport::route('/legacy/*'),'wildcard redirect blocked');
    rejects109(fn()=>MintlifyMdx::convert('import Alert from "./Alert";',$notes),'executable MDX import rejected');
    rejects109(fn()=>MintlifyMdx::convert('<Steps><Step title="A">A</Steps>',$notes),'malformed component rejected');
    $mint=['navigation'=>[['group'=>'Old navigation','pages'=>['intro']]],'redirects'=>['/old'=>'/intro']];
    $old=$root.'/mint';file_put_contents($old,json_encode($mint));$intro=$root.'/intro';file_put_contents($intro,'# Intro');
    $legacy=MintlifyImport::prepare(['mint.json'=>$old,'intro.md'=>$intro]);
    check109($legacy['nav']['intro']['section']==='Old navigation'&&$legacy['redirects']['old']==='intro','legacy mint.json navigation and redirects');
    // Pure link rewriting test: the production importer uses CommonMark region boundaries when installed.
    $ignored=[];$rewritten=MarkdownImport::rewrite('[start](/quickstart) ![logo](/images/logo.png)','index.mdx',['/quickstart'=>'guide/1.0/quickstart'],['images/logo.png'=>str_repeat('a',32)],$ignored,[]);
    check109(str_contains($rewritten,'/docs/guide/1.0/quickstart')&&str_contains($rewritten,'/media/'.str_repeat('a',32)),'root-relative Mintlify links and images resolve to Book URLs');
    echo "Mintlify 1.0.9 policy and conversion tests passed.\n";
}finally{foreach($created as $f)@unlink($f);@unlink($root.'/mint');@unlink($root.'/intro');@rmdir($root);}
