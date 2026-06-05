SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS hr_chiusure_mese (
    anno INT NOT NULL,
    mese TINYINT NOT NULL,
    chiuso TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    aggiornato_da INT NULL,
    data_aggiornamento DATETIME NOT NULL,
    PRIMARY KEY (anno, mese),
    KEY idx_hr_chiusure_mese_utente (aggiornato_da),
    KEY idx_hr_chiusure_mese_stato (chiuso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.chiusure_mese', 'Chiusure mese', 'pagina', p.id_risorsa, '/chiusure_mese.php', 'la-lock', 1, 46, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'pagina.configurazione_assenze'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.chiusure_mese');

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'pagina.configurazione_assenze'
SET r.descrizione = 'Chiusure mese',
    r.tipo_risorsa = 'pagina',
    r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/chiusure_mese.php',
    r.icona = 'la-lock',
    r.visibile_menu = 1,
    r.ordinamento = 46,
    r.attivo = 1
WHERE r.codice_risorsa = 'pagina.chiusure_mese';

INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, permesso, consentito)
SELECT DISTINCT src.id_ruolo, dest.id_risorsa, perm.permesso, 1
FROM aut_ruoli_permessi src
JOIN aut_risorse conf ON conf.id_risorsa = src.id_risorsa
JOIN aut_risorse dest ON dest.codice_risorsa = 'pagina.chiusure_mese'
JOIN (SELECT 'read' AS permesso UNION ALL SELECT 'write') perm
WHERE conf.codice_risorsa = 'pagina.configurazione_assenze'
  AND src.permesso = 'read'
  AND src.consentito = 1
  AND NOT EXISTS (
      SELECT 1 FROM aut_ruoli_permessi ex
      WHERE ex.id_ruolo = src.id_ruolo
        AND ex.id_risorsa = dest.id_risorsa
        AND ex.permesso = perm.permesso
  );

COMMIT;

SELECT r.codice_risorsa, r.descrizione, p.codice_risorsa AS padre, r.percorso, r.visibile_menu, r.ordinamento
FROM aut_risorse r
LEFT JOIN aut_risorse p ON p.id_risorsa = r.id_risorsa_padre
WHERE r.codice_risorsa = 'pagina.chiusure_mese';
