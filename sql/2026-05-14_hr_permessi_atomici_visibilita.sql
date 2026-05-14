-- LEVANTE WEB / Ravioli S.p.A.
-- HR Enterprise hardening - permessi atomici visibilita' assenze
-- Data: 2026-05-14
--
-- Obiettivo:
-- - NON creare un unico ruolo globale per HR e Direzione
-- - creare permessi atomici/componibili
-- - assegnare gli stessi permessi HR di visibilita' a ruoli distinti:
--   * HR responsabile personale
--   * Direzione - visibilita' HR
--
-- Script idempotente: puo' essere rilanciato senza duplicare risorse, ruoli o permessi.

SET NAMES utf8mb4;

-- =========================================================
-- 1) RISORSE ATOMICHE HR
-- =========================================================

INSERT INTO aut_risorse
(codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT
    'azione.hr.assenze.visualizza_tutte',
    'HR - Visualizza tutte le assenze',
    'azione',
    p.id_risorsa,
    NULL,
    NULL,
    0,
    1010,
    1
FROM aut_risorse p
WHERE p.codice_risorsa = 'pagina.calendario_assenze'
  AND NOT EXISTS (
      SELECT 1
      FROM aut_risorse r
      WHERE r.codice_risorsa = 'azione.hr.assenze.visualizza_tutte'
  );

INSERT INTO aut_risorse
(codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT
    'azione.hr.assenze.visualizza_tipologie',
    'HR - Visualizza tipologie reali assenze',
    'azione',
    p.id_risorsa,
    NULL,
    NULL,
    0,
    1020,
    1
FROM aut_risorse p
WHERE p.codice_risorsa = 'pagina.calendario_assenze'
  AND NOT EXISTS (
      SELECT 1
      FROM aut_risorse r
      WHERE r.codice_risorsa = 'azione.hr.assenze.visualizza_tipologie'
  );

INSERT INTO aut_risorse
(codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT
    'azione.hr.assenze.visualizza_pendenti_globali',
    'HR - Visualizza richieste pendenti globali',
    'azione',
    p.id_risorsa,
    NULL,
    NULL,
    0,
    1030,
    1
FROM aut_risorse p
WHERE p.codice_risorsa = 'pagina.calendario_assenze'
  AND NOT EXISTS (
      SELECT 1
      FROM aut_risorse r
      WHERE r.codice_risorsa = 'azione.hr.assenze.visualizza_pendenti_globali'
  );

-- =========================================================
-- 2) RUOLI DISTINTI
-- =========================================================

INSERT INTO aut_ruoli
(codice_ruolo, descrizione, id_ruolo_padre, attivo, ordinamento)
SELECT
    'hr_responsabile_personale',
    'HR - Responsabile personale',
    NULL,
    1,
    240
WHERE NOT EXISTS (
    SELECT 1 FROM aut_ruoli WHERE codice_ruolo = 'hr_responsabile_personale'
);

INSERT INTO aut_ruoli
(codice_ruolo, descrizione, id_ruolo_padre, attivo, ordinamento)
SELECT
    'direzione_visibilita_hr',
    'Direzione - Visibilita HR',
    NULL,
    1,
    55
WHERE NOT EXISTS (
    SELECT 1 FROM aut_ruoli WHERE codice_ruolo = 'direzione_visibilita_hr'
);

-- =========================================================
-- 3) PERMESSI SUI RUOLI
-- =========================================================

INSERT INTO aut_ruoli_permessi
(id_ruolo, id_risorsa, permesso, consentito)
SELECT ru.id_ruolo, ri.id_risorsa, 'read', 1
FROM aut_ruoli ru
INNER JOIN aut_risorse ri
    ON ri.codice_risorsa IN (
        'azione.hr.assenze.visualizza_tutte',
        'azione.hr.assenze.visualizza_tipologie',
        'azione.hr.assenze.visualizza_pendenti_globali'
    )
WHERE ru.codice_ruolo IN ('hr_responsabile_personale', 'direzione_visibilita_hr')
  AND NOT EXISTS (
      SELECT 1
      FROM aut_ruoli_permessi rp
      WHERE rp.id_ruolo = ru.id_ruolo
        AND rp.id_risorsa = ri.id_risorsa
        AND rp.permesso = 'read'
  );

-- =========================================================
-- 4) ASSEGNAZIONI UTENTI
-- =========================================================
-- Giorgia Bettolini ufficiale + test -> ruolo HR responsabile personale
-- Stefano Daidone ufficiale + test -> ruolo Direzione visibilita HR

INSERT INTO aut_utenti_ruoli
(id_utente, id_ruolo, data_inizio, data_fine, attivo)
SELECT u.id_utente, r.id_ruolo, NOW(), NULL, 1
FROM aut_utenti u
INNER JOIN aut_ruoli r
    ON r.codice_ruolo = 'hr_responsabile_personale'
WHERE u.attivo = 1
  AND LOWER(u.nome) = 'giorgia'
  AND LOWER(u.cognome) = 'bettolini'
  AND NOT EXISTS (
      SELECT 1
      FROM aut_utenti_ruoli ur
      WHERE ur.id_utente = u.id_utente
        AND ur.id_ruolo = r.id_ruolo
        AND ur.attivo = 1
        AND (ur.data_fine IS NULL OR ur.data_fine >= NOW())
  );

INSERT INTO aut_utenti_ruoli
(id_utente, id_ruolo, data_inizio, data_fine, attivo)
SELECT u.id_utente, r.id_ruolo, NOW(), NULL, 1
FROM aut_utenti u
INNER JOIN aut_ruoli r
    ON r.codice_ruolo = 'direzione_visibilita_hr'
WHERE u.attivo = 1
  AND LOWER(u.nome) = 'stefano'
  AND LOWER(u.cognome) = 'daidone'
  AND NOT EXISTS (
      SELECT 1
      FROM aut_utenti_ruoli ur
      WHERE ur.id_utente = u.id_utente
        AND ur.id_ruolo = r.id_ruolo
        AND ur.attivo = 1
        AND (ur.data_fine IS NULL OR ur.data_fine >= NOW())
  );

-- =========================================================
-- 5) VERIFICA
-- =========================================================

SELECT
    u.id_utente,
    u.username,
    u.nome,
    u.cognome,
    r.codice_ruolo,
    r.descrizione AS ruolo
FROM aut_utenti u
INNER JOIN aut_utenti_ruoli ur
    ON ur.id_utente = u.id_utente
   AND ur.attivo = 1
   AND (ur.data_fine IS NULL OR ur.data_fine >= NOW())
INNER JOIN aut_ruoli r
    ON r.id_ruolo = ur.id_ruolo
WHERE r.codice_ruolo IN ('hr_responsabile_personale', 'direzione_visibilita_hr')
ORDER BY r.codice_ruolo, u.cognome, u.nome, u.username;

SELECT
    r.codice_ruolo,
    ri.codice_risorsa,
    rp.permesso,
    rp.consentito
FROM aut_ruoli r
INNER JOIN aut_ruoli_permessi rp
    ON rp.id_ruolo = r.id_ruolo
INNER JOIN aut_risorse ri
    ON ri.id_risorsa = rp.id_risorsa
WHERE r.codice_ruolo IN ('hr_responsabile_personale', 'direzione_visibilita_hr')
  AND ri.codice_risorsa LIKE 'azione.hr.assenze.%'
ORDER BY r.codice_ruolo, ri.codice_risorsa, rp.permesso;
