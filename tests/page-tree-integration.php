<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,PageTree,Markdown,MediaStore,MediaBackup};
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function reject(callable $f,string $name):void{try{$f();}catch(InvalidArgumentException|DomainException){check(true,$name);return;}throw new RuntimeException('Accepted '.$name);}
function cleanup(string $dir):void{if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())cleanup($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$work=sys_get_temp_dir().'/book-tree-full-'.bin2hex(random_bytes(8));mkdir($work,0700);$dir=$work.'/data';$s=new BookStore($dir);
try{
 $p=['book'=>'qa','version'=>'1.0','path'=>'guide','title'=>'Guide','section'=>'Docs','order'=>1,'body'=>'[Child](guide/child#details)','revision'=>0];
 $first=$s->saveResult($p,true);$id=$first['id'];
 $child=$p;$child['path']='guide/child';$child['title']='Child';$child['body']='## Details';$s->save($child,true);
 $private=$p;$private['path']='guide/private';$private['title']='Private';$private['body']='private-content';$s->save($private,false);
 $other=$p;$other['path']='reference';$other['body']='[Guide](guide)';$s->save($other,true);
 $token=PageTree::token($s->read());$s->change(fn(&$db)=>PageTree::move($db,'qa/1.0/guide','qa/1.0/reference','inside','manual',$token));
 $new='qa/1.0/reference/manual';$db=$s->read();$p['path']='reference/manual';$p['revision']=$db['pages'][$new]['revision'];$p['body']="[Child](guide/child#details)\n\nDraft revision";
 $updated=$s->saveResult($p,false);check($updated['id']===$id,'real Markdown save preserves stable ID');
 $old=$p;$old['path']='guide';$old['revision']=0;reject(fn()=>$s->save($old,false),'old draft path cannot be recreated');
 $token=PageTree::token($s->read());$s->change(fn(&$db)=>PageTree::publish($db,'qa/1.0',$token));$db=$s->read();$public=BookStore::published($db);
 $r=Markdown::render($public[$new]['body'],$new,false,false,$public[$new]['link_base'],PageTree::linkMap($db));
 check(str_contains($r['html'],'href="/docs/'.$new.'/child#details"')&&!str_contains($r['html'],'Draft revision'),'real CommonMark resolves moved relative links and protects draft body');
 $p['revision']=$db['pages'][$new]['revision'];$s->save($p,true);$db=$s->read();check(BookStore::published($db)[$new]['body']===$p['body'],'explicit content publish updates body at new URL');
 $media=new MediaStore($dir,$s);$archive=$work.'/backup.tar';(new MediaBackup($dir,$s,$media))->create($archive);MediaBackup::restore($archive,$work.'/restored');
 $restored=(new BookStore($work.'/restored'))->read();check(PageTree::redirect($restored,'qa/1.0/guide')===$new&&PageTree::id($new,$restored['pages'][$new])===$id,'portable backup restores stable IDs and redirects');
 check($restored['pages'][$new]['draft']['link_base']==='qa/1.0/guide','portable backup retains link context');
}finally{cleanup($work);}
