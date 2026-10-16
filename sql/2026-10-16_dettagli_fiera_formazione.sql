-- Visibilita dei dettagli operativi per colleghi e responsabili.
-- Eseguire nel database del portale. Ripetibile senza creare duplicati.
-- Non modifica assenze personali, richieste, approvazioni o ambiti di visibilita.
UPDATE hr_tipologie_evento
SET mostra_dettaglio_colleghi = 1,
    mostra_dettaglio_responsabili = 1
WHERE codice IN ('FIERA', 'FORMAZIONE');

-- Verifica finale: entrambe le colonne devono risultare 1.
SELECT codice, descrizione, mostra_dettaglio_colleghi, mostra_dettaglio_responsabili
FROM hr_tipologie_evento
WHERE codice IN ('FIERA', 'FORMAZIONE')
ORDER BY codice;
