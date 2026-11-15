<?php
declare(strict_types=1);
namespace CajeerBook;

/** Provider operations used by the same transactional synchronization workflow. */
interface GitProvider
{
    public function head(string $repo,string $branch):string;
    public function fetch(string $repo,string $commit,string $root,?string $cacheDir=null,bool $specifications=false):array;
    public function propose(string $repo,string $branch,string $commit,array $changes):string;
}
