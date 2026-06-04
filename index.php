<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediLogin();

$nome = (string)($_SESSION['nome'] ?? '');
$username = (string)($_SESSION['username'] ?? '');
$ruolo = (string)($_SESSION['ruolo'] ?? '');
$idUtenteLoggato = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);

$puoLeggereUtenti = haPermessoLettura('utenti');
$puoLeggereOrdini = haPermessoLettura('ordini_fornitori_aperti');
$puoLeggereAssenze = haPermessoLettura('assenze');
$puoLeggereApprovazioniAssenze = haPermessoLettura('approvazioni_assenze');
$puoLeggereReportAssenze = haPermessoLettura('report_assenze');
$puoLeggereCalendarioAssenze = haPermessoLettura('calendario_assenze');
$puoLeggereConfigurazioneAssenze = haPermessoLettura('configurazione_assenze');
$utenteSenzaRuolo = utenteSenzaRuolo();

$accessiRapidi = [];
$approvazioniPendenti = 0;
$erroreApprovalsHome = '';

function contaApprovazioniHrPendentiHome(int $idUtente, bool $puoConfigurare): int
{
    if ($idUtente <= 0) {
        return 0;
    }

    $pdo = db();
    $filtro = $puoConfigurare ? '1 = 1' : 'a.id_approvatore_assegnato = :id_utente';
    $sql = "SELECT COUNT(DISTINCT r.id_richiesta)
            FROM hr_richieste r
            INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
            INNER JOIN hr_richieste_approvazioni a ON a.id_richiesta = r.id_richiesta
            WHERE sr.codice = 'IN_ATTESA'
              AND a.stato_approvazione = 'IN_ATTESA'
              AND {$filtro}";

    $stmt = $pdo->prepare($sql);
    $params = [];
    if (!$puoConfigurare) {
        $params['id_utente'] = $idUtente;
    }
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

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

    try {
        $approvazioniPendenti = contaApprovazioniHrPendentiHome($idUtenteLoggato, $puoLeggereConfigurazioneAssenze);
    } catch (Throwable $e) {
        $erroreApprovalsHome = 'Impossibile leggere le approvazioni HR pendenti.';
    }
}

if ($puoLeggereReportAssenze) {
    $accessiRapidi[] = [
        'label' => 'Report assenze',
        'href' => 'report_assenze.php',
        'kicker' => 'HR',
        'descrizione' => 'Analisi, filtri ed esportazione Excel delle richieste.'
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
        <a class="btn btn-light" href="cambia_password.php"><i class="la la-key" aria-hidden="true"></i> Cambia password</a>
    </div>
</div>

<?php if ($puoLeggereApprovazioniAssenze): ?>
    <div class="card card-compact">
        <div class="section-head">
            <div>
                <h2>Approvazioni HR pendenti</h2>
                <div class="meta">
                    <?= $puoLeggereConfigurazioneAssenze ? 'Vista HR globale sulle richieste ancora da gestire.' : 'Richieste assegnate direttamente a te come approvatore.' ?>
                </div>
            </div>
            <div class="section-head-actions">
                <a class="btn btn-light" href="approvazioni_assenze.php"><i class="la la-check-circle" aria-hidden="true"></i> Apri approvazioni</a>
                <?php if ($puoLeggereReportAssenze): ?>
                    <a class="btn btn-light" href="report_assenze.php"><i class="la la-file-excel" aria-hidden="true"></i> Report assenze</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($erroreApprovalsHome !== ''): ?>
            <div class="errore" style="margin-top:14px;"><?= htmlspecialchars($erroreApprovalsHome) ?></div>
        <?php elseif ($approvazioniPendenti > 0): ?>
            <div class="hr-summary-line" style="margin-top:14px;">
                <span><strong><?= (int)$approvazioniPendenti ?></strong> richieste da approvare</span>
            </div>
        <?php else: ?>
            <div class="info-box" style="margin-top:14px;">Non ci sono approvazioni HR pendenti.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>

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