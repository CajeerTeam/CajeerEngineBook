<?php
/** Controlled GitHub transport for HTTP integration tests only. Never deploy as a public route. */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/github-fixture.php';
require __DIR__.'/gitlab-fixture.php';
$path=getenv('BOOK_TEST_GITHUB_FIXTURE');if(PHP_SAPI!=='cli-server'||!$path||!is_file($path)){http_response_code(404);exit;}
$fixture=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);$f=($fixture['provider']??'github')==='gitlab'?new GitLabFixture():new GitFixture();$f->set($fixture['files']);$f->delay=$fixture['delay']??0;$f->fail=$fixture['fail']??false;$f->signal=$fixture['signal']??'';
register_shutdown_function(function()use($f,$path){if($f->requests)file_put_contents($path.'.requests',json_encode($f->requests,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);});
(new CajeerBook\App(dirname(__DIR__),fn($token,$c)=>($c['provider']??'github')==='gitlab'?$f->client($token,$c['origin'],$c['repo']):($f instanceof GitFixture?$f->client($token):(function()use($fixture,$token){$gh=new GitFixture();$gh->set($fixture['files']);return $gh->client($token);})())))->run();
