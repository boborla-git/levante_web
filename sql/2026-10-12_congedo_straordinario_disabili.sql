-- MySQL 5.7: eseguire prima dei PHP. Ripetibile, conserva richieste e assegnazioni.
SET NAMES utf8mb4;
START TRANSACTION;
INSERT INTO hr_tipologie_evento (codice, descrizione, descrizione_calendario,
    richiede_approvazione, approvazione_obbligatoria, consente_giorni, consente_ore,
    consente_multi_periodo, visibile_calendario, visibile_ai_colleghi,
    mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
    id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo)
SELECT 'CONGEDO_STRAORDINARIO_DISABILI', 'Congedo Straordinario Disabili',
    'Congedo Straordinario Disabili', 0, 0, 1, 0, 0, 1, 1, 0, 0, 1,
    sp.id_stato_presenza, 0, '#dc3545', 47, 1 FROM hr_stati_presenza sp
WHERE sp.codice = 'ASSENTE' AND NOT EXISTS
    (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'CONGEDO_STRAORDINARIO_DISABILI');
UPDATE hr_tipologie_evento te JOIN hr_stati_presenza sp ON sp.codice = 'ASSENTE'
SET te.descrizione = 'Congedo Straordinario Disabili',
    te.descrizione_calendario = 'Congedo Straordinario Disabili',
    te.richiede_approvazione = 0, te.approvazione_obbligatoria = 0,
    te.consente_giorni = 1, te.consente_ore = 0, te.consente_multi_periodo = 0,
    te.visibile_calendario = 1, te.visibile_ai_colleghi = 1,
    te.mostra_dettaglio_colleghi = 0, te.mostra_dettaglio_responsabili = 0,
    te.mostra_dettaglio_hr = 1, te.id_stato_presenza = sp.id_stato_presenza,
    te.disturbabile = 0, te.attivo = 1
WHERE te.codice = 'CONGEDO_STRAORDINARIO_DISABILI';
UPDATE hr_benefici_utenti SET consente_giorni = 1, consente_ore = 0
WHERE codice_beneficio = 'CONGEDO_STRAORDINARIO_DISABILI';
-- ALTRO non viene disattivato qui: lo storico deve rimanere nel calendario.
-- I PHP lo escludono dalle nuove richieste e ne bloccano l'inserimento.
COMMIT;
SELECT id_utente, data_inizio, data_fine FROM hr_benefici_utenti
WHERE codice_beneficio = 'CONGEDO_STRAORDINARIO_DISABILI' AND attivo = 1 AND data_fine IS NULL;
-- Eventuali righe richiedono il completamento della data finale in Benefici e diritti.
