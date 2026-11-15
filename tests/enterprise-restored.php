<?php
declare(strict_types=1);
// Called only in the disposable integrated Compose gate after restoring the backup.
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{IdentityStore,BookStore,Workspaces,Search,CustomDomains,AuditSnapshot};
[$script,$org,$id,$oldToken]=$argv;
$root=getenv('BOOK_DATA_DIR');$identity=new IdentityStore($root);$db=$identity->backup();$w=$db['workspaces'][$id]??null;
if(!$w||$w['organization']!==$org||$identity->current($oldToken)!==null||CustomDomains::active($w['domains']['docs.example.net']))throw new RuntimeException('Restored workspace, session or DNS isolation failed.');
$store=new BookStore(Workspaces::directory($root,$w),false);$published=BookStore::published($store->read());
if(($published['guide/1/integrated']['body']??null)!=='Enterprise integration quasar')throw new RuntimeException('Restored publication missing.');
$results=$store->search('public','none',Search::options(['q'=>'quasar','locale'=>'en']));
if(!in_array('guide/1/integrated',array_column($results['hits'],'key'),true))throw new RuntimeException('Restored publication is not searchable.');
$history=glob($root.'/audit-history/audit-*.jsonl');if(!$history)throw new RuntimeException('Restored audit history missing.');foreach($history as $file)AuditSnapshot::verify($file);
echo "PASS restored publication, search, workspace, session invalidation, DNS reset and historical audit\n";
