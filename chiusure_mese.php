<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('configurazione_assenze');

$pdo = db();
$idUtente = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$puoScrivere = haPermessoScrittura('configurazione_assenze');
$messaggio = '';
$errore = '';
$chiusure = [];

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function meseLabel(int $mese): string
{
    $nomi = [1=>'Gennaio',2=>'Febbraio',3=>'Marzo',4=>'Aprile',5=>'Maggio',6=>'Giugno',7=>'Luglio',8=>'Agosto',9=>'Settembre',10=>'Ottobre',11=>'Novembre',12=>'Dicembre'];
    return $nomi[$mese] ?? (string)$mese;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) { throw new RuntimeException('Non hai i permessi di modifica.'); }
        $azione = trim((string)($_POST['azione'] ?? ''));
        $anno = (int)($_POST['anno'] ?? 0);
        $mese = (int)($_POST['mese'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if ($anno < 2020 || $anno > 2100) { throw new RuntimeException('Anno non valido.'); }
        if ($mese < 1 || $mese > 12) { throw new RuntimeException('Mese non valido.'); }
        if (!in_array($azione, ['chiudi','riapri'], true)) { throw new RuntimeException('Azione non valida.'); }
        $stmt = $pdo->prepare('INSERT INTO hr_chiusure_mese (anno,mese,chiuso,note,aggiornato_da,data_aggiornamento) VALUES (:anno,:mese,:chiuso,:note,:aggiornato_da,NOW()) ON DUPLICATE KEY UPDATE chiuso=VALUES(chiuso), note=VALUES(note), aggiornato_da=VALUES(aggiornato_da), data_aggiornamento=NOW()');
        $stmt->execute([
            'anno'=>$anno,
            'mese'=>$mese,
            'chiuso'=>$azione === 'chiudi' ? 1 : 0,
            'note'=>$note !== '' ? $note : null,
            'aggiornato_da'=>$idUtente > 0 ? $idUtente : null,
        ]);
        header('Location: chiusure_mese.php?ok=1');
        exit;
    }
    if (isset($_GET['ok'])) { $messaggio = 'Operazione registrata correttamente.'; }
    $annoCorrente = (int)date('Y');
    $stmt = $pdo->prepare("SELECT cm.*, CONCAT(COALESCE(u.nome,''),' ',COALESCE(u.cognome,'')) AS utente_nome, u.username FROM hr_chiusure_mese cm LEFT JOIN aut_utenti u ON u.id_utente = cm.aggiornato_da WHERE cm.anno BETWEEN :da AND :a ORDER BY cm.anno DESC, cm.mese DESC");
    $stmt->execute(['da'=>$annoCorrente-2,'a'=>$annoCorrente+1]);
    $chiusure = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $errore = $e->getMessage(); }

layoutHeader('Chiusure mese HR');
?>
<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Chiusure mese HR</h1>
            <div class="meta">Gestione dei periodi mensili consolidati.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="approvazioni_assenze.php"><i class="la la-check-circle"></i> Approvazioni</a>
            <a class="btn btn-light" href="report_assenze.php"><i class="la la-file-excel"></i> Report</a>
        </div>
    </div>
</div>
<?php if ($messaggio !== ''): ?><div class="alert alert-success"><?= h($messaggio) ?></div><?php endif; ?>
<?php if ($errore !== ''): ?><div class="alert alert-error"><?= h($errore) ?></div><?php endif; ?>
<div class="card card-compact">
    <h2>Gestione mese</h2>
    <form method="post" class="hr-profile-form">
        <div class="hr-profile-form-grid">
            <div class="form-group"><label>Anno</label><input type="number" name="anno" min="2020" max="2100" value="<?= (int)date('Y') ?>" required></div>
            <div class="form-group"><label>Mese</label><select name="mese" required><?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m===(int)date('n')?'selected':'' ?>><?= h(meseLabel($m)) ?></option><?php endfor; ?></select></div>
            <div class="form-group hr-profile-form-wide"><label>Note</label><input type="text" name="note" maxlength="255"></div>
        </div>
        <div class="hr-profile-form-actions">
            <button class="btn btn-primary" type="submit" name="azione" value="chiudi" <?= $puoScrivere ? '' : 'disabled' ?>><i class="la la-lock"></i> Chiudi</button>
            <button class="btn btn-light" type="submit" name="azione" value="riapri" <?= $puoScrivere ? '' : 'disabled' ?>><i class="la la-lock-open"></i> Riapri</button>
        </div>
    </form>
</div>
<div class="card card-wide">
    <h2>Mesi registrati</h2>
    <?php if (!$chiusure): ?>
        <div class="info-box">Nessun mese configurato.</div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Periodo</th><th>Stato</th><th>Note</th><th>Aggiornato da</th><th>Data</th></tr></thead><tbody>
        <?php foreach ($chiusure as $r): ?>
            <?php $nome = trim((string)($r['utente_nome'] ?? '')); if ($nome === '') { $nome = trim((string)($r['username'] ?? '')); } ?>
            <tr><td><strong><?= h(meseLabel((int)$r['mese'])) ?> <?= (int)$r['anno'] ?></strong></td><td><?= ((int)$r['chiuso'] === 1) ? renderHrStatusBadge('APPROVATA','Chiuso') : renderHrStatusBadge('IN_ATTESA','Aperto') ?></td><td><?= h((string)($r['note'] ?? '')) ?></td><td><?= h($nome) ?></td><td><?= h((string)($r['data_aggiornamento'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php layoutFooter(); ?>
