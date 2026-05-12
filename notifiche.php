<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('notifiche');

$pdo = db();
$idUtente = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$errore = '';
$messaggio = '';

function h(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

function normalizzaLinkNotifica(?string $link): string
{
    $link = trim((string)$link);
    if ($link === '') {
        return '';
    }

    if (strpos($link, '/') === 0) {
        return $link;
    }

    if (preg_match('/^[a-zA-Z0-9_\-\/\.]+\.php(\?.*)?$/', $link) === 1) {
        return '/' . ltrim($link, '/');
    }

    return '';
}


function classificaTipoNotifica(?string $tipoEvento): array
{
    $tipo = strtoupper(trim((string)$tipoEvento));

    if ($tipo === '') {
        return [
            'label' => 'Notifica',
            'class' => 'notification-type-generic',
            'icon' => 'la-bell',
        ];
    }

    if (strpos($tipo, 'RIFIUT') !== false) {
        return [
            'label' => 'Rifiuto HR',
            'class' => 'notification-type-rejected',
            'icon' => 'la-times-circle',
        ];
    }

    if (strpos($tipo, 'APPROV') !== false) {
        return [
            'label' => 'Approvazione HR',
            'class' => 'notification-type-approved',
            'icon' => 'la-check-circle',
        ];
    }

    if (strpos($tipo, 'ANNULL') !== false) {
        return [
            'label' => 'Annullamento HR',
            'class' => 'notification-type-cancelled',
            'icon' => 'la-ban',
        ];
    }

    if (strpos($tipo, 'RICHIESTA') !== false || strpos($tipo, 'ASSEN') !== false || strpos($tipo, 'HR') !== false) {
        return [
            'label' => 'Richiesta HR',
            'class' => 'notification-type-request',
            'icon' => 'la-calendar-check',
        ];
    }

    return [
        'label' => ucfirst(strtolower(str_replace('_', ' ', $tipo))),
        'class' => 'notification-type-generic',
        'icon' => 'la-bell',
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'segna_letta') {
            $idDestinatario = (int)($_POST['id_notifica_destinatario'] ?? 0);
            if ($idDestinatario <= 0) {
                throw new RuntimeException('Notifica non valida.');
            }

            $stmt = $pdo->prepare(
                'UPDATE hr_notifiche_destinatari
                 SET letta = 1,
                     data_lettura = COALESCE(data_lettura, NOW())
                 WHERE id_notifica_destinatario = :id_notifica_destinatario
                   AND id_utente = :id_utente'
            );
            $stmt->execute([
                'id_notifica_destinatario' => $idDestinatario,
                'id_utente' => $idUtente,
            ]);

            header('Location: notifiche.php?letta=1');
            exit;
        }

        if ($azione === 'segna_tutte_lette') {
            $stmt = $pdo->prepare(
                'UPDATE hr_notifiche_destinatari
                 SET letta = 1,
                     data_lettura = COALESCE(data_lettura, NOW())
                 WHERE id_utente = :id_utente
                   AND letta = 0'
            );
            $stmt->execute(['id_utente' => $idUtente]);

            header('Location: notifiche.php?tutte_lette=1');
            exit;
        }

        if ($azione === 'apri_notifica') {
            $idDestinatario = (int)($_POST['id_notifica_destinatario'] ?? 0);
            if ($idDestinatario <= 0) {
                throw new RuntimeException('Notifica non valida.');
            }

            $stmt = $pdo->prepare(
                'SELECT n.link
                 FROM hr_notifiche_destinatari nd
                 INNER JOIN hr_notifiche n ON n.id_notifica = nd.id_notifica
                 WHERE nd.id_notifica_destinatario = :id_notifica_destinatario
                   AND nd.id_utente = :id_utente
                 LIMIT 1'
            );
            $stmt->execute([
                'id_notifica_destinatario' => $idDestinatario,
                'id_utente' => $idUtente,
            ]);
            $linkDestinazione = normalizzaLinkNotifica($stmt->fetchColumn() ?: '');

            if ($linkDestinazione === '') {
                throw new RuntimeException('La notifica non contiene un collegamento valido.');
            }

            $stmt = $pdo->prepare(
                'UPDATE hr_notifiche_destinatari
                 SET letta = 1,
                     data_lettura = COALESCE(data_lettura, NOW())
                 WHERE id_notifica_destinatario = :id_notifica_destinatario
                   AND id_utente = :id_utente'
            );
            $stmt->execute([
                'id_notifica_destinatario' => $idDestinatario,
                'id_utente' => $idUtente,
            ]);

            header('Location: ' . $linkDestinazione);
            exit;
        }

        throw new RuntimeException('Azione non valida.');
    }

    if (isset($_GET['letta']) && $_GET['letta'] === '1') {
        $messaggio = 'Notifica segnata come letta.';
    } elseif (isset($_GET['tutte_lette']) && $_GET['tutte_lette'] === '1') {
        $messaggio = 'Tutte le notifiche sono state segnate come lette.';
    }

    $stmtRiepilogo = $pdo->prepare(
        'SELECT
            COUNT(*) AS totale,
            SUM(CASE WHEN letta = 0 THEN 1 ELSE 0 END) AS non_lette,
            SUM(CASE WHEN letta = 1 THEN 1 ELSE 0 END) AS lette
         FROM hr_notifiche_destinatari
         WHERE id_utente = :id_utente'
    );
    $stmtRiepilogo->execute(['id_utente' => $idUtente]);
    $riepilogo = $stmtRiepilogo->fetch(PDO::FETCH_ASSOC) ?: ['totale' => 0, 'non_lette' => 0, 'lette' => 0];

    $stmtNotifiche = $pdo->prepare(
        "SELECT
            nd.id_notifica_destinatario,
            nd.letta,
            nd.data_invio,
            nd.data_lettura,
            nd.errore_invio,
            cn.codice AS canale_codice,
            n.tipo_evento,
            n.titolo,
            n.messaggio,
            n.link,
            n.id_richiesta,
            n.data_creazione,
            DATE_FORMAT(n.data_creazione, '%d/%m/%Y %H:%i') AS data_creazione_fmt
         FROM hr_notifiche_destinatari nd
         INNER JOIN hr_notifiche n ON n.id_notifica = nd.id_notifica
         LEFT JOIN hr_canali_notifica cn ON cn.id_canale_notifica = nd.id_canale_notifica
         WHERE nd.id_utente = :id_utente
         ORDER BY nd.letta ASC, n.data_creazione DESC, nd.id_notifica_destinatario DESC
         LIMIT 80"
    );
    $stmtNotifiche->execute(['id_utente' => $idUtente]);
    $notifiche = $stmtNotifiche->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errore = $e->getMessage();
    $riepilogo = $riepilogo ?? ['totale' => 0, 'non_lette' => 0, 'lette' => 0];
    $notifiche = $notifiche ?? [];
}

