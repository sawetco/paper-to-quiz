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
if __name__=='__main__':unittest.main()
