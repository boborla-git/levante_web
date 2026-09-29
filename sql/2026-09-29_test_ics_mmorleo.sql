-- =========================================================
-- LEVANTE WEB / RAVIOLI S.p.A.
-- Test feed ICS personale per test_MMorleo
-- Il token in chiaro NON viene salvato nel repository.
-- =========================================================

SET @ics_token_sha256 = 'c05c82e92e94bbcf95d16178879d32fb043a25cac97fe45745a3e017c2eba413';

INSERT INTO hr_configurazioni (codice, valore, descrizione, attivo)
SELECT
    CONCAT('HR_ICS_TOKEN_SHA256_USER_', u.id_utente),
    @ics_token_sha256,
    'SHA-256 token feed ICS personale - test_MMorleo',
    1
FROM aut_utenti u
WHERE u.username = 'test_MMorleo'
  AND u.attivo = 1
  AND NOT EXISTS (
      SELECT 1
      FROM hr_configurazioni c
      WHERE c.codice = CONCAT('HR_ICS_TOKEN_SHA256_USER_', u.id_utente)
  );

UPDATE hr_configurazioni c
INNER JOIN aut_utenti u
    ON c.codice = CONCAT('HR_ICS_TOKEN_SHA256_USER_', u.id_utente)
SET c.valore = @ics_token_sha256,
    c.descrizione = 'SHA-256 token feed ICS personale - test_MMorleo',
    c.attivo = 1
WHERE u.username = 'test_MMorleo'
  AND u.attivo = 1;

SELECT
    u.id_utente,
    u.username,
    c.codice,
    c.attivo
FROM aut_utenti u
LEFT JOIN hr_configurazioni c
    ON c.codice = CONCAT('HR_ICS_TOKEN_SHA256_USER_', u.id_utente)
WHERE u.username = 'test_MMorleo';
