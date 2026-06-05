<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/export_excel.php';

richiediPermessoLettura('profili_dipendenti');

$pdo = db();
$idUtenteLoggato = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$puoConfigurare = haPermessoLettura('configurazione_assenze');

function exportRespValore(?string $valore): string
{
    return trim((string)$valore);
}

function exportRespNominativo(?string $nome, ?string $cognome, ?string $username): string
{
    $nominativo = trim((string)$nome . ' ' . (string)$cognome);
    return $nominativo !== '' ? $nominativo : trim((string)$username);
}

$idResponsabile = (int)($_GET['id_responsabile'] ?? 0);
if ($idResponsabile <= 0 || !$puoConfigurare) {
    $idResponsabile = $idUtenteLoggato;
}

$stmtResponsabile = $pdo->prepare(
    "SELECT id_utente, username, nome, cognome
     FROM aut_utenti
     WHERE id_utente = :id_utente
       AND attivo = 1
     LIMIT 1"
);
$stmtResponsabile->execute(['id_utente' => $idResponsabile]);
$responsabile = $stmtResponsabile->fetch(PDO::FETCH_ASSOC);

if (!$responsabile) {
    http_response_code(404);
    echo 'Responsabile non trovato o non attivo.';
    exit;
}

$sql = "SELECT p.*,
               ro.data_inizio AS relazione_data_inizio,
               tro.codice AS relazione_codice,
               tro.descrizione AS relazione_tipo
        FROM hr_relazioni_organizzative ro
        INNER JOIN hr_tipi_relazione_organizzativa tro ON tro.id_tipo_relazione = ro.id_tipo_relazione
        INNER JOIN v_hr_profili_dipendenti p ON p.id_utente = ro.id_utente
        WHERE ro.id_utente_collegato = :id_responsabile
          AND ro.attiva = 1
          AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
          AND tro.codice IN ('RESPONSABILE_FUNZIONALE', 'RESPONSABILE_DIRETTO')
        ORDER BY p.cognome, p.nome, p.username";
$stmt = $pdo->prepare($sql);
$stmt->execute(['id_responsabile' => $idResponsabile]);
$collaboratori = $stmt->fetchAll(PDO::FETCH_ASSOC);

$teamRows = $pdo->query(
    "SELECT gu.id_utente,
            gl.nome AS gruppo_nome,
            gl.codice AS gruppo_codice,
            gu.ruolo_nel_gruppo
     FROM hr_gruppi_utenti gu
     INNER JOIN hr_gruppi_lavoro gl ON gl.id_gruppo_lavoro = gu.id_gruppo_lavoro
     WHERE gu.attivo = 1
       AND gl.attivo = 1
       AND (gu.data_fine IS NULL OR gu.data_fine >= CURDATE())
     ORDER BY gu.id_utente, gl.nome"
)->fetchAll(PDO::FETCH_ASSOC);

$teamByUtente = [];
foreach ($teamRows as $row) {
    $idUtente = (int)$row['id_utente'];
    $nome = trim((string)($row['gruppo_nome'] ?? ''));
    $codice = trim((string)($row['gruppo_codice'] ?? ''));
    $ruolo = trim((string)($row['ruolo_nel_gruppo'] ?? ''));
    $teamByUtente[$idUtente][] = trim($nome . ($codice !== '' ? ' (' . $codice . ')' : '') . ($ruolo !== '' ? ' - ' . $ruolo : ''));
}

$nomeResponsabile = exportRespNominativo(
    $responsabile['nome'] ?? '',
    $responsabile['cognome'] ?? '',
    $responsabile['username'] ?? ''
);

$rows = [];
foreach ($collaboratori as $profilo) {
    $idUtente = (int)($profilo['id_utente'] ?? 0);
    $rows[] = [
        $nomeResponsabile,
        exportRespNominativo($profilo['nome'] ?? '', $profilo['cognome'] ?? '', $profilo['username'] ?? ''),
        exportRespValore($profilo['username'] ?? ''),
        ((int)($profilo['utente_test'] ?? 0) === 1) ? 'Test' : 'Reale',
        ((int)($profilo['profilo_attivo'] ?? 0) === 1) ? 'Attivo' : 'Disattivo',
        exportRespValore($profilo['relazione_tipo'] ?? ''),
        exportRespValore($profilo['relazione_data_inizio'] ?? ''),
        exportRespValore($profilo['matricola'] ?? ''),
        exportRespValore($profilo['mansione'] ?? ''),
        exportRespValore($profilo['reparto'] ?? ''),
        exportRespValore($profilo['codice_reparto'] ?? ''),
        exportRespValore($profilo['centro_costo'] ?? ''),
        exportRespValore($profilo['codice_centro_costo'] ?? ''),
        implode(' | ', $teamByUtente[$idUtente] ?? []),
        exportRespValore($profilo['data_assunzione'] ?? ''),
        exportRespValore($profilo['data_cessazione'] ?? ''),
        exportRespValore($profilo['note_hr'] ?? ''),
    ];
}

levanteOutputXlsx(
    'HR_PersonaleResponsabile',
    'Personale responsabile',
    [
        'Responsabile',
        'Collaboratore',
        'Username',
        'Tipo utente',
        'Stato profilo',
        'Tipo relazione',
        'Dal',
        'Matricola',
        'Mansione',
        'Reparto',
        'Codice reparto',
        'Centro di costo',
        'Codice centro di costo',
        'Team',
        'Data assunzione',
        'Data cessazione',
        'Note HR',
    ],
    $rows,
    [28, 28, 22, 14, 14, 26, 16, 16, 24, 24, 18, 24, 20, 35, 18, 18, 40]
);
