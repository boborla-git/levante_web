import json, os, re, subprocess
from pathlib import Path
from html.parser import HTMLParser
here=Path(__file__).resolve().parent
root=here.parent.parent
php=[os.environ.get('PHP_BIN','php'),'-n','-d','extension='+os.environ.get('PHP_LIB_DIR','')+'/pdo.so'] if os.environ.get('PHP_LIB_DIR') else ['php','-n','-d','extension=pdo']
class Cells(HTMLParser):
 def __init__(self,html):
  super().__init__();self.cells=[];self.feed(html)
 def handle_starttag(self,tag,attrs):
  if tag=='div':
   a=dict(attrs)
   if 'hr-daycell' in a.get('class','') or 'hr-time-cell' in a.get('class','') or 'hr-closure-cell' in a.get('class',''):self.cells.append(a)
total=0
saved={}
def run(label,case):
 global total
 r=subprocess.run(php+[str(here/'fixture.php'),json.dumps(case)],capture_output=True,text=True)
 assert r.returncode==0,(label,r.stdout,r.stderr)
 d=json.loads(r.stdout)
 assert not d['fatal'] and not r.stderr,(label,d['fatal'],r.stderr)
 assert 'fixture-error' not in d['html'],(label,d['html'][:800])
 assert all(q['sql'].startswith('SELECT') for q in d['queries'])
 total+=1
 return d
cl=dict(descrizione='Ferie estive',data_da='2099-08-03',data_a='2099-08-25')
for view in ['mese','settimane','giorno']:
 d=run('closure only '+view,dict(view=view,closures=[cl]))
 assert 'Chiusure aziendali nel periodo' in d['html'] and 'Nessuna assenza o richiesta nel periodo' not in d['html']
 assert any(c.get('data-closure')=='1' for c in Cells(d['html']).cells)
 for all_ in [False,True]:
  d=run('scope '+view+str(all_),dict(view=view,closures=[cl],all=all_,admin=False))
  assert 'ABianchi' not in d['html'] and 'BIANCHI' not in d['html']
  cells=Cells(d['html']).cells
  if all_:
   assert any(c.get('data-user')=='1' and 'is-closure' in c.get('class','') for c in cells)
   assert any(c.get('data-user')=='2' and 'is-closure' in c.get('class','') for c in cells)
  assert 'Disponibile nel periodo selezionato' not in re.sub(r'<script>.*?</script>','',d['html'],flags=re.S)
 saved[view]=d
for day in ['2099-08-03','2099-08-25']:
 d=run('inclusive '+day,dict(view='giorno',date=day,closures=[cl],all=True))
 assert any('is-closure' in c.get('class','') for c in Cells(d['html']).cells)
for day in ['2099-08-02','2099-08-26']:
 d=run('outside '+day,dict(view='giorno',date=day,closures=[cl],all=True))
 assert not any('is-closure' in c.get('class','') for c in Cells(d['html']).cells)
d=run('deactivated',dict(closures=[cl|dict(attivo=0)],all=True))
assert not any('is-closure' in c.get('class','') for c in Cells(d['html']).cells)
d=run('event preserved, motive hidden',dict(view='giorno',closures=[cl],events=[{}],all=True,admin=False))
assert 'is-personal' in d['html'] and 'MOTIVO_RISERVATO' not in d['html']
saved['popup']=d
d=run('HR detail preserved',dict(view='giorno',closures=[cl],events=[{}],all=True,admin=True))
assert 'MOTIVO_RISERVATO' in d['html']
d=run('pending privacy preserved',dict(closures=[cl],events=[dict(codice_stato_richiesta='IN_ATTESA')],all=True,admin=False))
assert '"id":10' not in d['html']
d=run('script escaping',dict(closures=[cl|dict(descrizione='</script><img src=x onerror=bad>')],all=True))
assert '</script><img' not in d['html'] and '&lt;/script&gt;' in d['html']
d=run('denied',dict(denied=True,closures=[cl]))
assert d['status']==403 and 'Ferie estive' not in d['html']
print(f'Calendario: {total} scenari PASS, con pagine e helper reali, PDO sostituito.')
# Syntax-check all rendered scripts; execute actual popup logic with DOM fixture.
for d in saved.values():
 for js in re.findall(r'<script>(.*?)</script>',d['html'],re.S):
  r=subprocess.run(['node','--check'],input=js,capture_output=True,text=True)
  assert r.returncode==0,r.stderr
