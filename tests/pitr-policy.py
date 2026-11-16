#!/usr/bin/env python3
import importlib.util
from pathlib import Path
import tempfile
import copy

root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('pitr_repo',root/'bin/pitr-repo.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
ID='20261116T010000Z-'+'a'*32
meta={'format':1,'id':ID,'point':'book_'+'a'*32,'backup':'20261116-000000F','system_id':'7850000000000000000','database':'book','namespace':'b'*32,'binding':'c'*32,'lsn':'0/3000200','wal':'000000010000000000000003','timeline':1,'captured_at':100}
info=[{'cipher':'aes-256-cbc','status':{'code':0},'db':[{'id':1,'repo-key':1,'system-id':7850000000000000000}],'backup':[{'label':meta['backup'],'database':{'id':1,'repo-key':1},'archive':{'stop':'000000010000000000000002'},'lsn':{'stop':'0/2000200'},'timestamp':{'stop':90}}]}]

def ok(v,label):
    if not v: raise AssertionError(label)
    print('PASS',label)

def deny(fn,label):
    try: fn()
    except (ValueError,RuntimeError,OSError): ok(True,label);return
    raise AssertionError('Unexpected success: '+label)

ok(m.meta(meta)==meta,'validated metadata')
ok(m.select_backup(info,meta)['label']==meta['backup'],'physical backup belongs to point cluster and timeline')
for field,value in [('id','../bad'),('point','other'),('timeline',2),('wal','bad'),('lsn','0/ABC;'),('system_id','bad'),('backup','--force'),('captured_at',0)]:
    deny(lambda:m.meta(dict(meta,**{field:value})),'reject '+field)
for label,edit in [('wrong cluster',lambda x:x[0]['db'][0].update({'system-id':1})),('expired backup',lambda x:x[0].update(backup=[])),('repository failure',lambda x:x[0]['status'].update(code=1)),('unencrypted repository',lambda x:x[0].update(cipher='none')),('different timeline',lambda x:x[0]['backup'][0]['archive'].update(stop='000000020000000000000002')),('backup after target',lambda x:x[0]['backup'][0]['lsn'].update(stop='0/4000000')),('clock mismatch',lambda x:x[0]['backup'][0]['timestamp'].update(stop=101))]:
    other=copy.deepcopy(info);edit(other);deny(lambda:m.select_backup(other,meta),label)
with tempfile.TemporaryDirectory() as t:
    p=Path(t)/'new';ok(m.new_directory(str(p))==p and p.is_dir(),'new private PGDATA created');deny(lambda:m.new_directory(str(p)),'existing PGDATA refused');(Path(t)/'link').symlink_to(p,target_is_directory=True);deny(lambda:m.new_directory(str(Path(t)/'link'/'new')),'symlink parent refused')
