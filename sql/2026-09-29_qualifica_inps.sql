-- LEVANTE WEB - Qualifica INPS
-- Data: 2026-09-29
-- Aggiunge qualifica_inps ai profili HR e valorizza l'elenco fornito da Giorgia HR.

SET NAMES utf8mb4;

SET @colonna_esiste := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hr_profili_dipendenti'
      AND COLUMN_NAME = 'qualifica_inps'
);

SET @sql_add_colonna := IF(
    @colonna_esiste = 0,
    'ALTER TABLE hr_profili_dipendenti ADD COLUMN qualifica_inps VARCHAR(20) DEFAULT NULL AFTER mansione',
    'SELECT ''qualifica_inps gia presente'' AS info'
);

PREPARE stmt_add_colonna FROM @sql_add_colonna;
EXECUTE stmt_add_colonna;
DEALLOCATE PREPARE stmt_add_colonna;

DROP TEMPORARY TABLE IF EXISTS tmp_hr_qualifiche;
CREATE TEMPORARY TABLE tmp_hr_qualifiche (
    username VARCHAR(100) NOT NULL PRIMARY KEY,
    qualifica_inps VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_hr_qualifiche (username, qualifica_inps) VALUES
('test_GAlchieri','IMPIEGATO'),
('test_EAndrioli','OPERAIO'),
('test_CArioli','IMPIEGATO'),
('test_GBettolini','IMPIEGATO'),
('test_PCeriani','IMPIEGATO'),
('test_MCervellera','OPERAIO'),
('LChilan','OPERAIO'),
('FCoffari','IMPIEGATO'),
('test_EConti','OPERAIO'),
('test_MCuomo','OPERAIO'),
('GDimasi','OPERAIO'),
('test_VFerraris','IMPIEGATO'),
('test_GFeudale','IMPIEGATO'),
('test_CFugagnoli','IMPIEGATO'),
('test_MGagliardo','OPERAIO'),
('test_GGalbiati','IMPIEGATO'),
('test_MGiuliani','IMPIEGATO'),
('test_CGozzi','IMPIEGATO'),
('test_IHossain','OPERAIO'),
('test_FLandriani','OPERAIO'),
('test_ELanzallotto','IMPIEGATO'),
('test_VLiso','OPERAIO'),
('test_RMagna','OPERAIO'),
('test_FMillefanti','IMPIEGATO'),
('test_MMorleo','IMPIEGATO'),
('test_DMuzio','OPERAIO'),
('test_ROrlandi','IMPIEGATO'),
('test_IPiccinin','IMPIEGATO'),
('test_APiccinni','OPERAIO'),
('test_CRicetti','OPERAIO'),
('test_FRusciano','IMPIEGATO'),
('test_GSalihovic','IMPIEGATO'),
('test_SSchiavone','OPERAIO'),
('DSemenzato','OPERAIO'),
('test_ASpagnoli','IMPIEGATO'),
('test_CSpagnoli','OPERAIO'),
('test_LTinelli','OPERAIO'),
('test_Cteixeira','OPERAIO'),
('JTrujillo','OPERAIO'),
('test_FVolpi','OPERAIO'),
('test_EWarnots','IMPIEGATO');

INSERT INTO hr_profili_dipendenti (id_utente, attivo)
SELECT u.id_utente, 1
FROM aut_utenti u
INNER JOIN tmp_hr_qualifiche t
    ON BINARY t.username = BINARY u.username
LEFT JOIN hr_profili_dipendenti p
    ON p.id_utente = u.id_utente
WHERE u.attivo = 1
  AND p.id_utente IS NULL;

UPDATE hr_profili_dipendenti p
INNER JOIN aut_utenti u
    ON u.id_utente = p.id_utente
INNER JOIN tmp_hr_qualifiche t
    ON BINARY t.username = BINARY u.username
SET p.qualifica_inps = t.qualifica_inps,
    p.data_aggiornamento = NOW()
WHERE u.attivo = 1;

SELECT
    COUNT(*) AS utenti_hr_attesi,
    SUM(CASE WHEN u.id_utente IS NOT NULL THEN 1 ELSE 0 END) AS utenti_hr_trovati,
    SUM(CASE WHEN p.qualifica_inps IN ('OPERAIO','IMPIEGATO') THEN 1 ELSE 0 END) AS qualifiche_valorizzate
FROM tmp_hr_qualifiche t
LEFT JOIN aut_utenti u
    ON BINARY u.username = BINARY t.username
   AND u.attivo = 1
LEFT JOIN hr_profili_dipendenti p
    ON p.id_utente = u.id_utente;

DROP TEMPORARY TABLE IF EXISTS tmp_hr_qualifiche;
