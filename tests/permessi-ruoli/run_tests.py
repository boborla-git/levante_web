import json,os,subprocess
from pathlib import Path
here=Path(__file__).resolve().parent
php=[os.environ.get('PHP_BIN','php'),'-n','-d','extension='+os.environ.get('PHP_LIB_DIR','')+'/pdo.so']if os.environ.get('PHP_LIB_DIR')else['php','-n','-d','extension=pdo']
def run(case):
 r=subprocess.run(php+[str(here/'fixture.php'),json.dumps(case)],capture_output=True,text=True)
 assert r.returncode==0,(r.stdout,r.stderr)
 d=json.loads(r.stdout);assert not d['fatal'],d['fatal'];return d
x=run({'mode':'levels'});print(x['html'])
x=run({});assert 'Impostazione precedente: Scrittura. Accesso effettivo: Nessuno.'in x['html']
assert 'value="none" aria-label="Nessun accesso per Assenze" checked'in x['html']
other=x['map']['8']
for level,expected in [('none',(0,0)),('read',(1,0)),('write',(1,1))]:
 for menu in [True,False]:
  d=run({'post':True,'level':level,'menu':menu});assert d['committed']and not d['rolledBack']
  assert d['map']['8']==other
  p=d['map']['7']['2'];assert(p['read'],p['write'],p['view'],p['edit'])==expected+expected
  assert p['menu']==int(menu)
  assert d['map']['7']['1']=={'menu':1},'Pure menu must have no access grants'
  assert d['map']['7']['3']=={'read':0,'write':0,'view':0,'edit':0},'Reserved action remains denied'
  assert all(w['id_ruolo']==7 for w in d['writes'])
d=run({'post':True,'level':'write','menu':True,'fail':True});assert d['rolledBack']and not d['committed'];assert d['map']==x['map']
print('Editor e salvataggio PASS: disallineamento, 3 livelli, menu indipendente, altro ruolo invariato, azioni negate e rollback.')
