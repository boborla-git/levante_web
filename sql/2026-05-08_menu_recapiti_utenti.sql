-- 2026-05-08 - Recapiti utenti nel menu HR
-- Obiettivo:
-- 1) rendere Recapiti utenti una pagina reale in aut_risorse/permessi_ruoli.php;
-- 2) mostrarla nel menu sotto HR > Configurazione assenze;
-- 3) assegnare gli stessi permessi della pagina Configurazione assenze ai ruoli già autorizzati.

START TRANSACTION;

INSERT INTO aut_risorse
    (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT
    'pagina.recapiti_utenti',
    'Recapiti utenti',
    'pagina',
    p.id_risorsa,
    '/recapiti_utenti.php',
    'la-envelope',
    1,
    30,
    1
FROM aut_risorse p
WHERE p.codice_risorsa = 'pagina.configurazione_assenze'
  AND NOT EXISTS (
      SELECT 1 FROM aut_risorse x WHERE x.codice_risorsa = 'pagina.recapiti_utenti'
  );

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'pagina.configurazione_assenze'
SET r.descrizione = 'Recapiti utenti',
    r.tipo_risorsa = 'pagina',
    r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/recapiti_utenti.php',
    r.icona = 'la-envelope',
    r.visibile_menu = 1,
    r.ordinamento = 30,
    r.attivo = 1
WHERE r.codice_risorsa = 'pagina.recapiti_utenti';

INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, permesso, consentito)
SELECT p.id_ruolo, recapiti.id_risorsa, p.permesso, p.consentito
FROM aut_ruoli_permessi p
JOIN aut_risorse config ON config.id_risorsa = p.id_risorsa
JOIN aut_risorse recapiti ON recapiti.codice_risorsa = 'pagina.recapiti_utenti'
WHERE config.codice_risorsa = 'pagina.configurazione_assenze'
  AND NOT EXISTS (
      SELECT 1
      FROM aut_ruoli_permessi ex
      WHERE ex.id_ruolo = p.id_ruolo
        AND ex.id_risorsa = recapiti.id_risorsa
        AND ex.permesso = p.permesso
  );

UPDATE aut_ruoli_permessi rp
JOIN aut_risorse recapiti ON recapiti.id_risorsa = rp.id_risorsa
JOIN aut_risorse config ON config.codice_risorsa = 'pagina.configurazione_assenze'
JOIN aut_ruoli_permessi src ON src.id_risorsa = config.id_risorsa
    AND src.id_ruolo = rp.id_ruolo
    AND src.permesso = rp.permesso
SET rp.consentito = src.consentito
WHERE recapiti.codice_risorsa = 'pagina.recapiti_utenti';

-- Controllo post-esecuzione: deve apparire una riga pagina.recapiti_utenti attiva e visibile nel menu.
SELECT
    r.codice_risorsa,
    r.descrizione,
    r.tipo_risorsa,
    p.codice_risorsa AS padre,
    r.percorso,
    r.visibile_menu,
    r.ordinamento,
    r.attivo
FROM aut_risorse r
LEFT JOIN aut_risorse p ON p.id_risorsa = r.id_risorsa_padre
WHERE r.codice_risorsa = 'pagina.recapiti_utenti';

COMMIT;
