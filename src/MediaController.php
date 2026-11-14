<?php
declare(strict_types=1);
namespace CajeerBook;

final class MediaController
{
    public function __construct(private readonly MediaStore $media,private readonly BookStore $books) {}
    private static function e(string $s): string { return Markdown::e($s); }
    public function post(string $csrf): void
    {
        $json=($_SERVER['HTTP_X_BOOK_MEDIA']??'')==='1';
        $str=static fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';
        try{
            $action=$str('action');$result=[];
            if($action==='upload'){
                $f=$_FILES['file']??null;
                if(!is_array($f)||!is_int($f['error']??null)) throw new \InvalidArgumentException('Choose a file');
                if($f['error']!==UPLOAD_ERR_OK){if(in_array($f['error'],[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)) {http_response_code(413);throw new \InvalidArgumentException('Upload exceeds server limit');}throw new \InvalidArgumentException('Upload failed (code '.$f['error'].')');}
                if(!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||!is_string($f['name']??null)) throw new \InvalidArgumentException('Invalid upload');
                $result=$this->media->upload($f['tmp_name'],$f['name'],$str('folder'));
            }elseif(in_array($action,['update','delete'],true)){
                if(!preg_match('/\A[1-9][0-9]{0,17}\z/D',$str('revision')))throw new \InvalidArgumentException('Invalid revision');
                if($action==='update')$this->media->update($str('id'),(int)$str('revision'),$str('name'),$str('folder'));
                else $this->media->delete($str('id'),(int)$str('revision'));
            }else throw new \InvalidArgumentException('Unknown media action');
            if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return;}
            header('Location: /studio/media',true,303);
        }catch(\InvalidArgumentException|\DomainException $e){
            if(http_response_code()!==413)http_response_code($e instanceof \DomainException?409:422);
            if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
            else echo '<!doctype html><meta charset="utf-8"><h1>Media operation failed</h1><p>'.self::e($e->getMessage()).'</p><a href="/studio/media">Return to media library</a>';
        }
    }
    public function markup(string $csrf): string
    {
        $db=$this->books->read();$index=MediaStore::referenceIndex($db);$items=$db['media']??[];$total=array_sum(array_column($items,'size'));
        $query=static fn($k)=>is_string($_GET[$k]??null)?substr($_GET[$k],0,240):'';
        $q=$query('q');$folder=$query('folder');$type=$query('type');$sort=$query('sort');
        $folders=array_unique(array_column($items,'folder'));sort($folders,SORT_STRING);
        $h='<div class="eyebrow">AUTHORING / MEDIA</div><h1>Media library</h1><p>Files stay private until linked by a published page. '.count($items).' files · '.self::e(self::size($total)).' / 512 MiB.</p><div class="actions"><a href="/studio">Back to Studio</a><a href="/studio/media/backup">Download full backup (.tar)</a></div>';
        $hidden='<input type="hidden" name="csrf" value="'.self::e($csrf).'">';
        $h.='<form action="/studio/media" method="post" enctype="multipart/form-data" class="editor media-upload" data-media-upload>'.$hidden.'<input type="hidden" name="action" value="upload"><label>Folder (optional)<input name="folder" maxlength="240" placeholder="images/guides" value="'.self::e($folder==='@root'?'':$folder).'"></label><label class="media-drop" data-media-drop>Choose or drop files<input type="file" name="file" accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.zip,.txt,.md,.csv" required></label><p><small>PNG, JPEG, GIF, WebP, PDF, ZIP, UTF-8 TXT/MD/CSV. 20 MiB per file. Duplicate content reuses the existing file.</small></p><button class="primary">Upload</button><progress data-media-progress max="100" value="0" hidden></progress><div role="status" aria-live="polite" data-media-status></div></form>';
        $h.='<form method="get" action="/studio/media" class="media-filters"><label>Search<input name="q" value="'.self::e($q).'" placeholder="Name, folder or hash"></label><label>Folder<select name="folder"><option value="">All folders</option><option value="@root"'.($folder==='@root'?' selected':'').'>Root</option>';
        foreach($folders as $f)if($f!=='')$h.='<option value="'.self::e($f).'"'.($f===$folder?' selected':'').'>'.self::e($f).'</option>';
        $h.='</select></label><label>Type<select name="type">';foreach([''=>'All types','image'=>'Images','application/pdf'=>'PDF','application/zip'=>'ZIP','text/plain'=>'Text'] as $v=>$l)$h.='<option value="'.$v.'"'.($type===$v?' selected':'').'>'.$l.'</option>';
        $h.='</select></label><label>Sort<select name="sort"><option value="new">Newest</option><option value="name"'.($sort==='name'?' selected':'').'>Name</option><option value="size"'.($sort==='size'?' selected':'').'>Size</option></select></label><button>Filter</button></form>';
        $items=array_filter($items,static function($i)use($q,$folder,$type){return ($q===''||(function_exists('mb_stripos')?mb_stripos($i['name'].' '.$i['folder'].' '.$i['sha256'],$q,0,'UTF-8'):stripos($i['name'].' '.$i['folder'].' '.$i['sha256'],$q))!==false)&&($folder===''||$i['folder']===($folder==='@root'?'':$folder))&&($type===''||str_starts_with($i['mime'],$type));});
        uasort($items,static fn($a,$b)=>match($sort){'name'=>strnatcasecmp($a['name'],$b['name'])?:strcmp($a['id'],$b['id']),'size'=>$b['size']<=>$a['size']?:strcmp($a['id'],$b['id']),default=>strcmp($b['created'],$a['created'])?:strcmp($a['id'],$b['id'])});
        $page=max(1,min(100,(int)$query('page')));$pages=max(1,(int)ceil(count($items)/24));$page=min($page,$pages);$h.='<p>'.count($items).' matching files</p><div class="media-grid">';
        foreach(array_slice($items,($page-1)*24,24,true) as $id=>$i){
            $private='/studio/media/file/'.$id;$public='/media/'.$id;$image=str_starts_with($i['mime'],'image/');$refs=$index['all'][$id]??[];$published=$index['published'][$id]??[];
            $h.='<section class="tile media-item"><div class="media-preview">'.($image?'<img loading="lazy" decoding="async" src="'.$private.'?thumb=1" alt="'.self::e($i['name']).'">':'<span>'.self::e(strtoupper(pathinfo($i['name'],PATHINFO_EXTENSION))).'</span>').'</div><h2>'.self::e($i['name']).'</h2><p>'.self::e($i['folder']?: 'Root').' · '.self::size($i['size']).($image?' · '.$i['width'].' × '.$i['height']:'').'</p><p><small>'.($published?'Published reference':'Private').' · '.count($refs).' reference(s) · '.self::e($i['created']).'</small></p><div class="actions"><a href="'.$private.'?download=1">Download</a><a href="'.$private.'" target="_blank" rel="noopener">Open</a><button type="button" data-media-copy="'.$public.'">Copy URL</button></div><label>Markdown URL<input readonly value="'.$public.'"></label><details><summary>Manage file</summary><p><small>SHA-256: '.self::e($i['sha256']).'</small></p><form method="post" action="/studio/media" class="editor">'.$hidden.'<input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="revision" value="'.$i['revision'].'"><label>Name<input name="name" required maxlength="180" value="'.self::e($i['name']).'"></label><label>Folder<input name="folder" maxlength="240" value="'.self::e($i['folder']).'"></label><div class="actions"><button name="action" value="update">Save details</button><button name="action" value="delete" formnovalidate data-confirm="Delete this unused file permanently?"'.($refs?' disabled':'').'>Delete</button></div></form>';
            if($refs)$h.='<p><small>Used by: '.self::e(implode(', ',$refs)).'</small></p>';
            $h.='</details></section>';
        }
        $h.='</div><div class="pager">';for($n=max(1,$page-2);$n<=min($pages,$page+2);$n++)$h.='<a href="/studio/media?'.self::e(http_build_query(['q'=>$q,'folder'=>$folder,'type'=>$type,'sort'=>$sort,'page'=>$n])).'"'.($n===$page?' aria-current="page"':'').'>'.$n.'</a>';
        return $h.'</div>';
    }
    private static function size(int $n): string {return $n>=1048576?number_format($n/1048576,1).' MiB':number_format($n/1024,1).' KiB';}
    public function serve(string $id,bool $private,string $method): void
    {
        header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex');
        if($private && isset($_GET['thumb']) && !isset($_GET['download'])){
            $thumbnail=$this->media->thumbnail($id);
            if($thumbnail){[$size,$stream]=$thumbnail;try{header('Content-Type: image/png');header('Content-Length: '.$size);header("Content-Security-Policy: default-src 'none'; sandbox");if($method!=='HEAD')fpassthru($stream);}finally{fclose($stream);}return;}
        }
        $result=$this->media->open($id,$private);
        if(!$result){http_response_code(404);echo 'Media not found';return;}
        [$item,$stream]=$result;
        try{
            $image=str_starts_with($item['mime'],'image/');$download=isset($_GET['download'])||!$image;
            header('Content-Type: '.$item['mime']);header('Content-Length: '.$item['size']);header('Content-Security-Policy: default-src \'none\'; sandbox');header('X-Content-Type-Options: nosniff');
            $fallback=preg_replace('/[^a-zA-Z0-9._-]/','_',$item['name']);
            header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($item['name']));
            if($method!=='HEAD')fpassthru($stream);
        }finally{fclose($stream);}
    }
}
