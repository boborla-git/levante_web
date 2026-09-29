<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

function icsEscape(string $value): string
{
    return str_replace(
        ["\\", "\r\n", "\r", "\n", ",", ";"],
        ["\\\\", "\\n", "\\n", "\\n", "\\,", "\\;"],
        $value
    );
}

function icsFold(string $line): string
{
    $parts = [];
    $remaining = $line;

    while (strlen($remaining) > 73) {
        $chunk = mb_strcut($remaining, 0, 73, 'UTF-8');
        if ($chunk === '') {
            break;
        }
        $parts[] = $chunk;
        $remaining = substr($remaining, strlen($chunk));
    }

    $parts[] = $remaining;
    return implode("\r\n ", $parts);
}

function icsLine(string $name, string $value): string
{
    return icsFold($name . ':' . $value) . "\r\n";
}

function icsUtc(?string $value, DateTimeZone $rome, DateTimeZone $utc): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value, $rome))
            ->setTimezone($utc)
            ->format('Ymd\\THis\\Z');
    } catch (Throwable $e) {
        return null;
    }
}

$pdo = db();

$username = trim((string)($_GET['utente'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

if ($username === '' || $token === '') {
    http_response_code(403);
    exit('Accesso negato');
}

$stmtUtente = $pdo->prepare(
    "SELECT id_utente, username, nome, cognome
     FROM aut_utenti
     WHERE username = :username
       AND attivo = 1
     LIMIT 1"
);
$stmtUtente->execute(['username' => $username]);
$utente = $stmtUtente->fetch(PDO::FETCH_ASSOC);

if (!$utente) {
    http_response_code(403);
    exit('Accesso negato');
}

$idUtente = (int)$utente['id_utente'];
$codiceToken = 'HR_ICS_TOKEN_SHA256_USER_' . $idUtente;

$stmtToken = $pdo->prepare(
    "SELECT valore
     FROM hr_configurazioni
     WHERE codice = :codice
       AND attivo = 1
     LIMIT 1"
);
$stmtToken->execute(['codice' => $codiceToken]);
$hashAtteso = strtolower(trim((string)($stmtToken->fetchColumn() ?: '')));
$hashRicevuto = hash('sha256', $token);

if ($hashAtteso === '' || !hash_equals($hashAtteso, $hashRicevuto)) {
    http_response_code(403);
    exit('Accesso negato');
}

$stmtEventi = $pdo->prepare(
    "SELECT
        r.id_richiesta,
        r.codice_richiesta,
        r.oggetto,
        r.data_creazione,
        r.data_aggiornamento,
        p.id_richiesta_periodo,
        p.tipo_periodo,
        p.data_da,
        p.data_a,
        p.ora_da,
        p.ora_a,
        te.codice AS codice_tipologia,
        te.descrizione AS tipologia,
        te.descrizione_calendario,
        sr.codice AS codice_stato,
        sr.descrizione AS stato
     FROM hr_richieste r
     INNER JOIN hr_stati_richiesta sr
        ON sr.id_stato_richiesta = r.id_stato_richiesta
       AND sr.codice IN ('APPROVATA', 'IN_ATTESA')
     INNER JOIN hr_richieste_periodi p
        ON p.id_richiesta = r.id_richiesta
     INNER JOIN hr_tipologie_evento te
        ON te.id_tipologia_evento = r.id_tipologia_evento
       AND te.attivo = 1
       AND te.visibile_calendario = 1
     WHERE r.id_utente_richiedente = :id_utente
     ORDER BY p.data_da, p.ora_da, r.id_richiesta, p.id_richiesta_periodo"
);
$stmtEventi->execute(['id_utente' => $idUtente]);
$eventi = $stmtEventi->fetchAll(PDO::FETCH_ASSOC) ?: [];

$nomeUtente = trim((string)($utente['nome'] ?? '') . ' ' . (string)($utente['cognome'] ?? ''));
if ($nomeUtente === '') {
    $nomeUtente = (string)$utente['username'];
}

$rome = new DateTimeZone('Europe/Rome');
$utc = new DateTimeZone('UTC');
$nowUtc = (new DateTimeImmutable('now', $utc))->format('Ymd\\THis\\Z');

$ics = "BEGIN:VCALENDAR\r\n";
$ics .= "VERSION:2.0\r\n";
$ics .= "PRODID:-//Ravioli S.p.A.//Levante HR//IT\r\n";
$ics .= "CALSCALE:GREGORIAN\r\n";
$ics .= "METHOD:PUBLISH\r\n";
$ics .= icsLine('X-WR-CALNAME', icsEscape('Levante - ' . $nomeUtente));
$ics .= icsLine('X-WR-CALDESC', icsEscape('Calendario personale HR Levante aggiornato automaticamente'));

foreach ($eventi as $evento) {
    $tipoPeriodo = strtoupper(trim((string)($evento['tipo_periodo'] ?? '')));
    $codiceTipologia = strtoupper(trim((string)($evento['codice_tipologia'] ?? '')));
    $stato = strtoupper(trim((string)($evento['codice_stato'] ?? '')));

    $titolo = trim((string)($evento['descrizione_calendario'] ?? ''));
    if ($titolo === '') {
        $titolo = trim((string)($evento['tipologia'] ?? ''));
    }
    if ($titolo === '') {
        $titolo = 'Impegno';
    }
    if ($stato === 'IN_ATTESA') {
        $titolo = '[In attesa] ' . $titolo;
    }

    $descrizione = 'Stato: ' . trim((string)($evento['stato'] ?? $stato));
    $oggetto = trim((string)($evento['oggetto'] ?? ''));
    if ($oggetto !== '') {
        $descrizione .= "\nOggetto: " . $oggetto;
    }
    $codiceRichiesta = trim((string)($evento['codice_richiesta'] ?? ''));
    if ($codiceRichiesta !== '') {
        $descrizione .= "\nRichiesta Levante: " . $codiceRichiesta;
    }

    $uid = 'levante-hr-' . (int)$evento['id_richiesta'] . '-' . (int)$evento['id_richiesta_periodo'] . '@raviolispa.org';
    $lastModified = icsUtc(
        (string)($evento['data_aggiornamento'] ?: $evento['data_creazione'] ?: ''),
        $rome,
        $utc
    ) ?: $nowUtc;

    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= icsLine('UID', icsEscape($uid));
    $ics .= icsLine('DTSTAMP', $nowUtc);
    $ics .= icsLine('LAST-MODIFIED', $lastModified);
    $ics .= icsLine('SUMMARY', icsEscape($titolo));
    $ics .= icsLine('DESCRIPTION', icsEscape($descrizione));
    $ics .= icsLine('CATEGORIES', icsEscape('LEVANTE,' . ($codiceTipologia !== '' ? $codiceTipologia : 'HR')));
    $ics .= icsLine('STATUS', $stato === 'IN_ATTESA' ? 'TENTATIVE' : 'CONFIRMED');

    if ($codiceTipologia === 'SMART') {
        $ics .= "TRANSP:TRANSPARENT\r\n";
        $ics .= "X-MICROSOFT-CDO-BUSYSTATUS:FREE\r\n";
    } else {
        $ics .= "TRANSP:OPAQUE\r\n";
        $ics .= "X-MICROSOFT-CDO-BUSYSTATUS:BUSY\r\n";
    }

    if ($tipoPeriodo === 'ORE' && trim((string)$evento['ora_da']) !== '' && trim((string)$evento['ora_a']) !== '') {
        try {
            $inizioLocale = new DateTimeImmutable(
                (string)$evento['data_da'] . ' ' . (string)$evento['ora_da'],
                $rome
            );
            $fineLocale = new DateTimeImmutable(
                (string)$evento['data_a'] . ' ' . (string)$evento['ora_a'],
                $rome
            );
            $ics .= icsLine('DTSTART', $inizioLocale->setTimezone($utc)->format('Ymd\\THis\\Z'));
            $ics .= icsLine('DTEND', $fineLocale->setTimezone($utc)->format('Ymd\\THis\\Z'));
        } catch (Throwable $e) {
            continue;
        }
    } else {
        try {
            $inizio = new DateTimeImmutable((string)$evento['data_da']);
            $fineEsclusiva = (new DateTimeImmutable((string)$evento['data_a']))->modify('+1 day');
            $ics .= icsLine('DTSTART;VALUE=DATE', $inizio->format('Ymd'));
            $ics .= icsLine('DTEND;VALUE=DATE', $fineEsclusiva->format('Ymd'));
        } catch (Throwable $e) {
            continue;
        }
    }

    $ics .= "END:VEVENT\r\n";
}

$ics .= "END:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: inline; filename="levante-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $username) . '.ics"');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

echo $ics;
