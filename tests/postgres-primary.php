<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\PostgresContentStore as P;
// The gate has already installed schema 3. Simulate a read-only server/session without touching data.
$p=P::runtimeConnect();unset($p);$old=getenv('PGOPTIONS');putenv('PGOPTIONS=-c default_transaction_read_only=on');
try{try{$p=P::runtimeConnect();throw new LogicException('Read-only PostgreSQL accepted');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'writable primary'))throw $e;echo "PASS read-only PostgreSQL session refused before runtime work\n";}}
finally{putenv($old===false?'PGOPTIONS':'PGOPTIONS='.$old);}
$p=P::runtimeConnect();echo "PASS fresh writable connection recovered\n";
