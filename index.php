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

function h(?string $valore): string { return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8'); }

function contaApprovazioniHrPendentiHome(int $idUtente, bool $puoConfigurare): int
{
    if ($idUtente <= 0) { return 0; }
    $pdo = db();
    $filtro = $puoConfigurare ? '1 = 1' : 'a.id_approvatore_assegnato = :id_utente';
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT r.id_richiesta) FROM hr_richieste r INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta INNER JOIN hr_richieste_approvazioni a ON a.id_richiesta = r.id_richiesta WHERE sr.codice = 'IN_ATTESA' AND a.stato_approvazione = 'IN_ATTESA' AND {$filtro}");
    $stmt->execute($puoConfigurare ? [] : ['id_utente' => $idUtente]);
    return (int)$stmt->fetchColumn();
}

function hrTimbraturaMotiviFuoriSede(): array
{
    return ['CLIENTE'=>'Cliente','FORNITORE'=>'Fornitore','TRASFERTA'=>'Trasferta','FORMAZIONE'=>'Formazione','CONSEGNA_RITIRO'=>'Consegna / ritiro materiale','PERMESSO_PERSONALE'=>'Permesso personale','ALTRO'=>'Altro'];
}

function hrTimbraturaLabel(string $tipo): string
{
    $labels = ['PRESENTE'=>'Presente','IN_PAUSA'=>'In pausa','FINE_PAUSA'=>'Fine pausa','FUORI_SEDE'=>'Fuori sede','RIENTRO'=>'Rientro','FINE_LAVORO'=>'Fine lavoro','ENTRATA'=>'Presente','INIZIO_PAUSA'=>'In pausa','USCITA'=>'Fine lavoro'];
    $tipo = strtoupper(trim($tipo));
    return $labels[$tipo] ?? $tipo;
}

function hrTimbraturaCausaleLabel(?string $causale): string
{
    $causale = strtoupper(trim((string)$causale));
    if ($causale === '') { return ''; }
    $motivi = hrTimbraturaMotiviFuoriSede();
    return $motivi[$causale] ?? $causale;
}

function hrTimbraturaStatoCorrente(string $ultimoTipo): string
{
    $ultimoTipo = strtoupper(trim($ultimoTipo));
    if ($ultimoTipo === '') { return 'NON_INIZIATA'; }
    if (in_array($ultimoTipo, ['PRESENTE','FINE_PAUSA','RIENTRO','ENTRATA'], true)) { return 'PRESENTE'; }
    if (in_array($ultimoTipo, ['IN_PAUSA','INIZIO_PAUSA'], true)) { return 'IN_PAUSA'; }
    if ($ultimoTipo === 'FUORI_SEDE') { return 'FUORI_SEDE'; }
    if (in_array($ultimoTipo, ['FINE_LAVORO','USCITA'], true)) { return 'FINE_LAVORO'; }
    return $ultimoTipo;
}

function hrTimbraturaStatoLabel(string $stato): string
{
    $labels = ['NON_INIZIATA'=>'Giornata non iniziata','PRESENTE'=>'Presente','IN_PAUSA'=>'In pausa','FUORI_SEDE'=>'Fuori sede','FINE_LAVORO'=>'Fine lavoro'];
    return $labels[$stato] ?? $stato;
}

function hrTimbraturaProssimeAzioni(string $ultimoTipo): array
{
    $stato = hrTimbraturaStatoCorrente($ultimoTipo);
    if ($stato === 'NON_INIZIATA') { return ['PRESENTE'=>'Presente']; }
    if ($stato === 'PRESENTE') { return ['IN_PAUSA'=>'In pausa','FUORI_SEDE'=>'Fuori sede','FINE_LAVORO'=>'Fine lavoro']; }
    if ($stato === 'IN_PAUSA') { return ['FINE_PAUSA'=>'Fine pausa']; }
    if ($stato === 'FUORI_SEDE') { return ['RIENTRO'=>'Rientro']; }
    return [];
}

