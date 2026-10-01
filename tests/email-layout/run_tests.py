from pathlib import Path
import os,subprocess,shutil,re,email,email.policy
from email.header import decode_header
from lxml import html
t=Path(__file__).resolve().parent; r=t.parent
if r.name=='tests':r=r.parent
out=t/'output'
shutil.rmtree(out,ignore_errors=True);out.mkdir()
rt=t/'runtime'/'includes';rt.mkdir(parents=True,exist_ok=True)
for p in (r/'includes').glob('*.php'):shutil.copyfile(p,rt/p.name)
(rt/'db.php').write_text('<?php // Fixture: nessuna connessione al DB.\n')
php=os.environ.get('PHP_BINARY') or shutil.which('php') or '/tmp/levante-php/root/usr/bin/php8.3'
fixture_cli=[]; env=dict(os.environ)
if php.startswith('/tmp/levante-php/'):
 env['LD_LIBRARY_PATH']='/tmp/levante-php/root/usr/lib/x86_64-linux-gnu'
 fixture_cli=['-n','-d','extension=/tmp/levante-php/root/usr/lib/php/20230831/pdo.so']
for p in (r/'includes').glob('*.php'):
 proc=subprocess.run([php,'-n','-l',str(p)],text=True,capture_output=True,env=env);assert proc.returncode==0,proc.stdout+proc.stderr
proc=subprocess.run([php,*fixture_cli,'-d',f'sendmail_path=/usr/bin/python3 {t}/capture_mail.py',str(t/'fixture.php')],capture_output=True,text=True,env=env)
assert proc.returncode==0,proc.stdout+proc.stderr
assert 'Warning' not in proc.stdout and 'Deprecated' not in proc.stdout,proc.stdout
print(proc.stdout.strip())
# Verifica invarianti: query, destinatari e contenuti sorgente fuori dal rendering/trasporto.
def block(text,name):
 a=text.index("if (!function_exists('"+name+"'))");b=text.find("if (!function_exists('",a+10);return text[a:b if b!=-1 else len(text)]
if (r/'baseline').exists():
 for f,names in {'hr_email.php':['hrEmailConfig','hrEmailDestinatariUtenti','hrEmailRichiestaDettaglio','hrEmailRichiestePresentiNelPeriodo','hrEmailNomeUtente','hrEmailPeriodoTesto','hrEmailStatoBadge'], 'hr_riepilogo_assenze.php':['hrRiepilogoAssenzeTimezone','hrRiepilogoAssenzeNow','hrRiepilogoAssenzeConfigAttiva','hrRiepilogoAssenzeEmailLavoro','hrRiepilogoAssenzeEmailAdmin','hrRiepilogoAssenzeBccAdminAttiva','hrRiepilogoAssenzeDestinatari','hrRiepilogoAssenzeRighe','hrRiepilogoAssenzePeriodo','hrRiepilogoAssenzeMotivo','hrRiepilogoAssenzeGiaInviata','hrRiepilogoAssenzeLog','hrRiepilogoAssenzeRichiestaIncludeData','hrRiepilogoAssenzeInviaAggiornamentoSeNecessario']}.items():
  old=(r/'baseline'/'includes'/f).read_text();new=(r/'includes'/f).read_text()
  for name in names:assert block(old,name).strip()==block(new,name).strip(),name
if (r/'baseline').exists():print('Query, scope, privacy, stati, date, destinatari e dedup invariati: PASS')
for p in out.glob('*.html'):
 d=html.fromstring(p.read_text());texts=' '.join(d.itertext())
 assert d.xpath('//h1[text()="Portale HR Ravioli S.p.A."]'),p.name
 foot=d.xpath('//td[contains(text(),"Messaggio automatico del Portale HR Ravioli S.p.A.")]');assert len(foot)==1,p.name
 assert 'font-size:12px' in foot[0].get('style') and 'font-family:Arial,Helvetica,sans-serif' in foot[0].get('style')
 assert not d.xpath('//script')
 assert not re.search(r'(?<![\w-])font\s*:',p.read_text()),p.name
 for td in d.xpath('//table[thead]//td|//table[thead]//th'):assert 'font-family:Arial,Helvetica,sans-serif' in td.get('style',''),p.name
 if p.name.startswith('riepilogo_') and 'vuoto' not in p.name:
  assert len(d.xpath('//thead//th'))==(4 if p.name=='riepilogo_hr.html' else 3)
  assert 'Visita fornitore - Gias' in texts
  for row in d.xpath('//tbody/tr'):assert len(row.xpath('./td'))==(4 if p.name=='riepilogo_hr.html' else 3)
print('Layout condiviso, celle e footer coerenti, HTML escapato: PASS')
files=sorted((out/'mail').glob('*.eml'));assert len(files)==17,len(files)
messages=[]
fixture_names=['ricevuta','da_approvare','approvata','automatica','informativa','rifiutata','annullata']
for p in files:
 raw=p.read_bytes();m=email.message_from_bytes(raw,policy=email.policy.default);messages.append(m)
 assert m.get_content_type()=='text/html' and m.get_content_charset()=='utf-8'
 assert m['Content-Transfer-Encoding']=='base64'
 payload=m.get_payload(decode=True);payload.decode('utf-8');
 if len(messages)<=7:assert payload==(out/(fixture_names[len(messages)-1]+'.html')).read_bytes(),p
 body=raw.split(b'\r\n\r\n',1)[-1]
 assert max(map(len,body.splitlines()))<=76,(p,max(map(len,body.splitlines())))
 assert '< td' not in payload.decode() and '< tr' not in payload.decode()
 d=html.fromstring(payload);assert d.xpath('//h1')
 if 'Assenze del' in str(m['Subject']):
  if m['To']=='person3@example.invalid': assert 'Demo Pendente' in d.text_content() and len(d.xpath('//thead//th'))==4
  elif m['To']!='person99@example.invalid':assert 'Demo Pendente' not in d.text_content() and len(d.xpath('//thead//th'))==3
# BCC una volta per livello, esclusa dalle prove dirette admin.
assert sum(bool(m['Bcc']) for m in messages)==5
for m in messages:
 if m['Bcc']:assert str(m['Bcc'])=='person99@example.invalid'
long=(out/'header_original.txt').read_text();encoded=(out/'header_encoded.txt').read_text()
decoded=''.join(x.decode(ch or 'ascii') if isinstance(x,bytes) else x for x,ch in decode_header(encoded))
assert decoded==long.strip() and max(map(len,encoded.splitlines()))<=75
print(f'MIME reale mail(): {len(files)} messaggi, UTF-8, base64 <=76 byte, header senza mbstring, BCC: PASS')
# Regressione riproducibile: markup vecchio su una sola riga supera la soglia SMTP.
# La nuova codifica ricostruisce tutti i byte anche se gli a-capo di trasporto cambiano.
import base64
for m in messages:
 encoded=m.get_payload();assert base64.b64decode(encoded.replace('\r\n','\n'))==m.get_payload(decode=True)
print('Round-trip con newline del trasporto: PASS')
verification=messages[-1].get_payload(decode=True).decode();(out/'verifica_email.html').write_text(verification)
native=[]
for f in r.rglob('*.php'):
 if any(x in f.relative_to(r).parts for x in ['tests','baseline']):continue
 for match in re.finditer(r'@?\bmail\(\s*\$',f.read_text()):native.append(str(f.relative_to(r)))
assert native==['includes/hr_email.php'],native
print('Unico trasporto mail() in tutto il progetto: PASS')
print('ALL TESTS PASSED')
