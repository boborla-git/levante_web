from pathlib import Path
import json,subprocess
src=(Path(__file__).resolve().parents[2]/'includes/layout.php').read_text()
a=src.index('        function ricostruisciTipologie()');b=src.index('        function ensureAvvisoBox()',a)
fn=src[a:b]
js='''
function element(tag){return {tagName:tag.toUpperCase(),children:[],dataset:{},appendChild(x){this.children.push(x);},get options(){return this.children.flatMap(x=>x.tagName==='OPTGROUP'?x.children:[x]);},set innerHTML(x){this.children=[];}};}
const document={createElement:element};
const tipologia=element('select');tipologia.value='2';
const rules={1:{codice:'FERIE'},2:{codice:'CONGEDO_STRAORDINARIO_DISABILI'},3:{codice:'VISITA_CLIENTE'},4:{codice:'ALTRO'}};
const tipologieOriginali=[{value:'1',text:'Ferie',codice:'FERIE',gruppo:'Personali'},{value:'2',text:'Congedo',codice:'CONGEDO_STRAORDINARIO_DISABILI',gruppo:'Personali'},{value:'3',text:'Cliente',codice:'VISITA_CLIENTE',gruppo:'Lavoro'},{value:'4',text:'Altro',codice:'ALTRO',gruppo:''}];
const puoGestireMalattia=true,puoUsareAltro=true;function utenteHa104(){return true;}function utenteHaSmart(){return true;}
'''+fn+'''
ricostruisciTipologie();
if(tipologia.options.length!==3 ||tipologia.options.some(x=>x.dataset.codice==='ALTRO'))throw Error('Altro must be absent');
if(tipologia.children.length!==2||tipologia.children[0].label!=='Personali'||tipologia.children[1].label!=='Lavoro')throw Error('Groups lost');
if(tipologia.options[1].dataset.codice!=='CONGEDO_STRAORDINARIO_DISABILI'||tipologia.value!=='2')throw Error('Code/selection lost');
console.log('Ricostruzione select PASS: gruppi, codici, selezione e Altro assente.');
'''
r=subprocess.run(['node','-e',js],capture_output=True,text=True)
assert r.returncode==0,r.stderr
print(r.stdout.strip())
