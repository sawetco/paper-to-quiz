"""Offline distribution and retry tests; never connect to WordPress.org or SVN."""
import importlib.util,io,json,os,shutil,tempfile,unittest,zipfile
from pathlib import Path
from unittest.mock import patch
spec=importlib.util.spec_from_file_location('publisher',Path(__file__).with_name('publish-wordpress.py'))
p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)

class PublicationTest(unittest.TestCase):
 def test_traversal_duplicate_and_symlink_are_rejected(self):
  for names,symlink in [(['paper-to-quiz/../../outside'],False),(['paper-to-quiz/a','paper-to-quiz/a'],False),(['paper-to-quiz/link'],True),(['paper-to-quiz/..\\outside'],False)]:
   with self.subTest(names=names),tempfile.TemporaryDirectory() as d:
    root=Path(d);archive=root/'bad.zip'
    with zipfile.ZipFile(archive,'w') as z:
     for name in names:
      info=zipfile.ZipInfo(name)
      if symlink:info.external_attr=0o120777<<16
      z.writestr(info,'fixture')
    with self.assertRaises(RuntimeError):p.unpack(archive,root/'out')
    self.assertFalse((root/'outside').exists())

 def replay(self,different=False,cdn_failure=False):
  with tempfile.TemporaryDirectory() as d:
   root=Path(d);old=Path.cwd();os.chdir(root)
   try:
    package=root/'fixture';package.mkdir();(package/'readme.txt').write_text('Stable tag: 1.2.1\n');(package/'plugin.php').write_text('<?php // fixture\n')
    with zipfile.ZipFile('paper-to-quiz.zip','w') as z:
     for item in package.iterdir():z.write(item,'paper-to-quiz/'+item.name)
    calls=[]
    def svn(*args,**kwargs):
     calls.append((args,kwargs))
     self.assertFalse(kwargs.get('write',False),'An existing tag must never be overwritten')
     if args[0]=='list':return 'readme.txt\nplugin.php'
     if args[0]=='export':
      shutil.copytree(package,Path(args[2]))
      if different and str(args[2]).endswith('existing'):(Path(args[2])/'plugin.php').write_text('different')
      return ''
     self.fail('Unexpected SVN mutation')
    def urlopen(request,**kwargs):
     if cdn_failure:raise OSError('CDN not ready')
     if isinstance(request,str):return io.BytesIO(Path('paper-to-quiz.zip').read_bytes())
     return io.BytesIO(json.dumps(dict(version='1.2.1',download_link='https://downloads.wordpress.org/plugin/paper-to-quiz.1.2.1.zip')).encode())
    with patch.dict(os.environ,dict(RELEASE_VERSION='1.2.1',SVN_USERNAME='synthetic-user',SVN_PASSWORD='synthetic-password')),patch.object(p,'svn',svn),patch.object(p.urllib.request,'urlopen',urlopen),patch.object(p.time,'sleep'):
     if different:
      with self.assertRaisesRegex(RuntimeError,'different contents'):p.main()
     elif cdn_failure:
      with self.assertRaisesRegex(RuntimeError,'CDN is not verified'):p.main()
     else:
      p.main();self.assertEqual(json.loads(Path('wordpress-release-manifest.json').read_text())['version'],'1.2.1')
    return calls
   finally:os.chdir(old)

 def test_same_version_can_resume_without_writing(self):self.replay()
 def test_different_existing_tag_stops(self):self.replay(different=True)
 def test_cdn_delay_never_reports_completion(self):self.replay(cdn_failure=True)
if __name__=='__main__':unittest.main()
