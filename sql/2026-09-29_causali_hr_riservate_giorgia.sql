-- LEVANTE WEB - Causali HR riservate a Giorgia
-- Data: 2026-09-29
-- MySQL 5.7 compatible
--
-- Le causali vengono registrate come assenze personali:
-- - stato presenza ASSENTE
-- - nessuna approvazione del responsabile
-- - nel calendario il dettaglio e' visibile a HR ma non a colleghi/responsabili
-- - selezione applicativa consentita solo a Giorgia HR (gestita in assenze.php)

SET NAMES utf8mb4;
START TRANSACTION;

SET @id_stato_assente := (
    SELECT id_stato_presenza
    FROM hr_stati_presenza
    WHERE codice = 'ASSENTE'
    LIMIT 1
);

INSERT INTO hr_tipologie_evento (
    codice,
    descrizione,
    descrizione_calendario,
    richiede_approvazione,
    approvazione_obbligatoria,
    consente_giorni,
    consente_ore,
    consente_multi_periodo,
    visibile_calendario,
    visibile_ai_colleghi,
    mostra_dettaglio_colleghi,
    mostra_dettaglio_responsabili,
    mostra_dettaglio_hr,
    id_stato_presenza,
    disturbabile,
    colore_calendario,
    ordinamento,
    attivo
)
SELECT
    'ALLATTAMENTO',
    'Allattamento',
    'Assente',
    0,
    0,
    1,
    1,
    0,
    1,
    1,
    0,
    0,
    1,
    @id_stato_assente,
    0,
    '#dc3545',
    46,
    1
WHERE @id_stato_assente IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM hr_tipologie_evento
      WHERE codice = 'ALLATTAMENTO'
  );

UPDATE hr_tipologie_evento
SET descrizione = 'Allattamento',
    descrizione_calendario = 'Assente',
    richiede_approvazione = 0,
    approvazione_obbligatoria = 0,
    consente_giorni = 1,
    consente_ore = 1,
    consente_multi_periodo = 0,
    visibile_calendario = 1,
    visibile_ai_colleghi = 1,
    mostra_dettaglio_colleghi = 0,
    mostra_dettaglio_responsabili = 0,
    mostra_dettaglio_hr = 1,
    id_stato_presenza = COALESCE(@id_stato_assente, id_stato_presenza),
    disturbabile = 0,
    colore_calendario = '#dc3545',
    ordinamento = 46,
    attivo = 1
WHERE codice = 'ALLATTAMENTO';

INSERT INTO hr_tipologie_evento (
    codice,
    descrizione,
    descrizione_calendario,
    richiede_approvazione,
    approvazione_obbligatoria,
    consente_giorni,
    consente_ore,
    consente_multi_periodo,
    visibile_calendario,
    visibile_ai_colleghi,
    mostra_dettaglio_colleghi,
    mostra_dettaglio_responsabili,
    mostra_dettaglio_hr,
    id_stato_presenza,
    disturbabile,
    colore_calendario,
    ordinamento,
    attivo
)
SELECT
    'CONGEDO_STRAORDINARIO_DISABILI',
    'Congedo straordinario disabili',
    'Assente',
    0,
    0,
    1,
    1,
    0,
    1,
    1,
    0,
    0,
    1,
    @id_stato_assente,
    0,
    '#dc3545',
    47,
    1
WHERE @id_stato_assente IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM hr_tipologie_evento
      WHERE codice = 'CONGEDO_STRAORDINARIO_DISABILI'
  );

UPDATE hr_tipologie_evento
SET descrizione = 'Congedo straordinario disabili',
    descrizione_calendario = 'Assente',
    richiede_approvazione = 0,
    approvazione_obbligatoria = 0,
    consente_giorni = 1,
    consente_ore = 1,
    consente_multi_periodo = 0,
    visibile_calendario = 1,
    visibile_ai_colleghi = 1,
    mostra_dettaglio_colleghi = 0,
    mostra_dettaglio_responsabili = 0,
    mostra_dettaglio_hr = 1,
    id_stato_presenza = COALESCE(@id_stato_assente, id_stato_presenza),
    disturbabile = 0,
    colore_calendario = '#dc3545',
    ordinamento = 47,
    attivo = 1
WHERE codice = 'CONGEDO_STRAORDINARIO_DISABILI';

COMMIT;

SELECT
    codice,
    descrizione,
    descrizione_calendario,
    richiede_approvazione,
    approvazione_obbligatoria,
    consente_giorni,
    consente_ore,
    visibile_calendario,
    visibile_ai_colleghi,
    mostra_dettaglio_colleghi,
    mostra_dettaglio_responsabili,
    mostra_dettaglio_hr,
    ordinamento,
    attivo
FROM hr_tipologie_evento
WHERE codice IN ('ALLATTAMENTO', 'CONGEDO_STRAORDINARIO_DISABILI')
ORDER BY ordinamento, descrizione;
