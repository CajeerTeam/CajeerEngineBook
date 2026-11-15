<?php
declare(strict_types=1);
// Explicit opt-in read-only live acceptance. Never writes or prints credentials.
require dirname(__DIR__).'/bootstrap.php';
$repo=getenv('BOOK_TEST_GITLAB_REPO')?:'';$branch=getenv('BOOK_TEST_GITLAB_BRANCH')?:'';$origin=getenv('BOOK_TEST_GITLAB_ORIGIN')?:'https://gitlab.com';$root=getenv('BOOK_TEST_GITLAB_ROOT')?:'';
if($repo===''||$branch===''){fwrite(STDERR,"Set BOOK_TEST_GITLAB_REPO and BOOK_TEST_GITLAB_BRANCH for a disposable test repository.\n");exit(2);}
try{$client=new CajeerBook\GitLabClient($origin,getenv('BOOK_TEST_GITLAB_TOKEN')?:'');$id=$client->identify($repo);$sha=$client->head($repo,$branch);$remote=$client->fetch($repo,$sha,$root);if(!array_filter(array_keys($remote['files']->files()),fn($p)=>preg_match('/\.(md|markdown)$/i',$p)))throw new DomainException('No Markdown in the selected tree.');echo 'PASS live GitLab HTTPS read: project '.$id.' · '.$sha.' · '.count($remote['entries'])." verified files\n";}catch(InvalidArgumentException|DomainException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
