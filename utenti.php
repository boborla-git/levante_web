<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('utenti');

$pdo = db();

$errore = '';
$messaggio = '';
$ruoliSessione = $_SESSION['ruoli'] ?? [];
$puoGestireQualificaInps =
    !impersonazioneAttiva()
    && is_array($ruoliSessione)
    && in_array('admin_portale', $ruoliSessione, true)
    && haPermessoScrittura('utenti');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = trim((string)($_POST['azione'] ?? ''));

    if ($azione === 'salva_qualifica_inps') {
        if (!$puoGestireQualificaInps) {
            http_response_code(403);
            die('Solo l\'amministratore può modificare la qualifica INPS.');
        }

        $idUtenteQualifica = (int)($_POST['id_utente'] ?? 0);
        $qualificaInps = strtoupper(trim((string)($_POST['qualifica_inps'] ?? '')));
        $qualificheAmmesse = ['', 'OPERAIO', 'IMPIEGATO'];

        if ($idUtenteQualifica <= 0 || !in_array($qualificaInps, $qualificheAmmesse, true)) {
            $errore = 'Utente o qualifica INPS non validi.';
        } else {
            try {
                $stmtUtente = $pdo->prepare('SELECT COUNT(*) FROM aut_utenti WHERE id_utente = :id_utente');
                $stmtUtente->execute(['id_utente' => $idUtenteQualifica]);
                if ((int)$stmtUtente->fetchColumn() !== 1) {
                    throw new RuntimeException('Utente non trovato.');
                }

                $stmtQualifica = $pdo->prepare(
                    "INSERT INTO hr_profili_dipendenti
                        (id_utente, qualifica_inps, attivo, data_aggiornamento)
                     VALUES
                        (:id_utente, :qualifica_inps, 1, NOW())
                     ON DUPLICATE KEY UPDATE
                        qualifica_inps = VALUES(qualifica_inps),
                        data_aggiornamento = NOW()"
                );
                $stmtQualifica->execute([
                    'id_utente' => $idUtenteQualifica,
                    'qualifica_inps' => $qualificaInps !== '' ? $qualificaInps : null,
                ]);

                header('Location: utenti.php?qualifica_ok=1');
                exit;
            } catch (Throwable $e) {
                $errore = 'Impossibile salvare la qualifica INPS.';
            }
        }
    }
}

if (isset($_GET['qualifica_ok'])) {
    $messaggio = 'Qualifica INPS aggiornata correttamente.';
}

