-- 2026-09-22 - Tipologie HR: visita fornitore e abilitazione smart working
-- Idempotente per MySQL 5.7.

SET NAMES utf8mb4;
START TRANSACTION;

-- Visita fornitore: stessa configurazione della visita cliente, con ordinamento immediatamente successivo.
INSERT INTO hr_tipologie_evento (
    codice, descrizione, descrizione_calendario,
    richiede_approvazione, approvazione_obbligatoria,
    consente_giorni, consente_ore, consente_multi_periodo,
    visibile_calendario, visibile_ai_colleghi,
    mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
    id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo,
    motivazione_obbligatoria, avviso_richiedente
)
SELECT
    'VISITA_FORNITORE', 'Visita fornitore', 'Visita fornitore',
    vc.richiede_approvazione, vc.approvazione_obbligatoria,
    vc.consente_giorni, vc.consente_ore, vc.consente_multi_periodo,
    vc.visibile_calendario, vc.visibile_ai_colleghi,
    vc.mostra_dettaglio_colleghi, vc.mostra_dettaglio_responsabili, vc.mostra_dettaglio_hr,
    vc.id_stato_presenza, vc.disturbabile, vc.colore_calendario, vc.ordinamento + 1, 1,
    vc.motivazione_obbligatoria, vc.avviso_richiedente
FROM hr_tipologie_evento vc
WHERE vc.descrizione = 'Visita cliente'
  AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice='VISITA_FORNITORE')
LIMIT 1;

-- Se esiste gia', riallinea la logica a Visita cliente senza cambiare il codice identificativo.
UPDATE hr_tipologie_evento vf
JOIN hr_tipologie_evento vc ON vc.descrizione='Visita cliente'
SET vf.descrizione='Visita fornitore',
    vf.descrizione_calendario='Visita fornitore',
    vf.richiede_approvazione=vc.richiede_approvazione,
    vf.approvazione_obbligatoria=vc.approvazione_obbligatoria,
    vf.consente_giorni=vc.consente_giorni,
    vf.consente_ore=vc.consente_ore,
    vf.consente_multi_periodo=vc.consente_multi_periodo,
    vf.visibile_calendario=vc.visibile_calendario,
    vf.visibile_ai_colleghi=vc.visibile_ai_colleghi,
    vf.mostra_dettaglio_colleghi=vc.mostra_dettaglio_colleghi,
    vf.mostra_dettaglio_responsabili=vc.mostra_dettaglio_responsabili,
    vf.mostra_dettaglio_hr=vc.mostra_dettaglio_hr,
    vf.id_stato_presenza=vc.id_stato_presenza,
    vf.disturbabile=vc.disturbabile,
    vf.colore_calendario=vc.colore_calendario,
    vf.ordinamento=vc.ordinamento+1,
    vf.motivazione_obbligatoria=vc.motivazione_obbligatoria,
    vf.avviso_richiedente=vc.avviso_richiedente,
    vf.attivo=1
WHERE vf.codice='VISITA_FORNITORE';

-- La tabella benefici esiste gia' per la Legge 104.
-- SMART_WORKING usa la stessa abilitazione individuale, ma senza plafond.
-- Nessun utente viene abilitato automaticamente: HR scegliera' chi autorizzare da Benefici e diritti.

COMMIT;

SELECT codice, descrizione, ordinamento, attivo
FROM hr_tipologie_evento
WHERE descrizione IN ('Visita cliente','Visita fornitore')
ORDER BY ordinamento, descrizione;
