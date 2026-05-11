-- =========================================================
-- LEVANTE WEB / RAVIOLI S.p.A.
-- Modulo HR assenze - schema tabelle e risorse portale
-- Data: 2026-05-11
-- =========================================================
-- Script idempotente: puo' essere rilanciato senza duplicare
-- dati anagrafici o risorse gia' presenti.
-- =========================================================

SET NAMES utf8mb4;

-- =========================================================
-- HR - ANAGRAFICHE
-- =========================================================

CREATE TABLE IF NOT EXISTS hr_stati_richiesta (
    id_stato_richiesta INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice VARCHAR(50) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    colore VARCHAR(20) DEFAULT NULL,
    ordinamento INT NOT NULL DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_stato_richiesta),
    UNIQUE KEY uk_hr_stati_richiesta_codice (codice),
    KEY ix_hr_stati_richiesta_ordinamento (ordinamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_stati_presenza (
    id_stato_presenza INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice VARCHAR(50) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    descrizione_breve VARCHAR(50) DEFAULT NULL,
    colore VARCHAR(20) DEFAULT NULL,
    disturbabile_default TINYINT(1) NOT NULL DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_stato_presenza),
    UNIQUE KEY uk_hr_stati_presenza_codice (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_canali_notifica (
    id_canale_notifica INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice VARCHAR(50) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_canale_notifica),
    UNIQUE KEY uk_hr_canali_notifica_codice (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_tipologie_evento (
    id_tipologia_evento INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice VARCHAR(50) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    descrizione_calendario VARCHAR(100) DEFAULT NULL,
    richiede_approvazione TINYINT(1) NOT NULL DEFAULT 1,
    approvazione_obbligatoria TINYINT(1) NOT NULL DEFAULT 1,
    consente_giorni TINYINT(1) NOT NULL DEFAULT 1,
    consente_ore TINYINT(1) NOT NULL DEFAULT 1,
    consente_multi_periodo TINYINT(1) NOT NULL DEFAULT 0,
    visibile_calendario TINYINT(1) NOT NULL DEFAULT 1,
    visibile_ai_colleghi TINYINT(1) NOT NULL DEFAULT 1,
    mostra_dettaglio_colleghi TINYINT(1) NOT NULL DEFAULT 0,
    mostra_dettaglio_responsabili TINYINT(1) NOT NULL DEFAULT 1,
    mostra_dettaglio_hr TINYINT(1) NOT NULL DEFAULT 1,
    id_stato_presenza INT UNSIGNED NOT NULL,
    disturbabile TINYINT(1) NOT NULL DEFAULT 0,
    colore_calendario VARCHAR(20) DEFAULT NULL,
    ordinamento INT NOT NULL DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_tipologia_evento),
    UNIQUE KEY uk_hr_tipologie_evento_codice (codice),
    KEY ix_hr_tipologie_evento_stato_presenza (id_stato_presenza),
    KEY ix_hr_tipologie_evento_ordinamento (ordinamento),
    CONSTRAINT fk_hr_tipologie_evento_stato_presenza
        FOREIGN KEY (id_stato_presenza) REFERENCES hr_stati_presenza (id_stato_presenza)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_tipi_relazione_organizzativa (
    id_tipo_relazione INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice VARCHAR(50) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_tipo_relazione),
    UNIQUE KEY uk_hr_tipi_relazione_organizzativa_codice (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_tipi_recapito (
    id_tipo_recapito INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice VARCHAR(50) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_tipo_recapito),
    UNIQUE KEY uk_hr_tipi_recapito_codice (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- HR - ORGANIZZAZIONE
-- =========================================================

CREATE TABLE IF NOT EXISTS hr_relazioni_organizzative (
    id_relazione_organizzativa BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_utente INT UNSIGNED NOT NULL,
    id_utente_collegato INT UNSIGNED NOT NULL,
    id_tipo_relazione INT UNSIGNED NOT NULL,
    data_inizio DATE NOT NULL,
    data_fine DATE DEFAULT NULL,
    attiva TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id_relazione_organizzativa),
    KEY ix_hr_relazioni_org_utente (id_utente),
    KEY ix_hr_relazioni_org_utente_collegato (id_utente_collegato),
    KEY ix_hr_relazioni_org_tipo (id_tipo_relazione),
    KEY ix_hr_relazioni_org_attiva (attiva, data_inizio, data_fine),
    CONSTRAINT fk_hr_relazioni_org_utente
        FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente),
    CONSTRAINT fk_hr_relazioni_org_utente_collegato
        FOREIGN KEY (id_utente_collegato) REFERENCES aut_utenti (id_utente),
    CONSTRAINT fk_hr_relazioni_org_tipo
        FOREIGN KEY (id_tipo_relazione) REFERENCES hr_tipi_relazione_organizzativa (id_tipo_relazione)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_gruppi_lavoro (
    id_gruppo_lavoro INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice VARCHAR(50) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    descrizione VARCHAR(255) DEFAULT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_gruppo_lavoro),
    UNIQUE KEY uk_hr_gruppi_lavoro_codice (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_gruppi_utenti (
    id_gruppo_utente BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_gruppo_lavoro INT UNSIGNED NOT NULL,
    id_utente INT UNSIGNED NOT NULL,
    ruolo_nel_gruppo VARCHAR(50) DEFAULT NULL,
    data_inizio DATE NOT NULL,
    data_fine DATE DEFAULT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_gruppo_utente),
    UNIQUE KEY uk_hr_gruppi_utenti_periodo (id_gruppo_lavoro, id_utente, data_inizio),
    KEY ix_hr_gruppi_utenti_utente (id_utente),
    KEY ix_hr_gruppi_utenti_attivo (attivo, data_inizio, data_fine),
    CONSTRAINT fk_hr_gruppi_utenti_gruppo
        FOREIGN KEY (id_gruppo_lavoro) REFERENCES hr_gruppi_lavoro (id_gruppo_lavoro),
    CONSTRAINT fk_hr_gruppi_utenti_utente
        FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- HR - RECAPITI
-- =========================================================

CREATE TABLE IF NOT EXISTS hr_recapiti_utenti (
    id_recapito_utente BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_utente INT UNSIGNED NOT NULL,
    id_tipo_recapito INT UNSIGNED NOT NULL,
    valore VARCHAR(255) NOT NULL,
    principale TINYINT(1) NOT NULL DEFAULT 0,
    verificato TINYINT(1) NOT NULL DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id_recapito_utente),
    KEY ix_hr_recapiti_utenti_utente (id_utente),
    KEY ix_hr_recapiti_utenti_tipo (id_tipo_recapito),
    KEY ix_hr_recapiti_utenti_principale (id_utente, id_tipo_recapito, principale),
    CONSTRAINT fk_hr_recapiti_utenti_utente
        FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente),
    CONSTRAINT fk_hr_recapiti_utenti_tipo
        FOREIGN KEY (id_tipo_recapito) REFERENCES hr_tipi_recapito (id_tipo_recapito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- HR - RICHIESTE
-- =========================================================

CREATE TABLE IF NOT EXISTS hr_richieste (
    id_richiesta BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice_richiesta VARCHAR(30) NOT NULL,
    id_utente_richiedente INT UNSIGNED NOT NULL,
    id_tipologia_evento INT UNSIGNED NOT NULL,
    id_stato_richiesta INT UNSIGNED NOT NULL,
    id_responsabile_corrente INT UNSIGNED DEFAULT NULL,
    id_gruppo_lavoro INT UNSIGNED DEFAULT NULL,
    oggetto VARCHAR(150) DEFAULT NULL,
    note_richiedente TEXT DEFAULT NULL,
    note_interne TEXT DEFAULT NULL,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_invio DATETIME DEFAULT NULL,
    data_chiusura DATETIME DEFAULT NULL,
    data_aggiornamento DATETIME DEFAULT NULL,
    annullata_da_richiedente TINYINT(1) NOT NULL DEFAULT 0,
    origine VARCHAR(30) NOT NULL DEFAULT 'web',
    PRIMARY KEY (id_richiesta),
    UNIQUE KEY uk_hr_richieste_codice_richiesta (codice_richiesta),
    KEY ix_hr_richieste_richiedente (id_utente_richiedente),
    KEY ix_hr_richieste_tipologia (id_tipologia_evento),
    KEY ix_hr_richieste_stato (id_stato_richiesta),
    KEY ix_hr_richieste_responsabile (id_responsabile_corrente),
    KEY ix_hr_richieste_gruppo (id_gruppo_lavoro),
    KEY ix_hr_richieste_data_creazione (data_creazione),
    CONSTRAINT fk_hr_richieste_richiedente
        FOREIGN KEY (id_utente_richiedente) REFERENCES aut_utenti (id_utente),
    CONSTRAINT fk_hr_richieste_tipologia
        FOREIGN KEY (id_tipologia_evento) REFERENCES hr_tipologie_evento (id_tipologia_evento),
    CONSTRAINT fk_hr_richieste_stato
        FOREIGN KEY (id_stato_richiesta) REFERENCES hr_stati_richiesta (id_stato_richiesta),
    CONSTRAINT fk_hr_richieste_responsabile
        FOREIGN KEY (id_responsabile_corrente) REFERENCES aut_utenti (id_utente),
    CONSTRAINT fk_hr_richieste_gruppo
        FOREIGN KEY (id_gruppo_lavoro) REFERENCES hr_gruppi_lavoro (id_gruppo_lavoro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_richieste_periodi (
    id_richiesta_periodo BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_richiesta BIGINT UNSIGNED NOT NULL,
    tipo_periodo VARCHAR(20) NOT NULL,
    data_da DATE NOT NULL,
    data_a DATE NOT NULL,
    ora_da TIME DEFAULT NULL,
    ora_a TIME DEFAULT NULL,
    giornata_intera TINYINT(1) NOT NULL DEFAULT 0,
    minuti_totali INT UNSIGNED DEFAULT NULL,
    ordinamento INT NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id_richiesta_periodo),
    KEY ix_hr_richieste_periodi_richiesta (id_richiesta),
    KEY ix_hr_richieste_periodi_date (data_da, data_a),
    CONSTRAINT fk_hr_richieste_periodi_richiesta
        FOREIGN KEY (id_richiesta) REFERENCES hr_richieste (id_richiesta)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_richieste_approvazioni (
    id_richiesta_approvazione BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_richiesta BIGINT UNSIGNED NOT NULL,
    livello_approvazione INT NOT NULL DEFAULT 1,
    id_approvatore_assegnato INT UNSIGNED NOT NULL,
    stato_approvazione VARCHAR(30) NOT NULL,
    data_assegnazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_risposta DATETIME DEFAULT NULL,
    esito VARCHAR(30) DEFAULT NULL,
    note_approvatore TEXT DEFAULT NULL,
    gestita_da_hr TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id_richiesta_approvazione),
    KEY ix_hr_richieste_approvazioni_richiesta (id_richiesta),
    KEY ix_hr_richieste_approvazioni_approvatore (id_approvatore_assegnato),
    KEY ix_hr_richieste_approvazioni_stato (stato_approvazione),
    KEY ix_hr_richieste_approvazioni_livello (id_richiesta, livello_approvazione),
    CONSTRAINT fk_hr_richieste_approvazioni_richiesta
        FOREIGN KEY (id_richiesta) REFERENCES hr_richieste (id_richiesta)
        ON DELETE CASCADE,
    CONSTRAINT fk_hr_richieste_approvazioni_approvatore
        FOREIGN KEY (id_approvatore_assegnato) REFERENCES aut_utenti (id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_richieste_storico (
    id_richiesta_storico BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_richiesta BIGINT UNSIGNED NOT NULL,
    azione VARCHAR(50) NOT NULL,
    id_utente_azione INT UNSIGNED DEFAULT NULL,
    data_azione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dettagli TEXT DEFAULT NULL,
    origine VARCHAR(30) NOT NULL DEFAULT 'web',
    PRIMARY KEY (id_richiesta_storico),
    KEY ix_hr_richieste_storico_richiesta (id_richiesta),
    KEY ix_hr_richieste_storico_utente (id_utente_azione),
    KEY ix_hr_richieste_storico_data (data_azione),
    CONSTRAINT fk_hr_richieste_storico_richiesta
        FOREIGN KEY (id_richiesta) REFERENCES hr_richieste (id_richiesta)
        ON DELETE CASCADE,
    CONSTRAINT fk_hr_richieste_storico_utente
        FOREIGN KEY (id_utente_azione) REFERENCES aut_utenti (id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- HR - NOTIFICHE
-- =========================================================

CREATE TABLE IF NOT EXISTS hr_notifiche (
    id_notifica BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_evento VARCHAR(50) NOT NULL,
    titolo VARCHAR(150) NOT NULL,
    messaggio TEXT NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    id_richiesta BIGINT UNSIGNED DEFAULT NULL,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creato_da INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id_notifica),
    KEY ix_hr_notifiche_richiesta (id_richiesta),
    KEY ix_hr_notifiche_data (data_creazione),
    CONSTRAINT fk_hr_notifiche_richiesta
        FOREIGN KEY (id_richiesta) REFERENCES hr_richieste (id_richiesta)
        ON DELETE SET NULL,
    CONSTRAINT fk_hr_notifiche_creato_da
        FOREIGN KEY (creato_da) REFERENCES aut_utenti (id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_notifiche_destinatari (
    id_notifica_destinatario BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_notifica BIGINT UNSIGNED NOT NULL,
    id_utente INT UNSIGNED NOT NULL,
    id_canale_notifica INT UNSIGNED NOT NULL,
    inviata TINYINT(1) NOT NULL DEFAULT 0,
    letta TINYINT(1) NOT NULL DEFAULT 0,
    data_invio DATETIME DEFAULT NULL,
    data_lettura DATETIME DEFAULT NULL,
    errore_invio VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id_notifica_destinatario),
    KEY ix_hr_notifiche_destinatari_notifica (id_notifica),
    KEY ix_hr_notifiche_destinatari_utente (id_utente),
    KEY ix_hr_notifiche_destinatari_canale (id_canale_notifica),
    KEY ix_hr_notifiche_destinatari_letta (id_utente, letta),
    CONSTRAINT fk_hr_notifiche_destinatari_notifica
        FOREIGN KEY (id_notifica) REFERENCES hr_notifiche (id_notifica)
        ON DELETE CASCADE,
    CONSTRAINT fk_hr_notifiche_destinatari_utente
        FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente),
    CONSTRAINT fk_hr_notifiche_destinatari_canale
        FOREIGN KEY (id_canale_notifica) REFERENCES hr_canali_notifica (id_canale_notifica)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- HR - CONFIGURAZIONE
-- =========================================================

CREATE TABLE IF NOT EXISTS hr_configurazioni (
    id_configurazione INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codice VARCHAR(100) NOT NULL,
    valore VARCHAR(255) DEFAULT NULL,
    descrizione VARCHAR(255) DEFAULT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_configurazione),
    UNIQUE KEY uk_hr_configurazioni_codice (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- DATI INIZIALI HR
-- =========================================================

INSERT INTO hr_stati_richiesta (codice, descrizione, colore, ordinamento)
SELECT 'BOZZA', 'Bozza', '#6c757d', 10 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_richiesta WHERE codice = 'BOZZA');
INSERT INTO hr_stati_richiesta (codice, descrizione, colore, ordinamento)
SELECT 'IN_ATTESA', 'In attesa', '#f0ad4e', 20 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_richiesta WHERE codice = 'IN_ATTESA');
INSERT INTO hr_stati_richiesta (codice, descrizione, colore, ordinamento)
SELECT 'APPROVATA', 'Approvata', '#28a745', 30 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_richiesta WHERE codice = 'APPROVATA');
INSERT INTO hr_stati_richiesta (codice, descrizione, colore, ordinamento)
SELECT 'RIFIUTATA', 'Rifiutata', '#dc3545', 40 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_richiesta WHERE codice = 'RIFIUTATA');
INSERT INTO hr_stati_richiesta (codice, descrizione, colore, ordinamento)
SELECT 'ANNULLATA', 'Annullata', '#6c757d', 50 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_richiesta WHERE codice = 'ANNULLATA');
INSERT INTO hr_stati_richiesta (codice, descrizione, colore, ordinamento)
SELECT 'SCADUTA', 'Scaduta', '#343a40', 60 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_richiesta WHERE codice = 'SCADUTA');

INSERT INTO hr_stati_presenza (codice, descrizione, descrizione_breve, colore, disturbabile_default)
SELECT 'PRESENTE_SEDE', 'Presente in sede', 'Presente', '#28a745', 1 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_presenza WHERE codice = 'PRESENTE_SEDE');
INSERT INTO hr_stati_presenza (codice, descrizione, descrizione_breve, colore, disturbabile_default)
SELECT 'SMART_WORKING', 'Smart working', 'Smart', '#17a2b8', 1 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_presenza WHERE codice = 'SMART_WORKING');
INSERT INTO hr_stati_presenza (codice, descrizione, descrizione_breve, colore, disturbabile_default)
SELECT 'ASSENTE', 'Assente', 'Assente', '#dc3545', 0 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_presenza WHERE codice = 'ASSENTE');
INSERT INTO hr_stati_presenza (codice, descrizione, descrizione_breve, colore, disturbabile_default)
SELECT 'TRASFERTA', 'Trasferta', 'Trasferta', '#6f42c1', 1 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_presenza WHERE codice = 'TRASFERTA');
INSERT INTO hr_stati_presenza (codice, descrizione, descrizione_breve, colore, disturbabile_default)
SELECT 'NON_DISTURBARE', 'Non disturbare', 'Non disturbare', '#343a40', 0 WHERE NOT EXISTS (SELECT 1 FROM hr_stati_presenza WHERE codice = 'NON_DISTURBARE');

INSERT INTO hr_canali_notifica (codice, descrizione)
SELECT 'WEB', 'Notifica web' WHERE NOT EXISTS (SELECT 1 FROM hr_canali_notifica WHERE codice = 'WEB');
INSERT INTO hr_canali_notifica (codice, descrizione)
SELECT 'EMAIL', 'Email' WHERE NOT EXISTS (SELECT 1 FROM hr_canali_notifica WHERE codice = 'EMAIL');
INSERT INTO hr_canali_notifica (codice, descrizione)
SELECT 'SMS', 'SMS' WHERE NOT EXISTS (SELECT 1 FROM hr_canali_notifica WHERE codice = 'SMS');
INSERT INTO hr_canali_notifica (codice, descrizione)
SELECT 'WHATSAPP', 'WhatsApp' WHERE NOT EXISTS (SELECT 1 FROM hr_canali_notifica WHERE codice = 'WHATSAPP');

INSERT INTO hr_tipi_relazione_organizzativa (codice, descrizione)
SELECT 'RESPONSABILE_DIRETTO', 'Responsabile diretto' WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_relazione_organizzativa WHERE codice = 'RESPONSABILE_DIRETTO');
INSERT INTO hr_tipi_relazione_organizzativa (codice, descrizione)
SELECT 'RESPONSABILE_FUNZIONALE', 'Responsabile funzionale' WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_relazione_organizzativa WHERE codice = 'RESPONSABILE_FUNZIONALE');
INSERT INTO hr_tipi_relazione_organizzativa (codice, descrizione)
SELECT 'REFERENTE_HR', 'Referente HR' WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_relazione_organizzativa WHERE codice = 'REFERENTE_HR');

