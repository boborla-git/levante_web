-- LEVANTE WEB / Ravioli S.p.A.
-- HR Hardening finale - verifiche di sola lettura
-- Questo script NON modifica dati.

-- 1) Configurazioni email e workflow
SELECT codice, valore, attivo
FROM hr_configurazioni
WHERE codice IN (
  'HR_NOTIFICA_EMAIL_ATTIVA',
  'HR_EMAIL_WORKFLOW_ATTIVO',
  'HR_EMAIL_FROM',
  'HR_EMAIL_FROM_NAME',
  'HR_EMAIL_MITTENTE',
  'HR_EMAIL_NOME_MITTENTE',
  'HR_URL_PORTALE'
)
ORDER BY codice;

-- 2) Ruoli HR/Direzione e utenti assegnati
SELECT
    u.id_utente,
    u.username,
    u.nome,
    u.cognome,
    r.codice_ruolo,
    r.descrizione AS ruolo
FROM aut_utenti u
INNER JOIN aut_utenti_ruoli ur
    ON ur.id_utente = u.id_utente
   AND ur.attivo = 1
   AND (ur.data_fine IS NULL OR ur.data_fine >= NOW())
INNER JOIN aut_ruoli r
    ON r.id_ruolo = ur.id_ruolo
WHERE r.codice_ruolo IN ('hr_responsabile_personale', 'direzione_visibilita_hr')
ORDER BY r.codice_ruolo, u.cognome, u.nome, u.username;

-- 3) Permessi atomici HR
SELECT
    r.codice_ruolo,
    ri.codice_risorsa,
    rp.permesso,
    rp.consentito
FROM aut_ruoli r
INNER JOIN aut_ruoli_permessi rp
    ON rp.id_ruolo = r.id_ruolo
INNER JOIN aut_risorse ri
    ON ri.id_risorsa = rp.id_risorsa
WHERE r.codice_ruolo IN ('hr_responsabile_personale', 'direzione_visibilita_hr')
  AND ri.codice_risorsa LIKE 'azione.hr.assenze.%'
ORDER BY r.codice_ruolo, ri.codice_risorsa, rp.permesso;

-- 4) Ultime notifiche HR con contenuto parlante
SELECT
    n.id_notifica,
    n.tipo_evento,
    n.titolo,
    LEFT(n.messaggio, 250) AS messaggio,
    n.link,
    n.id_richiesta,
    n.data_creazione,
    nd.id_utente,
    nd.letta,
    nd.data_lettura
FROM hr_notifiche n
INNER JOIN hr_notifiche_destinatari nd
    ON nd.id_notifica = n.id_notifica
INNER JOIN hr_canali_notifica cn
    ON cn.id_canale_notifica = nd.id_canale_notifica
   AND cn.codice = 'WEB'
ORDER BY n.data_creazione DESC, n.id_notifica DESC
LIMIT 20;

-- 5) Ultimi log email HR
SELECT
    id_notifica_destinatario,
    id_notifica,
    id_richiesta,
    tipo_evento,
    titolo,
    nome_destinatario,
    cognome_destinatario,
    inviata,
    errore_invio,
    esito_email,
    data_creazione,
    data_invio
FROM v_hr_email_log
ORDER BY data_creazione DESC, id_notifica_destinatario DESC
LIMIT 20;

-- 6) Richieste recenti
SELECT
    r.id_richiesta,
    r.codice_richiesta,
    u.nome,
    u.cognome,
    te.codice AS tipologia,
    sr.codice AS stato,
    r.oggetto,
    r.data_creazione,
    MIN(p.data_da) AS data_da,
    MAX(p.data_a) AS data_a
FROM hr_richieste r
INNER JOIN aut_utenti u
    ON u.id_utente = r.id_utente_richiedente
INNER JOIN hr_tipologie_evento te
    ON te.id_tipologia_evento = r.id_tipologia_evento
INNER JOIN hr_stati_richiesta sr
    ON sr.id_stato_richiesta = r.id_stato_richiesta
LEFT JOIN hr_richieste_periodi p
    ON p.id_richiesta = r.id_richiesta
GROUP BY
    r.id_richiesta,
    r.codice_richiesta,
    u.nome,
    u.cognome,
    te.codice,
    sr.codice,
    r.oggetto,
    r.data_creazione
ORDER BY r.data_creazione DESC
LIMIT 30;