function hrTimbraturaLeggiOggi(PDO $pdo, int $idUtente): array
{
    if ($idUtente <= 0) { return []; }
    $stmt = $pdo->prepare("SELECT id_timbratura, tipo, causale, note, DATE_FORMAT(data_ora, '%H:%i:%s') AS ora FROM hr_timbrature WHERE id_utente = :id_utente AND DATE(data_ora) = CURDATE() ORDER BY data_ora ASC, id_timbratura ASC");
    $stmt->execute(['id_utente' => $idUtente]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hrTimbraturaUltimoTipo(array $timbrature): string
{
    if (!$timbrature) { return ''; }
    $ultima = $timbrature[count($timbrature) - 1];
    return strtoupper(trim((string)($ultima['tipo'] ?? '')));
}

try {
    $pdoHome = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['azione'] ?? '') === 'timbratura_home') {
        if ($idUtenteLoggato <= 0) { throw new RuntimeException('Utente non valido.'); }
        $tipoTimbratura = strtoupper(trim((string)($_POST['tipo_timbratura'] ?? '')));
        $timbratureCorrenti = hrTimbraturaLeggiOggi($pdoHome, $idUtenteLoggato);
        $azioniAmmesse = hrTimbraturaProssimeAzioni(hrTimbraturaUltimoTipo($timbratureCorrenti));
        if (!array_key_exists($tipoTimbratura, $azioniAmmesse)) { throw new RuntimeException('Timbratura non coerente con lo stato corrente della giornata.'); }
        $causaleTimbratura = null;
        $noteTimbratura = null;
        if ($tipoTimbratura === 'FUORI_SEDE') {
            $motivi = hrTimbraturaMotiviFuoriSede();
            $motivo = strtoupper(trim((string)($_POST['motivo_fuori_sede'] ?? '')));
            $notaLibera = trim((string)($_POST['nota_fuori_sede'] ?? ''));
            if (!array_key_exists($motivo, $motivi)) { throw new RuntimeException('Seleziona il motivo del fuori sede.'); }
            $causaleTimbratura = $motivo;
            $noteTimbratura = $notaLibera !== '' ? $notaLibera : null;
        }
        $stmtTimbratura = $pdoHome->prepare("INSERT INTO hr_timbrature (id_utente, tipo, causale, data_ora, origine, ip_address, user_agent, note) VALUES (:id_utente, :tipo, :causale, NOW(), 'web', :ip_address, :user_agent, :note)");
        $stmtTimbratura->execute(['id_utente'=>$idUtenteLoggato,'tipo'=>$tipoTimbratura,'causale'=>$causaleTimbratura,'ip_address'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),'user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,255),'note'=>$noteTimbratura]);
        header('Location: index.php?timbratura=1');
        exit;
    }
    if (isset($_GET['timbratura']) && $_GET['timbratura'] === '1') { $messaggioTimbratura = 'Registrazione presenza completata correttamente.'; }
    $timbratureOggi = hrTimbraturaLeggiOggi($pdoHome, $idUtenteLoggato);
    $ultimoTipoTimbratura = hrTimbraturaUltimoTipo($timbratureOggi);
} catch (Throwable $e) { $erroreTimbratura = $e->getMessage(); }

if ($puoLeggereUtenti) { $accessiRapidi[] = ['label'=>'Gestione utenti','href'=>'utenti.php','kicker'=>'Amministrazione','descrizione'=>'Accessi, ruoli e permessi del portale.']; }
if ($puoLeggereOrdini) { $accessiRapidi[] = ['label'=>'Ordini fornitori','href'=>'ordini_fornitori_aperti.php','kicker'=>'Acquisti','descrizione'=>'Controllo degli ordini fornitori ancora aperti.']; }
if ($puoLeggereAssenze) { $accessiRapidi[] = ['label'=>'Richieste assenze','href'=>'assenze.php','kicker'=>'HR','descrizione'=>'Invio e consultazione delle proprie richieste.']; }
if ($puoLeggereApprovazioniAssenze) {
    $accessiRapidi[] = ['label'=>'Approvazioni assenze','href'=>'approvazioni_assenze.php','kicker'=>'HR','descrizione'=>'Richieste pendenti da verificare e approvare.'];
    try { $approvazioniPendenti = contaApprovazioniHrPendentiHome($idUtenteLoggato, $puoLeggereConfigurazioneAssenze); } catch (Throwable $e) { $erroreApprovalsHome = 'Impossibile leggere le approvazioni HR pendenti.'; }
}
if ($puoLeggereReportAssenze) { $accessiRapidi[] = ['label'=>'Report assenze','href'=>'report_assenze.php','kicker'=>'HR','descrizione'=>'Analisi, filtri ed esportazione Excel delle richieste.']; }
if ($puoLeggereCalendarioAssenze) { $accessiRapidi[] = ['label'=>'Calendario assenze','href'=>'calendario_assenze.php','kicker'=>'HR','descrizione'=>'Vista giornaliera e mensile delle presenze.']; }
if ($puoLeggereConfigurazioneAssenze) { $accessiRapidi[] = ['label'=>'Configurazione assenze','href'=>'configurazione_assenze.php','kicker'=>'HR','descrizione'=>'Tipologie, gruppi di lavoro e relazioni organizzative.']; }

$statoTimbratura = hrTimbraturaStatoCorrente($ultimoTipoTimbratura);
$azioniTimbratura = hrTimbraturaProssimeAzioni($ultimoTipoTimbratura);
$motiviFuoriSede = hrTimbraturaMotiviFuoriSede();

layoutHeader('Dashboard');
?>

<style>
.presence-actions{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin-top:1rem}.presence-actions form{margin:0!important;display:block!important}.presence-away{margin-top:1rem;padding:1rem;border:1px solid #d9e2ef;border-radius:12px;background:#f8fbff}.presence-away-title{font-weight:700;margin-bottom:.75rem}.presence-away form{display:grid;grid-template-columns:minmax(180px,260px) minmax(220px,1fr) auto;gap:.75rem;align-items:end;margin:0}.presence-away label{display:grid;gap:.35rem;margin:0;font-weight:700}.presence-away select,.presence-away input{width:100%}@media(max-width:760px){.presence-actions,.presence-away form{display:grid;grid-template-columns:1fr}.presence-actions .btn,.presence-away .btn{width:100%}}
</style>

<div class="card card-compact">
    <h2>Benvenuto nell'area riservata</h2>
    <div class="meta"><div><strong>Utente:</strong> <?= h($username) ?></div><div><strong>Nome:</strong> <?= h($nome) ?></div><div><strong>Ruolo:</strong> <?= h($ruolo !== '' ? $ruolo : 'nessun ruolo') ?></div></div>
    <?php if ($utenteSenzaRuolo): ?><div class="errore" style="margin-top:18px;">Il tuo utente è autenticato ma non ha ancora un ruolo assegnato. Contatta un amministratore per abilitare i moduli.</div><?php endif; ?>
    <div class="links"><a class="btn btn-light" href="cambia_password.php"><i class="la la-key" aria-hidden="true"></i> Cambia password</a></div>
</div>

<div class="card card-compact">
    <div class="section-head"><div><h2>Stato presenza</h2><div class="meta">Registra lo stato della giornata: presente, pausa, fuori sede o fine lavoro.</div></div><div class="section-head-actions"><div style="font-size:1.8rem;font-weight:700;" id="hrClock">--:--:--</div></div></div>
    <?php if ($messaggioTimbratura !== ''): ?><div class="alert alert-success"><?= h($messaggioTimbratura) ?></div><?php endif; ?>
    <?php if ($erroreTimbratura !== ''): ?><div class="alert alert-error"><?= h($erroreTimbratura) ?></div><?php endif; ?>
    <div class="hr-summary-line" style="margin-top:14px;"><span><strong>Stato attuale:</strong> <?= h(hrTimbraturaStatoLabel($statoTimbratura)) ?></span></div>

    <?php if (!$azioniTimbratura): ?>
        <div class="info-box" style="margin-top:14px;">Giornata conclusa. Non sono disponibili altre registrazioni per oggi.</div>
    <?php else: ?>
        <?php $azioniRapide = $azioniTimbratura; $mostraFuoriSede = array_key_exists('FUORI_SEDE', $azioniRapide); unset($azioniRapide['FUORI_SEDE']); ?>
        <?php if ($azioniRapide): ?>
            <div class="presence-actions">
                <?php foreach ($azioniRapide as $tipo => $label): ?>
                    <form method="post"><input type="hidden" name="azione" value="timbratura_home"><input type="hidden" name="tipo_timbratura" value="<?= h($tipo) ?>"><button type="submit" class="btn <?= $tipo === 'FINE_LAVORO' ? 'btn-light js-fine-lavoro' : 'btn-primary' ?>"><i class="la la-clock" aria-hidden="true"></i> <?= h($label) ?></button></form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($mostraFuoriSede): ?>
            <div class="presence-away"><div class="presence-away-title">Uscita fuori sede</div><form method="post"><input type="hidden" name="azione" value="timbratura_home"><input type="hidden" name="tipo_timbratura" value="FUORI_SEDE"><label>Motivo<select name="motivo_fuori_sede" required><option value="">Seleziona...</option><?php foreach ($motiviFuoriSede as $codice => $motivo): ?><option value="<?= h($codice) ?>"><?= h($motivo) ?></option><?php endforeach; ?></select></label><label>Note<input type="text" name="nota_fuori_sede" placeholder="Nota opzionale"></label><button type="submit" class="btn btn-primary"><i class="la la-route" aria-hidden="true"></i> Registra fuori sede</button></form></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($timbratureOggi): ?>
        <div class="table-wrap" style="margin-top:16px;"><table><thead><tr><th>Ora</th><th>Evento</th><th>Causale</th><th>Note</th></tr></thead><tbody><?php foreach ($timbratureOggi as $timbratura): ?><tr><td><?= h((string)$timbratura['ora']) ?></td><td><?= h(hrTimbraturaLabel((string)$timbratura['tipo'])) ?></td><td><?= h(hrTimbraturaCausaleLabel($timbratura['causale'] ?? '')) ?></td><td><?= h((string)($timbratura['note'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</div>

<?php if ($puoLeggereApprovazioniAssenze): ?>
<div class="card card-compact"><div class="section-head"><div><h2>Approvazioni HR pendenti</h2><div class="meta"><?= $puoLeggereConfigurazioneAssenze ? 'Vista HR globale sulle richieste ancora da gestire.' : 'Richieste assegnate direttamente a te come approvatore.' ?></div></div><div class="section-head-actions"><a class="btn btn-light" href="approvazioni_assenze.php"><i class="la la-check-circle" aria-hidden="true"></i> Apri approvazioni</a><?php if ($puoLeggereReportAssenze): ?><a class="btn btn-light" href="report_assenze.php"><i class="la la-file-excel" aria-hidden="true"></i> Report assenze</a><?php endif; ?></div></div><?php if ($erroreApprovalsHome !== ''): ?><div class="errore" style="margin-top:14px;"><?= h($erroreApprovalsHome) ?></div><?php elseif ($approvazioniPendenti > 0): ?><div class="hr-summary-line" style="margin-top:14px;"><span><strong><?= (int)$approvazioniPendenti ?></strong> richieste da approvare</span></div><?php else: ?><div class="info-box" style="margin-top:14px;">Non ci sono approvazioni HR pendenti.</div><?php endif; ?></div>
<?php endif; ?>

<div class="card card-compact"><h2>Accessi rapidi</h2><div class="section-intro">Il menu in alto resta la guida principale della navigazione. Qui trovi alcune scorciatoie alle funzioni che usi più spesso.</div><?php if (!$accessiRapidi): ?><div class="info-box">Al momento non hai moduli disponibili in accesso rapido. Verifica i permessi del tuo ruolo.</div><?php else: ?><div class="modules"><?php foreach ($accessiRapidi as $voce): ?><div class="module-box clickable" onclick="location.href='<?= h($voce['href']) ?>'"><div class="module-kicker"><?= h($voce['kicker']) ?></div><h3><?= h($voce['label']) ?></h3><p><?= h($voce['descrizione']) ?></p></div><?php endforeach; ?></div><?php endif; ?></div>

<script>
(function(){function updateClock(){var el=document.getElementById('hrClock');if(!el){return;}el.textContent=new Date().toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}updateClock();window.setInterval(updateClock,1000);document.querySelectorAll('.js-fine-lavoro').forEach(function(button){button.addEventListener('click',function(event){if(!window.confirm('Confermi la fine della giornata lavorativa? Dopo questa operazione non potrai registrare ulteriori eventi oggi.')){event.preventDefault();}});});})();
</script>

<?php layoutFooter(); ?>
