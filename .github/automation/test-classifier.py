import importlib.util,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('classifier',Path(__file__).with_name('classify-manifest.py'))
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class DependencyPolicyTest(unittest.TestCase):
 def cargo(self,a,b):return module.classify(dict(kind='cargo',path='backend/Cargo.toml',before=a,after=b))
 def test_minor(self):self.assertTrue(self.cargo('[dependencies]\nserde="1.0.1"','[dependencies]\nserde="1.0.2"'))
 def test_major(self):self.assertFalse(self.cargo('[dependencies]\nserde="1.0.1"','[dependencies]\nserde="2.0.1"'))
 def test_feature_change(self):self.assertFalse(self.cargo('[dependencies]\na={version="1.0.1",features=["x"]}','[dependencies]\na={version="1.0.2",features=["y"]}'))
 def test_build_script(self):self.assertFalse(self.cargo('[package]\nbuild="a.rs"','[package]\nbuild="evil.rs"'))
 def test_target_dependency(self):self.assertTrue(self.cargo('[target."cfg(windows)".dependencies]\na="1.0.1"','[target."cfg(windows)".dependencies]\na="1.1.1"'))
 def test_zero_major(self):self.assertFalse(module.safe_version('0.7.1','0.8.0'))
 def test_range_widening(self):self.assertFalse(module.safe_version('^1.0.1','>=1.0.2'))
class PipPolicyTest(unittest.TestCase):
 def classify(self,a,b):return module.classify(dict(kind='pip',path='requirements.txt',before=a,after=b))
 def test_hashed_minor(self):self.assertTrue(self.classify('a==1.0.0 --hash=sha256:'+'a'*64,'a==1.1.0 --hash=sha256:'+'b'*64))
 def test_hash_does_not_hide_major(self):self.assertFalse(self.classify('a==1.0.0','a==2.0.0 --hash=sha256:'+'b'*64))
 def test_index_injection(self):
  with self.assertRaises(ValueError):self.classify('a==1.0.0','a==1.0.1 --index-url https://example.invalid')
 def test_new_dependency(self):self.assertFalse(self.classify('a==1.0.0','a==1.0.1\nb==1.0.0'))
if __name__=='__main__':unittest.main()
