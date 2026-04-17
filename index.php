<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediLogin();

$nome = (string)($_SESSION['nome'] ?? '');
$username = (string)($_SESSION['username'] ?? '');
$ruolo = (string)($_SESSION['ruolo'] ?? '');

$puoLeggereUtenti = haPermessoLettura('utenti');
$puoScrivereUtenti = haPermessoScrittura('utenti');
$puoLeggereOrdini = haPermessoLettura('ordini_fornitori_aperti');
$puoLeggereWorkflow = haPermessoLettura('workflow');

layoutHeader('Dashboard');
?>

<div class="card card-compact">
    <h2>Benvenuto nell'area riservata</h2>
    <div class="meta">
        <div><strong>Utente:</strong> <?= htmlspecialchars($username) ?></div>
        <div><strong>Nome:</strong> <?= htmlspecialchars($nome) ?></div>
        <div><strong>Ruolo:</strong> <?= htmlspecialchars($ruolo) ?></div>
    </div>

    <div class="links">
        <a href="cambia_password.php">Cambia password</a>
    </div>
</div>

<div class="card card-compact">
    <h2>Moduli</h2>
    <div class="modules">
        <?php if ($puoLeggereUtenti): ?>
            <div class="module-box clickable" onclick="location.href='utenti.php'">
                <h3>Admin</h3>
                <p>Gestione accessi, profili e autorizzazioni.</p>
                
            </div>
        <?php endif; ?>

        <?php if ($puoLeggereOrdini): ?>
            <div class="module-box clickable" onclick="location.href='ordini_fornitori_aperti.php'">
                <h3>Ordini fornitori</h3>
                <p>Gestione e monitoraggio degli ordini fornitori ancora aperti.</p>
            </div>
        <?php endif; ?>

        <?php if ($puoLeggereWorkflow): ?>
            <div class="module-box">
                <h3>Workflow</h3>
                <p>Base futura per processi, task e logica organizzativa.</p>
            </div>
        <?php endif; ?>

        <?php if (!$puoLeggereUtenti && !$puoLeggereOrdini && !$puoLeggereWorkflow): ?>
            <div class="meta">
                Nessun modulo disponibile per il tuo profilo.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php layoutFooter(); ?>
