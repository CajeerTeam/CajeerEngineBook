<?php
declare(strict_types=1);
// End-to-end signed discovery, file persistence, host-agent transport and delivery tests.
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerBook\\')){
        $f=$root.'/src/'.str_replace('\\','/',substr($class,strlen('CajeerBook\\'))).'.php';
        if(is_file($f))require_once $f;
    }
});
use CajeerBook\{UpdateScheduler,UpdateCenter,UpdateAgentClient};
function verify116(bool $condition,string $label):void {if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function rejects116(Closure $fn,string $label):void{try{$fn();}catch(Throwable){verify116(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
$dir=sys_get_temp_dir().'/book-scheduled-116-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/data',0700);
try{
    file_put_contents($dir.'/VERSION',"1.1.5\n");
    $kp=sodium_crypto_sign_keypair();$der=hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey($kp);
    $pub=$dir.'/key.pem';file_put_contents($pub,"-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64)."-----END PUBLIC KEY-----\n");
    $files=['source.zip','deployment.zip','gate-evidence.json','SHA256SUMS','qualification.json','qualification-lifecycle.json',
        'qualification-soak.json','qualification-candidate.json'];
    foreach(['book','postgres-pitr'] as $role)foreach(['sbom.spdx.json','provenance.json','build-metadata.json','scan.json','smoke.log'] as $s)$files[]="$role-$s";
    $assets=[];$artifacts=[];
    foreach($files as $file){$artifacts[$file]=['sha256'=>str_repeat('a',64),'size'=>12];$assets[]=['name'=>$file,'size'=>12,'state'=>'uploaded','digest'=>'sha256:'.str_repeat('a',64)];}
    $v='1.1.6';$commit=str_repeat('b',40);
    $manifest=['format'=>1,'product'=>'CajeerEngineBook','repository'=>'CajeerTeam/CajeerEngineBook','version'=>$v,
        'commit'=>$commit,'platform'=>'linux/amd64','schema_version'=>7,'signing_key_sha256'=>hash('sha256',$der),
        'images'=>['book'=>'ghcr.io/cajeerteam/cajeerenginebook@sha256:'.str_repeat('c',64),
        'postgres-pitr'=>'ghcr.io/cajeerteam/cajeerenginebook-postgres-pitr@sha256:'.str_repeat('d',64)],'artifacts'=>$artifacts];
    $body=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $sig=sodium_crypto_sign_detached($body,sodium_crypto_sign_secretkey($kp));
    foreach(['release.json'=>$body,'release.json.sig'=>$sig,'source.zip.sig'=>str_repeat('x',64),'deployment.zip.sig'=>str_repeat('y',64)] as $file=>$bytes)
        $assets[]=['name'=>$file,'size'=>strlen($bytes),'state'=>'uploaded','digest'=>'sha256:'.hash('sha256',$bytes)];
    $release=['tag_name'=>'v'.$v,'draft'=>false,'prerelease'=>false,'published_at'=>'2026-11-22T13:00:00Z',
        'name'=>'Signed release','body'=>'Test release','assets'=>$assets];
    $api='https://api.github.com/repos/CajeerTeam/CajeerEngineBook/releases?per_page=100&page=1';
    $download='https://github.com/CajeerTeam/CajeerEngineBook/releases/download/v'.$v.'/';
    $map=[$api=>json_encode([$release],JSON_THROW_ON_ERROR),$download.'release.json'=>$body,$download.'release.json.sig'=>$sig];
    $calls=0;$http=static function(string $url,int $cap)use(&$map,&$calls):string{$calls++;if(!isset($map[$url]))throw new RuntimeException('Unexpected URL');return $map[$url];};
    $center=new UpdateCenter($dir,$dir.'/data',$http,$pub);
    $now=1730000000;$sent=[];$mail=static function($to,$subject,$body)use(&$sent):bool{$sent[]=[$to,$subject,$body];return true;};
    $stage='none';$agentCalls=[];$target=['version'=>$v,'commit'=>$commit,'manifest_sha256'=>hash('sha256',$body)];
    $transport=static function(string $req)use(&$stage,&$agentCalls,$target):string{
        $r=json_decode($req,true,10,JSON_THROW_ON_ERROR);$action=$r['action'];$agentCalls[]=$action;
        if($action==='status')return json_encode(['ok'=>true,'data'=>['state'=>'succeeded','stage'=>$stage,'candidate'=>$target]]);
        if(($r['candidate']??null)!==$target)throw new RuntimeException('Candidate mismatch');
        if($action==='fetch')$stage='fetched';elseif($action==='preflight'){$stage='preflight';}else throw new RuntimeException('Forbidden action');
        return json_encode(['ok'=>true,'data'=>['state'=>'queued','stage'=>$stage]]);
    };
    $agent=new UpdateAgentClient('/tmp/mock-book-116.sock',$transport);
    $scheduler=new UpdateScheduler($center,$dir.'/data',$agent,$mail,static function()use(&$now):int{return $now;});
    verify116($scheduler->tick()['status']==='disabled'&&$calls===0,'Scheduler defaults to disabled and does not call GitHub');
    rejects116(fn()=>$scheduler->configure(['enabled'=>true,'channel'=>'beta','mode'=>'manual_approval']),'Prerelease install mode rejected');
    rejects116(fn()=>$scheduler->configure(['enabled'=>true,'notification_email'=>'a\r\nb@example.com']),'Header injection email rejected');
    $scheduler->configure(['enabled'=>true,'channel'=>'stable','mode'=>'notify_only','interval_minutes'=>60,'notification_email'=>'owner@example.org']);
    $first=$scheduler->tick();verify116($first['status']==='available'&&count($sent)===1,'Scheduled check validates real signed release and notifies owner');
    verify116($scheduler->snapshot()['settings']['mode']==='notify_only'&&count($scheduler->snapshot()['notifications'])===1,'Persistent notification state');
    $requests=$calls;$scheduler->tick();verify116($calls===$requests&&count($sent)===1,'Not-due tick does not check or duplicate emails');
    $now+=3600;$scheduler->tick();verify116(count($sent)===1,'Repeated signed release is deduplicated');
    $scheduler->configure(['enabled'=>true,'channel'=>'stable','mode'=>'download_only','interval_minutes'=>15,'notification_email'=>'']);
    $scheduler->tick();verify116($stage==='fetched'&&in_array('fetch',$agentCalls,true),'Download-only mode stages through host agent');
    $scheduler->configure(['enabled'=>true,'channel'=>'stable','mode'=>'manual_approval','interval_minutes'=>15,'notification_email'=>'']);
    $now+=901;$scheduler->tick();verify116($stage==='preflight'&&in_array('preflight',$agentCalls,true),'Manual approval mode runs host preflight');
    verify116(!in_array('apply',$agentCalls,true),'Scheduler never activates releases');
    verify116((fileperms($dir.'/data/update-center/schedule-state.json')&0777)===0600,'Scheduler file is private');
    $map[$download.'release.json.sig']=str_repeat('z',64);$now+=3600;$scheduler->tick();
    verify116($scheduler->snapshot()['last_status']==='rejected','Invalid release signature cannot stage');
    $scheduler->configure(['enabled'=>false]);$requests=$calls;$scheduler->tick();verify116($calls===$requests,'Disabling scheduler blocks network');
    $stateFile=$dir.'/data/update-center/schedule-state.json';unlink($stateFile);symlink($dir.'/VERSION',$stateFile);
    rejects116(fn()=>$scheduler->snapshot(),'Symlinked scheduler state rejected');
    echo "Scheduled Update 1.1.6 tests completed\n";
}finally{
    $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iter as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}
    rmdir($dir);
}
