import json, os, re, subprocess
from pathlib import Path
here = Path(__file__).resolve().parent
php = [os.environ.get('PHP_BIN','php'), '-n', '-d', 'extension='+os.environ.get('PHP_LIB_DIR','')+'/pdo.so'] if os.environ.get('PHP_LIB_DIR') else ['php','-n','-d','extension=pdo']
case = dict(role='hr',closures=[dict(data_da='2099-06-10',data_a='2099-06-15')])
r = subprocess.run(php+[str(here/'fixture.php'),json.dumps(case)],capture_output=True,text=True,check=True)
d=json.loads(r.stdout)
assert not d['error'] and not d['fatal']
script=re.findall(r'<script>(.*?)</script>',d['html'],re.S)[-1]
harness=r'''
const vm = require('vm');
const nodes = {};
const ids = ['modalita','id_tipologia_evento','gruppo_data_a','gruppo_ora_da','gruppo_ora_a','data_da','data_a','ora_da','ora_a','ora_da_ore','ora_da_minuti','ora_a_ore','ora_a_minuti','label_data_da','form-richiesta-assenza'];
for(const id of ids) nodes[id]={value:'',required:false,min:'',handlers:{},classList:{toggle(){}},addEventListener(e,fn){this.handlers[e]=fn;}};
const days={disabled:false};
nodes.modalita.value='giorni';
nodes.modalita.querySelector=()=>days;
nodes.id_tipologia_evento.selectedOptions=[{dataset:{codice:'ALLATTAMENTO'}}];
const alerts=[];
const context={window:{},document:{getElementById(id){return nodes[id]||null;}},alert(x){alerts.push(x);}};
vm.createContext(context);
vm.runInContext(SCRIPT,context);
function assert(x,m){if(!x)throw Error(m);}
assert(nodes.modalita.value==='ore' && days.disabled,'Allattamento deve forzare ore');
assert(nodes.ora_da.required && nodes.ora_a.required && !nodes.data_a.required,'Campi ore obbligatori');
nodes.id_tipologia_evento.selectedOptions[0].dataset.codice='FERIE';
nodes.id_tipologia_evento.handlers.change();
assert(!days.disabled,'Giorni di nuovo disponibili per Ferie');
nodes.modalita.value='giorni';nodes.modalita.handlers.change();
assert(nodes.data_a.required && !nodes.ora_da.required,'Campi giorni');
nodes.data_da.value='2099-06-01';nodes.data_a.value='2099-06-30';
let blocked=false;nodes['form-richiesta-assenza'].handlers.submit({preventDefault(){blocked=true;}});
assert(blocked && alerts.length===1,'Intervallo che attraversa chiusura deve essere bloccato');
nodes.data_a.value='2099-06-09';blocked=false;
nodes['form-richiesta-assenza'].handlers.submit({preventDefault(){blocked=true;}});
assert(!blocked,'Intervallo esterno deve essere consentito');
console.log('JavaScript modulo PASS: ore/giorni e chiusure negli intervalli.');
'''.replace('SCRIPT',json.dumps(script),1)
r=subprocess.run(['node','-e',harness],capture_output=True,text=True)
assert r.returncode==0,(r.stdout,r.stderr)
print(r.stdout.strip())
