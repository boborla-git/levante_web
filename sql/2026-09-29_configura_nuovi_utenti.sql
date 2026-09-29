-- LEVANTE WEB - Configurazione nuovi utenti
-- Data: 2026-09-29
-- Revisione v2: evita l'errore MySQL #1054 della versione precedente.
--
-- Configurazione richiesta:
--   LChilan    -> responsabile test_FMillefanti
--   FCoffari   -> nessun responsabile
--   GDimasi    -> responsabile test_MMorleo
--   JTrujillo  -> responsabile test_FMillefanti
--
-- Password iniziale per tutti: test@123
-- Cambio password obbligatorio: NO
--
-- NOTA:
-- Il ROLLBACK iniziale e' intenzionale: se la precedente esecuzione si e'
-- interrotta prima del COMMIT, annulla in sicurezza quella transazione.
-- Se non esiste una transazione aperta, non modifica nulla.

ROLLBACK;

SET @password_hash_test123 := '$2y$12$jn0T.KfP.Q85F548AA71c.m7n7ecu6CgTpIqh7lh8J4DMbSZf/PAC';

SET @id_lchilan := (
    SELECT id_utente FROM aut_utenti
    WHERE username = 'LChilan' AND attivo = 1
    LIMIT 1
);
SET @id_fcoffari := (
    SELECT id_utente FROM aut_utenti
    WHERE username = 'FCoffari' AND attivo = 1
    LIMIT 1
);
SET @id_gdimasi := (
    SELECT id_utente FROM aut_utenti
    WHERE username = 'GDimasi' AND attivo = 1
    LIMIT 1
);
SET @id_jtrujillo := (
    SELECT id_utente FROM aut_utenti
    WHERE username = 'JTrujillo' AND attivo = 1
    LIMIT 1
);
SET @id_fmillefanti := (
    SELECT id_utente FROM aut_utenti
    WHERE username = 'test_FMillefanti' AND attivo = 1
    LIMIT 1
);
SET @id_mmorleo := (
    SELECT id_utente FROM aut_utenti
    WHERE username = 'test_MMorleo' AND attivo = 1
    LIMIT 1
);
SET @id_tipo_responsabile := (
    SELECT id_tipo_relazione
    FROM hr_tipi_relazione_organizzativa
    WHERE codice = 'RESPONSABILE_FUNZIONALE'
      AND attivo = 1
    LIMIT 1
);

SET @precheck_ok := (
       @id_lchilan IS NOT NULL
   AND @id_fcoffari IS NOT NULL
   AND @id_gdimasi IS NOT NULL
   AND @id_jtrujillo IS NOT NULL
   AND @id_fmillefanti IS NOT NULL
   AND @id_mmorleo IS NOT NULL
   AND @id_tipo_responsabile IS NOT NULL
);

SELECT CASE
    WHEN @precheck_ok = 1 THEN 'OK - prerequisiti presenti'
    ELSE 'ERRORE - manca almeno un utente, un responsabile o RESPONSABILE_FUNZIONALE'
END AS controllo_preliminare;

START TRANSACTION;

UPDATE aut_utenti
SET password_hash = @password_hash_test123,
    deve_cambiare_password = 0,
    data_aggiornamento = NOW()
WHERE @precheck_ok = 1
  AND id_utente IN (@id_lchilan,@id_fcoffari,@id_gdimasi,@id_jtrujillo);

UPDATE hr_relazioni_organizzative ro
INNER JOIN hr_tipi_relazione_organizzativa tr
    ON tr.id_tipo_relazione = ro.id_tipo_relazione
SET ro.attiva = 0,
    ro.data_fine = COALESCE(ro.data_fine, CURDATE()),
    ro.note = TRIM(CONCAT(
        COALESCE(ro.note, ''),
        CASE WHEN COALESCE(ro.note, '') = '' THEN '' ELSE ' | ' END,
        'Chiusa da configurazione nuovi utenti 2026-09-29'
    ))
