<?php
declare(strict_types=1);
namespace CajeerBook;

/** Published-content policy. A private workspace is a ceiling, never overridden by a book. */
final class Visibility
{
    public const WORKSPACE = ['public', 'private'];
    public const BOOK = ['inherit', 'private', 'public'];
    public static function workspace(array $w): string {
        return $w['visibility'] ?? ($w['legacy'] ? 'public' : 'private');
    }
    public static function book(array $book): string { return $book['visibility'] ?? 'inherit'; }
    public static function publicBook(array $book, string $workspace): bool {
        return $workspace === 'public' && self::book($book) !== 'private';
    }
    /** Never use a raw published list for unauthenticated navigation, API or search. */
    public static function pages(array $db, string $workspace, string $role): array {
        $pages=BookStore::published($db);
        if(Permissions::allows($role,'read'))return $pages;
        if($workspace!=='public')return [];
        $books=BookStore::books($db);
        return array_filter($pages,static function($key)use($books,$workspace){$b=$books[explode('/',$key,2)[0]]??null;return $b!==null&&self::publicBook($b,$workspace);},ARRAY_FILTER_USE_KEY);
    }
    public static function links(array $db,array $visible): array {
        return array_filter(PageTree::linkMap($db),static fn($target)=>isset($visible[$target]));
    }
    public static function media(array $db,string $id,string $workspace,string $role):bool {
        $member=Permissions::allows($role,'read');if(!$member&&$workspace!=='public')return false;
        $books=$member?[]:BookStore::books($db);
        foreach($db['pages'] as $key=>$entry){
            $p=$entry['published']??null;if(!$p||!in_array($id,$p['media']??[],true))continue;
            if($member)return true;
            $b=$books[explode('/',PageTree::publicKey($key,$entry),2)[0]]??null;if($b&&self::publicBook($b,$workspace))return true;
        }
        return false;
    }
}
