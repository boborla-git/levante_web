-- LEVANTE WEB / Ravioli S.p.A.
-- Vista log email HR
-- Data: 2026-05-14
--
-- Scopo:
-- rendere leggibile il log degli invii email HR gia registrati in:
-- - hr_notifiche
-- - hr_notifiche_destinatari
-- - hr_canali_notifica
--
-- La vista e' di sola lettura logica: non cambia il workflow e non invia email.

CREATE OR REPLACE VIEW v_hr_email_log AS
SELECT
    nd.id_notifica_destinatario,
    n.id_notifica,
    n.id_richiesta,
    n.tipo_evento,
    n.titolo,
    n.messaggio,
    n.link,
    n.data_creazione,
    n.creato_da,

    nd.id_utente AS id_utente_destinatario,
    u.nome AS nome_destinatario,
    u.cognome AS cognome_destinatario,
    u.email AS email_aut_utenti,

    nd.inviata,
    nd.letta,
    nd.data_invio,
    nd.data_lettura,
    nd.errore_invio,

    CASE
        WHEN nd.inviata = 1 THEN 'INVIATA'
        WHEN nd.errore_invio IS NOT NULL AND nd.errore_invio <> '' THEN 'ERRORE'
        ELSE 'NON_INVIATA'
    END AS esito_email

FROM hr_notifiche_destinatari nd
INNER JOIN hr_notifiche n
    ON n.id_notifica = nd.id_notifica
INNER JOIN hr_canali_notifica cn
    ON cn.id_canale_notifica = nd.id_canale_notifica
   AND cn.codice = 'EMAIL'
LEFT JOIN aut_utenti u
    ON u.id_utente = nd.id_utente;