INSERT INTO hr_tipi_recapito (codice, descrizione)
SELECT 'EMAIL_LAVORO', 'Email lavoro' WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_recapito WHERE codice = 'EMAIL_LAVORO');
INSERT INTO hr_tipi_recapito (codice, descrizione)
SELECT 'EMAIL_PERSONALE', 'Email personale' WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_recapito WHERE codice = 'EMAIL_PERSONALE');
INSERT INTO hr_tipi_recapito (codice, descrizione)
SELECT 'CELLULARE_LAVORO', 'Cellulare lavoro' WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_recapito WHERE codice = 'CELLULARE_LAVORO');
INSERT INTO hr_tipi_recapito (codice, descrizione)
SELECT 'CELLULARE_PERSONALE', 'Cellulare personale' WHERE NOT EXISTS (SELECT 1 FROM hr_tipi_recapito WHERE codice = 'CELLULARE_PERSONALE');

INSERT INTO hr_tipologie_evento (
    codice, descrizione, descrizione_calendario,
    richiede_approvazione, approvazione_obbligatoria,
    consente_giorni, consente_ore, consente_multi_periodo,
    visibile_calendario, visibile_ai_colleghi,
    mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
    id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo
)
SELECT 'FERIE', 'Ferie', 'Ferie', 1, 1, 1, 0, 0, 1, 1, 0, 1, 1, sp.id_stato_presenza, 0, '#28a745', 10, 1
FROM hr_stati_presenza sp
WHERE sp.codice = 'ASSENTE' AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'FERIE');

