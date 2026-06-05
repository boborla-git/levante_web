SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS hr_timbrature (
    id_timbratura BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_utente INT UNSIGNED NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    data_ora DATETIME NOT NULL,
    origine VARCHAR(30) NOT NULL DEFAULT 'web',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id_timbratura),
    KEY idx_hr_timbrature_utente_data (id_utente, data_ora),
    KEY idx_hr_timbrature_tipo (tipo),
    CONSTRAINT fk_hr_timbrature_utente
        FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

SELECT 'hr_timbrature' AS tabella, COUNT(*) AS righe_presenti FROM hr_timbrature;
