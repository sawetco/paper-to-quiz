"""Publish one already-tested ZIP. Credentials never appear in output or manifests."""
import os,sys,subprocess,tempfile,pathlib,zipfile,hashlib,json,re,time,urllib.request,urllib.parse,shutil

BASE='https://plugins.svn.wordpress.org/paper-to-quiz'
def manifest(folder):
 return {p.relative_to(folder).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(folder.rglob('*')) if p.is_file() and '.svn' not in p.parts}
def unpack(source,destination):
 with zipfile.ZipFile(source) as z:
  names=set()
  for i in z.infolist():
   if i.filename in names or "\\" in i.filename or ".." in pathlib.PurePosixPath(i.filename).parts:raise RuntimeError("Duplicate or noncanonical package member")
   names.add(i.filename)
   if not i.filename.startswith('paper-to-quiz/') or not (destination/i.filename).resolve().is_relative_to(destination.resolve()) or (i.external_attr>>16)&0o170000==0o120000:raise RuntimeError('Unsafe package member')
  z.extractall(destination)
 return destination/'paper-to-quiz'
def svn(*args,write=False,allow_missing=False):
 cmd=['svn',*map(str,args),'--non-interactive','--no-auth-cache']
 if write:cmd+=['--username',os.environ['SVN_USERNAME'],'--password',os.environ['SVN_PASSWORD']]
 r=subprocess.run(cmd,capture_output=True,text=True)
 if r.returncode:
  if allow_missing and ('W160013' in r.stderr or 'E160013' in r.stderr):return None
  # Never emit a command line, authentication response, or credential-bearing diagnostic.
  raise RuntimeError('SVN operation failed; inspect repository access and retry the release')
 return r.stdout
def main():
 version=os.environ['RELEASE_VERSION']
 if not re.fullmatch(r'\d+\.\d+\.\d+',version):raise RuntimeError('Invalid version')
 if not os.environ.get('SVN_USERNAME') or not os.environ.get('SVN_PASSWORD'):raise RuntimeError('Configure SVN_USERNAME and SVN_PASSWORD repository secrets')
 source=pathlib.Path('paper-to-quiz.zip').resolve()
 with tempfile.TemporaryDirectory(prefix='ptq-release-') as tmp:
  root=pathlib.Path(tmp);package=unpack(source,root/'package');expected=manifest(package)
  if not expected or re.search(r'^Stable tag:\s*(\S+)',(package/'readme.txt').read_text(),re.M).group(1)!=version:raise RuntimeError('Package metadata mismatch')
  tag=f'{BASE}/tags/{version}'
  exists=svn('list',tag,allow_missing=True)
  if exists is not None:
   svn('export',tag,root/'existing');
   if manifest(root/'existing')!=expected:raise RuntimeError('Existing immutable SVN tag has different contents')
   print('Existing SVN tag contents match the tested package.')
  else:
   checkout=root/'svn';svn('checkout','--depth','empty',BASE,checkout)
   svn('update','--set-depth','infinity',checkout/'trunk')
   svn('update','--depth','empty',checkout/'tags')
   trunk=checkout/'trunk'
   old=re.search(r'^Stable tag:\s*(\S+)',(trunk/'readme.txt').read_text(),re.M)
   if old and re.fullmatch(r'\d+\.\d+\.\d+',old.group(1)) and tuple(map(int,old.group(1).split('.')))>tuple(map(int,version.split('.'))):raise RuntimeError('Refusing to downgrade SVN trunk')
   for relative in sorted(set(manifest(trunk))-set(expected)):
    target=(trunk/relative).resolve()
    if not target.is_relative_to(trunk.resolve()) or '.svn' in target.parts:raise RuntimeError('SVN deletion path escaped trunk')
    svn('delete','--force',target)
   for relative in expected:
    target=trunk/relative;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(package/relative,target)
   svn('add','--force',trunk)
   if manifest(trunk)!=expected:raise RuntimeError('SVN trunk differs from the tested package')
   svn('copy',trunk,checkout/'tags'/version)
   if manifest(checkout/'tags'/version)!=expected:raise RuntimeError('SVN tag differs from the tested package')
   svn('commit',checkout,'-m',f'Release paper-to-quiz {version}',write=True)
   print(f'SVN trunk and tags/{version} committed together.')
  for suffix in ['trunk',f'tags/{version}']:
   exported=root/('check-'+suffix.replace('/','-'));svn('export',f'{BASE}/{suffix}',exported)
   if manifest(exported)!=expected:raise RuntimeError('Published SVN content verification failed')
  for attempt in range(30):
   try:
    req=urllib.request.Request('https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=paper-to-quiz',headers={'User-Agent':'paper-to-quiz-release-verifier'})
    with urllib.request.urlopen(req,timeout=30) as response:info=json.load(response)
    if info.get('version')==version:
     url=info['download_link']
     if urllib.parse.urlparse(url).hostname not in ['downloads.wordpress.org']:raise RuntimeError('Unexpected download origin')
     with urllib.request.urlopen(url,timeout=60) as response:(root/'served.zip').write_bytes(response.read())
     served=unpack(root/'served.zip',root/'served')
     if manifest(served)!=expected:raise RuntimeError('WordPress.org package differs from the tested artifact')
     print('WordPress.org version and every served package file verified.')
     pathlib.Path('wordpress-release-manifest.json').write_text(json.dumps({'version':version,'svn_tag':tag,'wordpress_download':url,'zip_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'files':expected},indent=2)+'\n')
     return
   except (OSError,ValueError,KeyError):pass
   time.sleep(20)
  raise RuntimeError('SVN is published but the WordPress.org CDN is not verified yet; retry this same release')
if __name__=='__main__':
 try:main()
 except Exception as exc:print(f'Release verification failed: {exc}',file=sys.stderr);sys.exit(1)
