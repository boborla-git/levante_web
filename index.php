<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediLogin();

$nome = (string)($_SESSION['nome'] ?? '');
$username = (string)($_SESSION['username'] ?? '');
$ruolo = (string)($_SESSION['ruolo'] ?? '');

$puoLeggereUtenti = haPermessoLettura('utenti');
$puoLeggereOrdini = haPermessoLettura('ordini_fornitori_aperti');
$puoLeggereAssenze = haPermessoLettura('assenze');
$puoLeggereApprovazioniAssenze = haPermessoLettura('approvazioni_assenze');
$puoLeggereCalendarioAssenze = haPermessoLettura('calendario_assenze');
$puoLeggereConfigurazioneAssenze = haPermessoLettura('configurazione_assenze');
$puoLeggereWorkflow = haPermessoLettura('workflow');
$utenteSenzaRuolo = utenteSenzaRuolo();

$accessiRapidi = [];

if ($puoLeggereUtenti) {
    $accessiRapidi[] = [
        'label' => 'Gestione utenti',
        'href' => 'utenti.php',
        'kicker' => 'Amministrazione',
        'descrizione' => 'Accessi, ruoli e permessi del portale.'
    ];
}

if ($puoLeggereOrdini) {
    $accessiRapidi[] = [
        'label' => 'Ordini fornitori',
        'href' => 'ordini_fornitori_aperti.php',
        'kicker' => 'Acquisti',
        'descrizione' => 'Controllo degli ordini fornitori ancora aperti.'
    ];
}

if ($puoLeggereAssenze) {
    $accessiRapidi[] = [
        'label' => 'Richieste assenze',
        'href' => 'assenze.php',
        'kicker' => 'HR',
        'descrizione' => 'Invio e consultazione delle proprie richieste.'
    ];
}

if ($puoLeggereApprovazioniAssenze) {
    $accessiRapidi[] = [
        'label' => 'Approvazioni assenze',
        'href' => 'approvazioni_assenze.php',
        'kicker' => 'HR',
        'descrizione' => 'Richieste pendenti da verificare e approvare.'
    ];
}

if ($puoLeggereCalendarioAssenze) {
    $accessiRapidi[] = [
        'label' => 'Calendario assenze',
        'href' => 'calendario_assenze.php',
        'kicker' => 'HR',
        'descrizione' => 'Vista giornaliera e mensile delle presenze.'
    ];
}

if ($puoLeggereConfigurazioneAssenze) {
    $accessiRapidi[] = [
        'label' => 'Configurazione assenze',
        'href' => 'configurazione_assenze.php',
        'kicker' => 'HR',
        'descrizione' => 'Tipologie, gruppi di lavoro e relazioni organizzative.'
    ];
}

if ($puoLeggereWorkflow) {
    $accessiRapidi[] = [
        'label' => 'Workflow',
        'href' => 'workflow.php',
        'kicker' => 'Processi',
        'descrizione' => 'Struttura di processo e logica operativa futura.'
    ];
}

layoutHeader('Dashboard');
?>

<div class="card card-compact">
    <h2>Benvenuto nell'area riservata</h2>
    <div class="meta">
        <div><strong>Utente:</strong> <?= htmlspecialchars($username) ?></div>
        <div><strong>Nome:</strong> <?= htmlspecialchars($nome) ?></div>
        <div><strong>Ruolo:</strong> <?= htmlspecialchars($ruolo !== '' ? $ruolo : 'nessun ruolo') ?></div>
    </div>

    <?php if ($utenteSenzaRuolo): ?>
        <div class="errore" style="margin-top:18px;">
            Il tuo utente è autenticato ma non ha ancora un ruolo assegnato. Contatta un amministratore per abilitare i moduli.
        </div>
    <?php endif; ?>

    <div class="links">
        <a href="cambia_password.php">Cambia password</a>
    </div>
</div>

<div class="card card-compact">
    <h2>Accessi rapidi</h2>
    <div class="section-intro">
        Il menu in alto resta la guida principale della navigazione.
        Qui trovi alcune scorciatoie alle funzioni che usi più spesso.
    </div>

    <?php if (!$accessiRapidi): ?>
        <div class="info-box">
            Al momento non hai moduli disponibili in accesso rapido. Verifica i permessi del tuo ruolo.
        </div>
    <?php else: ?>
        <div class="modules">
            <?php foreach ($accessiRapidi as $voce): ?>
                <div class="module-box clickable" onclick="location.href='<?= htmlspecialchars($voce['href']) ?>'">
                    <div class="module-kicker"><?= htmlspecialchars($voce['kicker']) ?></div>
                    <h3><?= htmlspecialchars($voce['label']) ?></h3>
                    <p><?= htmlspecialchars($voce['descrizione']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php layoutFooter(); ?>
