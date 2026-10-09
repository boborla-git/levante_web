"""Verifica migration e SELECT reali su MariaDB --bootstrap in un datadir isolato.
Richiede MARIADB_BIN, MARIADB_BASEDIR, MARIADB_TEST_DATADIR (già inizializzato).
Non usa socket/network. Il datadir deve essere destinato esclusivamente ai test.
"""
import os, re, subprocess
from pathlib import Path
here = Path(__file__).resolve().parent
root = here.parent.parent
source = (root / 'includes/hr_regole_assenze.php').read_text()
migration = (root / 'sql/2026-10-09_chiusure_aziendali_allattamento.sql').read_text()
sum_query = re.search(r'\$stmtUsati = \$pdo->prepare\("(.*?)"\);', source, re.S).group(1)
def query(day, exclude=0):
    replacements = dict(utente='1',escludi=str(exclude),giorno_fine="'"+day+"'",giorno_inizio="'"+day+"'")
    return re.sub(r':(\w+)',lambda m:replacements[m.group(1)],sum_query)
sql = (here / 'schema_fixture.sql').read_text() + '\n' + migration + '\n' + migration
sql += """
CREATE TABLE assertions (ok INT NOT NULL CHECK (ok = 1));
INSERT INTO assertions SELECT COUNT(*)=8 FROM aut_ruoli_permessi;
INSERT INTO assertions SELECT COUNT(*)=0 FROM aut_ruoli_permessi WHERE id_ruolo=3;
INSERT INTO assertions SELECT COUNT(*)=1 FROM aut_risorse WHERE codice_risorsa='pagina.chiusure_aziendali';
INSERT INTO assertions SELECT consente_giorni=0 AND consente_ore=1 FROM hr_tipologie_evento WHERE codice='ALLATTAMENTO';
INSERT INTO assertions SELECT data_fine IS NULL AND consente_giorni=0 FROM hr_benefici_utenti WHERE id_utente=1;
INSERT INTO assertions SELECT COUNT(*)=8 FROM hr_richieste;
INSERT INTO hr_chiusure_aziendali (descrizione,data_da,data_a,attivo,aggiornato_da) VALUES
    ('Chiusura test','2099-06-10','2099-06-15',1,1),('Disattivata','2099-06-01','2099-06-01',0,1);
INSERT INTO assertions SELECT COUNT(*)=1 FROM hr_chiusure_aziendali WHERE attivo=1 AND data_da<='2099-06-10' AND data_a>='2099-06-10';
INSERT INTO assertions SELECT COUNT(*)=1 FROM hr_chiusure_aziendali WHERE attivo=1 AND data_da<='2099-06-15' AND data_a>='2099-06-15';
INSERT INTO assertions SELECT COUNT(*)=1 FROM hr_chiusure_aziendali WHERE attivo=1 AND data_da<='2099-06-30' AND data_a>='2099-06-01';
INSERT INTO assertions SELECT COUNT(*)=0 FROM hr_chiusure_aziendali WHERE attivo=1 AND data_da<='2099-06-09' AND data_a>='2099-06-09';
"""
for day, exclude, expected in [('2099-06-01',0,120),('2099-06-01',1,60),('2099-06-02',0,120),('2099-06-04',0,0)]:
    sql += '\nINSERT INTO assertions SELECT ('+query(day,exclude)+') = '+str(expected)+';\n'
r = subprocess.run([os.environ['MARIADB_BIN'],'--no-defaults','--bootstrap',
    '--basedir='+os.environ['MARIADB_BASEDIR'],'--datadir='+os.environ['MARIADB_TEST_DATADIR'],
    '--skip-grant-tables','--user=root'],input=sql,capture_output=True,text=True)
assert r.returncode==0,(r.stdout,r.stderr)
assert '[ERROR]' not in r.stderr, r.stderr
print('SQL reale PASS: migration ripetuta, permessi HR/admin, conservazione dati, chiusure e conteggio giornaliero.')