js=re.findall(r'<script>(.*?)</script>',saved['popup']['html'],re.S)[-1]
harness=r'''
const vm=require('vm');
const nodes={};
for(const id of ['hrDetailPop','hrDetailTitle','hrDetailBody','hrDetailClose'])nodes[id]={handlers:{},style:{},offsetHeight:220,classList:{add(){},remove(){},contains(){return false;}},addEventListener(e,f){this.handlers[e]=f;}};
const cell={dataset:{user:'2',day:'2099-08-10',slot:'480'},handlers:{},addEventListener(e,f){this.handlers[e]=f;},getBoundingClientRect(){return {left:10,bottom:30};}};
const context={window:{innerWidth:390,innerHeight:844},document:{getElementById(i){return nodes[i];},querySelectorAll(){return [cell];},addEventListener(){}}};
vm.createContext(context);vm.runInContext(SCRIPT,context);
cell.handlers.click();
if(!nodes.hrDetailBody.innerHTML.includes('Chiusura aziendale')||!nodes.hrDetailBody.innerHTML.includes('Assente'))throw Error('Closure and existing event must both be visible');
if(nodes.hrDetailBody.innerHTML.includes('MOTIVO_RISERVATO')||nodes.hrDetailBody.innerHTML.includes('Disponibile'))throw Error('Privacy/availability regression');
cell.dataset={closure:'1',day:'2099-08-10'};cell.handlers.click();
if(!nodes.hrDetailTitle.textContent.startsWith('Chiusura aziendale')||nodes.hrDetailBody.innerHTML.includes('Disponibile'))throw Error('Closure only popup');
console.log('Popup clic/tocco PASS.');
'''.replace('SCRIPT',json.dumps(js),1)
r=subprocess.run(['node','-e',harness],capture_output=True,text=True)
assert r.returncode==0,r.stderr
print(r.stdout.strip())
# Login backend is unchanged; button behavior uses the real page script.
source=(root/'login.php').read_text()
assert re.search(r'<button type="button" class="login-password-toggle hr-icon-btn"',source)
assert 'aria-controls="password"' in source and 'min-width:44px' in source
js=re.findall(r'<script>(.*?)</script>',source,re.S)[-1]
harness=r'''
const vm=require('vm');let submits=0;
const form={handlers:{},addEventListener(e,f){this.handlers[e]=f;}};
const campo={type:'password',name:'password',value:'Abc!é123',selectionStart:3,selectionEnd:3,form,focus(){this.focused=true;},setSelectionRange(a,b){this.selectionStart=a;this.selectionEnd=b;}};
const button={hidden:true,handlers:{},attrs:{},setAttribute(k,v){this.attrs[k]=v;},addEventListener(e,f){this.handlers[e]=f;}};
const show={hidden:false},hide={hidden:true};
const nodes={password:campo,'login-password-toggle':button,'login-eye-show':show,'login-eye-hide':hide};
const win={handlers:{},addEventListener(e,f){this.handlers[e]=f;}};
vm.runInNewContext(SCRIPT,{document:{getElementById(i){return nodes[i];}},window:win});
function check(x,m){if(!x)throw Error(m);}
check(!button.hidden && campo.type==='password','Initial masked');
button.handlers.click({detail:1});
check(campo.type==='text' && button.attrs['aria-pressed']==='true' && show.hidden && !hide.hidden,'Reveal');
check(campo.value==='Abc!é123' && campo.selectionStart===3 && campo.focused,'Preserve text/caret');
button.handlers.click({detail:0});check(campo.type==='password' && button.attrs['aria-label']==='Mostra password','Keyboard toggle');
button.handlers.click({detail:1});form.handlers.submit();check(campo.type==='password' && campo.value==='Abc!é123','Submit masks without changing value');
button.handlers.click({detail:1});win.handlers.pageshow();check(campo.type==='password','Back navigation masks');
console.log('Login mostra/nascondi PASS: mouse/tocco, tastiera, testo, cursore, invio e riapertura.');
'''.replace('SCRIPT',json.dumps(js),1)
r=subprocess.run(['node','-e',harness],capture_output=True,text=True)
assert r.returncode==0,r.stderr
print(r.stdout.strip())
