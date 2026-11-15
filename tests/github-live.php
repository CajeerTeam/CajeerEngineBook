<?php
declare(strict_types=1);
// Explicit opt-in live read-only acceptance; no credentials or response bodies are printed.
require dirname(__DIR__).'/bootstrap.php';
$repo=getenv('BOOK_TEST_GITHUB_REPO')?:'';$branch=getenv('BOOK_TEST_GITHUB_BRANCH')?:'';$root=getenv('BOOK_TEST_GITHUB_ROOT')?:'';
if($repo===''||$branch===''){fwrite(STDERR,"Set BOOK_TEST_GITHUB_REPO and BOOK_TEST_GITHUB_BRANCH for a disposable test repository.\n");exit(2);}
try{if($root!=='')CajeerBook\ImportFiles::path($root);$client=new CajeerBook\GitHubClient(getenv('BOOK_TEST_GITHUB_TOKEN')?:'');$sha=$client->head($repo,$branch);$remote=$client->fetch($repo,$sha,$root);if(!array_filter(array_keys($remote['files']->files()),fn($p)=>preg_match('/\.(md|markdown)$/i',$p)))throw new DomainException('No Markdown in the selected tree.');echo 'PASS live GitHub HTTPS read: '.$sha.' · '.count($remote['entries'])." verified files\n";}catch(InvalidArgumentException|DomainException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
