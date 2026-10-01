from pathlib import Path
import os,subprocess,shutil,json
from lxml import html
t=Path(__file__).resolve().parent;r=t.parent if t.name=='tests' else t.parents[1];rt=t/'runtime';(rt/'includes').mkdir(parents=True,exist_ok=True)
for p in (r/'includes').glob('*.php'):shutil.copyfile(p,rt/'includes'/p.name)
for name in ['reparti.php','centri_costo.php']:shutil.copyfile(r/name,rt/name)
(rt/'includes'/'hr_anagrafiche.php').write_text('<?php require_once '+repr(str(r/'includes'/'hr_anagrafiche.php'))+';')
php=os.environ.get('PHP_BINARY') or shutil.which('php')
if not php:raise SystemExit('Install PHP CLI with PDO or set PHP_BINARY')
env=dict(os.environ);args=[php]+os.environ.get('PHP_TEST_ARGS','').split()
for p in [*(r/'includes').glob('*.php'),*(r.glob('*.php')),t/'test_service.php']:
 q=subprocess.run([php,'-n','-l',str(p)],capture_output=True,text=True,env=env);assert q.returncode==0,q.stdout+q.stderr
q=subprocess.run(args+[str(t/'test_service.php')],capture_output=True,text=True,env=env);assert q.returncode==0,q.stdout+q.stderr;print(q.stdout.strip())
(rt/'includes'/'db.php').write_text('<?php function db(){return $GLOBALS["fixture_db"];}')
(rt/'includes'/'auth.php').write_text('''<?php function richiediPermessoLettura($p){if($p!=='profili_dipendenti')throw new Exception('Permission mismatch');if(($_SERVER['REQUEST_METHOD']??'')==='POST' && !empty($_SESSION['impersonazione_attiva'])){http_response_code(403);die('Visualizza come');}}function haPermessoScrittura($p){return !empty($GLOBALS['fixture_write']);}''')
(rt/'includes'/'layout.php').write_text('<?php function layoutHeader($title){echo "<html><body>";}function layoutFooter(){echo "</body></html>";}')
for page in ['reparti.php','centri_costo.php']:
 for scenario in ['read','readonly','bad_csrf','write_denied','impersonate','success']:
  out=t/(page+'.'+scenario+'.json');out.unlink(missing_ok=True)
  token='a'*64
  script='''<?php define('ANAGRAFICA_TEST_FIXTURE_ONLY',true);require '''+repr(str(t/'test_service.php'))+''';$GLOBALS['fixture_db']=new AnagraficaPDO();$_SESSION=['hr_anagrafiche_csrf'=>'''+repr(token)+'''];$GLOBALS['fixture_write']='''+('false' if scenario in ['readonly','write_denied'] else 'true')+''';$_SERVER['REQUEST_METHOD']='''+repr('GET' if scenario in ['read','readonly'] else 'POST')+''';$_POST=['csrf_token'=>'''+repr('wrong' if scenario=='bad_csrf' else token)+''','azione'=>'crea_voce','codice'=>'AMM','nome'=>'Amministrazione'];'''
  if scenario=='impersonate':script+="$_SESSION['impersonazione_attiva']=1;"
  script+="register_shutdown_function(function(){file_put_contents("+repr(str(out))+",json_encode(['status'=>http_response_code(),'writes'=>count($GLOBALS['fixture_db']->writes),'tables'=>$GLOBALS['fixture_db']->tables]));});require "+repr(str(rt/page))+";"
  f=t/'case.php';f.write_text(script)
  p=subprocess.run(args+[str(f)],capture_output=True,text=True,env=env);assert p.returncode==0,p.stdout+p.stderr;assert 'Warning' not in p.stdout,p.stdout
  data=json.loads(out.read_text());assert data['writes']==(1 if scenario=='success' else 0),(page,scenario,data)
  if scenario in ['write_denied','impersonate']:assert data['status']==403
  if scenario in ['read','readonly','bad_csrf']:
   d=html.fromstring(p.stdout)
   assert d.xpath('//a[@href="profili_dipendenti.php"]')
   assert len(d.xpath('//form'))==(0 if scenario=='readonly' else 1)
   if scenario=='bad_csrf':assert 'sessione del modulo' in d.text_content()
  print(page,scenario,'PASS')
print('ALL TESTS PASSED')
