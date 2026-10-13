-- Eseguire prima dei PHP. Ripetibile; non inserisce richieste o diritti individuali.
CREATE TABLE IF NOT EXISTS hr_allattamento_programmi (
    id_programma BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_utente INT UNSIGNED NOT NULL,
    data_da DATE NOT NULL,
    data_a DATE NOT NULL,
    decorrenza_config DATE NOT NULL,
    configurazione_json TEXT NOT NULL,
    creato_da INT UNSIGNED NOT NULL,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aggiornato_da INT UNSIGNED NOT NULL,
    data_aggiornamento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_programma),
    KEY ix_hr_allatt_programma_utente (id_utente),
    CONSTRAINT fk_hr_allatt_programma_utente FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solo collegamenti: date, orari e stati restano nelle normali richieste HR.
CREATE TABLE IF NOT EXISTS hr_allattamento_programma_richieste (
    id_richiesta BIGINT UNSIGNED NOT NULL,
    id_programma BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id_richiesta),
    KEY ix_hr_allatt_legame_programma (id_programma),
    CONSTRAINT fk_hr_allatt_legame_programma FOREIGN KEY (id_programma) REFERENCES hr_allattamento_programmi (id_programma),
    CONSTRAINT fk_hr_allatt_legame_richiesta FOREIGN KEY (id_richiesta) REFERENCES hr_richieste (id_richiesta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_allattamento_programmi_storico (
    id_storico BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_programma BIGINT UNSIGNED NOT NULL,
    id_operatore INT UNSIGNED NOT NULL,
    azione VARCHAR(30) NOT NULL,
    dettagli_json MEDIUMTEXT NOT NULL,
    data_azione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_storico),
    KEY ix_hr_allatt_storico_programma (id_programma),
    CONSTRAINT fk_hr_allatt_storico_programma FOREIGN KEY (id_programma) REFERENCES hr_allattamento_programmi (id_programma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
