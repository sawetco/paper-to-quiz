"""Parse dependency data only. Never import or execute code from a pull request."""
import copy,json,re,sys,tomllib

def safe_version(a,b):
    if a==b:return True
    if not isinstance(a,str) or not isinstance(b,str):return False
    pattern=r'([~^=<> ]*)(\d+)\.(\d+)(?:\.(\d+))?'
    x,y=re.fullmatch(pattern,a),re.fullmatch(pattern,b)
    if not x or not y or x[1].strip()!=y[1].strip():return False
    old,new=tuple(int(v or 0) for v in x.groups()[1:]),tuple(int(v or 0) for v in y.groups()[1:])
    return new>=old and old[0]==new[0] and (old[0]!=0 or old[1]==new[1])

def cargo_manifest(a,b):
    if type(a)!=type(b):return False
    if isinstance(a,dict):
        if a.keys()!=b.keys():return False
        for key in a:
            if key in ('dependencies','dev-dependencies','build-dependencies'):
                if a[key].keys()!=b[key].keys():return False
                for name,old in a[key].items():
                    new=b[key][name]
                    if old==new:continue
                    if isinstance(old,str):
                        if not safe_version(old,new):return False
                    elif isinstance(old,dict) and isinstance(new,dict):
                        x,y=copy.deepcopy(old),copy.deepcopy(new)
                        if not safe_version(x.pop('version',None),y.pop('version',None)) or x!=y:return False
                    else:return False
            elif not cargo_manifest(a[key],b[key]):return False
        return True
    return a==b

def pip_requirements(text):
    logical=[];pending=''
    for line in text.splitlines():
        line=line.split('#',1)[0].strip()
        if not line:continue
        pending+=line.rstrip('\\').strip()+' '
        if not line.endswith('\\'):logical.append(pending.strip());pending=''
    if pending:raise ValueError('Incomplete requirement')
    out={}
    for line in logical:
        line=re.sub(r'\s+--hash=sha256:[a-f0-9]{64}(?=\s|$)','',line).strip()
        version,separator,marker=line.partition(';')
        match=re.fullmatch(r'([a-zA-Z0-9_.-]+)==(\d+\.\d+(?:\.\d+)?)',version.strip())
        if not match:raise ValueError('Unsupported executable or unpinned requirement')
        name=match[1].lower().replace('_','-')
        if name in out:raise ValueError('Duplicate requirement')
        out[name]=(match[2],marker.strip())
    return out

def classify(data):
    if data['kind']=='pip':
        a,b=pip_requirements(data['before']),pip_requirements(data['after'])
        return a.keys()==b.keys() and all(a[k][1]==b[k][1] and safe_version(a[k][0],b[k][0]) for k in a)
    if data['kind']=='composer':
        a,b=json.loads(data['before']),json.loads(data['after'])
        for section in ('require','require-dev'):
            x,y=a.pop(section,{}),b.pop(section,{})
            if x.keys()!=y.keys() or any(not safe_version(x[k],y[k]) for k in x):return False
        return a==b
    a,b=tomllib.loads(data['before']),tomllib.loads(data['after'])
    if data['path'].endswith('Cargo.toml'):return cargo_manifest(a,b)
    old,new=a.pop('package',[]),b.pop('package',[])
    if a!=b:return False
    # A crate may have several concurrently installed major versions. Match exact
    # versions first, then compare only unmatched versions from the same registry.
    def group(items):
        out={}
        for p in items:out.setdefault((p['name'],p.get('source','local')),[]).append(p['version'])
        return out
    x,y=group(old),group(new)
    for key in x.keys() & y.keys():
        before,after=x[key][:],y[key][:]
        for version in before[:]:
            if version in after:before.remove(version);after.remove(version)
        if len(before)!=len(after):return False
        if not all(safe_version(u,v) for u,v in zip(sorted(before),sorted(after))):return False
    # A source change for an existing crate is never a routine version update.
    for name in {k[0] for k in x} & {k[0] for k in y}:
        if {k[1] for k in x if k[0]==name}!={k[1] for k in y if k[0]==name}:return False
    return True

if __name__=='__main__':
    try:print('safe' if classify(json.load(sys.stdin)) else 'review')
    except (ValueError,TypeError,KeyError):print('review')
