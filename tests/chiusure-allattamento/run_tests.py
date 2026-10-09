"""PHP_BIN + PHP_LIB_DIR opzionali; esegue codice reale con fixture PDO, senza email."""
import json, os, subprocess
from pathlib import Path

here = Path(__file__).resolve().parent
php = os.environ.get('PHP_BIN', 'php')
cmd = [php, '-n']
if os.environ.get('PHP_LIB_DIR'):
    cmd += ['-d', 'extension=' + os.environ['PHP_LIB_DIR'] + '/pdo.so']
else:
    cmd += ['-d', 'extension=pdo']
total = 0
def run(label, case, error=None, committed=False):
    global total
    r = subprocess.run(cmd + [str(here / 'fixture.php'), json.dumps(case)], capture_output=True, text=True)
    assert r.returncode == 0, (label, r.stdout, r.stderr)
    d = json.loads(r.stdout)
    assert not d['fatal'] and not r.stderr, (label, d['fatal'], r.stderr)
    if error:
        assert error in d['error'], (label, d['error'])
        assert not d['writes'] and not d['committed'], (label, d)
    else:
        assert not d['error'], (label, d['error'])
        assert d['committed'] == committed, (label, d['committed'])
    if committed:
        lock = next(i for i,q in enumerate(d['queries']) if 'FOR UPDATE' in q['sql'])
        assert d['queries'][lock]['tx']
        for i,q in enumerate(d['queries']):
            if q['tx'] and ('FROM hr_chiusure_aziendali' in q['sql'] or 'FROM hr_benefici_utenti' in q['sql']):
                assert i > lock, (label, 'read before lock')
    total += 1
    return d

def period(day='2099-06-01', start='08:00', end='10:00', kind='ORE', final=None):
    return dict(data_da=day,data_a=final or day,ora_da=start,ora_a=end,tipo_periodo=kind)
def helper(**changes):
    return dict(mode='helper', periods=[period()]) | changes
benefit = dict(data_inizio='2099-06-01',data_fine='2099-06-30')
for day in ['2099-06-01','2099-06-30']:
    run('inclusive validity '+day, helper(benefit=benefit, periods=[period(day)]), committed=True)
for day in ['2099-05-31','2099-07-01']:
    run('outside validity '+day, helper(benefit=benefit, periods=[period(day)]), 'non rientra')
run('missing grant', helper(benefit=False), 'periodo Allattamento completo')
run('missing end', helper(benefit=dict(data_inizio='2099-01-01',data_fine=None)), 'periodo Allattamento completo')
run('3h', helper(periods=[period(end='11:00')]), 'massimo 2 ore')
run('days denied', helper(periods=[period(kind='GIORNI')]), 'solo a ore')
run('multiday denied', helper(periods=[period(final='2099-06-02')]), 'singola giornata')
run('invalid time', helper(periods=[period(start='29:00')]), 'orari validi')
run('invalid date', helper(periods=[period(day='2099-02-30')]), 'periodo valido')
record = dict(data_da='2099-06-01',data_a='2099-06-01',minuti=60)
run('two separate hours', helper(periods=[period(start='10:00',end='11:00')], records=[record]), committed=True)
run('cumulative over limit', helper(periods=[period(start='10:00',end='11:15')], records=[record]), '2 ore complessive')
run('two periods same request', helper(periods=[period(),period(start='10:00',end='10:15')]), '2 ore complessive')
for state in ['APPROVATA','IN_ATTESA']:
    run('count '+state, helper(records=[record | dict(stato=state)]), '2 ore complessive')
for state in ['RIFIUTATA','ANNULLATA']:
    run('ignore '+state, helper(records=[record | dict(stato=state)]), committed=True)
run('other user independent', helper(records=[record | dict(id_utente=2)]), committed=True)
run('other day independent', helper(records=[record | dict(data_da='2099-06-02',data_a='2099-06-02')]), committed=True)
run('other type independent', helper(records=[record | dict(codice='FERIE')]), committed=True)
run('approval excludes itself', helper(records=[record | dict(id_richiesta=10,minuti=120)],exclude=10), committed=True)
closure = dict(data_da='2099-06-10',data_a='2099-06-15')
for day in ['2099-06-10','2099-06-15']:
    run('closure inclusive '+day, helper(periods=[period(day)], closures=[closure]), 'chiusura aziendale')
run('crossing closure', helper(code='FERIE',periods=[period(day='2099-06-01',final='2099-06-30',kind='GIORNI')],closures=[closure]), 'chiusura aziendale')
run('deactivated closure', helper(periods=[period('2099-06-10')],closures=[closure | dict(attivo=0)]), committed=True)
for code in ['FERIE','PERMESSO','MALATTIA','LEGGE_104','SMART','VISITA_CLIENTE','VISITA_FORNITORE','FORMAZIONE','FIERA','ALTRO','ALLATTAMENTO','CONGEDO_STRAORDINARIO_DISABILI']:
    run('all types closure '+code, helper(code=code,periods=[period('2099-06-12')],closures=[closure]), 'chiusura aziendale')

