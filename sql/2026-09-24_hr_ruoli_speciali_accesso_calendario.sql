-- LEVANTE WEB / Ravioli S.p.A.
-- Correzione accesso calendario per ruoli HR speciali
-- Data: 2026-09-24
--
-- Obiettivo:
-- - consentire a hr_responsabile_personale e direzione_visibilita_hr
--   di aprire il Calendario assenze e di vederlo nel menu HR;
-- - mantenere separati i permessi atomici di visibilita globale gia esistenti;
-- - NON modificare altri permessi dei due ruoli.
--
-- Script idempotente.

START TRANSACTION;

-- 1) Se le righe esistono gia ma sono negate, le abilitiamo.
UPDATE aut_ruoli_permessi rp
INNER JOIN aut_ruoli r
    ON r.id_ruolo = rp.id_ruolo
INNER JOIN aut_risorse s
    ON s.id_risorsa = rp.id_risorsa
SET rp.consentito = 1
WHERE r.codice_ruolo IN ('hr_responsabile_personale', 'direzione_visibilita_hr')
  AND (
        (s.codice_risorsa = 'menu.hr' AND rp.permesso = 'menu')
        OR
        (s.codice_risorsa = 'pagina.calendario_assenze' AND rp.permesso IN ('read', 'menu'))
      );

-- 2) Inseriamo le righe mancanti.
INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, permesso, consentito)
SELECT r.id_ruolo, s.id_risorsa, x.permesso, 1
FROM aut_ruoli r
INNER JOIN aut_risorse s
    ON s.codice_risorsa IN ('menu.hr', 'pagina.calendario_assenze')
INNER JOIN (
    SELECT 'menu.hr' AS codice_risorsa, 'menu' AS permesso
    UNION ALL
    SELECT 'pagina.calendario_assenze', 'read'
    UNION ALL
    SELECT 'pagina.calendario_assenze', 'menu'
) x
    ON x.codice_risorsa = s.codice_risorsa
WHERE r.codice_ruolo IN ('hr_responsabile_personale', 'direzione_visibilita_hr')
  AND NOT EXISTS (
      SELECT 1
      FROM aut_ruoli_permessi rp
      WHERE rp.id_ruolo = r.id_ruolo
        AND rp.id_risorsa = s.id_risorsa
        AND rp.permesso = x.permesso
  );

COMMIT;

-- 3) Verifica finale.
SELECT
    r.codice_ruolo,
    s.codice_risorsa,
    rp.permesso,
    rp.consentito
FROM aut_ruoli r
INNER JOIN aut_ruoli_permessi rp
    ON rp.id_ruolo = r.id_ruolo
INNER JOIN aut_risorse s
    ON s.id_risorsa = rp.id_risorsa
WHERE r.codice_ruolo IN ('hr_responsabile_personale', 'direzione_visibilita_hr')
  AND s.codice_risorsa IN (
      'menu.hr',
      'pagina.calendario_assenze',
      'azione.hr.assenze.visualizza_tutte',
      'azione.hr.assenze.visualizza_tipologie',
      'azione.hr.assenze.visualizza_pendenti_globali'
  )
ORDER BY r.codice_ruolo, s.codice_risorsa, rp.permesso;
