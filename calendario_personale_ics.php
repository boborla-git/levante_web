<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/hr_calendario.php';

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

function icsPresentazioneEvento(array $evento, array $scopeMap, int $idUtente, bool $puoConfigurare, bool $puoVedereTipologie): array
{
    $dettaglio = hrMostraDettaglioCalendario($evento, $scopeMap, $idUtente, $puoConfigurare, $puoVedereTipologie);
    $persona = hrNomeUtente($evento);
    $titolo = hrEtichettaCalendario($evento, $scopeMap, $idUtente, $puoConfigurare, $puoVedereTipologie);
    if (($evento['codice_stato_richiesta'] ?? '') === 'IN_ATTESA') {
        $titolo = '[In attesa] ' . $titolo;
    }
    $descrizione = 'Persona: ' . $persona . "\nStato: " . trim((string)($evento['stato_richiesta'] ?? ''));
    $oggetto = $dettaglio ? trim((string)($evento['oggetto'] ?? '')) : '';
    if ($oggetto !== '') $descrizione .= "\nOggetto: " . $oggetto;
    // Il codice richiesta resta nel proprio feed come prima; per le altre
    // persone esportiamo soltanto quanto mostra il calendario web.
    if ((int)($evento['id_utente_richiedente'] ?? 0) === $idUtente) {
        $codice = trim((string)($evento['codice_richiesta'] ?? ''));
        if ($codice !== '') $descrizione .= "\nRichiesta Levante: " . $codice;
    }
    $codiceTipologia = strtoupper(trim((string)($evento['codice_tipologia'] ?? '')));
    return [
        'titolo' => $persona . ' - ' . $titolo,
        'descrizione' => $descrizione,
        'categorie' => $dettaglio && $codiceTipologia !== '' ? 'LEVANTE,' . $codiceTipologia : 'LEVANTE',
    ];
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

try {
    // Il token identifica il proprietario. Non usare la sessione del browser:
    // il feed deve avere gli stessi permessi anche nei client calendario esterni.
    $permessiCalendario = hrCalendarioPermessiUtente($pdo, $idUtente);
    if (!$permessiCalendario['leggere']) {
        http_response_code(403);
        exit('Accesso negato');
    }
    $scopeUtenti = hrScopeUtentiCalendario($pdo, $idUtente, $permessiCalendario['tutte']);
    $scopeIds = [];
    $scopeMap = [];
    foreach ($scopeUtenti as $persona) {
        $uid = (int)$persona['id_utente'];
        $scopeIds[] = $uid;
        $scopeMap[$uid] = [
            'label' => hrNomeUtente($persona),
            'gerarchia' => (int)($persona['scope_gerarchia'] ?? 0) === 1,
            'gruppo' => (int)($persona['scope_gruppo'] ?? 0) === 1,
        ];
    }
    // Oggi in Italia, compreso: mantieni anche le assenze iniziate prima
    // di oggi ma non ancora terminate. Nessun limite pratico agli eventi futuri.
    $oggiRoma = (new DateTimeImmutable('today', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
    $eventi = hrEventiCalendario($pdo, $idUtente, $scopeIds, $permessiCalendario['pendenti'], $oggiRoma, '9999-12-31');
} catch (Throwable $e) {
    // Fail closed: nessun feed parziale e nessun errore SQL al client.
    http_response_code(503);
    exit('Calendario temporaneamente non disponibile');
}

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
$ics .= icsLine('X-WR-CALDESC', icsEscape('Calendario Levante da oggi in avanti con le persone e i dettagli autorizzati nel portale'));

foreach ($eventi as $evento) {
    $tipoPeriodo = strtoupper(trim((string)($evento['tipo_periodo'] ?? '')));
    $codiceTipologia = strtoupper(trim((string)($evento['codice_tipologia'] ?? '')));
    $stato = strtoupper(trim((string)($evento['codice_stato_richiesta'] ?? '')));

    $presentazione = icsPresentazioneEvento(
        $evento, $scopeMap, $idUtente,
        $permessiCalendario['configurare'], $permessiCalendario['tipologie']
    );
    $titolo = $presentazione['titolo'];
    $descrizione = $presentazione['descrizione'];

    $uid = 'levante-hr-' . (int)$evento['id_richiesta'] . '-' . (int)$evento['id_richiesta_periodo'] . '@raviolispa.org';
    $lastModified = icsUtc(
        (string)($evento['data_aggiornamento'] ?: $evento['data_creazione'] ?: ''),
        $rome,
        $utc
    ) ?: $nowUtc;

    $vevent = "BEGIN:VEVENT\r\n";
    $vevent .= icsLine('UID', icsEscape($uid));
    $vevent .= icsLine('DTSTAMP', $nowUtc);
    $vevent .= icsLine('LAST-MODIFIED', $lastModified);
    $vevent .= icsLine('SUMMARY', icsEscape($titolo));
    $vevent .= icsLine('DESCRIPTION', icsEscape($descrizione));
    $vevent .= icsLine('CATEGORIES', icsEscape($presentazione['categorie']));
    $vevent .= icsLine('STATUS', $stato === 'IN_ATTESA' ? 'TENTATIVE' : 'CONFIRMED');

    if ($codiceTipologia === 'SMART') {
        $vevent .= "TRANSP:TRANSPARENT\r\n";
        $vevent .= "X-MICROSOFT-CDO-BUSYSTATUS:FREE\r\n";
    } else {
        $vevent .= "TRANSP:OPAQUE\r\n";
        $vevent .= "X-MICROSOFT-CDO-BUSYSTATUS:BUSY\r\n";
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
            $vevent .= icsLine('DTSTART', $inizioLocale->setTimezone($utc)->format('Ymd\\THis\\Z'));
            $vevent .= icsLine('DTEND', $fineLocale->setTimezone($utc)->format('Ymd\\THis\\Z'));
        } catch (Throwable $e) {
            continue;
        }
    } else {
        try {
            $inizio = new DateTimeImmutable((string)$evento['data_da']);
            $fineEsclusiva = (new DateTimeImmutable((string)$evento['data_a']))->modify('+1 day');
            $vevent .= icsLine('DTSTART;VALUE=DATE', $inizio->format('Ymd'));
            $vevent .= icsLine('DTEND;VALUE=DATE', $fineEsclusiva->format('Ymd'));
        } catch (Throwable $e) {
            continue;
        }
    }

    $vevent .= "END:VEVENT\r\n";
    $ics .= $vevent;
}

$ics .= "END:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: inline; filename="levante-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $username) . '.ics"');
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

echo $ics;
