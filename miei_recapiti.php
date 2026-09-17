<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/hr_recapiti.php';
require_once __DIR__ . '/includes/badge.php';

richiediLogin();
$pdo = db();
$idUtente = (int)($_SESSION['utente_id'] ?? 0);
$errore = '';
$messaggio = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $valori = [
            'EMAIL_PERSONALE' => trim((string)($_POST['email_personale'] ?? '')),
            'EMAIL_LAVORO' => trim((string)($_POST['email_lavoro'] ?? '')),
            'CELLULARE_PERSONALE' => trim((string)($_POST['cellulare'] ?? '')),
        ];
        $pdo->beginTransaction();
        $verifiche = [];
        foreach ($valori as $tipo => $valore) {
            $esito = hrRecapitiSalva($pdo, $idUtente, $tipo, $valore, $idUtente);
            if ($esito['richiede_verifica'] && $valore !== '') {
                $verifiche[] = ['email' => $valore, 'token' => hrRecapitiCreaTokenVerifica($pdo, (int)$esito['id_recapito'], $idUtente)];
            }
        }
        $pdo->commit();
        $inviate = 0;
        foreach ($verifiche as $v) {
            $invio = hrRecapitiInviaVerifica($pdo, $idUtente, $v['email'], $v['token']);
            if ($invio['inviata']) $inviate++;
        }
        $messaggio = 'Recapiti aggiornati.';
        if (count($verifiche) > 0) {
            $messaggio .= $inviate === count($verifiche)
                ? ' Abbiamo inviato il link di verifica agli indirizzi email modificati.'
                : ' Uno o più indirizzi email sono da verificare; l’invio automatico del link non è riuscito o è disattivato.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errore = $e->getMessage();
    }
}

$recapiti = hrRecapitiUtente($pdo, $idUtente);
$emailPersonale = (string)($recapiti['EMAIL_PERSONALE']['valore'] ?? '');
$emailLavoro = (string)($recapiti['EMAIL_LAVORO']['valore'] ?? '');
$cellulare = (string)($recapiti['CELLULARE_PERSONALE']['valore'] ?? '');

layoutHeader('I miei recapiti');
?>
<div class="card card-form">
    <div class="section-head">
        <div>
            <h1>I miei recapiti</h1>
            <div class="meta">Gestisci i recapiti utilizzati dal portale HR.</div>
        </div>
    </div>

    <?php if ($messaggio !== ''): ?><div class="successo"><?= htmlspecialchars($messaggio) ?></div><?php endif; ?>
    <?php if ($errore !== ''): ?><div class="errore"><?= htmlspecialchars($errore) ?></div><?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label for="email_personale">Email personale</label>
            <input type="email" id="email_personale" name="email_personale" value="<?= htmlspecialchars($emailPersonale) ?>" autocomplete="email">
            <div class="meta" style="margin-top:6px">L'indirizzo email personale è utilizzato esclusivamente per ricevere comunicazioni relative alle richieste di assenza e permesso, compresa la conferma dell'inserimento e del relativo esito.</div>
            <?php if ($emailPersonale !== ''): ?>
                <div style="margin-top:8px"><?= (int)($recapiti['EMAIL_PERSONALE']['verificato'] ?? 0) === 1 ? renderHrStatusBadge('VERIFICATO', 'Verificata') : renderHrStatusBadge('PENDING', 'Da verificare') ?></div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="email_lavoro">Email di lavoro</label>
            <input type="email" id="email_lavoro" name="email_lavoro" value="<?= htmlspecialchars($emailLavoro) ?>" autocomplete="work email">
            <?php if ($emailLavoro !== ''): ?>
                <div style="margin-top:8px"><?= (int)($recapiti['EMAIL_LAVORO']['verificato'] ?? 0) === 1 ? renderHrStatusBadge('VERIFICATO', 'Verificata') : renderHrStatusBadge('PENDING', 'Da verificare') ?></div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="cellulare">Cellulare</label>
            <input type="tel" id="cellulare" name="cellulare" value="<?= htmlspecialchars($cellulare) ?>" autocomplete="tel">
            <div class="meta" style="margin-top:6px">Il cellulare viene registrato come recapito personale. In questa fase non è richiesta una verifica tramite SMS.</div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit"><i class="la la-save" aria-hidden="true"></i> Salva recapiti</button>
        </div>
    </form>
</div>
<?php layoutFooter(); ?>
