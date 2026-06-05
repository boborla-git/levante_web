SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hr_comunicazioni (
    id_comunicazione BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo VARCHAR(30) NOT NULL DEFAULT 'COMUNICAZIONE',
    titolo VARCHAR(180) NOT NULL,
    testo TEXT NOT NULL,
    nome_documento VARCHAR(180) DEFAULT NULL,
    url_documento VARCHAR(255) DEFAULT NULL,
    data_pubblicazione DATE NOT NULL,
    data_scadenza DATE DEFAULT NULL,
    visibile TINYINT(1) NOT NULL DEFAULT 1,
    richiede_presa_visione TINYINT(1) NOT NULL DEFAULT 0,
    creato_da INT UNSIGNED DEFAULT NULL,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_aggiornamento DATETIME DEFAULT NULL,
    PRIMARY KEY (id_comunicazione),
    KEY ix_hr_comunicazioni_tipo (tipo),
    KEY ix_hr_comunicazioni_pubblicazione (visibile, data_pubblicazione, data_scadenza),
    KEY ix_hr_comunicazioni_creato_da (creato_da),
    CONSTRAINT fk_hr_comunicazioni_creato_da
        FOREIGN KEY (creato_da) REFERENCES aut_utenti (id_utente)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_comunicazioni_letture (
    id_comunicazione_lettura BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_comunicazione BIGINT UNSIGNED NOT NULL,
    id_utente INT UNSIGNED NOT NULL,
    data_lettura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_comunicazione_lettura),
    UNIQUE KEY uk_hr_comunicazioni_letture (id_comunicazione, id_utente),
    KEY ix_hr_comunicazioni_letture_utente (id_utente),
    CONSTRAINT fk_hr_comunicazioni_letture_comunicazione
        FOREIGN KEY (id_comunicazione) REFERENCES hr_comunicazioni (id_comunicazione)
        ON DELETE CASCADE,
    CONSTRAINT fk_hr_comunicazioni_letture_utente
        FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_richieste_iban (
    id_richiesta_iban BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice_richiesta VARCHAR(40) NOT NULL,
    id_utente INT UNSIGNED NOT NULL,
    intestatario VARCHAR(180) NOT NULL,
    iban VARCHAR(34) NOT NULL,
    banca VARCHAR(180) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    stato VARCHAR(30) NOT NULL DEFAULT 'INVIATA',
    presa_in_carico_da INT UNSIGNED DEFAULT NULL,
    note_hr TEXT DEFAULT NULL,
    data_richiesta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_chiusura DATETIME DEFAULT NULL,
    PRIMARY KEY (id_richiesta_iban),
    UNIQUE KEY uk_hr_richieste_iban_codice (codice_richiesta),
    KEY ix_hr_richieste_iban_utente (id_utente),
    KEY ix_hr_richieste_iban_stato (stato),
    KEY ix_hr_richieste_iban_presa_in_carico (presa_in_carico_da),
    CONSTRAINT fk_hr_richieste_iban_utente
        FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente)
        ON DELETE CASCADE,
    CONSTRAINT fk_hr_richieste_iban_presa_in_carico
        FOREIGN KEY (presa_in_carico_da) REFERENCES aut_utenti (id_utente)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, puo_leggere, puo_scrivere)
SELECT p.id_ruolo, r2.id_risorsa, 1, 0
FROM aut_ruoli_permessi p
JOIN aut_risorse r1 ON r1.id_risorsa = p.id_risorsa
JOIN aut_risorse r2 ON r2.codice_risorsa IN ('pagina.hr_comunicazioni', 'pagina.cambio_iban')
LEFT JOIN aut_ruoli_permessi x ON x.id_ruolo = p.id_ruolo AND x.id_risorsa = r2.id_risorsa
WHERE r1.codice_risorsa = 'pagina.assenze'
  AND p.puo_leggere = 1
  AND x.id_ruolo IS NULL;

UPDATE aut_ruoli_permessi p
JOIN aut_risorse r ON r.id_risorsa = p.id_risorsa
SET p.puo_leggere = 1
WHERE r.codice_risorsa IN ('pagina.hr_comunicazioni', 'pagina.cambio_iban')
  AND p.id_ruolo IN (
      SELECT id_ruolo
      FROM (
          SELECT p2.id_ruolo
          FROM aut_ruoli_permessi p2
          JOIN aut_risorse r1 ON r1.id_risorsa = p2.id_risorsa
          WHERE r1.codice_risorsa = 'pagina.assenze'
            AND p2.puo_leggere = 1
      ) ruoli_hr_base
  );

UPDATE aut_ruoli_permessi p
JOIN aut_risorse r ON r.id_risorsa = p.id_risorsa
SET p.puo_scrivere = 1
WHERE r.codice_risorsa = 'pagina.hr_comunicazioni'
  AND p.id_ruolo IN (
      SELECT id_ruolo
      FROM (
          SELECT p2.id_ruolo
          FROM aut_ruoli_permessi p2
          JOIN aut_risorse r1 ON r1.id_risorsa = p2.id_risorsa
          WHERE r1.codice_risorsa = 'pagina.configurazione_assenze'
            AND p2.puo_leggere = 1
      ) ruoli_hr_admin
  );

UPDATE aut_ruoli_permessi p
JOIN aut_risorse r ON r.id_risorsa = p.id_risorsa
SET p.puo_scrivere = 1
WHERE r.codice_risorsa = 'pagina.cambio_iban'
  AND p.id_ruolo IN (
      SELECT id_ruolo
      FROM (
          SELECT p2.id_ruolo
          FROM aut_ruoli_permessi p2
          JOIN aut_risorse r1 ON r1.id_risorsa = p2.id_risorsa
          WHERE r1.codice_risorsa = 'pagina.assenze'
            AND p2.puo_leggere = 1
      ) ruoli_iban
  );
