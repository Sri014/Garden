#!/usr/bin/env python3
import re, json, urllib.request
from pathlib import Path

PARTNER='https://raw.githubusercontent.com/Sri014/3502/main/playlist_working.m3u'
W=Path('working.m3u'); N=Path('non_working.m3u'); R=Path('cross_sync.json')
def norm(s):
 s=s.lower().strip(); s=re.sub(r'\s*[\[(]?\s*(?:\d{3,4}p|hd|sd)\s*[\])]?$','',s); return re.sub(r'[^a-z0-9]+',' ',s).strip()
def parse(t):
 a=t.splitlines(); out=[]; i=0
 while i<len(a):
  if a[i].startswith('#EXTINF:'):
   info=a[i]; url=a[i+1].strip() if i+1<len(a) and not a[i+1].startswith('#') else ''; name=info.rsplit(',',1)[-1].strip() if ',' in info else ''; out.append([info,url,name]); i+=2
  else:i+=1
 return out
def render(a): return '#EXTM3U\n'+''.join(f'{x}\n{u}\n' for x,u,_ in a)
def fetch(u):
 q=urllib.request.Request(u,headers={'User-Agent':'Mozilla/5.0'}); return urllib.request.urlopen(q,timeout=30).read().decode('utf-8','replace')
w=parse(W.read_text(encoding='utf-8') if W.exists() else '#EXTM3U\n'); n=parse(N.read_text(encoding='utf-8') if N.exists() else '#EXTM3U\n'); p=parse(fetch(PARTNER)); pm={norm(x):u for _,u,x in p if norm(x) and u}; wn={norm(x) for _,_,x in w}; keep=[]; repaired=[]
for info,url,name in n:
 k=norm(name); u=pm.get(k)
 if u and k not in wn: w.append([info,u,name]); wn.add(k); repaired.append({'channel':name,'url':u})
 else: keep.append([info,url,name])
W.write_text(render(w),encoding='utf-8'); N.write_text(render(keep),encoding='utf-8'); R.write_text(json.dumps({'source':PARTNER,'repaired':len(repaired),'channels':repaired},indent=2,ensure_ascii=False),encoding='utf-8'); print(f'Garden cross-sync: repaired {len(repaired)} channels from 3502')
