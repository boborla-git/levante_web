-- LEVANTE WEB - Verifiche coerenza modulo HR assenze
-- Script di sola lettura: non modifica dati e non crea tabelle.
-- Da eseguire in phpMyAdmin sul database del sito per controllare schema, risorse e dati base.

SET NAMES utf8mb4;

-- 1) Tabelle HR attese
SELECT
    attese.nome_tabella,
    CASE WHEN t.TABLE_NAME IS NULL THEN 'MANCANTE' ELSE 'OK' END AS esito
FROM (
    SELECT 'hr_stati_richiesta' AS nome_tabella UNION ALL
    SELECT 'hr_stati_presenza' UNION ALL
    SELECT 'hr_canali_notifica' UNION ALL
    SELECT 'hr_tipologie_evento' UNION ALL
    SELECT 'hr_tipi_relazione_organizzativa' UNION ALL
    SELECT 'hr_relazioni_organizzative' UNION ALL
    SELECT 'hr_gruppi_lavoro' UNION ALL
    SELECT 'hr_gruppi_utenti' UNION ALL
    SELECT 'hr_tipi_recapito' UNION ALL
    SELECT 'hr_recapiti_utenti' UNION ALL
    SELECT 'hr_richieste' UNION ALL
    SELECT 'hr_richieste_periodi' UNION ALL
    SELECT 'hr_richieste_approvazioni' UNION ALL
    SELECT 'hr_richieste_storico' UNION ALL
    SELECT 'hr_notifiche' UNION ALL
    SELECT 'hr_notifiche_destinatari' UNION ALL
    SELECT 'hr_configurazioni'
) attese
LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE()
   AND t.TABLE_NAME = attese.nome_tabella
ORDER BY attese.nome_tabella;

-- 2) Colonne critiche che il codice PHP usa direttamente
SELECT
    attese.nome_tabella,
    attese.nome_colonna,
    CASE WHEN c.COLUMN_NAME IS NULL THEN 'MANCANTE' ELSE 'OK' END AS esito
FROM (
    SELECT 'hr_gruppi_utenti' AS nome_tabella, 'id_gruppo_lavoro' AS nome_colonna UNION ALL
    SELECT 'hr_gruppi_utenti', 'ruolo_nel_gruppo' UNION ALL
    SELECT 'hr_relazioni_organizzative', 'id_utente' UNION ALL
    SELECT 'hr_relazioni_organizzative', 'id_utente_collegato' UNION ALL
    SELECT 'hr_richieste', 'id_responsabile_corrente' UNION ALL
    SELECT 'hr_richieste', 'data_aggiornamento' UNION ALL
    SELECT 'hr_richieste_periodi', 'tipo_periodo' UNION ALL
    SELECT 'hr_richieste_periodi', 'data_da' UNION ALL
    SELECT 'hr_richieste_periodi', 'data_a' UNION ALL
    SELECT 'hr_richieste_periodi', 'ora_da' UNION ALL
    SELECT 'hr_richieste_periodi', 'ora_a' UNION ALL
    SELECT 'hr_richieste_approvazioni', 'stato_approvazione' UNION ALL
    SELECT 'hr_richieste_approvazioni', 'note_approvatore' UNION ALL
    SELECT 'hr_notifiche_destinatari', 'letta'
) attese
LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE()
   AND c.TABLE_NAME = attese.nome_tabella
   AND c.COLUMN_NAME = attese.nome_colonna
ORDER BY attese.nome_tabella, attese.nome_colonna;

-- 3) Risorse HR attese in aut_risorse
SELECT
    attese.codice_risorsa,
    CASE WHEN r.codice_risorsa IS NULL THEN 'MANCANTE' ELSE 'OK' END AS esito,
    r.descrizione,
    r.percorso,
    r.visibile_menu,
    r.attivo
FROM (
    SELECT 'pagina.assenze' AS codice_risorsa UNION ALL
    SELECT 'pagina.approvazioni_assenze' UNION ALL
    SELECT 'pagina.calendario_assenze' UNION ALL
    SELECT 'pagina.configurazione_assenze'
) attese
LEFT JOIN aut_risorse r
    ON r.codice_risorsa = attese.codice_risorsa
ORDER BY attese.codice_risorsa;

-- 4) Dati base HR attesi
SELECT 'stati_richiesta' AS gruppo, codice, descrizione, attivo
FROM hr_stati_richiesta
WHERE codice IN ('BOZZA', 'IN_ATTESA', 'APPROVATA', 'RIFIUTATA', 'ANNULLATA', 'SCADUTA')
UNION ALL
SELECT 'tipologie_evento' AS gruppo, codice, descrizione, attivo
FROM hr_tipologie_evento
WHERE codice IN ('FERIE', 'PERMESSO', 'MALATTIA', 'SMART', 'TRASFERTA', 'ALTRO')
UNION ALL
SELECT 'canali_notifica' AS gruppo, codice, descrizione, attivo
FROM hr_canali_notifica
WHERE codice IN ('WEB', 'EMAIL', 'SMS', 'WHATSAPP')
ORDER BY gruppo, codice;

-- 5) Richieste con stato non coerente rispetto alle approvazioni pendenti
SELECT
    r.id_richiesta,
    r.codice_richiesta,
    sr.codice AS stato_richiesta,
    a.stato_approvazione,
    COUNT(*) AS righe_approvazione
FROM hr_richieste r
INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
LEFT JOIN hr_richieste_approvazioni a ON a.id_richiesta = r.id_richiesta
GROUP BY r.id_richiesta, r.codice_richiesta, sr.codice, a.stato_approvazione
HAVING
    (sr.codice = 'IN_ATTESA' AND SUM(CASE WHEN a.stato_approvazione = 'IN_ATTESA' THEN 1 ELSE 0 END) = 0)
    OR
    (sr.codice IN ('APPROVATA', 'RIFIUTATA') AND SUM(CASE WHEN a.stato_approvazione = 'IN_ATTESA' THEN 1 ELSE 0 END) > 0);

-- 6) Periodi orari potenzialmente non validi
SELECT
    r.codice_richiesta,
    p.id_richiesta_periodo,
    p.data_da,
    p.data_a,
    p.ora_da,
    p.ora_a
FROM hr_richieste r
INNER JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
WHERE p.tipo_periodo = 'ORE'
  AND (
        p.data_da <> p.data_a
        OR p.ora_da IS NULL
        OR p.ora_a IS NULL
        OR p.ora_a <= p.ora_da
      )
ORDER BY p.data_da DESC, p.ora_da DESC;
