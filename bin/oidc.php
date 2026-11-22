<?php
declare(strict_types=1);
use CajeerBook\{Oidc,IdentityStore,DataDirectory};
$root=dirname(__DIR__);require $root.'/bootstrap.php';
try{
    if(($argv[1]??'')!=='check'||count($argv)!==2){fwrite(STDERR,"Usage: php bin/oidc.php check\n");exit(2);}
    $providers=Oidc::configs();if(!$providers)throw new RuntimeException('OIDC is not configured.');
    if(getenv('BOOK_SECURE_COOKIE')==='0')throw new RuntimeException('Enable secure cookies.');
    $dir=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');
    $identity=new IdentityStore($dir);foreach($providers as $c){$identity->checkOidcOrganization($c['organization']);Oidc::discovery($c);}echo "OIDC configuration, organization and HTTPS discovery OK.\n";
}catch(Throwable){fwrite(STDERR,"OIDC check failed: verify configuration, organization, TLS trust and provider discovery.\n");exit(1);}
