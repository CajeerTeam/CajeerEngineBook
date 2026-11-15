#!/usr/bin/env python3
"""CI-only authoritative TXT fixture. No recursion; exact owner records from mounted JSON."""
import json, socket, struct
from pathlib import Path

def reply(packet):
    if len(packet)<12 or struct.unpack_from('!H',packet,4)[0]!=1: return None
    labels=[];i=12
    while i<len(packet):
        n=packet[i];i+=1
        if n==0:break
        if n>63 or i+n>len(packet):return None
        labels.append(packet[i:i+n].decode('ascii').lower());i+=n
    if i+4>len(packet):return None
    qtype,qclass=struct.unpack_from('!HH',packet,i);end=i+4
    records=json.loads(Path('/records/records.json').read_text())
    value=records.get('.'.join(labels));answer=b''
    if qtype==16 and qclass==1 and isinstance(value,str):
        txt=value.encode('ascii');assert len(txt)<=255
        answer=b'\xc0\x0c'+struct.pack('!HHIH',16,1,0,len(txt)+1)+bytes([len(txt)])+txt
    return packet[:2]+struct.pack('!HHHHH',0x8180,1,int(bool(answer)),0,0)+packet[12:end]+answer

with socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as server:
    server.bind(('0.0.0.0',53))
    while True:
        data,peer=server.recvfrom(4096)
        try:
            response=reply(data)
            if response:server.sendto(response,peer)
        except (ValueError,OSError,UnicodeError,struct.error):pass
