SET NAMES utf8mb4;

START TRANSACTION;

-- Correzione permessi per HR Comunicazioni e Cambio IBAN.
-- Il database reale usa aut_ruoli_permessi.permesso + aut_ruoli_permessi.consentito,
-- non i campi puo_leggere / puo_scrivere.

-- Garantisce comunque la presenza delle due risorse, anche se lo script precedente si fosse fermato prima.
INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.hr_comunicazioni', 'HR Comunicazioni', 'pagina', p.id_risorsa, '/hr_comunicazioni.php', 'la-bullhorn', 1, 30, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.hr'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.hr_comunicazioni');

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.hr'
SET r.descrizione = 'HR Comunicazioni',
    r.tipo_risorsa = 'pagina',
    r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/hr_comunicazioni.php',
    r.icona = 'la-bullhorn',
    r.visibile_menu = 1,
    r.ordinamento = 30,
    r.attivo = 1
WHERE r.codice_risorsa = 'pagina.hr_comunicazioni';

INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.cambio_iban', 'Cambio IBAN', 'pagina', p.id_risorsa, '/cambio_iban.php', 'la-university', 1, 31, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.hr'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.cambio_iban');

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.hr'
SET r.descrizione = 'Cambio IBAN',
    r.tipo_risorsa = 'pagina',
    r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/cambio_iban.php',
    r.icona = 'la-university',
    r.visibile_menu = 1,
    r.ordinamento = 31,
    r.attivo = 1
WHERE r.codice_risorsa = 'pagina.cambio_iban';

-- HR Comunicazioni: lettura a chi legge le assenze.
INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, permesso, consentito)
SELECT DISTINCT src.id_ruolo, dest.id_risorsa, 'read', 1
FROM aut_ruoli_permessi src
JOIN aut_risorse assenze ON assenze.id_risorsa = src.id_risorsa
JOIN aut_risorse dest ON dest.codice_risorsa = 'pagina.hr_comunicazioni'
WHERE assenze.codice_risorsa = 'pagina.assenze'
  AND src.permesso = 'read'
  AND src.consentito = 1
  AND NOT EXISTS (
      SELECT 1
      FROM aut_ruoli_permessi ex
      WHERE ex.id_ruolo = src.id_ruolo
        AND ex.id_risorsa = dest.id_risorsa
        AND ex.permesso = 'read'
  );

UPDATE aut_ruoli_permessi rp
JOIN aut_risorse dest ON dest.id_risorsa = rp.id_risorsa
SET rp.consentito = 1
WHERE dest.codice_risorsa = 'pagina.hr_comunicazioni'
  AND rp.permesso = 'read'
  AND rp.id_ruolo IN (
      SELECT id_ruolo
      FROM (
          SELECT DISTINCT src.id_ruolo
          FROM aut_ruoli_permessi src
          JOIN aut_risorse assenze ON assenze.id_risorsa = src.id_risorsa
          WHERE assenze.codice_risorsa = 'pagina.assenze'
            AND src.permesso = 'read'
            AND src.consentito = 1
      ) ruoli_hr_base
  );

-- HR Comunicazioni: scrittura solo ai ruoli che leggono Configurazione assenze.
INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, permesso, consentito)
SELECT DISTINCT src.id_ruolo, dest.id_risorsa, 'write', 1
FROM aut_ruoli_permessi src
JOIN aut_risorse config ON config.id_risorsa = src.id_risorsa
JOIN aut_risorse dest ON dest.codice_risorsa = 'pagina.hr_comunicazioni'
WHERE config.codice_risorsa = 'pagina.configurazione_assenze'
  AND src.permesso = 'read'
  AND src.consentito = 1
  AND NOT EXISTS (
      SELECT 1
      FROM aut_ruoli_permessi ex
      WHERE ex.id_ruolo = src.id_ruolo
        AND ex.id_risorsa = dest.id_risorsa
        AND ex.permesso = 'write'
  );

UPDATE aut_ruoli_permessi rp
JOIN aut_risorse dest ON dest.id_risorsa = rp.id_risorsa
SET rp.consentito = 1
WHERE dest.codice_risorsa = 'pagina.hr_comunicazioni'
  AND rp.permesso = 'write'
  AND rp.id_ruolo IN (
      SELECT id_ruolo
      FROM (
          SELECT DISTINCT src.id_ruolo
          FROM aut_ruoli_permessi src
          JOIN aut_risorse config ON config.id_risorsa = src.id_risorsa
          WHERE config.codice_risorsa = 'pagina.configurazione_assenze'
            AND src.permesso = 'read'
            AND src.consentito = 1
      ) ruoli_hr_admin
  );

-- Cambio IBAN: lettura e scrittura ai ruoli che leggono Assenze.
-- La scrittura serve al dipendente per inviare la richiesta.
INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, permesso, consentito)
SELECT DISTINCT src.id_ruolo, dest.id_risorsa, perm.permesso, 1
FROM aut_ruoli_permessi src
JOIN aut_risorse assenze ON assenze.id_risorsa = src.id_risorsa
JOIN aut_risorse dest ON dest.codice_risorsa = 'pagina.cambio_iban'
JOIN (
    SELECT 'read' AS permesso
    UNION ALL
    SELECT 'write' AS permesso
) perm
WHERE assenze.codice_risorsa = 'pagina.assenze'
  AND src.permesso = 'read'
  AND src.consentito = 1
  AND NOT EXISTS (
      SELECT 1
      FROM aut_ruoli_permessi ex
      WHERE ex.id_ruolo = src.id_ruolo
        AND ex.id_risorsa = dest.id_risorsa
        AND ex.permesso = perm.permesso
  );

UPDATE aut_ruoli_permessi rp
JOIN aut_risorse dest ON dest.id_risorsa = rp.id_risorsa
SET rp.consentito = 1
WHERE dest.codice_risorsa = 'pagina.cambio_iban'
  AND rp.permesso IN ('read', 'write')
  AND rp.id_ruolo IN (
      SELECT id_ruolo
      FROM (
          SELECT DISTINCT src.id_ruolo
          FROM aut_ruoli_permessi src
          JOIN aut_risorse assenze ON assenze.id_risorsa = src.id_risorsa
          WHERE assenze.codice_risorsa = 'pagina.assenze'
            AND src.permesso = 'read'
            AND src.consentito = 1
      ) ruoli_iban
  );

-- Controllo finale: devono comparire righe read/write coerenti per le due nuove pagine.
SELECT
    r.codice_risorsa,
    r.descrizione,
    rp.id_ruolo,
    rp.permesso,
    rp.consentito
FROM aut_risorse r
LEFT JOIN aut_ruoli_permessi rp ON rp.id_risorsa = r.id_risorsa
WHERE r.codice_risorsa IN ('pagina.hr_comunicazioni', 'pagina.cambio_iban')
ORDER BY r.codice_risorsa, rp.id_ruolo, rp.permesso;

COMMIT;
