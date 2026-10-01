<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/hr_anagrafiche.php';

// Le anagrafiche condividono gli accessi della pagina che le assegna.
richiediPermessoLettura('profili_dipendenti');
$config = hrAnagraficaConfig((string)($tipoAnagrafica ?? ''));
$pdo = db();
$puoScrivere = haPermessoScrittura('profili_dipendenti');
$errore = '';
$voci = [];
$limiti = ['codice' => 50, 'nome' => 100];
$form = ['codice' => '', 'nome' => ''];
if (!isset($_SESSION['hr_anagrafiche_csrf'])) $_SESSION['hr_anagrafiche_csrf'] = bin2hex(random_bytes(32));

try {
    $limiti = hrAnagraficaLimiti($pdo, (string)$tipoAnagrafica);
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        if (!$puoScrivere) {
            http_response_code(403);
            die('Accesso negato.');
        }
        $form = ['codice' => trim((string)($_POST['codice'] ?? '')), 'nome' => trim((string)($_POST['nome'] ?? ''))];
        if (!hash_equals((string)$_SESSION['hr_anagrafiche_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('La sessione del modulo è scaduta. Ricarica la pagina e riprova.');
        }
        if ((string)($_POST['azione'] ?? '') !== 'crea_voce') throw new RuntimeException('Operazione non valida.');
        hrAnagraficaCrea($pdo, (string)$tipoAnagrafica, $form);
        header('Location: ' . $config['pagina'] . '?creato=1');
        exit;
    }
} catch (PDOException $e) {
    error_log('HR anagrafiche: ' . $e->getMessage());
    $errore = (string)$e->getCode() === '23000'
        ? 'Il codice esiste già oppure i dati non sono compatibili con l’anagrafica.'
        : 'Non è stato possibile completare l’operazione. Nessuna voce esistente è stata modificata.';
} catch (Throwable $e) {
    $errore = $e->getMessage();
}
try {
    $tabella = $config['tabella'];
    $id = $config['id'];
    $voci = $pdo->query("SELECT {$id} AS id_voce, codice, nome, ordinamento, attivo FROM {$tabella} ORDER BY ordinamento, nome, {$id}")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($errore === '') $errore = 'Non è stato possibile caricare l’anagrafica.';
}
$esc = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
layoutHeader((string)$config['titolo']);
?>
<style>
.hr-anagrafiche-stack{display:grid;gap:20px}.hr-anagrafiche-fields{display:grid;grid-template-columns:minmax(140px,1fr) minmax(220px,2fr);gap:16px;max-width:820px}.hr-anagrafiche-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.hr-anagrafiche-voce{padding:16px;border:1px solid #dbe3ee;border-radius:8px;background:#fff}.hr-anagrafiche-voce p{margin:8px 0 0}.hr-anagrafiche-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}@media(max-width:600px){.hr-anagrafiche-fields{grid-template-columns:1fr}.hr-anagrafiche-list{grid-template-columns:1fr}}
</style>
<div class="hr-anagrafiche-stack">
    <section class="card card-compact">
        <div class="section-head">
            <div><h1><?= $esc($config['titolo']) ?></h1><div class="meta">Crea le voci da assegnare ai dipendenti nella pagina Profili dipendenti.</div></div>
            <div class="section-head-actions">
                <a class="btn btn-light" href="profili_dipendenti.php">Torna ai profili dipendenti</a>
                <a class="btn btn-light" href="<?= $tipoAnagrafica === 'reparti' ? 'centri_costo.php' : 'reparti.php' ?>"><?= $tipoAnagrafica === 'reparti' ? 'Centri di costo' : 'Reparti' ?></a>
            </div>
        </div>
    </section>
    <?php if ($errore !== ''): ?><div class="errore" role="alert"><?= $esc($errore) ?></div><?php endif; ?>
    <?php if (isset($_GET['creato']) && $_GET['creato'] === '1'): ?><div class="ok" role="status">Nuovo <?= $esc($config['singolare']) ?> creato. Ora puoi selezionarlo nei profili dipendenti.</div><?php endif; ?>
    <section class="card card-form">
        <h2>Nuovo <?= $esc($config['singolare']) ?></h2>
        <?php if (!$puoScrivere): ?>
            <div class="info-box">Il tuo profilo può consultare queste voci ma non crearne di nuove.</div>
        <?php else: ?>
            <form method="post" action="<?= $esc($config['pagina']) ?>">
                <input type="hidden" name="azione" value="crea_voce">
                <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['hr_anagrafiche_csrf']) ?>">
                <div class="hr-anagrafiche-fields">
                    <div class="form-group"><label for="codice">Codice</label><input type="text" id="codice" name="codice" required maxlength="<?= (int)$limiti['codice'] ?>" value="<?= $esc($form['codice']) ?>" placeholder="<?= $tipoAnagrafica === 'reparti' ? 'AMM' : 'AMM01' ?>"><div class="meta">Codice univoco, ad esempio <?= $tipoAnagrafica === 'reparti' ? 'AMM' : 'AMM01' ?>.</div></div>
                    <div class="form-group"><label for="nome">Nome</label><input type="text" id="nome" name="nome" required maxlength="<?= (int)$limiti['nome'] ?>" value="<?= $esc($form['nome']) ?>" placeholder="Amministrazione"><div class="meta">Nome visualizzato nelle tendine del profilo.</div></div>
                </div>
                <div class="hr-anagrafiche-actions"><button type="submit" class="btn btn-primary">Crea <?= $esc($config['singolare']) ?></button></div>
            </form>
        <?php endif; ?>
    </section>
    <section class="card card-wide">
        <h2>Voci presenti</h2>
        <?php if (!$voci): ?><p class="meta">Nessuna voce presente.</p><?php else: ?>
        <div class="hr-anagrafiche-list">
            <?php foreach ($voci as $voce): ?>
                <article class="hr-anagrafiche-voce"><strong><?= $esc($voce['nome']) ?></strong><p class="meta">Codice: <?= $esc($voce['codice']) ?> · <?= (int)$voce['attivo'] === 1 ? 'Attivo' : 'Non attivo' ?></p></article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</div>
<?php layoutFooter(); ?>
