SET NAMES utf8mb4;

-- Regole beta HR - 23/09/2026
-- Disattiva Trasferta e introduce Formazione, Fiera e Altro.
-- Le regole di visibilita/obbligatorieta di Altro e Oggetto breve sono applicate lato applicazione.

UPDATE hr_tipologie_evento
SET attivo = 0
WHERE codice = 'TRASFERTA';

SET @id_stato_fuori = (
    SELECT id_stato_presenza
    FROM hr_stati_presenza
    WHERE codice IN ('FUORI_SEDE', 'ASSENTE')
    ORDER BY CASE WHEN codice = 'FUORI_SEDE' THEN 0 ELSE 1 END
    LIMIT 1
);

INSERT INTO hr_tipologie_evento
    (codice, descrizione, descrizione_calendario, richiede_approvazione, approvazione_obbligatoria,
     consente_giorni, consente_ore, consente_multi_periodo, visibile_calendario, visibile_ai_colleghi,
     mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
     id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo)
SELECT
    'FORMAZIONE', 'Formazione', 'Formazione', 1, 1,
    1, 1, 0, 1, 1,
    0, 1, 1,
    @id_stato_fuori, 0, NULL, 70, 1
WHERE @id_stato_fuori IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'FORMAZIONE');

UPDATE hr_tipologie_evento
SET descrizione = 'Formazione',
    descrizione_calendario = 'Formazione',
    richiede_approvazione = 1,
    approvazione_obbligatoria = 1,
    consente_giorni = 1,
    consente_ore = 1,
    visibile_calendario = 1,
    mostra_dettaglio_responsabili = 1,
    mostra_dettaglio_hr = 1,
    id_stato_presenza = COALESCE(@id_stato_fuori, id_stato_presenza),
    ordinamento = 70,
    attivo = 1
WHERE codice = 'FORMAZIONE';

INSERT INTO hr_tipologie_evento
    (codice, descrizione, descrizione_calendario, richiede_approvazione, approvazione_obbligatoria,
     consente_giorni, consente_ore, consente_multi_periodo, visibile_calendario, visibile_ai_colleghi,
     mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
     id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo)
SELECT
    'FIERA', 'Fiera', 'Fiera', 1, 1,
    1, 1, 0, 1, 1,
    0, 1, 1,
    @id_stato_fuori, 0, NULL, 80, 1
WHERE @id_stato_fuori IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'FIERA');

UPDATE hr_tipologie_evento
SET descrizione = 'Fiera',
    descrizione_calendario = 'Fiera',
    richiede_approvazione = 1,
    approvazione_obbligatoria = 1,
    consente_giorni = 1,
    consente_ore = 1,
    visibile_calendario = 1,
    mostra_dettaglio_responsabili = 1,
    mostra_dettaglio_hr = 1,
    id_stato_presenza = COALESCE(@id_stato_fuori, id_stato_presenza),
    ordinamento = 80,
    attivo = 1
WHERE codice = 'FIERA';

INSERT INTO hr_tipologie_evento
    (codice, descrizione, descrizione_calendario, richiede_approvazione, approvazione_obbligatoria,
     consente_giorni, consente_ore, consente_multi_periodo, visibile_calendario, visibile_ai_colleghi,
     mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
     id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo)
SELECT
    'ALTRO', 'Altro', 'Altro', 0, 0,
    1, 1, 0, 1, 1,
    0, 1, 1,
    @id_stato_fuori, 0, NULL, 90, 1
WHERE @id_stato_fuori IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'ALTRO');

UPDATE hr_tipologie_evento
SET descrizione = 'Altro',
    descrizione_calendario = 'Altro',
    richiede_approvazione = 0,
    approvazione_obbligatoria = 0,
    consente_giorni = 1,
    consente_ore = 1,
    visibile_calendario = 1,
    mostra_dettaglio_responsabili = 1,
    mostra_dettaglio_hr = 1,
    id_stato_presenza = COALESCE(@id_stato_fuori, id_stato_presenza),
    ordinamento = 90,
    attivo = 1
WHERE codice = 'ALTRO';

-- Verifica finale
SELECT codice, descrizione, attivo, ordinamento
FROM hr_tipologie_evento
WHERE codice IN ('TRASFERTA', 'FORMAZIONE', 'FIERA', 'ALTRO')
ORDER BY ordinamento, descrizione;
