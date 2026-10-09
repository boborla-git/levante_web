<?php
declare(strict_types=1);

// Regole comuni: nuova richiesta, riclassificazione e approvazione.
function hrRegoleOperatoreHr(): bool
{
    return utenteAdminGlobale()
        || in_array('hr_responsabile_personale', (array)($_SESSION['ruoli'] ?? []), true);
}

function hrRegoleCsrfToken(): string
{
    if (empty($_SESSION['hr_regole_csrf'])) {
        $_SESSION['hr_regole_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['hr_regole_csrf'];
}

function hrRegoleVerificaCsrf(): void
{
    if (!hash_equals(hrRegoleCsrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('La sessione del modulo è scaduta. Ricarica la pagina e riprova.');
    }
}

function hrRegoleDataValida(string $data): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $d !== false && $d->format('Y-m-d') === $data && $data >= '1000-01-01';
}

function hrRegoleValidaDate(string $da, string $a): void
{
    if (!hrRegoleDataValida($da) || !hrRegoleDataValida($a) || $a < $da) {
        throw new RuntimeException('Indica un periodo valido: la data finale non può precedere quella iniziale.');
    }
}

// Mutex transazionale su una risorsa esistente, senza modificare i permessi.
// Va acquisito prima delle letture: serializza chiusure, assegnazioni e richieste,
// impedendo che invii simultanei superino le due ore o attraversino una chiusura.
function hrRegoleBloccaScrittura(PDO $pdo): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Il controllo delle assenze richiede una transazione.');
    }
    $stmt = $pdo->query("SELECT id_risorsa FROM aut_risorse WHERE codice_risorsa = 'pagina.assenze' FOR UPDATE");
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Risorsa Assenze non configurata: contatta l’amministratore.');
    }
}

