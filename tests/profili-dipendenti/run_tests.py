from pathlib import Path
import os,shutil,subprocess,json,re
from html.parser import HTMLParser
root=Path(__file__).resolve().parent;t=root;r=t.parent if t.name=='tests' else t.parents[1];rt=t/'runtime';(rt/'includes').mkdir(parents=True,exist_ok=True)
php=os.environ.get('PHP_BINARY') or shutil.which('php');args=[php]+os.environ.get('PHP_TEST_ARGS','').split();env=dict(os.environ)
def run(p):
 x=subprocess.run(args+[str(p)],text=True,capture_output=True,env=env)
 assert x.returncode==0 and not re.search(r'Warning|Fatal error|Deprecated',x.stdout+x.stderr),x.stdout+x.stderr
 return x.stdout
for f in ['profili_dipendenti.php','relazioni_organizzative.php','includes/hr_organizzazione.php']:
 x=subprocess.run([php,'-n','-l',str(r/f)],capture_output=True,text=True,env=env);assert x.returncode==0,x.stdout+x.stderr
print(run(t/'test_service.php').strip())
for f in ['profili_dipendenti.php','relazioni_organizzative.php']:shutil.copy(r/f,rt/f)
shutil.copy(r/'includes/hr_organizzazione.php',rt/'includes/hr_organizzazione.php')
(rt/'includes/db.php').write_text('<?php function db(){return $GLOBALS["fixture_db"];}')
(rt/'includes/auth.php').write_text('''<?php function richiediPermessoLettura($p){if(!in_array($p,['profili_dipendenti','configurazione_assenze']))throw new Exception('Wrong permission');if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&!empty($GLOBALS['fixture_impersonate'])){http_response_code(403);die('Visualizza come');}}function haPermessoScrittura($p){return !empty($GLOBALS['fixture_write']);}''')
(rt/'includes/layout.php').write_text('<?php function layoutHeader($s){echo "<html><body>";}function layoutFooter(){echo "</body></html>";}')
(rt/'includes/badge.php').write_text('<?php function renderHrStatusBadge($s,$label){return htmlspecialchars($label);} ')
class Document(HTMLParser):
 def __init__(self):super().__init__();self.tags=[]
 def handle_starttag(self,t,a):self.tags.append((t,dict(a)))
scenarios={'profili_dipendenti.php':['get','readonly','selected','filter','filter_empty','inactive_hr','inactive_account','csrf','denied','impersonate','save','invalid_date','invalid_department','conflict','create_missing'], 'relazioni_organizzative.php':['get','csrf','denied','impersonate','save','conflict','invalid_date','close']}
for page,cases in scenarios.items():
 for scenario in cases:
  out=t/'case-result.json';out.unlink(missing_ok=True)
  pre='''<?php require '''+repr(str(t/'fixture.php'))+''';$GLOBALS['fixture_db']=new OrgPDO();$d=$GLOBALS['fixture_db'];$d->relations=[1=>orgRow(1)];$_SESSION=['hr_organizzazione_csrf'=>str_repeat('a',64)];$_SERVER['REQUEST_METHOD']='GET';$_GET=[];$_POST=[];$GLOBALS['fixture_write']=true;$GLOBALS['fixture_impersonate']=false;'''
  if scenario=='readonly':pre+="$GLOBALS['fixture_write']=false;$_GET=['profilo'=>1];"
  if scenario in ['selected','inactive_hr','inactive_account']:pre+="$_GET=['profilo'=>1];"
  if scenario=='inactive_hr':pre+="$d->profiles[1]['attivo']=0;"
  if scenario=='inactive_account':pre+="$d->users[1]['attivo']=0;"
  if scenario=='filter':pre+="$_GET=['q'=>'ROSSI','reparto'=>1,'centro'=>1,'stato'=>'attivi'];"
  if scenario=='filter_empty':pre+="$_GET=['q'=>'Does not exist'];"
  if scenario in ['csrf','denied','impersonate','save','invalid_date','invalid_department','conflict','create_missing','close']:
   pre+="$_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf_token'=>str_repeat('a',64),'azione'=>'salva_profilo','id_profilo_dipendente'=>1,'matricola'=>'10','mansione'=>'Tecnico aggiornato','id_reparto'=>1,'id_centro_costo'=>1,'id_responsabile'=>3,'attivo'=>'1','data_assunzione'=>'2026-01-01'];"
   if page=='relazioni_organizzative.php':pre+="$_POST=array_merge($_POST,['azione'=>'nuova_relazione','id_utente'=>1,'id_utente_collegato'=>3,'id_tipo_relazione'=>1,'data_inizio'=>'2026-10-01','data_fine'=>'']);"
   if scenario=='csrf':pre+="$_POST['csrf_token']='invalid';"
   if scenario=='denied':pre+="$GLOBALS['fixture_write']=false;"
   if scenario=='impersonate':pre+="$GLOBALS['fixture_impersonate']=true;"
   if scenario=='invalid_date':pre+="$_POST['data_assunzione']='2026-02-30';$_POST['data_inizio']='2026-02-30';"
   if scenario=='invalid_department':pre+="$_POST['id_reparto']=999;"
   if scenario=='conflict':pre+="$d->relations[2]=orgRow(2,2,'2026-10-15');"
   if scenario=='create_missing':pre+="$_POST['azione']='crea_profili_mancanti';"
   if scenario=='close':pre+="$_POST['azione']='chiudi_relazione';$_POST['id_relazione_organizzativa']=1;"
  pre+="register_shutdown_function(function(){file_put_contents("+repr(str(out))+",json_encode(['writes'=>$GLOBALS['fixture_db']->writes,'profiles'=>$GLOBALS['fixture_db']->profiles,'relations'=>$GLOBALS['fixture_db']->relations,'tx'=>$GLOBALS['fixture_db']->tx,'status'=>http_response_code()]));});require "+repr(str(rt/page))+";"
  script=t/'case.php';script.write_text(pre);body=run(script);data=json.loads(out.read_text())
  if scenario in ['save','create_missing','close']:assert len(data['writes'])>0,(page,scenario,body)
  else:assert not data['writes'],(page,scenario,data,body)
  assert not data['tx'],(page,scenario,'unclosed transaction')
  if scenario=='save':assert data['relations']['1']['data_fine']=='2026-09-30'
  if scenario=='conflict':assert data['relations']['1']['data_fine'] is None and data['profiles']['1']['mansione']=='Tecnico'
  if scenario=='create_missing':assert len(data['profiles'])==3
  if scenario=='impersonate':assert data['status']==403
  if scenario in ['selected','inactive_hr','inactive_account','readonly']:
   doc=Document();doc.feed(body);forms=[a for tag,a in doc.tags if tag=='input' and a.get('name')=='azione' and a.get('value')=='salva_profilo'];assert len(forms)==1
   if scenario in ['readonly','inactive_account']:assert 'readonly' in next(a for tag,a in doc.tags if tag=='input' and a.get('name')=='mansione')
   if scenario=='inactive_hr':assert 'readonly' not in next(a for tag,a in doc.tags if tag=='input' and a.get('name')=='mansione')
  if scenario=='filter_empty':assert 'Nessun profilo corrisponde' in body
  if scenario=='csrf':assert 'modulo è scaduto' in body
  if scenario=='get' and page=='profili_dipendenti.php':assert 'hr-directory-table' in body and 'hr-profile-card' not in body
  print(page,scenario,'PASS')
print('ALL TESTS PASSED')
