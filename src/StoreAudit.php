<?php
declare(strict_types=1);
namespace CajeerBook;

/** Offline consistency check and explicit, atomic reindex of old media references. */
final class StoreAudit
{
    public function __construct(private readonly BookStore $store,private readonly MediaStore $media) {}
    public function run(bool $repair=false): array
    {
        $result=[];
        $audit=function(array &$db)use($repair,&$result): void {
            PageTree::validate($db);
            if(count($db['pages'])>1000||count($db['media']??[])>MediaStore::MAX_FILES)throw new \RuntimeException('Store exceeds supported record counts');
            $items=$db['media']??[];$total=0;$changed=0;$snapshots=0;
            foreach($items as $id=>$item){
                if(!is_string($id)||!MediaStore::validId($id)||!is_array($item)||($item['id']??null)!==$id||!is_string($item['name']??null)||!is_string($item['folder']??null)||!is_int($item['revision']??null)||$item['revision']<1)throw new \RuntimeException('Invalid media record');
                MediaStore::folder($item['folder']);
                $meta=MediaStore::inspect($this->media->blob($id),$item['name']);
                foreach($meta as $key=>$value)if(($item[$key]??null)!==$value)throw new \RuntimeException('Media integrity mismatch: '.$id);
                $total+=$meta['size'];
            }
            if($total>MediaStore::MAX_TOTAL)throw new \RuntimeException('Store exceeds media quota');
            foreach($db['pages'] as $key=>&$entry){
                if(!is_int($entry['revision']??null)||$entry['revision']<1||!array_key_exists('published',$entry))throw new \RuntimeException('Invalid page revision: '.$key);
                $pageChanged=false;
                foreach(['draft','published'] as $state){
                    $page=$entry[$state]??null;
                    if($state==='published'&&$page===null)continue;
                    if(!is_array($page)||!is_string($page['body']??null)||!is_string($page['title']??null)||!is_string($page['section']??null)||!is_int($page['order']??null)||!is_string($page['updated']??null))throw new \RuntimeException('Invalid page snapshot: '.$key);
                    $base=$page['link_base']??($state==='published'?PageTree::publicKey($key,$entry):$key);
                    $refs=Markdown::mediaReferences($page['body'],$base);sort($refs,SORT_STRING);
                    foreach($refs as $id)if(!isset($items[$id]))throw new \RuntimeException('Missing referenced media: '.$id.' in '.$key);
                    $old=$page['media']??[];if(!is_array($old))throw new \RuntimeException('Invalid media reference index: '.$key);sort($old,SORT_STRING);
                    if($old!==$refs){$changed++;$pageChanged=true;if($repair)$entry[$state]['media']=$refs;}
                    $snapshots++;
                }
                if($repair&&$pageChanged)$entry['revision']++; // invalidate Studio writers using old snapshots
            }unset($entry);
            $result=['pages'=>count($db['pages']),'snapshots'=>$snapshots,'media'=>count($items),'media_bytes'=>$total,'reference_mismatches'=>$changed,'repaired'=>$repair];
        };
        if($repair)$this->store->change($audit);
        else $this->store->snapshot(function(array $db)use($audit){$audit($db);});
        return $result;
    }
}
