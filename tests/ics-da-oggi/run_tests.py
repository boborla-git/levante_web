from pathlib import Path
import os,re,subprocess,json,shutil
p=Path(__file__).resolve();t=p.parent;r=t.parent if t.name=='tests' else t.parents[1];rt=t/'runtime';(rt/'includes').mkdir(parents=True,exist_ok=True)
php=os.environ.get('PHP_BINARY') or shutil.which('php');args=[php]+os.environ.get('PHP_TEST_ARGS','').split();env=dict(os.environ)
source=(t/'source/hr_calendario.php').read_text() if (t/'source/hr_calendario.php').exists() else (r/'includes/hr_calendario.php').read_text()
for name in ['hrCalendarioPermessiUtente','hrScopeUtentiCalendario']:source=re.sub(r'function '+name+r'\(.*?(?=\nfunction |\Z)','',source,flags=re.S)
(rt/'includes/hr_calendario.php').write_text(source)
(rt/'includes/db.php').write_text('<?php')
shutil.copy(r/'calendario_personale_ics.php',rt/'calendario_personale_ics.php')
for scenario in ['valid','bad_token','revoked','denied','failure']:
 out=t/'result.json';out.unlink(missing_ok=True)
 script='<?php require '+repr(str(t/'fixture.php'))+';$GLOBALS["scenario"]='+repr(scenario)+';$GLOBALS["fixture_db"]=new IcsPDO();$_GET=["utente"=>"Demo","token"=>'+repr('wrong' if scenario=='bad_token' else 'fixture-token')+'];register_shutdown_function(function(){file_put_contents('+repr(str(out))+',json_encode(["status"=>http_response_code(),"params"=>$GLOBALS["fixture_db"]->queryParams,"today"=>(new DateTimeImmutable("today",new DateTimeZone("Europe/Rome")))->format("Y-m-d")]));});require '+repr(str(rt/'calendario_personale_ics.php'))+';'
 f=t/'case.php';f.write_text(script);proc=subprocess.run(args+[str(f)],capture_output=True,text=True,env=env);assert proc.returncode==0 and not re.search(r'Warning|Fatal error',proc.stdout+proc.stderr),proc.stdout+proc.stderr
 data=json.loads(out.read_text());body=proc.stdout
 if scenario=='valid':
  ids={int(x) for x in re.findall(r'UID:levante-hr-\d+-(\d+)@',body)};assert ids=={2,3,4,6,8,9,11},ids
  assert data['params']==[1,2,'9999-12-31',data['today'],1,0,1],data
  assert 'Motivo riservato' not in next(e for e in body.split('BEGIN:VEVENT') if 'UID:levante-hr-3-3@' in e)
  assert 'STATUS:TENTATIVE' in body and 'DTSTART;VALUE=DATE' in body
  # Today includes all timed events, even when the morning has already passed.
  assert 'UID:levante-hr-2-2@' in body and 'DTSTART:' in body
  assert body.startswith('BEGIN:VCALENDAR\n') and body.endswith('END:VCALENDAR\n')
 else:assert data['status']==(503 if scenario=='failure' else 403) and 'BEGIN:VCALENDAR' not in body
 print(scenario,'PASS')
for s,expected in [('2026-10-06T22:30:00+00:00','2026-10-07'),('2026-12-31T23:30:00+00:00','2027-01-01')]:
 code='$d=new DateTimeImmutable('+repr(s)+');echo $d->setTimezone(new DateTimeZone("Europe/Rome"))->format("Y-m-d");'
 x=subprocess.run(args+['-r',code],capture_output=True,text=True,env=env);assert x.stdout==expected
print('Rome date boundary and DST PASS')
print('ALL TESTS PASSED')