INSERT INTO hr_tipologie_evento (
    codice, descrizione, descrizione_calendario,
    richiede_approvazione, approvazione_obbligatoria,
    consente_giorni, consente_ore, consente_multi_periodo,
    visibile_calendario, visibile_ai_colleghi,
    mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
    id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo
)
SELECT 'PERMESSO', 'Permesso', 'Permesso', 1, 1, 1, 1, 0, 1, 1, 0, 1, 1, sp.id_stato_presenza, 0, '#ffc107', 20, 1
FROM hr_stati_presenza sp
WHERE sp.codice = 'ASSENTE' AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'PERMESSO');

INSERT INTO hr_tipologie_evento (
    codice, descrizione, descrizione_calendario,
    richiede_approvazione, approvazione_obbligatoria,
    consente_giorni, consente_ore, consente_multi_periodo,
    visibile_calendario, visibile_ai_colleghi,
    mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
    id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo
)
SELECT 'MALATTIA', 'Malattia', 'Assente', 0, 0, 1, 1, 0, 1, 1, 0, 0, 1, sp.id_stato_presenza, 0, '#dc3545', 30, 1
FROM hr_stati_presenza sp
WHERE sp.codice = 'ASSENTE' AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'MALATTIA');

INSERT INTO hr_tipologie_evento (
    codice, descrizione, descrizione_calendario,
    richiede_approvazione, approvazione_obbligatoria,
    consente_giorni, consente_ore, consente_multi_periodo,
    visibile_calendario, visibile_ai_colleghi,
    mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
    id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo
)
SELECT 'SMART', 'Smart working', 'Smart working', 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, sp.id_stato_presenza, 1, '#17a2b8', 40, 1
FROM hr_stati_presenza sp
WHERE sp.codice = 'SMART_WORKING' AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'SMART');