# Pagina Assenze completa: utente abilitato, HR e admin, POST e GET.
post = dict(azione='nuova_richiesta', id_utente='1',id_tipologia_evento='2',modalita='ore',data_da='2099-06-01',data_a='2099-06-01',ora_da='08:00',ora_a='10:00')
for role in ['', 'hr', 'admin']:
    run('page 2h '+role, dict(method='POST',post=post,role=role), committed=True)
    run('page closed '+role, dict(method='POST',post=post,role=role,closures=[dict(data_da='2099-06-01',data_a='2099-06-01')]), 'chiusura aziendale')
    run('page cumulative '+role, dict(method='POST',post=post,role=role,records=[record]), '2 ore complessive')
    d = run('page options '+role, dict(role=role))
    assert 'data-codice="ALLATTAMENTO"' in d['html']
    assert ('data-codice="MALATTIA"' in d['html']) == (role in ['hr','admin'])
run('tampered csrf',dict(method='POST',post=post,invalid_csrf=True),'sessione del modulo')
run('page absent grant',dict(method='POST',post=post,benefit=False),'periodo Allattamento completo')
run('page 3h',dict(method='POST',post=post | dict(ora_a='11:00')),'massimo 2 ore')
run('page days',dict(method='POST',post=post | dict(modalita='giorni')),'non consente richieste a giorni')
d = run('GET absent grant',dict(benefit=False))
assert 'data-codice="ALLATTAMENTO"' not in d['html']

# Assegnazione dal/al e conservazione della 104.
assign = dict(azione='assegna_beneficio',id_utente='1',tipo_beneficio='ALLATTAMENTO',data_inizio='2099-01-01',data_fine='2099-12-31')
for role in ['hr','admin']:
    d=run('benefit saved '+role,dict(page='benefici_hr.php',role=role,method='POST',post=assign),committed=True)
    assert d['writes'][0]['params']['consente_giorni'] == 0
run('employee cannot grant',dict(page='benefici_hr.php',method='POST',post=assign),'permessi di modifica')
run('grant needs end',dict(page='benefici_hr.php',role='hr',method='POST',post=assign | dict(data_fine='')),'sia la data iniziale')
run('grant invalid day',dict(page='benefici_hr.php',role='hr',method='POST',post=assign | dict(data_fine='2099-02-30')),'periodo valido')
run('grant 104 preserved',dict(page='benefici_hr.php',role='hr',method='POST',post=assign | dict(tipo_beneficio='LEGGE_104',plafond_giorni_mese='3',plafond_ore_mese='24',ore_giornata_equivalenza='8')),committed=True)

# Gestione chiusure, riclassificazione ed approvazioni.
close = dict(azione='salva',descrizione='Chiusura estiva',data_da='2099-06-01',data_a='2099-06-15')
for role in ['hr','admin']:
    run('save closure '+role,dict(page='chiusure_aziendali.php',method='POST',role=role,post=close),committed=True)
d = run('employee closure denied',dict(page='chiusure_aziendali.php',method='POST',post=close))
assert d['status'] == 403 and not d['writes']
run('closure overlapping',dict(page='chiusure_aziendali.php',method='POST',role='hr',post=close,closures=[closure]),'già una chiusura')
run('closure reversed',dict(page='chiusure_aziendali.php',method='POST',role='hr',post=close | dict(data_a='2099-05-31')),'periodo valido')
run('disable closure',dict(page='chiusure_aziendali.php',method='POST',role='hr',post=dict(azione='disattiva',id_chiusura='1')),committed=True)
reclass = dict(azione='riclassifica_altro',id_richiesta='10',id_utente='1',id_nuova_tipologia='2')
run('reclass to allattamento',dict(role='hr',method='POST',post=reclass,periods=[period()]),committed=True)
run('reclass limit',dict(role='hr',method='POST',post=reclass,periods=[period()],records=[record]),'2 ore complessive')
run('reclass days denied',dict(role='hr',method='POST',post=reclass,periods=[period(kind='GIORNI')]),'solo a ore')
approve = dict(azione='approva_richiesta',id_richiesta='10')
run('approval valid',dict(page='approvazioni_assenze.php',role='hr',method='POST',post=approve,periods=[period()]),committed=True)
run('approval closure',dict(page='approvazioni_assenze.php',role='hr',method='POST',post=approve,periods=[period('2099-06-12')],closures=[closure]),'chiusura aziendale')
run('approval allatt limit',dict(page='approvazioni_assenze.php',role='hr',method='POST',post=approve,periods=[period()],records=[record]),'2 ore complessive')
run('refusal still allowed',dict(page='approvazioni_assenze.php',role='hr',method='POST',post=approve | dict(azione='rifiuta_richiesta',nota_approvatore='Non valida'),periods=[period('2099-06-12')],closures=[closure]),committed=True)
d = run('impersonated POST denied',dict(role='admin',method='POST',post=post,impersonate=True))
assert d['status'] == 403 and not d['writes']
print(f'{total} scenari PASS (PHP reale, DB e notifiche sostituiti).')
