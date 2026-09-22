-- 2026-09-22 - Riepilogo giornaliero assenze via email
SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS hr_riepilogo_assenze_destinatari (
    id_destinatario BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_utente INT UNSIGNED NOT NULL,
    livello_dettaglio VARCHAR(20) NOT NULL DEFAULT 'BASE',
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    aggiornato_da INT UNSIGNED DEFAULT NULL,
    data_aggiornamento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_destinatario),
    UNIQUE KEY uk_hr_riepilogo_assenze_dest_utente (id_utente),
    KEY ix_hr_riepilogo_assenze_dest_attivo (attivo),
    CONSTRAINT fk_hr_riepilogo_assenze_dest_utente FOREIGN KEY (id_utente) REFERENCES aut_utenti(id_utente),
    CONSTRAINT fk_hr_riepilogo_assenze_dest_operatore FOREIGN KEY (aggiornato_da) REFERENCES aut_utenti(id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_riepilogo_assenze_invi (
    id_invio BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    data_riepilogo DATE NOT NULL,
    tipo_invio VARCHAR(20) NOT NULL,
    id_utente_destinatario INT UNSIGNED NOT NULL,
    email_destinatario VARCHAR(255) NOT NULL,
    livello_dettaglio VARCHAR(20) NOT NULL,
    oggetto VARCHAR(200) NOT NULL,
    esito VARCHAR(20) NOT NULL,
    errore_invio VARCHAR(255) DEFAULT NULL,
    id_richiesta_trigger BIGINT UNSIGNED DEFAULT NULL,
    data_invio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_invio),
    KEY ix_hr_riepilogo_assenze_invi_data (data_riepilogo,tipo_invio),
    KEY ix_hr_riepilogo_assenze_invi_utente (id_utente_destinatario),
    KEY ix_hr_riepilogo_assenze_invi_richiesta (id_richiesta_trigger),
    CONSTRAINT fk_hr_riepilogo_assenze_invi_utente FOREIGN KEY (id_utente_destinatario) REFERENCES aut_utenti(id_utente),
    CONSTRAINT fk_hr_riepilogo_assenze_invi_richiesta FOREIGN KEY (id_richiesta_trigger) REFERENCES hr_richieste(id_richiesta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_configurazioni (codice,valore,descrizione,attivo)
SELECT 'HR_RIEPILOGO_ASSENZE_ATTIVO','1','Invio riepilogo giornaliero assenze via email',1
WHERE NOT EXISTS (SELECT 1 FROM hr_configurazioni WHERE codice='HR_RIEPILOGO_ASSENZE_ATTIVO');

INSERT INTO hr_configurazioni (codice,valore,descrizione,attivo)
SELECT 'HR_CRON_TOKEN','','Token chiamata HTTP dei job pianificati HR',1
WHERE NOT EXISTS (SELECT 1 FROM hr_configurazioni WHERE codice='HR_CRON_TOKEN');

COMMIT;
