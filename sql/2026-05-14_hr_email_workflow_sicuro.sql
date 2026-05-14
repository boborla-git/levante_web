-- Interruttore specifico per l'invio automatico delle email del workflow HR.
-- Valore iniziale 0 = nessun invio email automatico, anche se HR_NOTIFICA_EMAIL_ATTIVA = 1.

INSERT INTO hr_configurazioni (codice, valore, descrizione, attivo)
SELECT 'HR_EMAIL_WORKFLOW_ATTIVO', '0', 'Abilita invio automatico email per eventi workflow HR', 1
WHERE NOT EXISTS (
    SELECT 1
    FROM hr_configurazioni
    WHERE codice = 'HR_EMAIL_WORKFLOW_ATTIVO'
);
