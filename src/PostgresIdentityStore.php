<?php
declare(strict_types=1);
namespace CajeerBook;

use PDO;

/** Identity records and role assignments are SQL-authoritative. The installation row serializes
 * authorization and identity changes, including scoped content dispatch and backup snapshots. */
final class PostgresIdentityStore
{
    private const COLLECTIONS=['users','sessions','resets','attempts','organizations','invitations','workspaces','activity'];
    private const TABLES=['users','organizations','workspaces','sessions','resets','attempts','invitations','activity','memberships','workspace_roles'];
    public function __construct(private readonly string $installation,private readonly string $binding) {}

    public static function forDirectory(string $root):?self
    {
        $binding=PostgresContentStore::identityBinding($root);
        return $binding===null?null:new self($binding[0],$binding[1]);
    }
    private static function query(PDO $pdo,string $sql,array $args=[]):\PDOStatement
    {
        $q=$pdo->prepare($sql);$q->execute($args);return $q;
    }
    private static function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    private static function decode(string $value):array
    {
        $r=json_decode($value,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($r))throw new \RuntimeException('Invalid PostgreSQL identity record.');return $r;
    }
    private static function owner(array $db):?string
    {
        foreach($db['users'] as $id=>$u)if($u['owner'])return $id;return null;
    }
    private function assertBinding(PDO $pdo):void
    {
        $actual=self::query($pdo,'SELECT binding FROM cajeer_book.content_installation WHERE installation=?',[$this->installation])->fetchColumn();
        if($actual!==$this->binding)throw new \RuntimeException('PostgreSQL identity installation binding mismatch.');
    }
    /** Hold the identity row lock across callbacks, even when they use another SQL content connection. */
    public function transaction(callable $fn,bool $shared=false):mixed
    {
        $pdo=PostgresContentStore::runtimeConnect();$pdo->beginTransaction();
        try{
            $this->assertBinding($pdo);$db=$this->load($pdo,true,$shared);
            $result=$fn($db,$pdo);$pdo->commit();return $result;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private function load(PDO $pdo,bool $lock=false,bool $shared=false):array
    {
        $state=self::query($pdo,'SELECT format,owner_anchor,metadata::text AS metadata FROM cajeer_book.identity_state WHERE installation=?'.($lock?($shared?' FOR SHARE':' FOR UPDATE'):''),[$this->installation])->fetch(PDO::FETCH_ASSOC);
        if(!$state||(int)$state['format']!==1)throw new \RuntimeException('PostgreSQL identity missing or unsupported; run offline installation migration.');
        $meta=self::decode($state['metadata']);$rows=[];
        foreach(self::TABLES as $table){
            $rows[$table]=[];
            $q=self::query($pdo,'SELECT record_key,position,value::text AS value FROM cajeer_book.identity_'.$table.' WHERE installation=? ORDER BY position',[$this->installation]);
            foreach($q as $r){
                if((int)$r['position']!==count($rows[$table]))throw new \RuntimeException('Invalid PostgreSQL identity record order.');
                $rows[$table][$r['record_key']]=self::decode($r['value']);
            }
        }
        $db=self::assemble($meta,$rows);
        if(self::owner($db)!==$state['owner_anchor'])throw new \RuntimeException('PostgreSQL identity owner anchor mismatch.');
        return $db;
    }
    /** Preserve PHP array order and optional keys; membership and role values have independent rows. */
    private static function split(array $db):array
    {
        $meta=$db;$rows=array_fill_keys(self::TABLES,[]);
        foreach(self::COLLECTIONS as $key)if(is_array($db[$key]??null)){$rows[$key]=$db[$key];$meta[$key]=[];}
        foreach($rows['organizations'] as $id=>&$o){
            $rolePositions=array_flip(array_keys($o['roles']??[]));
            foreach($o['members'] as $uid=>$joined)$rows['memberships'][$id.':'.$uid]=['organization'=>$id,'user'=>$uid,'joined'=>$joined,'role'=>$o['roles'][$uid]??null,'role_position'=>$rolePositions[$uid]??null];
            $o['members']=[];if(is_array($o['roles']??null))$o['roles']=[];
        }unset($o);
        foreach($rows['workspaces'] as $id=>&$w){
            foreach($w['roles']??[] as $uid=>$role)$rows['workspace_roles'][$id.':'.$uid]=['workspace'=>$id,'organization'=>$w['organization'],'user'=>$uid,'role'=>$role];
            if(is_array($w['roles']??null))$w['roles']=[];
        }unset($w);
        return [$meta,$rows];
    }
    private static function assemble(array $meta,array $rows):array
    {
        $db=$meta;
        foreach(self::COLLECTIONS as $key){
            if(is_array($db[$key]??null)){if($db[$key]!==[])throw new \RuntimeException('Invalid PostgreSQL identity metadata.');$db[$key]=$rows[$key];}
            elseif($rows[$key]!==[])throw new \RuntimeException('Unregistered PostgreSQL identity collection.');
        }
        foreach($db['organizations'] as $o)if(($o['members']??null)!==[]||($o['roles']??[])!==[])throw new \RuntimeException('Invalid SQL organization skeleton.');
        foreach($db['workspaces'] as $w)if(($w['roles']??[])!==[])throw new \RuntimeException('Invalid SQL workspace skeleton.');
        $roles=[];
        foreach($rows['memberships'] as $key=>$m){
            $oid=$m['organization'];$uid=$m['user'];
            if($key!==$oid.':'.$uid||!isset($db['organizations'][$oid])||!array_key_exists('members',$db['organizations'][$oid]))throw new \RuntimeException('Invalid SQL membership.');
            $db['organizations'][$oid]['members'][$uid]=$m['joined'];
            if($m['role']!==null){
                if(!is_array($db['organizations'][$oid]['roles']??null)||!is_int($m['role_position'])||isset($roles[$oid][$m['role_position']]))throw new \RuntimeException('Invalid SQL organization role order.');
                $roles[$oid][$m['role_position']]=[$uid,$m['role']];
            }elseif($m['role_position']!==null)throw new \RuntimeException('Invalid SQL role position.');
        }
        foreach($roles as $oid=>$ordered){ksort($ordered,SORT_NUMERIC);if(array_keys($ordered)!==range(0,count($ordered)-1))throw new \RuntimeException('Invalid SQL role order.');foreach($ordered as [$uid,$role])$db['organizations'][$oid]['roles'][$uid]=$role;}
        foreach($rows['workspace_roles'] as $key=>$r){
            $id=$r['workspace'];$uid=$r['user'];
            if($key!==$id.':'.$uid||!isset($db['workspaces'][$id])||$db['workspaces'][$id]['organization']!==$r['organization']||!is_array($db['workspaces'][$id]['roles']??null))throw new \RuntimeException('Invalid SQL workspace role.');
            $db['workspaces'][$id]['roles'][$uid]=$r['role'];
        }
        return $db;
    }
    /** Caller owns the transaction and lock. Update only changed entity records. */
    public function write(PDO $pdo,array $before,array $after):void
    {
        if(!$pdo->inTransaction())throw new \LogicException('Identity write outside transaction.');
        if($before===$after)return;
        [, $old]=self::split($before);[$meta,$new]=self::split($after);
        $owner=self::owner($after);$previous=self::owner($before);
        if($previous!==null&&$previous!==$owner)throw new \RuntimeException('Installation owner cannot change.');
        // Delete removed rows in reverse dependency order, then insert/update in dependency order.
        foreach(array_reverse(self::TABLES) as $table)foreach($old[$table] as $key=>$value)if(!array_key_exists($key,$new[$table]))self::query($pdo,'DELETE FROM cajeer_book.identity_'.$table.' WHERE installation=? AND record_key=?',[$this->installation,(string)$key]);
        foreach(self::TABLES as $table){
            $positions=array_flip(array_keys($old[$table]));$position=0;
            foreach($new[$table] as $key=>$value){
                if(!array_key_exists($key,$old[$table])||$old[$table][$key]!==$value||$positions[$key]!==$position)
                    self::query($pdo,'INSERT INTO cajeer_book.identity_'.$table.'(installation,record_key,position,value) VALUES(?,?,?,CAST(? AS json)) ON CONFLICT(installation,record_key) DO UPDATE SET position=EXCLUDED.position,value=EXCLUDED.value',[$this->installation,(string)$key,$position,self::json($value)]);
                $position++;
            }
        }
        self::query($pdo,'UPDATE cajeer_book.identity_state SET metadata=CAST(? AS json),owner_anchor=?,revision=revision+1 WHERE installation=?',[self::json($meta),$owner,$this->installation]);
    }
    public function auditReady(PDO $pdo):void{AuditLog::assertInstallation($pdo,$this->installation);}
    public function audit(PDO $pdo,array $events):void{AuditLog::append($pdo,$this->installation,'identity',$events);}
    public function auditFind(PDO $pdo,array $access,array $filters,int $limit):array{return AuditLog::find($pdo,$this->installation,$access,$filters,$limit);}
    /** Import on the same transaction as content or upgrade an existing 0.4.1 SQL installation. */
    public function import(PDO $pdo,array $db):void
    {
        $this->assertBinding($pdo);
        [$meta]=self::split($db);
        $n=self::query($pdo,'INSERT INTO cajeer_book.identity_state(installation,metadata) VALUES(?,CAST(? AS json)) ON CONFLICT DO NOTHING',[$this->installation,self::json($meta)])->rowCount();
        if($n===0){if($this->load($pdo,true)!==$db)throw new \RuntimeException('PostgreSQL identity already initialized with different data; refusing overwrite.');return;}
        // The state row is new; its skeleton contains no records.
        $this->write($pdo,$meta,$db);
        if($this->load($pdo,true)!==$db)throw new \RuntimeException('PostgreSQL identity import round-trip mismatch.');
    }
    public function initializeWorkspace(PDO $pdo,string $scope):void
    {
        (new PostgresContentStore($this->installation,$scope,$this->binding))->initializeInTransaction($pdo,['schema'=>1,'pages'=>[],'media'=>[],'books'=>[]]);
        AuditLog::initializeScope($pdo,$this->installation,$scope);
    }
}
