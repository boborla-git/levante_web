-- 2026-09-17 - Recapiti personali e verifica email
-- Script idempotente per MySQL 5.7

START TRANSACTION;

INSERT INTO hr_tipi_recapito (codice, descrizione, attivo)
SELECT 'EMAIL_PERSONALE', 'Email personale', 1
WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_recapito WHERE codice = 'EMAIL_PERSONALE');
INSERT INTO hr_tipi_recapito (codice, descrizione, attivo)
SELECT 'EMAIL_LAVORO', 'Email di lavoro', 1
WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_recapito WHERE codice = 'EMAIL_LAVORO');
INSERT INTO hr_tipi_recapito (codice, descrizione, attivo)
SELECT 'CELLULARE_PERSONALE', 'Cellulare', 1
WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_recapito WHERE codice = 'CELLULARE_PERSONALE');

UPDATE hr_tipi_recapito SET descrizione = 'Email personale', attivo = 1 WHERE codice = 'EMAIL_PERSONALE';
UPDATE hr_tipi_recapito SET descrizione = 'Email di lavoro', attivo = 1 WHERE codice = 'EMAIL_LAVORO';
UPDATE hr_tipi_recapito SET descrizione = 'Cellulare', attivo = 1 WHERE codice = 'CELLULARE_PERSONALE';

CREATE TABLE IF NOT EXISTS hr_recapiti_verifiche (
    id_verifica_recapito BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_recapito_utente BIGINT UNSIGNED NOT NULL,
    id_utente INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    scade_il DATETIME NOT NULL,
    utilizzato_il DATETIME DEFAULT NULL,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_verifica_recapito),
    UNIQUE KEY uk_hr_recapiti_verifiche_token (token_hash),
    KEY ix_hr_recapiti_verifiche_recapito (id_recapito_utente),
    KEY ix_hr_recapiti_verifiche_utente (id_utente),
    KEY ix_hr_recapiti_verifiche_scadenza (scade_il, utilizzato_il),
    CONSTRAINT fk_hr_recapiti_verifiche_recapito FOREIGN KEY (id_recapito_utente) REFERENCES hr_recapiti_utenti (id_recapito_utente),
    CONSTRAINT fk_hr_recapiti_verifiche_utente FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pagina personale sotto Profilo.
INSERT INTO aut_risorse
    (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.miei_recapiti', 'I miei recapiti', 'pagina', p.id_risorsa,
       '/miei_recapiti.php', 'la-address-card', 1, 30, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.profilo'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse x WHERE x.codice_risorsa = 'pagina.miei_recapiti');
UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.profilo'
SET r.descrizione='I miei recapiti', r.tipo_risorsa='pagina', r.id_risorsa_padre=p.id_risorsa,
    r.percorso='/miei_recapiti.php', r.icona='la-address-card', r.visibile_menu=1, r.ordinamento=30, r.attivo=1
WHERE r.codice_risorsa='pagina.miei_recapiti';

INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, permesso, consentito)
SELECT ru.id_ruolo, ri.id_risorsa, 'read', 1
FROM aut_ruoli ru JOIN aut_risorse ri ON ri.codice_risorsa='pagina.miei_recapiti'
WHERE ru.attivo=1
  AND NOT EXISTS (SELECT 1 FROM aut_ruoli_permessi x WHERE x.id_ruolo=ru.id_ruolo AND x.id_risorsa=ri.id_risorsa AND x.permesso='read');
UPDATE aut_ruoli_permessi rp JOIN aut_risorse ri ON ri.id_risorsa=rp.id_risorsa
SET rp.consentito=1 WHERE ri.codice_risorsa='pagina.miei_recapiti' AND rp.permesso='read';

-- La voce HR/admin Recapiti utenti apre il nuovo editor del singolo utente.
-- Conserviamo codice risorsa e permessi esistenti: Giorgia/HR e admin mantengono quindi
-- l'accesso secondo i ruoli, senza autorizzazioni basate sul nome della persona.
UPDATE aut_risorse
SET descrizione='Recapiti utenti', percorso='/recapiti_utente.php', icona='la-envelope', attivo=1
WHERE codice_risorsa='pagina.recapiti_utenti';

COMMIT;

SELECT codice, descrizione, attivo FROM hr_tipi_recapito
WHERE codice IN ('EMAIL_PERSONALE','EMAIL_LAVORO','CELLULARE_PERSONALE') ORDER BY codice;
SELECT codice_risorsa, descrizione, percorso, visibile_menu, attivo
FROM aut_risorse WHERE codice_risorsa IN ('pagina.miei_recapiti','pagina.recapiti_utenti');
