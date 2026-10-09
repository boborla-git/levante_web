-- Eseguire prima di caricare i PHP. Compatibile con MySQL 5.7.
-- Ripetibile: non cancella richieste, diritti o chiusure già registrate.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS hr_chiusure_aziendali (
    id_chiusura BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    descrizione VARCHAR(150) NOT NULL,
    data_da DATE NOT NULL,
    data_a DATE NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    aggiornato_da INT UNSIGNED NOT NULL,
    data_aggiornamento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_chiusura),
    KEY ix_hr_chiusure_date (attivo, data_da, data_a),
    CONSTRAINT fk_hr_chiusure_operatore FOREIGN KEY (aggiornato_da) REFERENCES aut_utenti (id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
UPDATE hr_tipologie_evento SET consente_giorni = 0, consente_ore = 1
WHERE codice = 'ALLATTAMENTO';
UPDATE hr_benefici_utenti SET consente_giorni = 0, consente_ore = 1
WHERE codice_beneficio = 'ALLATTAMENTO';
-- Le vecchie assegnazioni senza data finale vanno completate da HR.
-- Nessuna data viene inventata e nessuna richiesta preesistente viene cancellata.
INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre,
    percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.chiusure_aziendali', 'Chiusure aziendali', 'pagina', p.id_risorsa,
    '/chiusure_aziendali.php', 'la-calendar-times', 1, 48, 1
FROM aut_risorse p WHERE p.codice_risorsa = 'pagina.configurazione_assenze'
AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.chiusure_aziendali');

INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, permesso, consentito)
SELECT r.id_ruolo, a.id_risorsa, p.permesso, 1
FROM aut_ruoli r CROSS JOIN aut_risorse a
CROSS JOIN (SELECT 'read' AS permesso UNION ALL SELECT 'write') p
WHERE r.codice_ruolo IN ('hr_responsabile_personale', 'admin', 'admin_portale', 'amministratore')
  AND a.codice_risorsa IN ('pagina.chiusure_aziendali', 'pagina.benefici_hr')
  AND NOT EXISTS (SELECT 1 FROM aut_ruoli_permessi x WHERE x.id_ruolo = r.id_ruolo
    AND x.id_risorsa = a.id_risorsa AND x.permesso = p.permesso);
UPDATE aut_ruoli_permessi p INNER JOIN aut_ruoli r ON r.id_ruolo = p.id_ruolo
INNER JOIN aut_risorse a ON a.id_risorsa = p.id_risorsa SET p.consentito = 1
WHERE r.codice_ruolo IN ('hr_responsabile_personale', 'admin', 'admin_portale', 'amministratore')
  AND a.codice_risorsa IN ('pagina.chiusure_aziendali', 'pagina.benefici_hr') AND p.permesso IN ('read', 'write');
COMMIT;

SELECT id_utente, data_inizio, data_fine FROM hr_benefici_utenti
WHERE codice_beneficio = 'ALLATTAMENTO' AND attivo = 1 AND data_fine IS NULL;
-- Se l'ultima SELECT restituisce righe, completare le date in Benefici e diritti.
