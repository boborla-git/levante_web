<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badge.php';

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
$messaggioTimbratura = '';
$erroreTimbratura = '';
$timbratureOggi = [];
$ultimoTipoTimbratura = '';

function h(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

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

function hrTimbraturaLabel(string $tipo): string
{
    $tipo = strtoupper(trim($tipo));
    $labels = [
        'ENTRATA' => 'Entrata',
        'INIZIO_PAUSA' => 'Inizio pausa',
        'FINE_PAUSA' => 'Fine pausa',
        'USCITA' => 'Uscita',
    ];
    return $labels[$tipo] ?? $tipo;
}

function hrTimbraturaProssimeAzioni(string $ultimoTipo): array
{
    $ultimoTipo = strtoupper(trim($ultimoTipo));
    if ($ultimoTipo === '') {
        return ['ENTRATA' => 'Entrata'];
    }
    if ($ultimoTipo === 'ENTRATA') {
        return ['INIZIO_PAUSA' => 'Inizio pausa', 'USCITA' => 'Uscita'];
    }
    if ($ultimoTipo === 'INIZIO_PAUSA') {
        return ['FINE_PAUSA' => 'Fine pausa'];
    }
    if ($ultimoTipo === 'FINE_PAUSA') {
        return ['INIZIO_PAUSA' => 'Inizio pausa', 'USCITA' => 'Uscita'];
    }
    return [];
}

function hrTimbraturaLeggiOggi(PDO $pdo, int $idUtente): array
{
    if ($idUtente <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT id_timbratura,
                tipo,
                DATE_FORMAT(data_ora, '%H:%i:%s') AS ora,
                DATE_FORMAT(data_ora, '%d/%m/%Y %H:%i:%s') AS data_ora_fmt
         FROM hr_timbrature
         WHERE id_utente = :id_utente
           AND DATE(data_ora) = CURDATE()
         ORDER BY data_ora ASC, id_timbratura ASC"
    );
    $stmt->execute(['id_utente' => $idUtente]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hrTimbraturaUltimoTipo(array $timbrature): string
{
    if (!$timbrature) {
        return '';
    }
    $ultima = $timbrature[count($timbrature) - 1];
    return strtoupper(trim((string)($ultima['tipo'] ?? '')));
}

try {
    $pdoHome = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'timbratura_home') {
        if ($idUtenteLoggato <= 0) {
            throw new RuntimeException('Utente non valido.');
        }

        $tipoTimbratura = strtoupper(trim((string)($_POST['tipo_timbratura'] ?? '')));
        $timbratureCorrenti = hrTimbraturaLeggiOggi($pdoHome, $idUtenteLoggato);
        $azioniAmmesse = hrTimbraturaProssimeAzioni(hrTimbraturaUltimoTipo($timbratureCorrenti));

        if (!array_key_exists($tipoTimbratura, $azioniAmmesse)) {
            throw new RuntimeException('Timbratura non coerente con lo stato corrente della giornata.');
        }

        $stmtTimbratura = $pdoHome->prepare(
            "INSERT INTO hr_timbrature
             (id_utente, tipo, data_ora, origine, ip_address, user_agent)
             VALUES
             (:id_utente, :tipo, NOW(), 'web', :ip_address, :user_agent)"
        );
        $stmtTimbratura->execute([
            'id_utente' => $idUtenteLoggato,
            'tipo' => $tipoTimbratura,
            'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        header('Location: index.php?timbratura=1');
        exit;
    }

    if (isset($_GET['timbratura']) && $_GET['timbratura'] === '1') {
        $messaggioTimbratura = 'Timbratura registrata correttamente.';
    }

    $timbratureOggi = hrTimbraturaLeggiOggi($pdoHome, $idUtenteLoggato);
    $ultimoTipoTimbratura = hrTimbraturaUltimoTipo($timbratureOggi);
} catch (Throwable $e) {
    $erroreTimbratura = $e->getMessage();
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

$azioniTimbratura = hrTimbraturaProssimeAzioni($ultimoTipoTimbratura);

layoutHeader('Dashboard');
?>

<div class="card card-compact">
    <h2>Benvenuto nell'area riservata</h2>
    <div class="meta">
        <div><strong>Utente:</strong> <?= h($username) ?></div>
        <div><strong>Nome:</strong> <?= h($nome) ?></div>
        <div><strong>Ruolo:</strong> <?= h($ruolo !== '' ? $ruolo : 'nessun ruolo') ?></div>
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

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h2>Timbratura presenze</h2>
            <div class="meta">Registra entrata, pausa e uscita della giornata.</div>
        </div>
        <div class="section-head-actions">
            <div style="font-size:1.8rem;font-weight:700;" id="hrClock">--:--:--</div>
        </div>
    </div>

    <?php if ($messaggioTimbratura !== ''): ?><div class="alert alert-success"><?= h($messaggioTimbratura) ?></div><?php endif; ?>
    <?php if ($erroreTimbratura !== ''): ?><div class="alert alert-error"><?= h($erroreTimbratura) ?></div><?php endif; ?>

    <div class="hr-summary-line" style="margin-top:14px;">
        <span><strong>Stato:</strong> <?= h($ultimoTipoTimbratura !== '' ? hrTimbraturaLabel($ultimoTipoTimbratura) : 'Non ancora entrato') ?></span>
    </div>

    <div class="links" style="margin-top:14px;">
        <?php if (!$azioniTimbratura): ?>
            <span class="info-box">Giornata completata. Nessuna ulteriore timbratura disponibile.</span>
        <?php else: ?>
            <?php foreach ($azioniTimbratura as $tipo => $label): ?>
                <form method="post" style="display:inline-block;margin-right:8px;">
                    <input type="hidden" name="azione" value="timbratura_home">
                    <input type="hidden" name="tipo_timbratura" value="<?= h($tipo) ?>">
                    <button type="submit" class="btn <?= $tipo === 'USCITA' ? 'btn-light' : 'btn-primary' ?>">
                        <i class="la la-clock" aria-hidden="true"></i> <?= h($label) ?>
                    </button>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($timbratureOggi): ?>
        <div class="table-wrap" style="margin-top:16px;">
            <table>
                <thead><tr><th>Ora</th><th>Evento</th></tr></thead>
                <tbody>
                    <?php foreach ($timbratureOggi as $timbratura): ?>
                        <tr>
                            <td><?= h((string)$timbratura['ora']) ?></td>
                            <td><?= h(hrTimbraturaLabel((string)$timbratura['tipo'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
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
            <div class="errore" style="margin-top:14px;"><?= h($erroreApprovalsHome) ?></div>
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
                <div class="module-box clickable" onclick="location.href='<?= h($voce['href']) ?>'">
                    <div class="module-kicker"><?= h($voce['kicker']) ?></div>
                    <h3><?= h($voce['label']) ?></h3>
                    <p><?= h($voce['descrizione']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    function updateClock() {
        var el = document.getElementById('hrClock');
        if (!el) { return; }
        var now = new Date();
        el.textContent = now.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    updateClock();
    window.setInterval(updateClock, 1000);
})();
</script>

<?php layoutFooter(); ?>
