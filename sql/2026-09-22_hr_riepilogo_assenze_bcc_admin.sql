-- 2026-09-22 - Copia nascosta tecnica del riepilogo assenze all'Amministratore
-- Usa esclusivamente il recapito EMAIL_LAVORO attivo e verificato dell'utente username='admin'.
-- La copia viene inviata una sola volta per ciascun livello di dettaglio (BASE / HR) e per ciascun invio.

INSERT INTO hr_configurazioni (codice, valore, descrizione, attivo)
SELECT
    'HR_RIEPILOGO_ASSENZE_BCC_ADMIN',
    '1',
    'Invia in BCC tecnico all''Amministratore una copia dei riepiloghi assenze',
    1
WHERE NOT EXISTS (
    SELECT 1
    FROM hr_configurazioni
    WHERE codice = 'HR_RIEPILOGO_ASSENZE_BCC_ADMIN'
);
