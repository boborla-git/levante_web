-- LEVANTE WEB - Configurazione nuovi utenti
-- Data: 2026-09-29
-- Obiettivi:
--   1) password iniziale test@123 senza cambio obbligatorio
--   2) assegnazione responsabile funzionale:
--      LChilan    -> test_FMillefanti
--      FCoffari   -> nessuno
--      GDimasi    -> test_MMorleo
--      JTrujillo  -> test_FMillefanti
--
-- Lo script e' prudente e sostanzialmente idempotente:
-- se i prerequisiti non sono soddisfatti, le modifiche non vengono applicate.

SET @password_hash_test123 := '$2y$12$jn0T.KfP.Q85F548AA71c.m7n7ecu6CgTpIqh7lh8J4DMbSZf/PAC';

SET @precheck_ok := (
    (SELECT COUNT(*)
     FROM aut_utenti
     WHERE username IN ('LChilan','FCoffari','GDimasi','JTrujillo')
       AND attivo = 1) = 4
    AND
    (SELECT COUNT(*)
     FROM aut_utenti
     WHERE username IN ('test_FMillefanti','test_MMorleo')
       AND attivo = 1) = 2
    AND
    (SELECT COUNT(*)
     FROM hr_tipi_relazione_organizzativa
     WHERE codice = 'RESPONSABILE_FUNZIONALE'
       AND attivo = 1) = 1
);

SELECT
    CASE
        WHEN @precheck_ok = 1
            THEN 'OK - prerequisiti presenti'
        ELSE 'ERRORE - mancano utenti/responsabili o tipo RESPONSABILE_FUNZIONALE'
    END AS controllo_preliminare;

START TRANSACTION;

-- Password iniziale richiesta, senza obbligo di cambio al primo accesso.
UPDATE aut_utenti
SET password_hash = @password_hash_test123,
    deve_cambiare_password = 0,
    data_aggiornamento = NOW()
WHERE @precheck_ok = 1
  AND username IN ('LChilan','FCoffari','GDimasi','JTrujillo');

-- Chiude solo eventuali relazioni responsabile non coerenti con la configurazione richiesta.
-- Per FCoffari chiude qualsiasi relazione gerarchica/funzionale attiva.
UPDATE hr_relazioni_organizzative ro
INNER JOIN aut_utenti u
    ON u.id_utente = ro.id_utente
INNER JOIN aut_utenti responsabile
    ON responsabile.id_utente = ro.id_utente_collegato
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
  AND tr.codice IN ('RESPONSABILE_FUNZIONALE','RESPONSABILE_DIRETTO')
  AND u.username IN ('LChilan','FCoffari','GDimasi','JTrujillo')
  AND (
        u.username = 'FCoffari'
        OR (
            u.username IN ('LChilan','JTrujillo')
            AND NOT (
                responsabile.username = 'test_FMillefanti'
                AND tr.codice = 'RESPONSABILE_FUNZIONALE'
            )
        )
        OR (
            u.username = 'GDimasi'
            AND NOT (
                responsabile.username = 'test_MMorleo'
                AND tr.codice = 'RESPONSABILE_FUNZIONALE'
            )
        )
      );

-- Inserisce le tre relazioni richieste se non sono gia' presenti.
INSERT INTO hr_relazioni_organizzative
    (id_utente, id_utente_collegato, id_tipo_relazione, data_inizio, data_fine, attiva, note)
SELECT
    u.id_utente,
    responsabile.id_utente,
    tr.id_tipo_relazione,
    CURDATE(),
    NULL,
    1,
    'Configurata da script nuovi utenti 2026-09-29'
FROM aut_utenti u
INNER JOIN aut_utenti responsabile
    ON responsabile.username =
       CASE
           WHEN u.username IN ('LChilan','JTrujillo') THEN 'test_FMillefanti'
           WHEN u.username = 'GDimasi' THEN 'test_MMorleo'
       END
   AND responsabile.attivo = 1
INNER JOIN hr_tipi_relazione_organizzativa tr
    ON tr.codice = 'RESPONSABILE_FUNZIONALE'
   AND tr.attivo = 1
WHERE @precheck_ok = 1
  AND u.username IN ('LChilan','GDimasi','JTrujillo')
  AND u.attivo = 1
  AND NOT EXISTS (
      SELECT 1
      FROM hr_relazioni_organizzative rox
      INNER JOIN hr_tipi_relazione_organizzativa trx
          ON trx.id_tipo_relazione = rox.id_tipo_relazione
      WHERE rox.id_utente = u.id_utente
        AND rox.id_utente_collegato = responsabile.id_utente
        AND rox.attiva = 1
        AND (rox.data_fine IS NULL OR rox.data_fine >= CURDATE())
        AND trx.codice = 'RESPONSABILE_FUNZIONALE'
  );

COMMIT;

-- Verifica leggibile dei quattro utenti.
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
LEFT JOIN hr_relazioni_organizzative ro
    ON ro.id_utente = u.id_utente
LEFT JOIN hr_tipi_relazione_organizzativa tr
    ON tr.id_tipo_relazione = ro.id_tipo_relazione
LEFT JOIN aut_utenti r
    ON r.id_utente = ro.id_utente_collegato
WHERE u.username IN ('LChilan','FCoffari','GDimasi','JTrujillo')
GROUP BY
    u.id_utente, u.username, u.nome, u.cognome, u.attivo,
    u.deve_cambiare_password, u.password_hash
ORDER BY u.cognome, u.nome;

-- Esito finale sintetico.
SELECT
    CASE
        WHEN @precheck_ok <> 1 THEN
            'ERRORE - prerequisiti non soddisfatti, nessuna configurazione applicata'
        WHEN
            (SELECT COUNT(*)
             FROM aut_utenti
             WHERE username IN ('LChilan','FCoffari','GDimasi','JTrujillo')
               AND password_hash = @password_hash_test123
               AND deve_cambiare_password = 0
               AND attivo = 1) <> 4
        THEN 'ATTENZIONE - password/flag utenti non allineati'
        WHEN
            (SELECT COUNT(*)
             FROM hr_relazioni_organizzative ro
             INNER JOIN aut_utenti u ON u.id_utente = ro.id_utente
             INNER JOIN aut_utenti r ON r.id_utente = ro.id_utente_collegato
             INNER JOIN hr_tipi_relazione_organizzativa tr ON tr.id_tipo_relazione = ro.id_tipo_relazione
             WHERE ro.attiva = 1
               AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
               AND tr.codice = 'RESPONSABILE_FUNZIONALE'
               AND (
                    (u.username IN ('LChilan','JTrujillo') AND r.username = 'test_FMillefanti')
                    OR
                    (u.username = 'GDimasi' AND r.username = 'test_MMorleo')
               )
            ) <> 3
        THEN 'ATTENZIONE - relazioni responsabile non allineate'
        WHEN
            (SELECT COUNT(*)
             FROM hr_relazioni_organizzative ro
             INNER JOIN aut_utenti u ON u.id_utente = ro.id_utente
             INNER JOIN hr_tipi_relazione_organizzativa tr ON tr.id_tipo_relazione = ro.id_tipo_relazione
             WHERE u.username = 'FCoffari'
               AND ro.attiva = 1
               AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
               AND tr.codice IN ('RESPONSABILE_FUNZIONALE','RESPONSABILE_DIRETTO')
            ) <> 0
        THEN 'ATTENZIONE - FCoffari ha ancora un responsabile attivo'
        ELSE 'OK - password e responsabili configurati correttamente'
    END AS esito_finale;
