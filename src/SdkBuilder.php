<?php
declare(strict_types=1);
namespace CajeerBook;

/** Generate standalone clients from the exact Reader-visible published operation definitions.
 * No source bundle, unpublished data, credential or network request enters an artifact. */
final class SdkBuilder
{
    public const LANGUAGES=['typescript','python','php'];
    public const MAX_OPERATIONS=200;
    private static function json(mixed $v):string{return OpenApiDocument::json($v);}
    private static function template(string $name):string{
        $s=file_get_contents(dirname(__DIR__).'/resources/sdk/'.$name);if($s===false)throw new \RuntimeException('SDK runtime unavailable.');return $s;
    }
    public static function collect(array $db,array $visible,string $key):?array{
        // Select only a canonical operation from the same generated reference, never arbitrary book/slug input.
        if(!preg_match('~\A(.+/reference/[^/]+)/operations/[a-z]+-[a-f0-9]{24}\z~D',$key,$m)||!isset($visible[$key]))return null;
        $ops=[];$selected=null;$prefix=$m[1].'/operations/';$legacyCache=[];
        foreach($visible as $k=>$p){if(!str_starts_with($k,$prefix))continue;$d=ApiPlayground::published($db,$visible,$k,$legacyCache);if(!$d)continue;
            $label=trim(preg_replace('/[^a-z0-9]+/','_',strtolower($d['path'])),'_');$name=strtolower($d['method']).'_'.substr($label?:'root',0,48).'_'.substr(hash('sha256',$d['path']),0,24);
            if(isset($ops[$name]))throw new \DomainException('Duplicate SDK operation.');
            $ops[$name]=['definition'=>$d,'key'=>$k,'binding'=>ApiPlaygroundView::binding($k,$p,$d)];if($k===$key)$selected=$name;
            if(count($ops)>self::MAX_OPERATIONS)throw new \DomainException('SDK operation limit exceeded.');
        }
        if($selected===null)return null;
        ksort($ops,SORT_STRING);$defs=array_map(fn($op)=>$op['definition'],$ops);
        $version=hash('sha256',self::json(['generator'=>'0.3.6','operations'=>$ops,'runtimes'=>array_map(fn($n)=>hash('sha256',self::template($n)),['runtime.mjs','runtime.py','Runtime.php'])]));
        return ['version'=>$version,'reference'=>$m[1],'selected'=>$selected,'operations'=>$ops,'definitions'=>$defs];
    }
    private static function sample(array $d,int $server,int $security):array{
        $values=[];foreach($d['parameters'] as $p){$s=$p['schema'];$v=$s['enum'][0]??match($s['type']){'integer','number'=>'1','boolean'=>'true',default=>'example'};$values[]=$p['required']?(is_bool($v)?($v?'true':'false'):(string)$v):null;}
        $auth=array_map(fn($a)=>$a['kind']==='basic'?'USERNAME:PASSWORD':'YOUR_API_CREDENTIAL',$d['security'][$security]);
        $type=$d['body_types'][0]??'';$body=$d['body_required']?($type==='application/json'||str_ends_with($type,'+json')?'{}':($type==='application/x-www-form-urlencoded'?'name=example':'example')):'';
        return ['server'=>$server,'security'=>$security,'values'=>$values,'auth'=>$auth,'body'=>$body,'content_type'=>$body!==''?$type:''];
    }
    private static function py(mixed $v):string{
        if(is_array($v)){if(array_is_list($v))return '['.implode(', ',array_map(self::py(...),$v)).']';$out=[];foreach($v as $k=>$x)$out[]=self::py((string)$k).': '.self::py($x);return '{'.implode(', ',$out).'}';}
        return $v===null?'None':(is_bool($v)?($v?'True':'False'):self::json($v));
    }
    public static function examples(array $model,int $server=0,int $security=0):array{
        $n=$model['selected'];$d=$model['definitions'][$n];if(!isset($d['servers'][$server],$d['security'][$security]))throw new \InvalidArgumentException('Invalid SDK example server or security alternative.');$input=self::sample($d,$server,$security);$opts=self::json($input);$args=[];foreach($input as $k=>$v)$args[]=$k.'='.self::py($v);
        $curl='';try{$r=ApiPlayground::request($d,$input,array_map(ApiPlayground::origin(...),$d['servers']),true);$quote=fn($s)=>"'".str_replace("'","'\\''",$s)."'";$parts=["curl --proto '=https' --max-time 15 --request ".$r['method'].' '.$quote($r['url'])];foreach($r['headers'] as $k=>$v)$parts[]='  --header '.$quote($k.': '.$v);if($r['body']!=='')$parts[]='  --data-binary '.$quote($r['body']);$curl=implode(" \\\n",$parts);}catch(\InvalidArgumentException){$curl='# Fill required parameters with valid values before sending this operation.';}
        return ['curl'=>$curl,'typescript'=>"import { Client } from './client.mjs';\n\nconst client = new Client();\nconst response = await client.$n($opts);\nconsole.log(response.status, response.text());\n",'python'=>"from cajeer_sdk import Client\n\nclient = Client()\nresponse = client.$n(".implode(', ',$args).")\nprint(response.status, response.text())\n",'php'=>"<?php\nrequire __DIR__.'/Client.php';\n\n\$client = new \\CajeerSdk\\Client();\n\$response = \$client->$n(".var_export($input,true).");\necho \$response->status, ' ', \$response->body, PHP_EOL;\n"];
    }
    /** Files are built from fixed runtime code, JSON data and hash-based identifiers only. */
    public static function files(array $m,string $language):array{
        if(!in_array($language,self::LANGUAGES,true))throw new \InvalidArgumentException('Unsupported SDK language.');
        $defs=$m['definitions'];$digest=substr($m['version'],0,16);$version='0.3.6+'.$digest;$json=self::json($defs);$examples=self::examples(array_replace($m,['selected'=>array_key_first($defs)]));
        $readme="# CajeerBook SDK\n\nVersion: $version\nPublication digest: {$m['version']}\n\nGenerated from visible published operations in {$m['reference']}. This is a snapshot, not the latest draft.\n\nReplace example values and credential placeholders. Never commit API secrets. Requests go directly to the published HTTPS servers; no CajeerBook proxy or session is used. Redirects are not followed. HTTP error status codes are returned as responses; transport and validation errors throw exceptions. Responses are limited to 1 MiB, bodies to 256 KiB. JS uses a 15s deadline; Python/PHP use socket timeouts. No automatic retries. Browser requests require CORS and cannot set cookies.\n\nParameters are ordered exactly as in operations.json; optional values use null (None in Python). Scalar values are strings. Select server and security alternative by zero-based index, and supply credential values in the alternative's order. JSON bodies are strings; fill them according to the documented schema.\n\nOperations:\n";
        foreach($defs as $n=>$d){$readme.="- `$n`: {$d['method']} {$d['path']}\n";foreach($d['parameters'] as $i=>$p)$readme.="  - values[$i]: {$p['in']} {$p['name']} ({$p['schema']['type']}, ".($p['required']?'required':'optional').")\n";foreach($d['security'] as $i=>$alt)$readme.="  - security $i: ".($alt?implode(', ',array_column($alt,'name')):'none')."\n";}
        $files=['README.md'=>$readme,'LICENSE'=>(string)file_get_contents(dirname(__DIR__).'/LICENSE'),'NOTICE'=>(string)file_get_contents(dirname(__DIR__).'/NOTICE'),'operations.json'=>$json."\n",'publication.json'=>self::json(['version'=>$version,'digest'=>$m['version'],'reference'=>$m['reference'],'operations'=>array_map(fn($op)=>['key'=>$op['key'],'binding'=>$op['binding']],$m['operations'])])."\n"];
        if($language==='typescript'){
            $js="import { execute } from './runtime.mjs';\nconst operations = JSON.parse(".self::json($json).");\nexport class Client {\n";
            $types="export interface Response { status: number; headers: Record<string,string>; body: Uint8Array; text(): string; json(): unknown; }\n";$methods='';$typedMethods='';
            foreach($defs as $n=>$d){$js.="  $n(input) { return execute(operations.$n, input); }\n";$tuple=[];foreach($d['parameters'] as $i=>$p)$tuple[]='p'.$i.'_'.preg_replace('/[^a-zA-Z0-9_]/','_',$p['name']).': '.(isset($p['schema']['enum'])&&$p['schema']['enum']?implode(' | ',array_map(fn($v)=>self::json(is_bool($v)?($v?'true':'false'):(string)$v),$p['schema']['enum'])):($p['schema']['type']==='boolean'?'"true" | "false"':'string')).($p['required']?'':' | null');$alts=[];foreach($d['security'] as $i=>$alt)$alts[]='{ security'.($i===0?'?':'').": $i; auth: [".implode(', ',array_fill(0,count($alt),'string')).'] }';$servers=implode(' | ',array_keys($d['servers']));$types.="export type Input_$n = { server?: $servers; values: [".implode(', ',$tuple).']; '.($d['body_required']?'body: string; content_type: ':'body?: string; content_type?: ').($d['body_types']?implode(' | ',array_map(self::json(...),$d['body_types'])).($d['body_required']?'':' | ""'):'""').'; } & ('.implode(' | ',$alts).");\n";$methods.="  $n(input: Input_$n): Promise<Response>;\n";$typedMethods.="  $n(input: Input_$n): Promise<Response> { return execute(operations.$n, input); }\n";}
            $files+=['client.mjs'=>$js."}\n",'client.d.mts'=>$types."export class Client {\n$methods}\n",'client.mts'=>"import { execute } from './runtime.mjs';\nconst operations = JSON.parse(".self::json($json).");\n".$types."export class Client {\n$typedMethods}\n",'runtime.d.mts'=>"export function execute(definition: unknown, input: unknown): Promise<import('./client.mjs').Response>;\n",'runtime.mjs'=>self::template('runtime.mjs'),'example.mjs'=>$examples['typescript'],'example.mts'=>$examples['typescript'],'package.json'=>self::json(['name'=>'@cajeerbook/sdk-'.$digest,'version'=>$version,'type'=>'module','license'=>'Apache-2.0','exports'=>['.'=>['types'=>'./client.d.mts','import'=>'./client.mjs']],'types'=>'./client.d.mts','engines'=>['node'=>'>=22']])."\n"];$files['README.md'].="\nRun `node example.mjs` after editing credentials/parameters. Import Client from client.mjs (TypeScript source and declarations included). Install locally with npm install ./path-to-extracted-package.\n";
        }elseif($language==='python'){
            $py="import json\nfrom pathlib import Path\nfrom .runtime import execute, Response\n\n_operations = json.loads(Path(__file__).with_name('operations.json').read_text(encoding='utf-8'))\n\nclass Client:\n";
            foreach($defs as $n=>$d)$py.="    def $n(self, *, server: int = 0, security: int = 0, values: tuple[str | None, ...] | list[str | None] = (), auth: tuple[str, ...] | list[str] = (), body: str = '', content_type: str = '') -> Response:\n        return execute(_operations['$n'], server=server, security=security, values=values, auth=auth, body=body, content_type=content_type)\n\n";
            $files+=['cajeer_sdk/__init__.py'=>$py,'cajeer_sdk/runtime.py'=>self::template('runtime.py'),'cajeer_sdk/operations.json'=>$json."\n",'example.py'=>$examples['python'],'pyproject.toml'=>"[build-system]\nrequires = [\"setuptools>=68\"]\nbuild-backend = \"setuptools.build_meta\"\n\n[project]\nname = \"cajeerbook-sdk-$digest\"\nversion = \"$version\"\nrequires-python = \">=3.10\"\n\n[tool.setuptools]\npackages = [\"cajeer_sdk\"]\n\n[tool.setuptools.package-data]\ncajeer_sdk = [\"operations.json\"]\n"];$files['README.md'].="\nRun `python3 example.py` in the extracted directory (Python 3.10+). Optionally install with `pip install .`.\n";
        }else{
            $php="<?php\ndeclare(strict_types=1);\nnamespace CajeerSdk;\nrequire_once __DIR__.'/Runtime.php';\nfinal class Client {\n    private const OPERATIONS = ".var_export($defs,true).";\n";
            foreach($defs as $n=>$d)$php.="    public function $n(array \$input): Response { return Runtime::execute(self::OPERATIONS['$n'], \$input); }\n";
            $files+=['Client.php'=>$php."}\n",'Runtime.php'=>self::template('Runtime.php'),'example.php'=>$examples['php'],'composer.json'=>self::json(['name'=>'cajeerbook/sdk-'.$digest,'version'=>$version,'type'=>'library','license'=>'Apache-2.0','require'=>['php'=>'>=8.1','ext-openssl'=>'*'],'autoload'=>['files'=>['Client.php']]])."\n"];$files['README.md'].="\nRun `php example.php` (PHP 8.1+, OpenSSL, allow_url_fopen). Or load Client.php from your application.\n";
        }
        $files['README.md'].="\nThe SDK implements the executable subset of OpenAPI: scalar parameters, supported auth alternatives and JSON/form/text bodies. It does not generate schema model classes, OAuth flows or multipart upload. Each client package uses the same local namespace/module name; install separate versions in separate projects.\n";
        return $files;
    }
    /** Private ephemeral ZIP, no filesystem paths from the imported specification. */
    public static function archive(array $m,string $language):string{
        $files=self::files($m,$language);$total=array_sum(array_map('strlen',$files));if($total>12*1024*1024)throw new \DomainException('SDK package exceeds size limit.');
        $dir=sys_get_temp_dir().'/book-sdk-'.bin2hex(random_bytes(16));if(!mkdir($dir,0700))throw new \RuntimeException('Cannot create SDK staging directory.');$path=$dir.'/sdk.zip';
        try{$zip=new \PharData($path,0,null,\Phar::ZIP);$zip->startBuffering();foreach($files as $name=>$data)$zip->addFromString($name,$data);$zip->stopBuffering();unset($zip);$data=file_get_contents($path);if($data===false)throw new \RuntimeException('Cannot read SDK package.');return $data;}finally{if(is_file($path))unlink($path);rmdir($dir);}
    }
}