WHERE @precheck_ok = 1
  AND ro.attiva = 1
  AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
  AND tr.codice IN ('RESPONSABILE_FUNZIONALE', 'RESPONSABILE_DIRETTO')
  AND (
       ro.id_utente = @id_fcoffari
       OR (
            ro.id_utente = @id_lchilan
            AND NOT (
                ro.id_utente_collegato = @id_fmillefanti
                AND tr.codice = 'RESPONSABILE_FUNZIONALE'
            )
       )
       OR (
            ro.id_utente = @id_jtrujillo
            AND NOT (
                ro.id_utente_collegato = @id_fmillefanti
                AND tr.codice = 'RESPONSABILE_FUNZIONALE'
            )
       )
       OR (
            ro.id_utente = @id_gdimasi
            AND NOT (
                ro.id_utente_collegato = @id_mmorleo
                AND tr.codice = 'RESPONSABILE_FUNZIONALE'
            )
       )
  );

INSERT INTO hr_relazioni_organizzative
    (id_utente, id_utente_collegato, id_tipo_relazione, data_inizio, data_fine, attiva, note)
SELECT @id_lchilan,@id_fmillefanti,@id_tipo_responsabile,CURDATE(),NULL,1,
       'Configurata da script nuovi utenti 2026-09-29'
FROM DUAL
WHERE @precheck_ok = 1
  AND NOT EXISTS (
      SELECT 1
      FROM hr_relazioni_organizzative ro
      INNER JOIN hr_tipi_relazione_organizzativa tr
          ON tr.id_tipo_relazione = ro.id_tipo_relazione
      WHERE ro.id_utente = @id_lchilan
        AND ro.id_utente_collegato = @id_fmillefanti
        AND ro.attiva = 1
        AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
        AND tr.codice = 'RESPONSABILE_FUNZIONALE'
  );

INSERT INTO hr_relazioni_organizzative
    (id_utente, id_utente_collegato, id_tipo_relazione, data_inizio, data_fine, attiva, note)
SELECT @id_gdimasi,@id_mmorleo,@id_tipo_responsabile,CURDATE(),NULL,1,
       'Configurata da script nuovi utenti 2026-09-29'
FROM DUAL
WHERE @precheck_ok = 1
  AND NOT EXISTS (
      SELECT 1
      FROM hr_relazioni_organizzative ro
      INNER JOIN hr_tipi_relazione_organizzativa tr
          ON tr.id_tipo_relazione = ro.id_tipo_relazione
      WHERE ro.id_utente = @id_gdimasi
        AND ro.id_utente_collegato = @id_mmorleo
        AND ro.attiva = 1
        AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
        AND tr.codice = 'RESPONSABILE_FUNZIONALE'
  );

INSERT INTO hr_relazioni_organizzative
    (id_utente, id_utente_collegato, id_tipo_relazione, data_inizio, data_fine, attiva, note)
SELECT @id_jtrujillo,@id_fmillefanti,@id_tipo_responsabile,CURDATE(),NULL,1,
       'Configurata da script nuovi utenti 2026-09-29'
FROM DUAL
WHERE @precheck_ok = 1
  AND NOT EXISTS (
      SELECT 1
      FROM hr_relazioni_organizzative ro
      INNER JOIN hr_tipi_relazione_organizzativa tr
          ON tr.id_tipo_relazione = ro.id_tipo_relazione
      WHERE ro.id_utente = @id_jtrujillo
        AND ro.id_utente_collegato = @id_fmillefanti
        AND ro.attiva = 1
        AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
        AND tr.codice = 'RESPONSABILE_FUNZIONALE'
  );

COMMIT;

