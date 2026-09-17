<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/hr_recapiti.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoScrittura('recapiti_utenti');
$pdo = db();
$idOperatore = (int)($_SESSION['utente_id'] ?? 0);
$idUtente = (int)($_GET['id'] ?? $_POST['id_utente'] ?? 0);
$errore = '';
$messaggio = '';

// L'account tecnico/amministrativo non è un dipendente e non deve comparire
// nella gestione HR dei recapiti. Il filtro non dipende dall'id dell'account.
$stmtUtenti = $pdo->query(
    "SELECT id_utente, username, nome, cognome
     FROM aut_utenti
     WHERE attivo = 1
       AND LOWER(TRIM(username)) NOT IN ('admin', 'amministratore')
     ORDER BY cognome, nome, username"
);
$utenti = $stmtUtenti->fetchAll(PDO::FETCH_ASSOC);
$utentiConsentiti = [];
foreach ($utenti as $u) $utentiConsentiti[(int)$u['id_utente']] = true;
if ($idUtente <= 0 && count($utenti) > 0) $idUtente = (int)$utenti[0]['id_utente'];

// Protezione anche lato server: non basta nascondere l'account dal menu a tendina.
if ($idUtente > 0 && !isset($utentiConsentiti[$idUtente])) {
    http_response_code(404);
    die('Utente non disponibile nella gestione recapiti HR.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $valori = [
            'EMAIL_PERSONALE' => trim((string)($_POST['email_personale'] ?? '')),
            'EMAIL_LAVORO' => trim((string)($_POST['email_lavoro'] ?? '')),
            'CELLULARE_PERSONALE' => trim((string)($_POST['cellulare'] ?? '')),
        ];
        $pdo->beginTransaction();
        foreach ($valori as $tipo => $valore) {
            // Chi possiede il permesso di scrittura Recapiti utenti opera come HR/admin.
            // Il dato inserito è quindi considerato confermato senza ulteriore email al dipendente.
            hrRecapitiSalva($pdo, $idUtente, $tipo, $valore, $idOperatore, true);
        }
        $pdo->commit();
        $messaggio = 'Recapiti aggiornati per l’utente selezionato. Gli indirizzi email inseriti da HR/amministrazione sono considerati confermati.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errore = $e->getMessage();
    }
}

$utenteSelezionato = null;
foreach ($utenti as $u) if ((int)$u['id_utente'] === $idUtente) { $utenteSelezionato = $u; break; }
if (!$utenteSelezionato) { http_response_code(404); die('Utente non trovato.'); }
$recapiti = hrRecapitiUtente($pdo, $idUtente);

layoutHeader('Recapiti utente');
?>
<div class="card card-form">
    <div class="section-head">
        <div><h1>Recapiti utente</h1><div class="meta">Gestione HR/amministrativa dei recapiti personali e di lavoro.</div></div>
        <div class="section-head-actions"><a class="btn btn-light" href="/recapiti_utenti.php">Torna ai recapiti utenti</a></div>
    </div>

    <?php if ($messaggio !== ''): ?><div class="successo"><?= htmlspecialchars($messaggio) ?></div><?php endif; ?>
    <?php if ($errore !== ''): ?><div class="errore"><?= htmlspecialchars($errore) ?></div><?php endif; ?>

    <form method="get" class="form-group">
        <label for="id">Dipendente</label>
        <select id="id" name="id" onchange="this.form.submit()">
            <?php foreach ($utenti as $u): $nome = trim((string)$u['nome'].' '.(string)$u['cognome']); ?>
                <option value="<?= (int)$u['id_utente'] ?>" <?= (int)$u['id_utente'] === $idUtente ? 'selected' : '' ?>><?= htmlspecialchars($nome !== '' ? $nome : (string)$u['username']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <form method="post">
        <input type="hidden" name="id_utente" value="<?= $idUtente ?>">
        <div class="form-group">
            <label for="email_personale">Email personale</label>
            <input type="email" id="email_personale" name="email_personale" value="<?= htmlspecialchars((string)($recapiti['EMAIL_PERSONALE']['valore'] ?? '')) ?>">
            <div class="meta" style="margin-top:6px">L'indirizzo email personale è utilizzato esclusivamente per ricevere comunicazioni relative alle richieste di assenza e permesso, compresa la conferma dell'inserimento e del relativo esito.</div>
            <?php if (!empty($recapiti['EMAIL_PERSONALE']['valore'])): ?><div style="margin-top:8px"><?= (int)$recapiti['EMAIL_PERSONALE']['verificato'] === 1 ? renderHrStatusBadge('VERIFICATO','Verificata') : renderHrStatusBadge('PENDING','Da verificare') ?></div><?php endif; ?>
        </div>
        <div class="form-group">
            <label for="email_lavoro">Email di lavoro</label>
            <input type="email" id="email_lavoro" name="email_lavoro" value="<?= htmlspecialchars((string)($recapiti['EMAIL_LAVORO']['valore'] ?? '')) ?>">
            <?php if (!empty($recapiti['EMAIL_LAVORO']['valore'])): ?><div style="margin-top:8px"><?= (int)$recapiti['EMAIL_LAVORO']['verificato'] === 1 ? renderHrStatusBadge('VERIFICATO','Verificata') : renderHrStatusBadge('PENDING','Da verificare') ?></div><?php endif; ?>
        </div>
        <div class="form-group">
            <label for="cellulare">Cellulare</label>
            <input type="tel" id="cellulare" name="cellulare" value="<?= htmlspecialchars((string)($recapiti['CELLULARE_PERSONALE']['valore'] ?? '')) ?>">
        </div>
        <div class="actions"><button class="btn btn-primary" type="submit"><i class="la la-save"></i> Salva recapiti</button></div>
    </form>
</div>
<?php layoutFooter(); ?>