INSERT INTO hr_tipologie_evento (
    codice, descrizione, descrizione_calendario,
    richiede_approvazione, approvazione_obbligatoria,
    consente_giorni, consente_ore, consente_multi_periodo,
    visibile_calendario, visibile_ai_colleghi,
    mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
    id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo
)
SELECT 'TRASFERTA', 'Trasferta', 'Trasferta', 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, sp.id_stato_presenza, 1, '#6f42c1', 50, 1
FROM hr_stati_presenza sp
WHERE sp.codice = 'TRASFERTA' AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'TRASFERTA');

INSERT INTO hr_tipologie_evento (
    codice, descrizione, descrizione_calendario,
    richiede_approvazione, approvazione_obbligatoria,
    consente_giorni, consente_ore, consente_multi_periodo,
    visibile_calendario, visibile_ai_colleghi,
    mostra_dettaglio_colleghi, mostra_dettaglio_responsabili, mostra_dettaglio_hr,
    id_stato_presenza, disturbabile, colore_calendario, ordinamento, attivo
)
SELECT 'ALTRO', 'Altro', 'Assenza', 1, 1, 1, 1, 0, 1, 0, 0, 1, 1, sp.id_stato_presenza, 0, '#6c757d', 60, 1
FROM hr_stati_presenza sp
WHERE sp.codice = 'ASSENTE' AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice = 'ALTRO');

