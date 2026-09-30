-- LEVANTE WEB - Calendario personale per tutti gli utenti.
-- MySQL 5.7.4+ / MySQL 8. Script rieseguibile: conserva i token gia' creati.
-- Non contiene token reali. RANDOM_BYTES genera i segreti direttamente nel DB.
-- Non cambia aut_utenti, password, ruoli o permessi esistenti.
-- La tabella hr_ics_token_utenti contiene segreti: non esportarla in repository.

ROLLBACK;
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS hr_ics_token_utenti (
    id_utente INT UNSIGNED NOT NULL,
    token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_utente),
    UNIQUE KEY uk_hr_ics_token (token),
    CONSTRAINT fk_hr_ics_token_utente FOREIGN KEY (id_utente)
        REFERENCES aut_utenti (id_utente) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TEMPORARY TABLE IF EXISTS lw_ics_nuovi;
CREATE TEMPORARY TABLE lw_ics_nuovi (
    id_utente INT UNSIGNED NOT NULL PRIMARY KEY,
    token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB;

START TRANSACTION;
-- Blocca gli account per evitare due inizializzazioni contemporanee.
SELECT id_utente FROM aut_utenti ORDER BY id_utente FOR UPDATE;

SET @lw_ics_precheck := (
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('aut_utenti','hr_ics_token_utenti','hr_configurazioni','aut_risorse')
       AND ENGINE = 'InnoDB') = 4
    AND (SELECT COUNT(*) FROM aut_risorse WHERE codice_risorsa = 'menu.profilo' AND attivo = 1) = 1
);

-- Ogni account riceve un token diverso. Gli account inattivi restano
-- esclusi dall'accesso ICS grazie al controllo gia' presente nell'endpoint.
INSERT INTO lw_ics_nuovi (id_utente, token)
SELECT u.id_utente, LOWER(HEX(RANDOM_BYTES(32)))
FROM aut_utenti u
LEFT JOIN hr_ics_token_utenti t ON t.id_utente = u.id_utente
WHERE t.id_utente IS NULL AND @lw_ics_precheck = 1;

SET @lw_ics_da_creare := (SELECT COUNT(*) FROM lw_ics_nuovi);

INSERT INTO hr_ics_token_utenti (id_utente, token)
SELECT id_utente, token FROM lw_ics_nuovi WHERE @lw_ics_precheck = 1;

-- Aggiorna solo i token appena creati. Il vecchio token di prova viene
-- sostituito una sola volta; successive esecuzioni non revocano abbonamenti
-- e non riattivano configurazioni disabilitate dall'amministratore.
INSERT INTO hr_configurazioni (codice, valore, descrizione, attivo)
SELECT CONCAT('HR_ICS_TOKEN_SHA256_USER_', id_utente), SHA2(token,256),
       'SHA-256 token calendario personale', 1
FROM lw_ics_nuovi WHERE @lw_ics_precheck = 1
ON DUPLICATE KEY UPDATE valore = VALUES(valore),
    descrizione = VALUES(descrizione), attivo = 1;

INSERT INTO aut_risorse
    (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.mio_calendario', 'Il mio calendario', 'pagina', p.id_risorsa,
       '/mio_calendario.php', 'la-calendar', 1, 35, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.profilo' AND @lw_ics_precheck = 1
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.mio_calendario');
UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.profilo'
SET r.descrizione = 'Il mio calendario', r.tipo_risorsa = 'pagina',
    r.id_risorsa_padre = p.id_risorsa, r.percorso = '/mio_calendario.php',
    r.icona = 'la-calendar', r.visibile_menu = 1, r.ordinamento = 35, r.attivo = 1
WHERE r.codice_risorsa = 'pagina.mio_calendario' AND @lw_ics_precheck = 1;

SET @lw_ics_creati := (
    SELECT COUNT(*) FROM lw_ics_nuovi n
    JOIN hr_ics_token_utenti t ON t.id_utente = n.id_utente AND t.token = n.token
    JOIN hr_configurazioni c ON c.codice = CONCAT('HR_ICS_TOKEN_SHA256_USER_', n.id_utente)
       AND c.attivo = 1 AND c.valore = SHA2(n.token,256)
);
SET @lw_ics_mancanti := (
    SELECT COUNT(*) FROM aut_utenti u
    LEFT JOIN hr_ics_token_utenti t ON t.id_utente = u.id_utente
    WHERE t.id_utente IS NULL
);
SET @lw_ics_commit_ok := (
    @lw_ics_precheck = 1 AND @lw_ics_creati = @lw_ics_da_creare AND @lw_ics_mancanti = 0
    AND (SELECT COUNT(*) FROM aut_risorse
         WHERE codice_risorsa = 'pagina.mio_calendario' AND attivo = 1 AND visibile_menu = 1) = 1
);
SET @lw_ics_chiusura := IF(COALESCE(@lw_ics_commit_ok,0) = 1, 'COMMIT', 'ROLLBACK');
PREPARE lw_ics_chiudi FROM @lw_ics_chiusura;
EXECUTE lw_ics_chiudi;
DEALLOCATE PREPARE lw_ics_chiudi;

-- Risultato senza mostrare i segreti.
SELECT IF(COALESCE(@lw_ics_commit_ok,0) = 1, 'OK - calendari personali configurati',
          'BLOCCATO - operazione annullata. Verificare menu Profilo e tabelle InnoDB') AS esito_finale,
       IF(COALESCE(@lw_ics_commit_ok,0) = 1,@lw_ics_creati,0) AS nuovi_token,
       (SELECT COUNT(*) FROM hr_ics_token_utenti) AS token_totali,
       @lw_ics_chiusura AS transazione;
DROP TEMPORARY TABLE lw_ics_nuovi;
