#!/usr/bin/env python3
"""Strict signed Compose overlay admission tests; live Docker gate remains separate."""
import copy
import importlib.util
from pathlib import Path
from unittest import mock

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('book_update_114',ROOT/'bin/update.py')
u=importlib.util.module_from_spec(spec);spec.loader.exec_module(u)


class ComposeFake:
    def __init__(self,extra=None,fail_inventory=False):
        self.tree=Path('/signed/release/tree');self.extra=extra
        self.fail_inventory=fail_inventory;self.checked=False
    def config(self,m):
        result={'services':{'book':{'image':'ghcr.io/test/book@sha256:'+'a'*64,'environment':{},'volumes':[]},
                            'worker':{'image':'ghcr.io/test/book@sha256:'+'a'*64,'environment':{},'volumes':[]}},'volumes':{}}
        if self.extra is not None:
            book=result['services']['book'];book['environment']['BOOK_UPDATE_AGENT_SOCKET']='/run/book-update/agent.sock'
            book['volumes'].append({'type':'bind','source':'/run/cajeerbook','target':'/run/book-update','read_only':True})
            if self.extra=='ports':book['ports']=['0.0.0.0:9999:80']
            if self.extra=='rw':book['volumes'][0]['read_only']=False
        return result
    def inventory(self,config):
        if self.fail_inventory: raise RuntimeError('Live Book container has not been reconciled')
        self.checked=True
    def check(self,m,sql):self.checked=True


def fixture(extra='',pending=None,live_failure=False):
    before=ComposeFake();after=ComposeFake(extra,live_failure)
    mgr=object.__new__(u.Manager)
    cfg={'project':'book','repository':'CajeerTeam/CajeerEngineBook','overlays':[],'env':'/tmp/ignored','env_sha':'a'*64}
    receipt={'version':'1.1.4','commit':'a'*40,'schema':7,'manifest':'b'*64,'id':'1.1.4-'+'a'*40}
    state={'config':cfg,'current':receipt,'pending':pending}
    def load(config,target):
        assert target is receipt
        return (after if 'update-agent' in config['overlays'] else before),{'images':{}}
    return mgr,state,load,after


def test(extra='',pending=None,live_failure=False,socket=True,success=True):
    mgr,state,load,after=fixture(extra,pending,live_failure)
    with mock.patch.object(u.Manager,'load',side_effect=load),mock.patch.object(u.Manager,'save') as save,\
         mock.patch.object(u.Path,'is_socket',return_value=socket):
        if success:
            result=mgr.enable_agent_overlay(state)
            assert result['status']=='adopted' and state['config']['overlays']==['update-agent']
            assert after.checked and save.call_count==1
        else:
            try:mgr.enable_agent_overlay(state)
            except (ValueError,RuntimeError):pass
            else:raise AssertionError('Unsafe Compose overlay accepted')
            assert state['config']['overlays']==[] and save.call_count==0


def main():
    test();print('PASS signed socket-only overlay adopted against live inventory')
    test(extra='ports',success=False);print('PASS unexpected port exposure refused')
    test(extra='rw',success=False);print('PASS read-write host socket mount refused')
    test(socket=False,success=False);print('PASS missing host update socket refused')
    test(live_failure=True,success=False);print('PASS unreconciled running container refused')
    test(pending={'phase':'migration'},success=False);print('PASS pending migration blocks topology adoption')


if __name__=='__main__':main()
