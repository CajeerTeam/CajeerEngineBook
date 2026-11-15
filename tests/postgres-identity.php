<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{IdentityStore,PostgresContentStore,BookStore,Workspaces,InstallationBackup};
use CajeerEngine\Update\OperationFiles as Files;
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(RuntimeException|DomainException|InvalidArgumentException){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$dsn=getenv('BOOK_PG_DSN');if(!$dsn)throw new RuntimeException('Real PostgreSQL required.');
$base=sys_get_temp_dir().'/pg-identity-'.bin2hex(random_bytes(8));mkdir($base,0700);$root=$base.'/data';$ns=bin2hex(random_bytes(16));$restoreNs=bin2hex(random_bytes(16));$binding=bin2hex(random_bytes(16));$public=dirname(__DIR__).'/public';
putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_PG_DSN');
$trigger='test_atomic_'.substr($ns,0,16);$pdo=null;
try{
    $s=new IdentityStore($root);$owner=$s->initialize('owner','Owner','owner@example.org','owner-password-123');$ot=$s->login('owner','owner-password-123','192.0.2.1')['token'];
    $writer=$s->createUser($ot,'writer','Writer','writer@example.org','writer-password-123');$wt=$s->login('writer','writer-password-123','192.0.2.2')['token'];
    $outsider=$s->createUser($ot,'outsider','Outsider','outsider@example.org','outsider-password-123');$xt=$s->login('outsider','outsider-password-123','192.0.2.3')['token'];
    $org=$s->createOrganization($ot,'Team','team');$oid=$org['id'];$other=$s->createOrganization($xt,'Other','other');
    $invite=$s->mutateOrganization($ot,$oid,1,'invite',['username'=>'writer'])['token'];$s->acceptInvitation($invite,$wt,'192.0.2.2');
    $s->mutateOrganization($ot,$oid,3,'role',['user'=>$writer['id'],'role'=>'editor']);
    $w=$s->createWorkspace($ot,$oid,'Private','private');$wid=$w['id'];$s->assignWorkspaceRole($ot,$wid,1,$writer['id'],'viewer');
    $w2=$s->createWorkspace($xt,$other['id'],'Other','other');$s->mutateWorkspace($xt,$w2['id'],1,'archive','','');
    $pending=$s->mutateOrganization($ot,$oid,$s->organization($ot,$oid)['revision'],'invite',['username'=>'newuser'])['token'];$reset=$s->issueReset('writer');
    $legacy=new BookStore($root);$legacy->change(fn(&$d)=>$d['pages']=[]);
    $store=new BookStore(Workspaces::directory($root,$w),false);$store->createBook('guide','Original');
    $oldIdentity=(new IdentityStore($root,true))->migrationSnapshot(fn($d)=>$d);$oldFile=file_get_contents($root.'/identity/identity.json');
    $content=['legacy'=>$legacy->read(),$oid.'/'.$wid=>$store->read(),$other['id'].'/'.$w2['id']=>(new BookStore(Workspaces::directory($root,$w2),false))->read()];
    // Reproduce actual 0.4.1 SQL content + file identity: no identity_state row, same schema/binding.
    putenv('BOOK_PG_DSN='.$dsn);$pdo=PostgresContentStore::connect();$pdo->exec(file_get_contents(dirname(__DIR__).'/sql/postgres-content.sql'));CajeerBook\AuditLog::schema($pdo);$pdo->exec(file_get_contents(dirname(__DIR__).'/sql/postgres-identity.sql'));
    $pdo->beginTransaction();$q=$pdo->prepare('INSERT INTO cajeer_book.content_installation(installation,binding) VALUES(?,?)');$q->execute([$ns,$binding]);
    foreach($content as $scope=>$data)(new PostgresContentStore($ns,$scope,$binding))->initializeInTransaction($pdo,$data);CajeerBook\AuditLog::initialize($pdo,$ns);$pdo->commit();Files::write($root.'/postgres.required',$ns.':'.$binding."\n");
    $sqlContent=new BookStore(Workspaces::directory($root,$w),false);$sqlContent->createBook('sql-only','SQL only');$sqlBefore=$sqlContent->read();
    deny(fn()=>$s->current($ot),'0.4.1 installation requires explicit identity upgrade; no stale JSON login');
    PostgresContentStore::migrate($root);ok($sqlContent->read()===$sqlBefore,'0.4.1 upgrade does not overwrite newer PostgreSQL content');
    $count=function(string $table)use($pdo,$ns){$q=$pdo->prepare('SELECT count(*) FROM cajeer_book.'.$table.' WHERE installation=?');$q->execute([$ns]);return (int)$q->fetchColumn();};
    ok($count('identity_users')===3&&$count('identity_organizations')===2&&$count('identity_workspaces')===2&&$count('identity_memberships')===3&&$count('identity_workspace_roles')===1,'users, organizations, archived workspace and memberships migrated into distinct SQL rows');
    ok($s->current($ot)['user']['id']===$owner['id']&&$s->current($wt)['user']['id']===$writer['id'],'existing hashed sessions and password identities survive upgrade');
    $q=$pdo->prepare('SELECT record_key,value::text FROM cajeer_book.identity_sessions WHERE installation=? AND record_key=?');$q->execute([$ns,hash('sha256',$ot)]);$sessionRow=$q->fetch(PDO::FETCH_ASSOC);ok($sessionRow!==false&&!str_contains($sessionRow['value'],$ot),'SQL session stores digest rather than bearer token');
    for($i=0;$i<5;$i++)ok($s->login('unknown','wrong-password','192.0.2.'.(40+$i))['status']==='invalid','failed login records durable throttle');
    ok((new IdentityStore($root))->login('unknown','wrong-password','192.0.2.50')['status']==='limited','account rate limit survives a new SQL connection');

    ok($s->backup()['workspaces']===$oldIdentity['workspaces']&&$s->backup()['organizations']===$oldIdentity['organizations'],'workspace identifiers, revisions and roles preserved');
    ok($count('identity_invitations')===1&&$count('identity_resets')===1,'pending invitation and hashed reset migrated');
    ok(file_get_contents($root.'/identity/identity.json')===$oldFile,'migration leaves source identity bytes unchanged');
    deny(fn()=>PostgresContentStore::migrate($root),'re-running migration cannot overwrite live identity');
    unlink($root.'/identity/identity.json');
    $s->profile($ot,'SQL Owner','sql@example.org');ok((new IdentityStore($root))->current($ot)['user']['name']==='SQL Owner'&&!file_exists($root.'/identity/identity.json'),'new processes read/write identity with no JSON file');
    ok($s->withWorkspace($wt,$oid,$wid,fn($c,$w)=>$w['role'])==='viewer'&&$s->withWorkspace($xt,$oid,$wid,fn($c,$w)=>$w)===null&&$s->withWorkspace($ot,$other['id'],$wid,fn($c,$w)=>$w)===null,'live RBAC uses SQL memberships and workspace assignments, no tenant bypass');
    // The database itself rejects an override referencing a user in another organization.
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('INSERT INTO cajeer_book.identity_workspace_roles(installation,record_key,position,value) VALUES(?,?,?,CAST(? AS json))');
        $q->execute([$ns,$wid.':'.$outsider['id'],1,json_encode(['workspace'=>$wid,'organization'=>$oid,'user'=>$outsider['id'],'role'=>'admin'],JSON_THROW_ON_ERROR)]);
        deny(fn()=>$pdo->exec('SET CONSTRAINTS ALL IMMEDIATE'),'SQL foreign key rejects cross-organization role assignment');
    }finally{if($pdo->inTransaction())$pdo->rollBack();}

    $s->assignWorkspaceRole($ot,$wid,2,$writer['id'],'none');ok($s->withWorkspace($wt,$oid,$wid,fn($c,$w)=>$w)===null,'explicit deny immediately enforced');
    $s->assignWorkspaceRole($ot,$wid,3,$writer['id'],'inherit');ok($s->withWorkspace($wt,$oid,$wid,fn($c,$w)=>$w['role'])==='editor'&&$count('identity_workspace_roles')===0,'inherit removes persisted override');
    ok($s->reset($reset,'changed-password-123','192.0.2.2')==='ok'&&$s->current($wt)===null&&$s->reset($reset,'changed-password-123','192.0.2.2')==='invalid','migrated reset single-use and revokes old sessions');
    $wt=$s->login('writer','changed-password-123','192.0.2.2')['token'];
    $s->setEnabled($ot,$writer['id'],false);ok($s->current($wt)===null,'account disable revokes SQL sessions');$s->setEnabled($ot,$writer['id'],true);$wt=$s->login('writer','changed-password-123','192.0.2.2')['token'];
    ok($s->acceptInvitation($pending,'','192.0.2.4','New User','new@example.org','new-password-123')['status']==='ok'&&$count('identity_invitations')===0,'migrated invitation creates user and membership transactionally');
    // Database-side injected error after content scope creation must roll back both subsystems.
    $pdo->exec("CREATE FUNCTION cajeer_book.$trigger() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.installation = '$ns' AND NEW.value->>'slug' = 'atomic-fail' THEN RAISE EXCEPTION 'injected identity failure'; END IF; RETURN NEW; END; \$\$");
    $pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON cajeer_book.identity_workspaces FOR EACH ROW EXECUTE FUNCTION cajeer_book.$trigger()");
    $scopes=$count('content_store');$workspaces=$count('identity_workspaces');deny(fn()=>$s->createWorkspace($ot,$oid,'Atomic fail','atomic-fail'),'failure after SQL content initialization aborts workspace creation');
    ok($count('content_store')===$scopes&&$count('identity_workspaces')===$workspaces,'failed workspace creation leaves no orphan SQL scope or registry entry');
    $pdo->exec("DROP TRIGGER $trigger ON cajeer_book.identity_workspaces; DROP FUNCTION cajeer_book.$trigger()");
    $new=$s->createWorkspace($ot,$oid,'Atomic success','atomic-success');$dir=Workspaces::directory($root,$new);ok($count('content_store')===$scopes+1&&$count('identity_workspaces')===$workspaces+1&&(new BookStore($dir,false))->read()['pages']===[]&&!file_exists($dir.'/books.json'),'new workspace and content commit together without JSON');
    // Anonymous readers hold compatible shared locks; an authorization writer cannot
    // pass them. NOWAIT proves this without depending on timing or sleeps.
    $s->withWorkspace('', $oid,$wid,function($ctx,$w)use($ns){
        $p=PostgresContentStore::connect();$p->beginTransaction();
        try{$q=$p->prepare('SELECT installation FROM cajeer_book.identity_state WHERE installation=? FOR SHARE NOWAIT');$q->execute([$ns]);ok($q->fetchColumn()===$ns,'parallel anonymous reader can acquire shared identity lock');}finally{$p->rollBack();}
        $p->beginTransaction();try{$q=$p->prepare('SELECT installation FROM cajeer_book.identity_state WHERE installation=? FOR UPDATE NOWAIT');deny(fn()=>$q->execute([$ns]),'authorization writer cannot pass active public reader');}finally{$p->rollBack();}
    },true);
    // Independent process cannot change authorization during a scoped content operation.
    $revision=$s->organization($ot,$oid)['revision'];$code='require $argv[1]."/bootstrap.php"; $i=new CajeerBook\\IdentityStore($argv[2]);echo "started\n";flush();$i->mutateOrganization($argv[3],$argv[4],(int)$argv[5],"role",["user"=>$argv[6],"role"=>"viewer"]);echo "done\n";';
    $proc=null;$pipes=[];$s->withWorkspace($wt,$oid,$wid,function($ctx,$w)use(&$proc,&$pipes,$code,$root,$ot,$oid,$revision,$writer){
        ok($w['role']==='editor','authorized scoped operation begins');$proc=proc_open([PHP_BINARY,'-r',$code,dirname(__DIR__),$root,$ot,$oid,(string)$revision,$writer['id']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Child unavailable');fclose($pipes[0]);stream_set_timeout($pipes[1],10);ok(trim((string)fgets($pipes[1]))==='started','parallel role writer started');usleep(250000);ok(proc_get_status($proc)['running'],'SQL identity lock pins in-flight authorization');
    });$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);ok(proc_close($proc)===0&&trim($out)==='done'&&$s->withWorkspace($wt,$oid,$wid,fn($c,$w)=>$w['role'])==='viewer','parallel role downgrade commits and next operation sees it: '.$err);
    // A portable backup contains current SQL identity but never reusable sessions/reset/invitation credentials.
    $s->checkWorkspaceStores($root);$backup=$base.'/backup';InstallationBackup::create($root,$backup,$public);$identity=Files::read($backup.'/identity.json');ok($identity['users'][$owner['id']]['name']==='SQL Owner'&&!isset($identity['sessions'],$identity['resets'],$identity['invitations']),'portable backup contains live SQL data without temporary credentials');
    InstallationBackup::restore($backup,$base.'/restored',$public);putenv('BOOK_DATA_DIR='.$base.'/restored');putenv('BOOK_PG_NAMESPACE='.$restoreNs);PostgresContentStore::migrate($base.'/restored');
    $restored=new IdentityStore($base.'/restored');ok($restored->current($ot)===null&&$restored->login('owner','owner-password-123','192.0.2.1')['status']==='ok'&&$restored->backup()['workspaces']===$identity['workspaces'],'restore to new namespace preserves identity and roles, invalidates old sessions');
    putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_PG_DSN');deny(fn()=>$s->current($ot),'removed DSN cannot fall back to old identity');putenv('BOOK_PG_DSN='.$dsn);
    putenv('BOOK_PG_NAMESPACE='.$restoreNs);deny(fn()=>$s->current($ot),'wrong installation namespace refused');putenv('BOOK_PG_NAMESPACE='.$ns);
    putenv('BOOK_PG_DSN=pgsql:host=127.0.0.1;port=1;dbname=book;connect_timeout=1');deny(fn()=>$s->current($ot),'PostgreSQL outage fails closed');putenv('BOOK_PG_DSN='.$dsn);
    // Damaged SQL identity must never be mistaken for an old 0.4.1 installation.
    Files::write($root.'/identity/identity.json',$oldFile);$q=$pdo->prepare('DELETE FROM cajeer_book.identity_state WHERE installation=?');$q->execute([$ns]);
    deny(fn()=>$s->current($ot),'missing migrated SQL identity cannot use stale file credentials');
    deny(fn()=>PostgresContentStore::migrate($root),'identity cutover marker prevents destructive reimport after database loss');
}finally{
    putenv('BOOK_PG_DSN='.$dsn);try{$pdo=PostgresContentStore::connect();$pdo->exec("DROP TRIGGER IF EXISTS $trigger ON cajeer_book.identity_workspaces; DROP FUNCTION IF EXISTS cajeer_book.$trigger()");$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation IN (?,?)');$q->execute([$ns,$restoreNs]);}catch(Throwable $e){fwrite(STDERR,'Test cleanup: '.$e->getMessage()."\n");}clean($base);
}
