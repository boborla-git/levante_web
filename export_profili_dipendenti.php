<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/export_excel.php';

richiediPermessoLettura('profili_dipendenti');

$pdo = db();

function exportProfiliValore(?string $valore): string
{
    return trim((string)$valore);
}

function exportProfiliNominativo(?string $nome, ?string $cognome, ?string $username): string
{
    $nominativo = trim((string)$nome . ' ' . (string)$cognome);
    return $nominativo !== '' ? $nominativo : trim((string)$username);
}

$profili = $pdo->query(
    'SELECT *
     FROM v_hr_profili_dipendenti
     ORDER BY cognome, nome, utente_test, username'
)->fetchAll(PDO::FETCH_ASSOC);

$responsabiliRows = $pdo->query(
    "SELECT ro.id_utente,
            ro.id_utente_collegato,
            tro.codice AS tipo_codice,
            tro.descrizione AS tipo_descrizione,
            CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,'')) AS responsabile_nome,
            u.username AS responsabile_username,
            ro.data_inizio
     FROM hr_relazioni_organizzative ro
     INNER JOIN hr_tipi_relazione_organizzativa tro ON tro.id_tipo_relazione = ro.id_tipo_relazione
     INNER JOIN aut_utenti u ON u.id_utente = ro.id_utente_collegato
     WHERE ro.attiva = 1
       AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
     ORDER BY ro.id_utente,
              CASE WHEN tro.codice = 'RESPONSABILE_FUNZIONALE' THEN 0 WHEN tro.codice = 'RESPONSABILE_DIRETTO' THEN 1 ELSE 2 END,
              ro.data_inizio DESC,
              u.cognome,
              u.nome"
)->fetchAll(PDO::FETCH_ASSOC);

$responsabiliByUtente = [];
foreach ($responsabiliRows as $row) {
    $idUtente = (int)$row['id_utente'];
    $nome = trim((string)($row['responsabile_nome'] ?? ''));
    $username = trim((string)($row['responsabile_username'] ?? ''));
    $label = $nome !== '' ? $nome : $username;
    $tipo = trim((string)($row['tipo_descrizione'] ?? ''));
    $responsabiliByUtente[$idUtente][] = trim($label . ($tipo !== '' ? ' - ' . $tipo : ''));
}

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

$rows = [];
foreach ($profili as $profilo) {
    $idUtente = (int)($profilo['id_utente'] ?? 0);
    $rows[] = [
        exportProfiliNominativo($profilo['nome'] ?? '', $profilo['cognome'] ?? '', $profilo['username'] ?? ''),
        exportProfiliValore($profilo['username'] ?? ''),
        ((int)($profilo['utente_test'] ?? 0) === 1) ? 'Test' : 'Reale',
        ((int)($profilo['profilo_attivo'] ?? 0) === 1) ? 'Attivo' : 'Disattivo',
        exportProfiliValore($profilo['matricola'] ?? ''),
        exportProfiliValore($profilo['mansione'] ?? ''),
        exportProfiliValore($profilo['reparto'] ?? ''),
        exportProfiliValore($profilo['codice_reparto'] ?? ''),
        exportProfiliValore($profilo['centro_costo'] ?? ''),
        exportProfiliValore($profilo['codice_centro_costo'] ?? ''),
        implode(' | ', $responsabiliByUtente[$idUtente] ?? []),
        implode(' | ', $teamByUtente[$idUtente] ?? []),
        exportProfiliValore($profilo['data_assunzione'] ?? ''),
        exportProfiliValore($profilo['data_cessazione'] ?? ''),
        exportProfiliValore($profilo['note_hr'] ?? ''),
    ];
}

levanteOutputXlsx(
    'HR_ProfiliDipendenti',
    'Profili dipendenti',
    [
        'Dipendente',
        'Username',
        'Tipo utente',
        'Stato profilo',
        'Matricola',
        'Mansione',
        'Reparto',
        'Codice reparto',
        'Centro di costo',
        'Codice centro di costo',
        'Responsabile / referente',
        'Team',
        'Data assunzione',
        'Data cessazione',
        'Note HR',
    ],
    $rows,
    [26, 22, 14, 14, 16, 24, 24, 18, 24, 20, 35, 35, 18, 18, 40]
);