layoutHeader('Notifiche');
?>

<div class="page-header">
    <div>
        <h1><i class="la la-bell"></i> Notifiche</h1>
        <p class="text-muted">Centro notifiche personale: richieste assenze, approvazioni, rifiuti e messaggi operativi.</p>
    </div>
    <div class="page-actions">
        <a href="index.php" class="btn btn-outline-primary"><i class="la la-home"></i> Dashboard</a>
        <?php if ((int)($riepilogo['non_lette'] ?? 0) > 0): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="azione" value="segna_tutte_lette">
                <button type="submit" class="btn btn-primary"><i class="la la-check-double"></i> Segna tutte come lette</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php renderHrAlert($messaggio, 'success'); ?>
<?php renderHrAlert($errore, 'danger'); ?>

<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-value"><?= (int)($riepilogo['totale'] ?? 0) ?></div>
        <div class="summary-label">Totali</div>
    </div>
    <div class="summary-card warning">
        <div class="summary-value"><?= (int)($riepilogo['non_lette'] ?? 0) ?></div>
        <div class="summary-label">Non lette</div>
    </div>
    <div class="summary-card success">
        <div class="summary-value"><?= (int)($riepilogo['lette'] ?? 0) ?></div>
        <div class="summary-label">Lette</div>
    </div>
</div>

<div class="card">
    <div class="section-head">
        <div>
            <h2>Ultime notifiche</h2>
        </div>
    </div>

    <?php if (count($notifiche) === 0): ?>
        <p class="empty-state"><i class="la la-inbox"></i> Non hai notifiche.</p>
    <?php else: ?>
        <div class="notifications-list">
            <?php foreach ($notifiche as $notifica): ?>
                <?php
                $letta = (int)($notifica['letta'] ?? 0) === 1;
                $linkSicuro = normalizzaLinkNotifica($notifica['link'] ?? '');
                $tipoNotifica = classificaTipoNotifica($notifica['tipo_evento'] ?? '');
                ?>
                <article class="notification-item <?= $letta ? 'is-read' : 'is-unread' ?> <?= h($tipoNotifica['class']) ?>">
                    <div class="notification-icon" aria-hidden="true">
                        <i class="la <?= h($tipoNotifica['icon']) ?>"></i>
                    </div>
                    <div class="notification-body">
                        <div class="notification-title-row">
                            <h3><?= h((string)$notifica['titolo']) ?></h3>
                            <span class="status-badge <?= $letta ? 'status-ok' : 'status-wait' ?>">
                                <?= $letta ? 'Letta' : 'Nuova' ?>
                            </span>
                            <span class="notification-type-badge <?= h($tipoNotifica['class']) ?>">
                                <i class="la <?= h($tipoNotifica['icon']) ?>"></i>
                                <?= h($tipoNotifica['label']) ?>
                            </span>
                        </div>
                        <div class="notification-meta">
                            <?= h((string)$notifica['data_creazione_fmt']) ?>
                            <?php if (trim((string)($notifica['tipo_evento'] ?? '')) !== ''): ?>
                                · <?= h((string)$notifica['tipo_evento']) ?>
                            <?php endif; ?>
                        </div>
                        <p><?= nl2br(h((string)$notifica['messaggio'])) ?></p>
                        <div class="notification-actions">
                            <?php if ($linkSicuro !== ''): ?>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="azione" value="apri_notifica">
                                    <input type="hidden" name="id_notifica_destinatario" value="<?= (int)$notifica['id_notifica_destinatario'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="la la-external-link-alt"></i> Apri</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$letta): ?>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="azione" value="segna_letta">
                                    <input type="hidden" name="id_notifica_destinatario" value="<?= (int)$notifica['id_notifica_destinatario'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="la la-check"></i> Segna letta</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
layoutFooter();