function h(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

function adminUserDisplayName(array $utente): string
{
    $nome = trim((string)($utente['nome'] ?? ''));
    $cognome = trim((string)($utente['cognome'] ?? ''));
    $username = trim((string)($utente['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);

    return $nominativo !== '' ? $nominativo : $username;
}

function adminUserInitials(array $utente): string
{
    $nome = trim((string)($utente['nome'] ?? ''));
    $cognome = trim((string)($utente['cognome'] ?? ''));
    $username = trim((string)($utente['username'] ?? ''));

    $iniziali = '';
    if ($nome !== '') {
        $iniziali .= mb_substr($nome, 0, 1, 'UTF-8');
    }
    if ($cognome !== '') {
        $iniziali .= mb_substr($cognome, 0, 1, 'UTF-8');
    }

    if ($iniziali === '' && $username !== '') {
        $iniziali = mb_substr($username, 0, 2, 'UTF-8');
    }

    return mb_strtoupper($iniziali !== '' ? $iniziali : '?', 'UTF-8');
}

function adminFormatDate(?string $valore): string
{
    $valore = trim((string)$valore);
    if ($valore === '') {
        return 'N/D';
    }

    try {
        return (new DateTime($valore))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $valore;
    }
}

$stmt = $pdo->query(
    "SELECT
        u.id_utente,
        u.username,
        u.nome,
        u.cognome,
        u.attivo,
        u.deve_cambiare_password,
        u.data_creazione,
        u.data_aggiornamento,
        p.qualifica_inps,
        GROUP_CONCAT(DISTINCT ar.codice_ruolo ORDER BY ar.ordinamento, ar.codice_ruolo SEPARATOR ', ') AS ruoli_attivi
    FROM aut_utenti u
    LEFT JOIN hr_profili_dipendenti p
        ON p.id_utente = u.id_utente
    LEFT JOIN aut_utenti_ruoli aur
        ON aur.id_utente = u.id_utente
        AND aur.attivo = 1
        AND (aur.data_fine IS NULL OR aur.data_fine >= NOW())
    LEFT JOIN aut_ruoli ar
        ON ar.id_ruolo = aur.id_ruolo
        AND ar.attivo = 1
    GROUP BY
        u.id_utente,
        u.username,
        u.nome,
        u.cognome,
        u.attivo,
        u.deve_cambiare_password,
        u.data_creazione,
        u.data_aggiornamento,
        p.qualifica_inps
    ORDER BY
        u.attivo DESC,
        COALESCE(NULLIF(u.cognome, ''), u.username),
        COALESCE(NULLIF(u.nome, ''), u.username),
        u.username"
);

$utenti = $stmt->fetchAll(PDO::FETCH_ASSOC);

$riepilogo = [
    'totali' => count($utenti),
    'attivi' => 0,
    'disattivi' => 0,
    'senza_ruolo' => 0,
    'cambio_password' => 0,
    'operai' => 0,
    'impiegati' => 0,
    'senza_qualifica_inps' => 0,
];

foreach ($utenti as $utente) {
    if ((int)$utente['attivo'] === 1) {
        $riepilogo['attivi']++;
    } else {
        $riepilogo['disattivi']++;
    }

    if (trim((string)($utente['ruoli_attivi'] ?? '')) === '') {
        $riepilogo['senza_ruolo']++;
    }

    if ((int)$utente['deve_cambiare_password'] === 1) {
        $riepilogo['cambio_password']++;
    }

    if ((int)$utente['attivo'] === 1) {
        $qualificaInps = strtoupper(trim((string)($utente['qualifica_inps'] ?? '')));
        if ($qualificaInps === 'OPERAIO') {
            $riepilogo['operai']++;
        } elseif ($qualificaInps === 'IMPIEGATO') {
            $riepilogo['impiegati']++;
        } else {
            $riepilogo['senza_qualifica_inps']++;
        }
    }
}

$idUtenteCorrente = (int)($_SESSION['utente_id'] ?? 0);

layoutHeader('Gestione utenti');
?>
<link rel="stylesheet" href="/assets/admin.css">

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Gestione utenti</h1>
            <div class="meta">Directory amministrativa degli utenti del portale: accessi, ruoli attivi, stato e azioni di sicurezza.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-primary" href="utente_nuovo.php"><i class="la la-user-plus" aria-hidden="true"></i> Nuovo utente</a>
            <a class="btn btn-light" href="index.php"><i class="la la-arrow-left" aria-hidden="true"></i> Dashboard</a>
        </div>
    </div>
</div>

<div class="card card-compact">
    <?php renderAdminTabs('utenti'); ?>
</div>

<?php if ($errore !== ''): ?><?php renderAdminAlert($errore, 'danger'); ?><?php endif; ?>
<?php if ($messaggio !== ''): ?><?php renderAdminAlert($messaggio, 'success'); ?><?php endif; ?>

<section class="hr-config-summary">
    <span><strong><?= (int)$riepilogo['totali'] ?></strong> utenti</span>
    <span><strong><?= (int)$riepilogo['attivi'] ?></strong> attivi</span>
    <span><strong><?= (int)$riepilogo['disattivi'] ?></strong> disattivi</span>
    <span><strong><?= (int)$riepilogo['senza_ruolo'] ?></strong> senza ruolo</span>
    <span><strong><?= (int)$riepilogo['cambio_password'] ?></strong> cambio password</span>
    <span><strong><?= (int)$riepilogo['operai'] ?></strong> operai attivi</span>
    <span><strong><?= (int)$riepilogo['impiegati'] ?></strong> impiegati attivi</span>
    <span><strong><?= (int)$riepilogo['senza_qualifica_inps'] ?></strong> attivi senza Qual. INPS</span>
</section>

<div class="card card-wide admin-users-card">
    <div class="hr-filter-toolbar admin-section-toolbar">
        <div class="admin-section-title">
            <h2>Utenti</h2>
            <div class="meta">Vista unica e compatta per gestire utenti, ruoli, qualifica INPS e sicurezza.</div>
        </div>
        <?php renderAdminQuickFilter('filtroRapidoUtenti', 'tabellaUtenti', 'Cerca persona, username, ruolo, qualifica...'); ?>
    </div>

    <div class="table-wrap admin-users-table-wrap">
        <table id="tabellaUtenti" class="admin-users-table">
            <thead>
                <tr>
                    <th>Dipendente</th>
                    <th>Ruoli attivi</th>
                    <th>Qual. INPS</th>
                    <th>Stato</th>
                    <th>Password</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($utenti as $utente): ?>
                    <?php
                    $idUtente = (int)$utente['id_utente'];
                    $username = trim((string)$utente['username']);
                    $nomeCompleto = adminUserDisplayName($utente);
                    $ruoli = trim((string)($utente['ruoli_attivi'] ?? ''));
                    $qualificaInps = strtoupper(trim((string)($utente['qualifica_inps'] ?? '')));
                    $qualificaInpsLabel = $qualificaInps !== '' ? ucfirst(strtolower($qualificaInps)) : 'Non assegnata';
                    $utenteAttivo = (int)$utente['attivo'] === 1;
                    $cambioPassword = (int)$utente['deve_cambiare_password'] === 1;
                    ?>
                    <tr>
                        <td class="admin-users-person" data-label="Dipendente">
                            <div class="admin-users-person-main">
                                <div class="admin-user-avatar" aria-hidden="true"><?= h(adminUserInitials($utente)) ?></div>
                                <div class="admin-users-person-text">
                                    <strong><?= h($nomeCompleto) ?></strong>
                                    <span><?= h($username) ?></span>
                                    <details class="admin-users-details">
                                        <summary>Dettagli</summary>
                                        <div>
                                            <span><strong>ID:</strong> <?= $idUtente ?></span>
                                            <span><strong>Creato:</strong> <?= h(adminFormatDate((string)$utente['data_creazione'])) ?></span>
                                            <span><strong>Aggiornato:</strong> <?= h(adminFormatDate((string)($utente['data_aggiornamento'] ?? ''))) ?></span>
                                        </div>
                                    </details>
                                </div>
                            </div>
                        </td>
                        <td data-label="Ruoli attivi">
                            <span class="admin-users-role"><?= h($ruoli !== '' ? $ruoli : 'Nessun ruolo') ?></span>
                        </td>
                        <td data-label="Qual. INPS">
                            <?php if ($puoGestireQualificaInps): ?>
                                <form method="post" class="admin-users-qualifica-form">
                                    <input type="hidden" name="azione" value="salva_qualifica_inps">
                                    <input type="hidden" name="id_utente" value="<?= $idUtente ?>">
                                    <select name="qualifica_inps" aria-label="Qualifica INPS di <?= h($nomeCompleto) ?>">
                                        <option value="" <?= $qualificaInps === '' ? 'selected' : '' ?>>Non assegnata</option>
                                        <option value="OPERAIO" <?= $qualificaInps === 'OPERAIO' ? 'selected' : '' ?>>Operaio</option>
                                        <option value="IMPIEGATO" <?= $qualificaInps === 'IMPIEGATO' ? 'selected' : '' ?>>Impiegato</option>
                                    </select>
                                    <button class="btn btn-sm btn-light" type="submit" title="Salva Qualifica INPS">
                                        <i class="la la-save" aria-hidden="true"></i><span class="admin-users-action-text">Salva</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <?= h($qualificaInpsLabel) ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Stato">
                            <?= $utenteAttivo
                                ? renderHrStatusBadge('ATTIVO', 'Attivo', ['class' => 'user-badge'])
                                : renderHrStatusBadge('DISATTIVO', 'Disattivo', ['class' => 'user-badge']) ?>
                        </td>
                        <td data-label="Password">
                            <?= $cambioPassword
                                ? renderHrStatusBadge('OBBLIGATORIO', 'Cambio richiesto', ['class' => 'user-badge'])
                                : renderHrStatusBadge('OK', 'OK', ['class' => 'user-badge']) ?>
                        </td>
                        <td data-label="Azioni">
                            <?php if ($idUtente !== $idUtenteCorrente): ?>
                                <div class="admin-users-row-actions">
                                    <a class="btn btn-sm btn-light" href="utente_reset_password.php?id=<?= $idUtente ?>" title="Reset password">
                                        <i class="la la-key" aria-hidden="true"></i><span class="admin-users-action-text">Reset</span>
                                    </a>
                                    <a class="btn btn-sm btn-light" href="utente_forza_password.php?id=<?= $idUtente ?>"
                                       onclick="return confirm('Vuoi obbligare questo utente a cambiare la password al prossimo accesso?');"
                                       title="Forza cambio password">
                                        <i class="la la-exclamation-circle" aria-hidden="true"></i><span class="admin-users-action-text">Forza</span>
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="meta">Utente corrente</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php layoutFooter(); ?>