SELECT
    u.username,
    CONCAT(TRIM(COALESCE(u.nome,'')), ' ', TRIM(COALESCE(u.cognome,''))) AS nominativo,
    u.attivo,
    u.deve_cambiare_password,
    CASE WHEN u.password_hash = @password_hash_test123 THEN 'OK' ELSE 'NO' END AS password_configurata,
    GROUP_CONCAT(
        DISTINCT CASE
            WHEN ro.attiva = 1
             AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
             AND tr.codice IN ('RESPONSABILE_FUNZIONALE','RESPONSABILE_DIRETTO')
            THEN CONCAT(r.username, ' [', tr.codice, ']')
        END
        ORDER BY r.username
        SEPARATOR ', '
    ) AS responsabile_attivo
FROM aut_utenti u
LEFT JOIN hr_relazioni_organizzative ro ON ro.id_utente = u.id_utente
LEFT JOIN hr_tipi_relazione_organizzativa tr ON tr.id_tipo_relazione = ro.id_tipo_relazione
LEFT JOIN aut_utenti r ON r.id_utente = ro.id_utente_collegato
WHERE u.id_utente IN (@id_lchilan,@id_fcoffari,@id_gdimasi,@id_jtrujillo)
GROUP BY u.id_utente,u.username,u.nome,u.cognome,u.attivo,u.deve_cambiare_password,u.password_hash
ORDER BY u.cognome, u.nome;

SELECT CASE
    WHEN @precheck_ok <> 1 THEN
        'ERRORE - prerequisiti non soddisfatti'
    WHEN (
        SELECT COUNT(*)
        FROM aut_utenti
        WHERE id_utente IN (@id_lchilan,@id_fcoffari,@id_gdimasi,@id_jtrujillo)
          AND password_hash = @password_hash_test123
          AND deve_cambiare_password = 0
          AND attivo = 1
    ) <> 4 THEN
        'ATTENZIONE - password/flag utenti non allineati'
    WHEN NOT EXISTS (
        SELECT 1
        FROM hr_relazioni_organizzative ro
        INNER JOIN hr_tipi_relazione_organizzativa tr
            ON tr.id_tipo_relazione = ro.id_tipo_relazione
        WHERE ro.id_utente = @id_lchilan
          AND ro.id_utente_collegato = @id_fmillefanti
          AND ro.attiva = 1
          AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
          AND tr.codice = 'RESPONSABILE_FUNZIONALE'
    ) THEN 'ATTENZIONE - responsabile LChilan non allineato'
    WHEN NOT EXISTS (
        SELECT 1
        FROM hr_relazioni_organizzative ro
        INNER JOIN hr_tipi_relazione_organizzativa tr
            ON tr.id_tipo_relazione = ro.id_tipo_relazione
        WHERE ro.id_utente = @id_gdimasi
          AND ro.id_utente_collegato = @id_mmorleo
          AND ro.attiva = 1
          AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
          AND tr.codice = 'RESPONSABILE_FUNZIONALE'
    ) THEN 'ATTENZIONE - responsabile GDimasi non allineato'
    WHEN NOT EXISTS (
        SELECT 1
        FROM hr_relazioni_organizzative ro
        INNER JOIN hr_tipi_relazione_organizzativa tr
            ON tr.id_tipo_relazione = ro.id_tipo_relazione
        WHERE ro.id_utente = @id_jtrujillo
          AND ro.id_utente_collegato = @id_fmillefanti
          AND ro.attiva = 1
          AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
          AND tr.codice = 'RESPONSABILE_FUNZIONALE'
    ) THEN 'ATTENZIONE - responsabile JTrujillo non allineato'
    WHEN EXISTS (
        SELECT 1
        FROM hr_relazioni_organizzative ro
        INNER JOIN hr_tipi_relazione_organizzativa tr
            ON tr.id_tipo_relazione = ro.id_tipo_relazione
        WHERE ro.id_utente = @id_fcoffari
          AND ro.attiva = 1
          AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
          AND tr.codice IN ('RESPONSABILE_FUNZIONALE','RESPONSABILE_DIRETTO')
    ) THEN 'ATTENZIONE - FCoffari ha ancora un responsabile attivo'
    ELSE 'OK - password e responsabili configurati correttamente'
END AS esito_finale;
