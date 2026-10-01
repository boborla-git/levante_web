<?php
declare(strict_types=1);

function hrOrgCsrfToken(): string
{
    if (empty($_SESSION['hr_organizzazione_csrf'])) $_SESSION['hr_organizzazione_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['hr_organizzazione_csrf'];
}

function hrOrgVerificaCsrf(): void
{
    if (!hash_equals(hrOrgCsrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Il modulo è scaduto. Ricarica la pagina e riprova.');
    }
}

function hrOrgData(string $valore): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valore);
    if (!$d || $d->format('Y-m-d') !== $valore) throw new RuntimeException('Data non valida.');
    return $valore;
}

/** Usa la transazione del chiamante. Un solo responsabile gerarchico per periodo. */
function hrOrgAssegnaResponsabile(PDO $pdo, int $utente, int $responsabile, int $tipo, string $inizio, ?string $fine, ?string $note = null, bool $soloSeCambia = false): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Transazione organizzativa non avviata.');
    $inizio = hrOrgData($inizio);
    $fine = $fine !== null && $fine !== '' ? hrOrgData($fine) : null;
    if ($fine !== null && $fine < $inizio) throw new RuntimeException('La data fine non può precedere la data inizio.');
    if ($utente <= 0 || $responsabile < 0 || ($responsabile > 0 && $utente === $responsabile)) throw new RuntimeException('Dipendente e responsabile non validi.');

    // Tutti i salvataggi delle due pagine si serializzano sullo stesso dipendente.
    $stmt = $pdo->prepare('SELECT id_utente FROM aut_utenti WHERE id_utente = :id AND attivo = 1 FOR UPDATE');
    $stmt->execute(['id' => $utente]);
    if ($stmt->fetchColumn() === false) throw new RuntimeException('Dipendente non trovato o non attivo.');
    if ($responsabile > 0) {
        $stmt = $pdo->prepare('SELECT id_utente FROM aut_utenti WHERE id_utente = :id AND attivo = 1');
        $stmt->execute(['id' => $responsabile]);
        if ($stmt->fetchColumn() === false) throw new RuntimeException('Responsabile non trovato o non attivo.');
        $stmt = $pdo->prepare("SELECT codice FROM hr_tipi_relazione_organizzativa WHERE id_tipo_relazione = :id AND attivo = 1");
        $stmt->execute(['id' => $tipo]);
        if (!in_array($stmt->fetchColumn(), ['RESPONSABILE_FUNZIONALE', 'RESPONSABILE_DIRETTO'], true)) throw new RuntimeException('Tipo di responsabile non valido.');
    }
    $stmt = $pdo->prepare("SELECT ro.* FROM hr_relazioni_organizzative ro
        INNER JOIN hr_tipi_relazione_organizzativa tro ON tro.id_tipo_relazione = ro.id_tipo_relazione
        WHERE ro.id_utente = :utente AND ro.attiva = 1
        AND tro.codice IN ('RESPONSABILE_FUNZIONALE','RESPONSABILE_DIRETTO')
        ORDER BY ro.data_inizio, ro.id_relazione_organizzativa FOR UPDATE");
    $stmt->execute(['utente' => $utente]);
    $relazioni = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $correnti = array_values(array_filter($relazioni, static fn(array $r): bool => $r['data_inizio'] <= $inizio && ($r['data_fine'] === null || $r['data_fine'] >= $inizio)));
    // Salvare mansione/reparto non altera né proroga una responsabilità invariata.
    if ($soloSeCambia && (($responsabile === 0 && count($correnti) === 0) || (count($correnti) === 1 && (int)$correnti[0]['id_utente_collegato'] === $responsabile))) return;
    if (!$soloSeCambia && count($correnti) === 1) {
        $r = $correnti[0];
        if ((int)$r['id_utente_collegato'] === $responsabile && (int)$r['id_tipo_relazione'] === $tipo && $r['data_inizio'] === $inizio && $r['data_fine'] === $fine && trim((string)$r['note']) === trim((string)$note)) return;
    }
    $sovrapposte = array_values(array_filter($relazioni, static fn(array $r): bool => ($fine === null || $r['data_inizio'] <= $fine) && ($r['data_fine'] === null || $r['data_fine'] >= $inizio)));
    foreach ($sovrapposte as $r) {
        if ($r['data_inizio'] > $inizio) throw new RuntimeException('Esiste già una responsabilità pianificata nel periodo scelto. Verificala nella pagina Relazioni organizzative prima di procedere.');
    }
    if ($fine !== null && count($sovrapposte) > 1) throw new RuntimeException('Nel periodo scelto risultano più responsabili. Verifica prima le relazioni esistenti.');
    $giornoPrima = (new DateTimeImmutable($inizio))->modify('-1 day')->format('Y-m-d');
    foreach ($sovrapposte as $r) {
        if ($r['data_inizio'] === $inizio) {
            // Una sostituzione nello stesso giorno non genera un periodo negativo.
            $stmt = $pdo->prepare('UPDATE hr_relazioni_organizzative SET attiva = 0 WHERE id_relazione_organizzativa = :id');
            $stmt->execute(['id' => (int)$r['id_relazione_organizzativa']]);
        } else {
            // Mantiene la relazione consultabile e valida fino al giorno precedente.
            $stmt = $pdo->prepare('UPDATE hr_relazioni_organizzative SET data_fine = :fine WHERE id_relazione_organizzativa = :id');
            $stmt->execute(['fine' => $giornoPrima, 'id' => (int)$r['id_relazione_organizzativa']]);
        }
    }
    // Una sostituzione a termine conserva la responsabilità già prevista dopo
    // la fine, senza sovrascrivere eventuali assegnazioni successive.
    if ($fine !== null && count($sovrapposte) === 1) {
        $r = $sovrapposte[0];
        if ($r['data_fine'] === null || $r['data_fine'] > $fine) {
            $ripresa = (new DateTimeImmutable($fine))->modify('+1 day')->format('Y-m-d');
            $fineRipresa = $r['data_fine'];
            foreach ($relazioni as $pianificata) {
                if ($pianificata['data_inizio'] > $fine && ($fineRipresa === null || $pianificata['data_inizio'] <= $fineRipresa)) {
                    $fineRipresa = (new DateTimeImmutable($pianificata['data_inizio']))->modify('-1 day')->format('Y-m-d');
                }
            }
            if ($fineRipresa === null || $fineRipresa >= $ripresa) {
                $stmt = $pdo->prepare('INSERT INTO hr_relazioni_organizzative (id_utente,id_utente_collegato,id_tipo_relazione,data_inizio,data_fine,attiva,note) VALUES (:utente,:responsabile,:tipo,:inizio,:fine,1,:note)');
                $stmt->execute(['utente'=>$utente, 'responsabile'=>(int)$r['id_utente_collegato'], 'tipo'=>(int)$r['id_tipo_relazione'], 'inizio'=>$ripresa, 'fine'=>$fineRipresa, 'note'=>$r['note']]);
            }
        }
    }
    if ($responsabile > 0) {
        $stmt = $pdo->prepare('INSERT INTO hr_relazioni_organizzative (id_utente,id_utente_collegato,id_tipo_relazione,data_inizio,data_fine,attiva,note) VALUES (:utente,:responsabile,:tipo,:inizio,:fine,1,:note)');
        $stmt->execute(['utente' => $utente, 'responsabile' => $responsabile, 'tipo' => $tipo, 'inizio' => $inizio, 'fine' => $fine, 'note' => $note]);
    }
}

function hrOrgChiudiRelazione(PDO $pdo, int $idRelazione): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Transazione organizzativa non avviata.');
    $stmt = $pdo->prepare('SELECT id_utente FROM hr_relazioni_organizzative WHERE id_relazione_organizzativa = :id');
    $stmt->execute(['id' => $idRelazione]);
    $utente = (int)$stmt->fetchColumn();
    if ($utente <= 0) throw new RuntimeException('Relazione non trovata.');
    $stmt = $pdo->prepare('SELECT id_utente FROM aut_utenti WHERE id_utente = :id FOR UPDATE');
    $stmt->execute(['id' => $utente]);
    if ($stmt->fetchColumn() === false) throw new RuntimeException('Dipendente non trovato.');
    $stmt = $pdo->prepare('UPDATE hr_relazioni_organizzative SET attiva = 0, data_fine = CASE WHEN data_inizio > CURDATE() THEN data_fine WHEN data_fine IS NULL OR data_fine > CURDATE() THEN CURDATE() ELSE data_fine END WHERE id_relazione_organizzativa = :id');
    $stmt->execute(['id' => $idRelazione]);
}
