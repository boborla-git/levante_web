-- MySQL 5.7. Ripetibile: aggiunge il tipo senza assegnare relazioni o permessi.
SET NAMES utf8mb4;
INSERT INTO hr_tipi_relazione_organizzativa (codice, descrizione, attivo)
VALUES ('VISIBILITA_CALENDARIO', 'mostra nel calendario di', 1)
ON DUPLICATE KEY UPDATE descrizione = VALUES(descrizione), attivo = 1;
SELECT id_tipo_relazione, codice, descrizione, attivo FROM hr_tipi_relazione_organizzativa
WHERE codice = 'VISIBILITA_CALENDARIO';