INSERT INTO hr_configurazioni (codice, valore, descrizione)
SELECT 'HR_NOTIFICA_WEB_ATTIVA', '1', 'Abilita notifiche web' WHERE NOT EXISTS (SELECT 1 FROM hr_configurazioni WHERE codice = 'HR_NOTIFICA_WEB_ATTIVA');
INSERT INTO hr_configurazioni (codice, valore, descrizione)
SELECT 'HR_NOTIFICA_EMAIL_ATTIVA', '1', 'Abilita notifiche email' WHERE NOT EXISTS (SELECT 1 FROM hr_configurazioni WHERE codice = 'HR_NOTIFICA_EMAIL_ATTIVA');
INSERT INTO hr_configurazioni (codice, valore, descrizione)
SELECT 'HR_EMAIL_FROM', '', 'Mittente email notifiche HR' WHERE NOT EXISTS (SELECT 1 FROM hr_configurazioni WHERE codice = 'HR_EMAIL_FROM');
INSERT INTO hr_configurazioni (codice, valore, descrizione)
SELECT 'HR_EMAIL_FROM_NAME', 'Ravioli S.p.A.', 'Nome mittente email notifiche HR' WHERE NOT EXISTS (SELECT 1 FROM hr_configurazioni WHERE codice = 'HR_EMAIL_FROM_NAME');
INSERT INTO hr_configurazioni (codice, valore, descrizione)
SELECT 'HR_RICHIESTE_CODICE_PREFISSO', 'HR', 'Prefisso del codice richiesta' WHERE NOT EXISTS (SELECT 1 FROM hr_configurazioni WHERE codice = 'HR_RICHIESTE_CODICE_PREFISSO');
INSERT INTO hr_configurazioni (codice, valore, descrizione)
SELECT 'HR_APPROVAZIONE_MULTI_LIVELLO', '0', 'Abilita approvazioni multi-livello' WHERE NOT EXISTS (SELECT 1 FROM hr_configurazioni WHERE codice = 'HR_APPROVAZIONE_MULTI_LIVELLO');