function hrRegoleChiusure(PDO $pdo): array
{
    return $pdo->query('SELECT id_chiusura, descrizione, data_da, data_a, attivo
        FROM hr_chiusure_aziendali WHERE attivo = 1 ORDER BY data_da, id_chiusura')->fetchAll(PDO::FETCH_ASSOC);
}

function hrRegoleVerificaChiusura(PDO $pdo, string $da, string $a): void
{
    hrRegoleValidaDate($da, $a);
    $stmt = $pdo->prepare('SELECT descrizione, data_da, data_a FROM hr_chiusure_aziendali
        WHERE attivo = 1 AND data_da <= :fine AND data_a >= :inizio
        ORDER BY data_da LIMIT 1');
    $stmt->execute(['fine' => $a, 'inizio' => $da]);
    $chiusura = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($chiusura) {
        throw new RuntimeException('Il periodo comprende una chiusura aziendale: '
            . $chiusura['descrizione'] . ' (' . date('d/m/Y', strtotime($chiusura['data_da']))
            . ' – ' . date('d/m/Y', strtotime($chiusura['data_a']))
            . '). Non è possibile inserire o approvare richieste in questi giorni.');
    }
}

function hrRegoleVerificaAllattamento(PDO $pdo, int $utente, array $periodi, int $escludiRichiesta = 0): void
{
    $stmt = $pdo->prepare("SELECT data_inizio, data_fine FROM hr_benefici_utenti
        WHERE id_utente = :utente AND codice_beneficio = 'ALLATTAMENTO' AND attivo = 1 LIMIT 1");
    $stmt->execute(['utente' => $utente]);
    $beneficio = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$beneficio || !$beneficio['data_fine']) {
        throw new RuntimeException('HR deve assegnare un periodo Allattamento completo, con data iniziale e finale.');
    }
    if (!$periodi) {
        throw new RuntimeException('La richiesta non contiene un periodo valido.');
    }
    $minutiPerGiorno = [];
    foreach ($periodi as $p) {
        $da = (string)$p['data_da'];
        $a = (string)$p['data_a'];
        hrRegoleValidaDate($da, $a);
        if (strtoupper((string)$p['tipo_periodo']) !== 'ORE' || $da !== $a) {
            throw new RuntimeException('L’allattamento può essere inserito solo a ore, per una singola giornata.');
        }
        if ($da < $beneficio['data_inizio'] || $a > $beneficio['data_fine']) {
            throw new RuntimeException('Il giorno richiesto non rientra nel periodo Allattamento assegnato da HR.');
        }
        $oraDa = (string)($p['ora_da'] ?? '');
        $oraA = (string)($p['ora_a'] ?? '');
        if (!preg_match('/^(\d{2}):(\d{2})(?::00)?$/', $oraDa, $mDa)
            || !preg_match('/^(\d{2}):(\d{2})(?::00)?$/', $oraA, $mA)
            || (int)$mDa[1] > 23 || (int)$mA[1] > 23 || (int)$mDa[2] > 59 || (int)$mA[2] > 59) {
            throw new RuntimeException('Indica orari validi per l’allattamento.');
        }
        $minuti = ((int)$mA[1] * 60 + (int)$mA[2]) - ((int)$mDa[1] * 60 + (int)$mDa[2]);
        if ($minuti <= 0 || $minuti > 120) {
            throw new RuntimeException('Per l’allattamento puoi inserire al massimo 2 ore al giorno.');
        }
        $minutiPerGiorno[$da] = ($minutiPerGiorno[$da] ?? 0) + $minuti;
    }
    // APPROVATA e IN_ATTESA occupano il plafond; rifiutate e annullate lo liberano.
    // Eventuali vecchie registrazioni a giorni occupano l’intero plafond.
    $stmtUsati = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN p.tipo_periodo = 'ORE'
            AND p.data_da = p.data_a AND p.ora_da IS NOT NULL AND p.ora_a > p.ora_da
            THEN TIME_TO_SEC(TIMEDIFF(p.ora_a, p.ora_da)) / 60 ELSE 120 END), 0)
        FROM hr_richieste r
        INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento AND te.codice = 'ALLATTAMENTO'
        INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta AND sr.codice IN ('APPROVATA', 'IN_ATTESA')
        INNER JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
        WHERE r.id_utente_richiedente = :utente AND r.id_richiesta <> :escludi
          AND p.data_da <= :giorno_fine AND p.data_a >= :giorno_inizio");
    foreach ($minutiPerGiorno as $giorno => $minuti) {
        $stmtUsati->execute(['utente' => $utente, 'escludi' => $escludiRichiesta,
            'giorno_fine' => $giorno, 'giorno_inizio' => $giorno]);
        $usati = (float)$stmtUsati->fetchColumn();
        if ($usati + $minuti > 120) {
            throw new RuntimeException('Limite Allattamento di 2 ore complessive al giorno superato per il '
                . date('d/m/Y', strtotime($giorno)) . '. Sono già presenti '
                . (int)$usati . ' minuti approvati o in attesa.');
        }
    }
}

function hrRegoleVerificaRichiesta(PDO $pdo, int $utente, string $codice, array $periodi, int $escludiRichiesta = 0): void
{
    if (!$periodi) throw new RuntimeException('La richiesta non contiene un periodo valido.');
    foreach ($periodi as $p) {
        hrRegoleVerificaChiusura($pdo, (string)$p['data_da'], (string)$p['data_a']);
    }
    if (strtoupper($codice) === 'ALLATTAMENTO') {
        hrRegoleVerificaAllattamento($pdo, $utente, $periodi, $escludiRichiesta);
    }
}

function hrRegoleVerificaRichiestaEsistente(PDO $pdo, int $richiesta, ?string $codiceNuovo = null): void
{
    $stmt = $pdo->prepare('SELECT r.id_utente_richiedente, te.codice FROM hr_richieste r
        INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
        WHERE r.id_richiesta = :richiesta');
    $stmt->execute(['richiesta' => $richiesta]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('Richiesta non trovata.');
    $stmt = $pdo->prepare('SELECT data_da, data_a, tipo_periodo, ora_da, ora_a
        FROM hr_richieste_periodi WHERE id_richiesta = :richiesta');
    $stmt->execute(['richiesta' => $richiesta]);
    hrRegoleVerificaRichiesta($pdo, (int)$r['id_utente_richiedente'], $codiceNuovo ?? (string)$r['codice'],
        $stmt->fetchAll(PDO::FETCH_ASSOC), $richiesta);
}
