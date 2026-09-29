-- LEVANTE WEB - Migrazione definitiva Qualifica INPS
-- Data: 2026-09-29
-- NON modifica nome/cognome.
-- Mantiene admin / SDaidone / EDaidone senza Qualifica INPS.
--
-- Prerequisiti verificati:
--   41 utenti HR attesi e trovati
--   44 utenti attivi totali
--   3 extra attivi: admin, SDaidone, EDaidone
--   21 operai / 20 impiegati
--
-- La migrazione applica modifiche SOLO se questi prerequisiti risultano ancora veri.

DROP TEMPORARY TABLE IF EXISTS tmp_hr_qualifiche_inps;

CREATE TEMPORARY TABLE tmp_hr_qualifiche_inps (
    username VARCHAR(100) NOT NULL,
    qualifica_inps VARCHAR(20) NOT NULL,
    PRIMARY KEY (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_hr_qualifiche_inps (username, qualifica_inps) VALUES
('test_GAlchieri',    'IMPIEGATO'),
('test_EAndrioli',    'OPERAIO'),
('test_CArioli',      'IMPIEGATO'),
('test_GBettolini',   'IMPIEGATO'),
('test_PCeriani',     'IMPIEGATO'),
('test_MCervellera',  'OPERAIO'),
('LChilan',           'OPERAIO'),
('FCoffari',          'IMPIEGATO'),
('test_EConti',       'OPERAIO'),
('test_MCuomo',       'OPERAIO'),
('GDimasi',           'OPERAIO'),
('test_VFerraris',    'IMPIEGATO'),
('test_GFeudale',     'IMPIEGATO'),
('test_CFugagnoli',   'IMPIEGATO'),
('test_MGagliardo',   'OPERAIO'),
('test_GGalbiati',    'IMPIEGATO'),
('test_MGiuliani',    'IMPIEGATO'),
('test_CGozzi',       'IMPIEGATO'),
('test_IHossain',     'OPERAIO'),
('test_FLandriani',   'OPERAIO'),
('test_ELanzallotto', 'IMPIEGATO'),
('test_VLiso',        'OPERAIO'),
('test_RMagna',       'OPERAIO'),
('test_FMillefanti',  'IMPIEGATO'),
('test_MMorleo',      'IMPIEGATO'),
('test_DMuzio',       'OPERAIO'),
('test_ROrlandi',     'IMPIEGATO'),
('test_IPiccinin',    'IMPIEGATO'),
('test_APiccinni',    'OPERAIO'),
('test_CRicetti',     'OPERAIO'),
('test_FRusciano',    'IMPIEGATO'),
('test_GSalihovic',   'IMPIEGATO'),
('test_SSchiavone',   'OPERAIO'),
('DSemenzato',        'OPERAIO'),
('test_ASpagnoli',    'IMPIEGATO'),
('test_CSpagnoli',    'OPERAIO'),
('test_LTinelli',     'OPERAIO'),
('test_Cteixeira',    'OPERAIO'),
('JTrujillo',         'OPERAIO'),
('test_FVolpi',       'OPERAIO'),
('test_EWarnots',     'IMPIEGATO');

SET @utenti_hr_attesi := (
    SELECT COUNT(*) FROM tmp_hr_qualifiche_inps
);

SET @utenti_hr_trovati := (
    SELECT COUNT(*)
    FROM tmp_hr_qualifiche_inps t
    INNER JOIN aut_utenti u
        ON CAST(u.username AS BINARY) = CAST(t.username AS BINARY)
       AND u.attivo = 1
);

SET @utenti_attivi_db := (
    SELECT COUNT(*) FROM aut_utenti WHERE attivo = 1
);

SET @extra_attivi_db := (
    SELECT COUNT(*)
    FROM aut_utenti u
    LEFT JOIN tmp_hr_qualifiche_inps t
        ON CAST(t.username AS BINARY) = CAST(u.username AS BINARY)
    WHERE u.attivo = 1
      AND t.username IS NULL
);

SET @extra_specifici_ok := (
    SELECT COUNT(*)
    FROM aut_utenti
    WHERE attivo = 1
      AND username IN ('admin','SDaidone','EDaidone')
);

SET @operai_attesi := (
    SELECT COUNT(*) FROM tmp_hr_qualifiche_inps WHERE qualifica_inps = 'OPERAIO'
);

SET @impiegati_attesi := (
    SELECT COUNT(*) FROM tmp_hr_qualifiche_inps WHERE qualifica_inps = 'IMPIEGATO'
);

SET @precheck_ok := (
       @utenti_hr_attesi = 41
   AND @utenti_hr_trovati = 41
   AND @utenti_attivi_db = 44
   AND @extra_attivi_db = 3
   AND @extra_specifici_ok = 3
   AND @operai_attesi = 21
   AND @impiegati_attesi = 20
);

SELECT
    @utenti_hr_attesi AS utenti_hr_attesi,
    @utenti_hr_trovati AS utenti_hr_trovati,
    @utenti_attivi_db AS utenti_attivi_db,
    @extra_attivi_db AS extra_attivi_db,
    @operai_attesi AS operai_attesi,
    @impiegati_attesi AS impiegati_attesi,
    CASE
        WHEN @precheck_ok = 1 THEN 'OK - prerequisiti confermati'
        ELSE 'STOP - prerequisiti non confermati'
    END AS esito_precheck;

SET @colonna_esiste := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hr_profili_dipendenti'
      AND COLUMN_NAME = 'qualifica_inps'
);

SET @sql_alter := IF(
    @precheck_ok = 1 AND @colonna_esiste = 0,
    'ALTER TABLE hr_profili_dipendenti ADD COLUMN qualifica_inps VARCHAR(20) DEFAULT NULL AFTER mansione',
    'SELECT ''Nessuna ALTER necessaria'' AS info'
);

PREPARE stmt_alter FROM @sql_alter;
EXECUTE stmt_alter;
DEALLOCATE PREPARE stmt_alter;

INSERT INTO hr_profili_dipendenti
    (id_utente, attivo, data_creazione, data_aggiornamento)
SELECT
    u.id_utente,
    1,
    NOW(),
    NOW()
FROM aut_utenti u
INNER JOIN tmp_hr_qualifiche_inps t
    ON CAST(t.username AS BINARY) = CAST(u.username AS BINARY)
LEFT JOIN hr_profili_dipendenti p
    ON p.id_utente = u.id_utente
WHERE @precheck_ok = 1
  AND u.attivo = 1
  AND p.id_utente IS NULL;

UPDATE hr_profili_dipendenti p
INNER JOIN aut_utenti u
    ON u.id_utente = p.id_utente
INNER JOIN tmp_hr_qualifiche_inps t
    ON CAST(t.username AS BINARY) = CAST(u.username AS BINARY)
SET p.qualifica_inps = t.qualifica_inps,
    p.data_aggiornamento = NOW()
WHERE @precheck_ok = 1
  AND u.attivo = 1;

SELECT
    u.id_utente,
    u.username,
    u.cognome,
    u.nome,
    p.qualifica_inps
FROM aut_utenti u
LEFT JOIN hr_profili_dipendenti p
    ON p.id_utente = u.id_utente
WHERE u.attivo = 1
ORDER BY u.cognome, u.nome, u.username;

SET @qualifiche_valorizzate := (
    SELECT COUNT(*)
    FROM tmp_hr_qualifiche_inps t
    INNER JOIN aut_utenti u
        ON CAST(u.username AS BINARY) = CAST(t.username AS BINARY)
    INNER JOIN hr_profili_dipendenti p
        ON p.id_utente = u.id_utente
       AND CAST(p.qualifica_inps AS BINARY) = CAST(t.qualifica_inps AS BINARY)
    WHERE u.attivo = 1
);

SET @extra_con_qualifica := (
    SELECT COUNT(*)
    FROM aut_utenti u
    INNER JOIN hr_profili_dipendenti p
        ON p.id_utente = u.id_utente
    WHERE u.attivo = 1
      AND u.username IN ('admin','SDaidone','EDaidone')
      AND p.qualifica_inps IS NOT NULL
      AND TRIM(p.qualifica_inps) <> ''
);

SELECT
    @qualifiche_valorizzate AS qualifiche_valorizzate,
    @extra_con_qualifica AS extra_con_qualifica,
    CASE
        WHEN @precheck_ok <> 1
            THEN 'STOP - prerequisiti non confermati'
        WHEN @qualifiche_valorizzate <> 41
            THEN 'ATTENZIONE - non tutte le 41 qualifiche risultano valorizzate'
        WHEN @extra_con_qualifica <> 0
            THEN 'ATTENZIONE - admin / SDaidone / EDaidone hanno una qualifica non prevista'
        ELSE 'OK - Qualifica INPS configurata correttamente'
    END AS esito_finale;

DROP TEMPORARY TABLE IF EXISTS tmp_hr_qualifiche_inps;
