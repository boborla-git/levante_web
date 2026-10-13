import json
import os
from pathlib import Path
import shlex
import subprocess

HERE = Path(__file__).parent
PHP = shlex.split(os.environ.get('PHP_CMD', 'php'))
checks = 0

def run(case):
    p = subprocess.run(PHP + [str(HERE / 'page-fixture.php'), json.dumps(case)], text=True, capture_output=True, check=True)
    result = json.loads(p.stdout)
    assert result['fatal'] is None, result['fatal']
    return result

def check(condition, label):
    global checks
    assert condition, label
    checks += 1

base = dict(azione='anteprima', azione_programma='crea', id_utente=1,
            data_da='2026-10-13', data_a='2027-03-06', giorni=[1,2,3,4,5],
            ora_da=['08:00','16:00'], ora_a=['09:00','17:00'])
for role in ['hr', 'admin']:
    r = run(dict(role=role))
    check(not r['error'] and 'Mostra anteprima' in r['html'], 'form HR/admin')
    check('2027-03-06' in r['html'], 'termine dal beneficio')
    r = run(dict(role=role, method='POST', post=base))
    check('208</strong> fasce da registrare' in r['html'], 'anteprima completa')
    check('Conferma 208 inserimenti e 0 annullamenti' in r['html'], 'conferma concreta')
    check(r['richieste']==0 and r['pending']==1, 'anteprima senza scritture DB')
    r = run(dict(role=role, method='POST', confirm=True))
    check(r['richieste']==208 and r['programmi']==1 and not r['error'], 'salvataggio')
    check(r['web']==1 and r['email']==1 and r['riepiloghi']==1, 'una notifica, email e aggiornamento odierno')
    check(r['pending']==0, 'token consumato')
    check('\n' in r['notifica'] and '\\n' not in r['notifica'], 'righe della comunicazione')
for bad in [dict(csrf_bad=True), dict(changed=True), dict(expired=True), dict(consumed=True), dict(deny=True), dict(impersonate=True)]:
    r = run(dict(method='POST', confirm=True, **bad))
    check(r['richieste']==0 and r['email']==0, 'conferma rifiutata senza effetti')
    check(bool(r['error']) or r['status']==403, 'errore leggibile')
r = run(dict(role='user'))
check(r['status']==403, 'GET non HR vietato')
r = run(dict(role='user', method='POST', post=base))
check(r['status']==403 and r['richieste']==0, 'POST non HR vietato')
r = run(dict(deny=True))
check('consultare ma non modificare' in r['html'] and 'Mostra anteprima' not in r['html'], 'sola lettura')
r = run(dict(seed=True, new_closure=True))
check('2 fasce future ricadono in una chiusura' in r['html'], 'segnalazione nuova chiusura')
check('Annulla fascia' in r['html'] and 'Storico del programma' in r['html'], 'gestione serie')
for action, da, a, expected in [('giorno','2026-10-20','2026-10-20',2),('chiusure','2026-10-13','2027-03-06',2),('annulla','2027-03-05','2027-03-06',2)]:
    post=base | dict(azione_programma=action, id_programma=1, data_da=da, data_a=a)
    r = run(dict(seed=True, new_closure=True, method='POST', post=post))
    check(not r['error'] and f'{expected}</strong> da annullare' in r['html'], 'anteprima annullamento')
    check(r['richieste']==208 and r['email']==0, 'annullamento attende conferma')
r = run(dict(method='POST', post=base | dict(ora_da=['08:00'],ora_a=['11:00'])))
check('massimo complessivo di 2 ore' in r['error'] and r['richieste']==0, 'errore del form')
print(f'{checks} controlli delle pagine superati')