-- =========================================================
-- RISORSE PORTALE HR
-- =========================================================

INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'menu.hr', 'HR', 'menu', NULL, NULL, 'la-calendar-check', 1, 60, 1
WHERE NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'menu.hr');

UPDATE aut_risorse
SET descrizione = 'HR', tipo_risorsa = 'menu', id_risorsa_padre = NULL, percorso = NULL,
    icona = 'la-calendar-check', visibile_menu = 1, ordinamento = 60, attivo = 1
WHERE codice_risorsa = 'menu.hr';

INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.assenze', 'Assenze e permessi', 'pagina', p.id_risorsa, '/assenze.php', 'la-calendar-plus', 1, 10, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.hr'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.assenze');

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.hr'
SET r.descrizione = 'Assenze e permessi', r.tipo_risorsa = 'pagina', r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/assenze.php', r.icona = 'la-calendar-plus', r.visibile_menu = 1, r.ordinamento = 10, r.attivo = 1
WHERE r.codice_risorsa = 'pagina.assenze';

INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.approvazioni_assenze', 'Approvazioni assenze', 'pagina', p.id_risorsa, '/approvazioni_assenze.php', 'la-check-circle', 1, 20, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.hr'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.approvazioni_assenze');

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.hr'
SET r.descrizione = 'Approvazioni assenze', r.tipo_risorsa = 'pagina', r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/approvazioni_assenze.php', r.icona = 'la-check-circle', r.visibile_menu = 1, r.ordinamento = 20, r.attivo = 1
WHERE r.codice_risorsa = 'pagina.approvazioni_assenze';

INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.calendario_assenze', 'Calendario assenze', 'pagina', p.id_risorsa, '/calendario_assenze.php', 'la-calendar', 1, 30, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.hr'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.calendario_assenze');

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.hr'
SET r.descrizione = 'Calendario assenze', r.tipo_risorsa = 'pagina', r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/calendario_assenze.php', r.icona = 'la-calendar', r.visibile_menu = 1, r.ordinamento = 30, r.attivo = 1
WHERE r.codice_risorsa = 'pagina.calendario_assenze';

INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.configurazione_assenze', 'Configurazione assenze', 'pagina', p.id_risorsa, '/configurazione_assenze.php', 'la-cog', 1, 40, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.hr'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.configurazione_assenze');

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.hr'
SET r.descrizione = 'Configurazione assenze', r.tipo_risorsa = 'pagina', r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/configurazione_assenze.php', r.icona = 'la-cog', r.visibile_menu = 1, r.ordinamento = 40, r.attivo = 1
WHERE r.codice_risorsa = 'pagina.configurazione_assenze';

INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.recapiti_utenti', 'Recapiti utenti', 'pagina', p.id_risorsa, '/recapiti_utenti.php', 'la-address-book', 0, 50, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.hr'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.recapiti_utenti');

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.hr'
SET r.descrizione = 'Recapiti utenti', r.tipo_risorsa = 'pagina', r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/recapiti_utenti.php', r.icona = 'la-address-book', r.visibile_menu = 0, r.ordinamento = 50, r.attivo = 1
WHERE r.codice_risorsa = 'pagina.recapiti_utenti';

-- Nota: notifiche.php e' gia' gestita dallo script 2026-04-30_notifiche_aut_risorse.sql.
