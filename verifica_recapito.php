<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/layout.php';

$pdo = db();
$token = trim((string)($_GET['token'] ?? ''));
$ok = false;
$messaggio = 'Link di verifica non valido.';

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
    try {
        $hash = hash('sha256', $token);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT v.id_verifica_recapito, v.id_recapito_utente
             FROM hr_recapiti_verifiche v
             INNER JOIN hr_recapiti_utenti r ON r.id_recapito_utente = v.id_recapito_utente AND r.attivo = 1
             WHERE v.token_hash = :hash
               AND v.utilizzato_il IS NULL
               AND v.scade_il >= NOW()
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['hash' => $hash]);
        $riga = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($riga) {
            $pdo->prepare('UPDATE hr_recapiti_utenti SET verificato = 1 WHERE id_recapito_utente = :id')
                ->execute(['id' => (int)$riga['id_recapito_utente']]);
            $pdo->prepare('UPDATE hr_recapiti_verifiche SET utilizzato_il = NOW() WHERE id_verifica_recapito = :id')
                ->execute(['id' => (int)$riga['id_verifica_recapito']]);
            $pdo->commit();
            $ok = true;
            $messaggio = 'Indirizzo email verificato correttamente.';
        } else {
            $pdo->rollBack();
            $messaggio = 'Il link di verifica non è valido, è già stato utilizzato oppure è scaduto.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $messaggio = 'Non è stato possibile completare la verifica.';
    }
}

layoutHeader('Verifica email');
?>
<div class="card card-form">
    <h1>Verifica indirizzo email</h1>
    <div class="<?= $ok ? 'successo' : 'errore' ?>"><?= htmlspecialchars($messaggio) ?></div>
    <div class="actions">
        <a class="btn btn-primary" href="<?= utenteAutenticato() ? '/miei_recapiti.php' : '/login.php' ?>">Continua</a>
    </div>
</div>
<?php layoutFooter(); ?>
