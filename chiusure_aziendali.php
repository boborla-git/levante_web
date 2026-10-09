<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/hr_regole_assenze.php';
richiediPermessoLettura('chiusure_aziendali');
if (!hrRegoleOperatoreHr()) {
    http_response_code(403);
    exit('La gestione delle chiusure aziendali è riservata a HR e all’amministratore.');
}
$pdo = db();
$puoScrivere = haPermessoScrittura('chiusure_aziendali');
$idOperatore = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$errore = '';
$messaggio = '';
$chiusure = [];
$form = ['id_chiusura' => 0, 'descrizione' => '', 'data_da' => '', 'data_a' => ''];
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) throw new RuntimeException('Non hai i permessi di modifica.');
        hrRegoleVerificaCsrf();
        $azione = (string)($_POST['azione'] ?? '');
        $id = (int)($_POST['id_chiusura'] ?? 0);
        $pdo->beginTransaction();
        hrRegoleBloccaScrittura($pdo);
        if ($azione === 'disattiva') {
            $stmt = $pdo->prepare('UPDATE hr_chiusure_aziendali SET attivo = 0,
                aggiornato_da = :operatore, data_aggiornamento = NOW() WHERE id_chiusura = :id AND attivo = 1');
            $stmt->execute(['operatore' => $idOperatore, 'id' => $id]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Chiusura non trovata o già disattivata.');
        } elseif ($azione === 'salva') {
            $form = ['id_chiusura' => $id, 'descrizione' => trim((string)($_POST['descrizione'] ?? '')),
                'data_da' => trim((string)($_POST['data_da'] ?? '')), 'data_a' => trim((string)($_POST['data_a'] ?? ''))];
            hrRegoleValidaDate($form['data_da'], $form['data_a']);
            if ($form['descrizione'] === '' || preg_match_all('/./us', $form['descrizione']) > 150) {
                throw new RuntimeException('Indica una descrizione di massimo 150 caratteri.');
            }
            $stmt = $pdo->prepare('SELECT id_chiusura FROM hr_chiusure_aziendali
                WHERE attivo = 1 AND id_chiusura <> :id AND data_da <= :fine AND data_a >= :inizio LIMIT 1');
            $stmt->execute(['id' => $id, 'fine' => $form['data_a'], 'inizio' => $form['data_da']]);
            if ($stmt->fetchColumn()) throw new RuntimeException('Esiste già una chiusura che comprende queste date. Modifica quella esistente.');
            $params = ['descrizione' => $form['descrizione'], 'inizio' => $form['data_da'],
                'fine' => $form['data_a'], 'operatore' => $idOperatore];
            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT id_chiusura FROM hr_chiusure_aziendali WHERE id_chiusura = :id AND attivo = 1');
                $stmt->execute(['id' => $id]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Chiusura non trovata o disattivata.');
                $stmt = $pdo->prepare('UPDATE hr_chiusure_aziendali SET descrizione = :descrizione,
                    data_da = :inizio, data_a = :fine, aggiornato_da = :operatore, data_aggiornamento = NOW()
                    WHERE id_chiusura = :id');
                $params['id'] = $id;
            } else {
                $stmt = $pdo->prepare('INSERT INTO hr_chiusure_aziendali
                    (descrizione, data_da, data_a, attivo, aggiornato_da)
                    VALUES (:descrizione, :inizio, :fine, 1, :operatore)');
            }
            $stmt->execute($params);
        } else {
            throw new RuntimeException('Azione non valida.');
        }
        $pdo->commit();
        header('Location: chiusure_aziendali.php?ok=1');
        exit;
    }
    if (isset($_GET['ok'])) $messaggio = 'Chiusure aziendali aggiornate correttamente.';
    $idModifica = (int)($_GET['modifica'] ?? 0);
    if ($idModifica > 0) {
        $stmt = $pdo->prepare('SELECT id_chiusura, descrizione, data_da, data_a
            FROM hr_chiusure_aziendali WHERE id_chiusura = :id AND attivo = 1');
        $stmt->execute(['id' => $idModifica]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC) ?: $form;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errore = $e->getMessage();
}
try {
    $chiusure = $pdo->query("SELECT c.*,
        (SELECT COUNT(DISTINCT r.id_richiesta) FROM hr_richieste r
         INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
            AND sr.codice IN ('APPROVATA', 'IN_ATTESA')
         INNER JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
         WHERE p.data_da <= c.data_a AND p.data_a >= c.data_da) AS richieste_presenti
        FROM hr_chiusure_aziendali c ORDER BY c.attivo DESC, c.data_da DESC, c.id_chiusura DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $errore = $e->getMessage(); }
layoutHeader('Chiusure aziendali');
?>
<style>
.hr-closure-stack{display:grid;gap:20px}.hr-closure-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px}
.hr-closure-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.hr-closure-grid .form-group{min-width:0}
@media(max-width:700px){.hr-closure-grid{grid-template-columns:1fr}}
</style>
<div class="page-container hr-closure-stack">
    <div class="card card-wide"><div class="section-head"><div>
        <h1>Chiusure aziendali</h1><div class="meta">Le date iniziale e finale sono comprese. Nessuno può inserire richieste durante una chiusura.</div>
    </div><a class="btn btn-light" href="configurazione_assenze.php">Configurazione assenze</a></div></div>
    <?php if ($errore !== ''): ?><div class="alert alert-error"><?= h($errore) ?></div><?php endif; ?>
    <?php if ($messaggio !== ''): ?><div class="alert alert-success"><?= h($messaggio) ?></div><?php endif; ?>
    <?php if ($puoScrivere): ?>
    <div class="card card-form" id="modifica-chiusura">
        <h2><?= (int)$form['id_chiusura'] > 0 ? 'Modifica chiusura' : 'Nuova chiusura' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(hrRegoleCsrfToken()) ?>">
            <input type="hidden" name="azione" value="salva">
            <input type="hidden" name="id_chiusura" value="<?= (int)$form['id_chiusura'] ?>">
            <div class="hr-closure-grid">
                <div class="form-group"><label for="descrizione">Descrizione</label><input type="text" id="descrizione" name="descrizione" maxlength="150" value="<?= h($form['descrizione']) ?>" required></div>
                <div class="form-group"><label for="data_da">Dal giorno</label><input type="date" id="data_da" name="data_da" value="<?= h($form['data_da']) ?>" required></div>
                <div class="form-group"><label for="data_a">Al giorno</label><input type="date" id="data_a" name="data_a" value="<?= h($form['data_a']) ?>" required></div>
            </div>
            <div class="hr-closure-actions"><button class="btn btn-primary" type="submit">Salva chiusura</button>
                <?php if ((int)$form['id_chiusura'] > 0): ?><a class="btn btn-light" href="chiusure_aziendali.php">Annulla modifica</a><?php endif; ?></div>
        </form>
    </div>
    <?php endif; ?>
    <div class="card card-wide"><h2>Chiusure registrate</h2>
        <p class="meta">Le richieste già presenti restano registrate: verifica gli eventuali conflitti indicati sotto e gestiscili dalla pagina Assenze. Le richieste in attesa che comprendono una chiusura non possono essere approvate.</p>
        <?php if (!$chiusure): ?><div class="info-box">Nessuna chiusura registrata.</div><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Descrizione</th><th>Dal</th><th>Al</th><th>Stato</th><th>Richieste già presenti</th><th>Azioni</th></tr></thead><tbody>
        <?php foreach ($chiusure as $c): ?><tr>
            <td><?= h($c['descrizione']) ?></td><td><?= h(date('d/m/Y', strtotime($c['data_da']))) ?></td><td><?= h(date('d/m/Y', strtotime($c['data_a']))) ?></td>
            <td><?= (int)$c['attivo'] === 1 ? 'Attiva' : 'Disattivata' ?></td>
            <td><?php if ((int)$c['attivo'] === 1 && (int)$c['richieste_presenti'] > 0): ?>
                <strong><?= (int)$c['richieste_presenti'] ?> da verificare</strong> · <a href="assenze.php">Vai ad Assenze</a>
                <?php else: ?>—<?php endif; ?></td>
            <td><?php if ($puoScrivere && (int)$c['attivo'] === 1): ?><div class="hr-closure-actions">
                <a class="btn btn-light btn-sm" href="chiusure_aziendali.php?modifica=<?= (int)$c['id_chiusura'] ?>#modifica-chiusura">Modifica</a>
                <form method="post" onsubmit="return confirm('Disattivare la chiusura e consentire nuovamente le richieste in queste date?');">
                    <input type="hidden" name="csrf_token" value="<?= h(hrRegoleCsrfToken()) ?>"><input type="hidden" name="azione" value="disattiva">
                    <input type="hidden" name="id_chiusura" value="<?= (int)$c['id_chiusura'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Disattiva</button>
                </form></div><?php else: ?>—<?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
</div>
<?php layoutFooter(); ?>
