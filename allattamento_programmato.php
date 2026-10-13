<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/hr_allattamento_programmato.php';
require_once __DIR__ . '/includes/hr_notifiche.php';
require_once __DIR__ . '/includes/hr_riepilogo_assenze.php';
richiediPermessoLettura('benefici_hr');
richiediPermessoLettura('assenze');
if (!hrRegoleOperatoreHr()) { http_response_code(403); exit('La programmazione dell’allattamento è riservata a HR e admin.'); }
$pdo = db();
$scrittura = haPermessoScrittura('benefici_hr') && haPermessoScrittura('assenze');
$operatore = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$oggi = hrAllattOggi(); $errore = ''; $messaggio = ''; $anteprima = null; $token = '';
$utenti = []; $programmi = []; $richieste = []; $storico = []; $programma = null; $diritto = null;
$idUtente = (int)($_GET['id_utente'] ?? $_POST['id_utente'] ?? 0);
$idProgramma = (int)($_GET['programma'] ?? $_POST['id_programma'] ?? 0);
$form = ['azione_programma' => 'crea', 'id_programma' => 0, 'id_utente' => $idUtente,
    'data_da' => $oggi, 'data_a' => '', 'giorni' => [1, 2, 3, 4, 5], 'ora_da' => ['08:00', '16:00'], 'ora_a' => ['09:00', '17:00']];
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function dataIt(string $v): string { return hrRegoleDataValida($v) ? date('d/m/Y', strtotime($v)) : $v; }
try {
    // Nessuna creazione automatica di tabelle durante una visita alla pagina.
    $pdo->query('SELECT id_programma FROM hr_allattamento_programmi LIMIT 1');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        hrAllattRichiediOperatore(); hrRegoleVerificaCsrf();
        if (($_POST['azione'] ?? '') === 'conferma') {
            $token = (string)($_POST['token_anteprima'] ?? '');
            $salvata = $_SESSION['hr_allatt_anteprime'][$token] ?? null;
            if (!$salvata || $salvata['operatore'] !== $operatore || $salvata['scadenza'] < time()) {
                throw new RuntimeException('Anteprima scaduta o già confermata. Ripeti l’anteprima.');
            }
            $c = hrAllattConfig($salvata['input']);
            $pdo->beginTransaction(); hrRegoleBloccaScrittura($pdo);
            $piano = hrAllattPrepara($pdo, $c);
            if (!hash_equals($salvata['impronta'], hrAllattImpronta($piano))) {
                throw new RuntimeException('Richieste, diritto o chiusure sono cambiati dopo l’anteprima. Ripeti l’anteprima per vedere la situazione aggiornata.');
            }
            $risultato = hrAllattSalva($pdo, $piano, $operatore);
            $titolo = 'Programma allattamento aggiornato';
            $messaggioNotifica = 'HR ha aggiornato il tuo programma allattamento.'
                . '\nOperazione: ' . ['crea' => 'nuova programmazione', 'modifica' => 'modifica degli inserimenti futuri',
                    'annulla' => 'annullamento nel periodo', 'chiusure' => 'annullamento nei giorni di chiusura', 'giorno' => 'annullamento di una giornata o fascia'][$c['azione']]
                . '.\nPeriodo: ' . dataIt($c['data_da']) . ' – ' . dataIt($c['data_a']) . '.';
            if ($c['fasce']) {
                $etichetteGiorni = [1 => 'lunedì', 2 => 'martedì', 3 => 'mercoledì', 4 => 'giovedì', 5 => 'venerdì', 6 => 'sabato', 7 => 'domenica'];
                $messaggioNotifica .= '\nGiorni: ' . implode(', ', array_map(static fn($g) => $etichetteGiorni[$g], $c['giorni']))
                    . '.\nFasce: ' . implode(', ', array_map(static fn($f) => $f['ora_da'] . '–' . $f['ora_a'], $c['fasce'])) . '.';
            }
            $messaggioNotifica .= '\nFasce registrate: ' . $risultato['inserite'] . '; fasce annullate: ' . $risultato['annullate']
                . '; giorni esclusi per chiusura: ' . count($piano['chiusure']) . '; fasce già presenti: ' . count($piano['duplicati'])
                . '.\nLe registrazioni sono consultabili nella pagina delle tue richieste.';
            $messaggioNotifica = str_replace('\\n', "\n", $messaggioNotifica);
            hrCreaNotificaWeb($pdo, 'ALLATTAMENTO_PROGRAMMA', $titolo, $messaggioNotifica, '/assenze.php', null, $operatore, [$c['id_utente']]);
            $pdo->commit();
            unset($_SESSION['hr_allatt_anteprime'][$token]);
            $avvisi = [];
            try {
                // Una sola email complessiva al dipendente; recapito personale verificato, nessun allegato richiesta singola.
                $invio = hrCreaNotificaEmailPerUtenti($pdo, 'ALLATTAMENTO_PROGRAMMA', $titolo, $messaggioNotifica,
                    '/assenze.php', null, $operatore, [$c['id_utente']], $c['id_utente']);
                if ($invio['saltate'] || $invio['errori']) $avvisi[] = 'La comunicazione email non è stata inviata al dipendente; la notifica resta disponibile nel portale.';
            } catch (Throwable $e) { error_log('Allattamento: notifica email non inviata: ' . $e->getMessage()); $avvisi[] = 'Comunicazione email non inviata; la programmazione è stata salvata.'; }
            if ($risultato['trigger_oggi']) {
                try { hrRiepilogoAssenzeInviaAggiornamentoSeNecessario($pdo, $risultato['trigger_oggi'], true); }
                catch (Throwable $e) { error_log('Allattamento: riepilogo odierno: ' . $e->getMessage()); $avvisi[] = 'Aggiornamento del riepilogo odierno non completato.'; }
            }
            $_SESSION['hr_allatt_messaggio'] = $risultato['inserite'] . ' fasce registrate e ' . $risultato['annullate'] . ' annullate. ' . implode(' ', $avvisi);
            header('Location: allattamento_programmato.php?id_utente=' . $c['id_utente'] . '&programma=' . $risultato['id_programma']); exit;
        } elseif (($_POST['azione'] ?? '') === 'anteprima') {
            $c = hrAllattConfig($_POST); $idUtente = $c['id_utente']; $idProgramma = $c['id_programma'];
            $anteprima = hrAllattPrepara($pdo, $c);
            $form = ['azione_programma' => $c['azione'], 'id_programma' => $c['id_programma'], 'id_utente' => $c['id_utente'],
                'id_richiesta' => $c['id_richiesta'], 'data_da' => $c['data_da'], 'data_a' => $c['data_a'], 'giorni' => $c['giorni'],
                'ora_da' => array_column($c['fasce'], 'ora_da'), 'ora_a' => array_column($c['fasce'], 'ora_a')];
            $_SESSION['hr_allatt_anteprime'] = array_filter($_SESSION['hr_allatt_anteprime'] ?? [], static fn($p) => $p['scadenza'] >= time());
            if (count($_SESSION['hr_allatt_anteprime']) >= 5) array_shift($_SESSION['hr_allatt_anteprime']);
            $token = bin2hex(random_bytes(24));
            $_SESSION['hr_allatt_anteprime'][$token] = ['input' => $form, 'impronta' => hrAllattImpronta($anteprima), 'operatore' => $operatore, 'scadenza' => time() + 1800];
        } else throw new RuntimeException('Azione non valida.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errore = $e->getMessage();
    if ($e instanceof PDOException) $errore = 'La programmazione non è disponibile. Verifica di aver eseguito lo script 2026-10-13_allattamento_programmato.sql.';
    if (($_POST['azione'] ?? '') === 'anteprima') $form = array_replace($form, array_intersect_key($_POST, $form));
}
if (isset($_SESSION['hr_allatt_messaggio'])) { $messaggio = $_SESSION['hr_allatt_messaggio']; unset($_SESSION['hr_allatt_messaggio']); }
try {
    $utenti = $pdo->query("SELECT u.id_utente, u.nome, u.cognome, u.username FROM aut_utenti u
        INNER JOIN hr_profili_dipendenti hp ON hp.id_utente = u.id_utente AND hp.attivo = 1
        WHERE u.attivo = 1 AND LOWER(u.username) <> 'admin' ORDER BY u.cognome, u.nome")->fetchAll(PDO::FETCH_ASSOC);
    $s = $pdo->prepare("SELECT data_inizio, data_fine FROM hr_benefici_utenti WHERE id_utente = :utente AND codice_beneficio = 'ALLATTAMENTO' AND attivo = 1 LIMIT 1");
    $s->execute(['utente' => $idUtente]); $diritto = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$anteprima && $_SERVER['REQUEST_METHOD'] !== 'POST' && $diritto) { $form['data_da'] = max($oggi, $diritto['data_inizio']); $form['data_a'] = (string)$diritto['data_fine']; }
    $s = $pdo->prepare('SELECT a.*, u.nome, u.cognome,
        (SELECT COUNT(*) FROM hr_allattamento_programma_richieste l INNER JOIN hr_richieste r ON r.id_richiesta = l.id_richiesta
         INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
         WHERE l.id_programma = a.id_programma AND sr.codice IN (\'BOZZA\', \'IN_ATTESA\', \'APPROVATA\')) AS fasce_attive,
        (SELECT COUNT(DISTINCT r.id_richiesta) FROM hr_allattamento_programma_richieste l
         INNER JOIN hr_richieste r ON r.id_richiesta = l.id_richiesta
         INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
         INNER JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
         INNER JOIN hr_chiusure_aziendali c ON c.attivo = 1 AND c.data_da <= p.data_a AND c.data_a >= p.data_da
         WHERE l.id_programma = a.id_programma AND sr.codice IN (\'BOZZA\', \'IN_ATTESA\', \'APPROVATA\')
         AND p.data_a >= CURDATE()) AS fasce_in_chiusura
        FROM hr_allattamento_programmi a INNER JOIN aut_utenti u ON u.id_utente = a.id_utente
        WHERE (:filtro = 0 OR a.id_utente = :utente) ORDER BY a.id_programma DESC');
    $s->execute(['filtro' => $idUtente, 'utente' => $idUtente]); $programmi = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($programmi as $r) if ((int)$r['id_programma'] === $idProgramma) $programma = $r;
    if ($programma) {
        $idUtente = (int)$programma['id_utente'];
        if (!$anteprima && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $config = json_decode($programma['configurazione_json'], true, 512, JSON_THROW_ON_ERROR);
            $form = ['azione_programma' => 'modifica', 'id_programma' => $idProgramma, 'id_utente' => $idUtente,
                'data_da' => max($oggi, $programma['data_da']), 'data_a' => $programma['data_a'], 'giorni' => $config['giorni'],
                'ora_da' => array_column($config['fasce'], 'ora_da'), 'ora_a' => array_column($config['fasce'], 'ora_a')];
        }
        $s = $pdo->prepare("SELECT r.id_richiesta, sr.codice AS stato, p.data_da, p.ora_da, p.ora_a FROM hr_allattamento_programma_richieste l
            INNER JOIN hr_richieste r ON r.id_richiesta = l.id_richiesta INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
            INNER JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta WHERE l.id_programma = :programma
            ORDER BY p.data_da, p.ora_da, r.id_richiesta");
        $s->execute(['programma' => $idProgramma]); $richieste = $s->fetchAll(PDO::FETCH_ASSOC);
        $s = $pdo->prepare('SELECT s.*, u.nome, u.cognome FROM hr_allattamento_programmi_storico s
            LEFT JOIN aut_utenti u ON u.id_utente = s.id_operatore WHERE s.id_programma = :programma ORDER BY s.id_storico DESC');
        $s->execute(['programma' => $idProgramma]); $storico = $s->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { if ($errore === '') $errore = 'Impossibile leggere i programmi. Verifica lo script di aggiornamento e riprova.'; }
layoutHeader('Allattamento programmato');
?>
<style>
.hr-allatt-stack{display:grid;gap:20px}.hr-allatt-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px}.hr-allatt-grid .form-group{min-width:0;margin:0}.hr-allatt-days,.hr-allatt-actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.hr-allatt-days label{display:flex;gap:6px;align-items:center}.hr-allatt-days input{width:auto!important}.hr-allatt-fasce{display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:600px}.hr-allatt-scroll{max-height:420px;overflow:auto}.hr-allatt-stack details summary{cursor:pointer;font-weight:600;padding:10px 0}.hr-allatt-stack form{display:grid;gap:16px}.hr-allatt-inline{display:flex!important;gap:8px!important;align-items:center;flex-wrap:wrap}.hr-allatt-inline input[type=date]{width:160px!important}.hr-allatt-stack .table-wrap{overflow-x:auto}
@media(max-width:700px){.hr-allatt-grid,.hr-allatt-fasce{grid-template-columns:1fr}.hr-allatt-inline input[type=date]{width:100%!important}}
</style>
<div class="page-container hr-allatt-stack">
 <div class="card card-wide"><div class="section-head"><div><h1>Allattamento programmato</h1><div class="meta">Programma e gestisci gli inserimenti con un’unica operazione. Le chiusure aziendali vengono escluse.</div></div><a class="btn btn-light" href="benefici_hr.php">Permessi tutelati</a></div></div>
 <?php if ($errore !== ''): ?><div class="alert alert-error"><?= h($errore) ?></div><?php endif; ?>
 <?php if ($messaggio !== ''): ?><div class="alert alert-success"><?= h($messaggio) ?></div><?php endif; ?>
 <div class="card card-wide"><label for="filtro_dipendente"><strong>Dipendente</strong></label><select id="filtro_dipendente"><option value="0">Tutti i programmi</option><?php foreach ($utenti as $u): ?><option value="<?= (int)$u['id_utente'] ?>" <?= (int)$u['id_utente'] === $idUtente ? 'selected' : '' ?>><?= h(trim($u['nome'] . ' ' . $u['cognome']) ?: $u['username']) ?></option><?php endforeach; ?></select></div>
 <?php if ($idUtente > 0): ?>
 <div class="card card-wide"><div class="section-head"><h2><?= $programma ? 'Modifica programma #' . (int)$idProgramma : 'Nuovo programma' ?></h2><?php if ($programma): ?><a class="btn btn-light" href="allattamento_programmato.php?id_utente=<?= $idUtente ?>">Nuovo programma</a><?php endif; ?></div>
 <p class="meta"><?= $diritto ? 'Diritto attivo dal ' . h(dataIt($diritto['data_inizio'])) . ' al ' . h(dataIt((string)$diritto['data_fine'])) . '.' : 'Assegna prima il diritto Allattamento con data iniziale e finale nei permessi tutelati.' ?> Massimo 2 ore complessive al giorno. Le modifiche sostituiscono le fasce del programma dalla decorrenza indicata in avanti; lo storico precedente resta disponibile.</p>
 <?php if ($scrittura): ?><form method="post">
 <input type="hidden" name="csrf_token" value="<?= h(hrRegoleCsrfToken()) ?>"><input type="hidden" name="azione" value="anteprima"><input type="hidden" name="azione_programma" value="<?= $programma ? 'modifica' : 'crea' ?>"><input type="hidden" name="id_programma" value="<?= (int)$idProgramma ?>"><input type="hidden" name="id_utente" value="<?= $idUtente ?>">
 <div class="hr-allatt-grid"><div class="form-group"><label for="data_da">Dal / decorrenza della modifica</label><input type="date" name="data_da" id="data_da" min="<?= h($oggi) ?>" value="<?= h((string)$form['data_da']) ?>" required></div><div class="form-group"><label for="data_a">Al (compreso)</label><input type="date" name="data_a" id="data_a" value="<?= h((string)$form['data_a']) ?>" required></div></div>
 <div><strong>Giorni della settimana</strong><div class="hr-allatt-days"><?php foreach ([1=>'Lun',2=>'Mar',3=>'Mer',4=>'Gio',5=>'Ven',6=>'Sab',7=>'Dom'] as $g=>$label): ?><label><input type="checkbox" name="giorni[]" value="<?= $g ?>" <?= in_array($g,(array)$form['giorni']) ? 'checked' : '' ?>><?= $label ?></label><?php endforeach; ?></div></div>
 <p class="meta">Lascia vuote le fasce che non utilizzi.</p><div class="hr-allatt-fasce"><?php for ($i=0;$i<4;$i++): ?><div class="form-group"><label for="inizio<?= $i ?>">Fascia <?= $i+1 ?> — dalle</label><input type="time" id="inizio<?= $i ?>" name="ora_da[]" min="08:00" max="17:00" step="900" value="<?= h((string)($form['ora_da'][$i] ?? '')) ?>"></div><div class="form-group"><label for="fine<?= $i ?>">Alle</label><input type="time" id="fine<?= $i ?>" name="ora_a[]" min="08:00" max="17:00" step="900" value="<?= h((string)($form['ora_a'][$i] ?? '')) ?>"></div><?php endfor; ?></div>
 <div class="hr-allatt-actions"><button type="submit" class="btn">Mostra anteprima</button></div></form><?php else: ?><div class="info-box">Il tuo profilo può consultare ma non modificare i programmi.</div><?php endif; ?></div>
 <?php endif; ?>
 <?php if ($anteprima): $c=$anteprima['config']; ?>
 <div class="card card-wide"><h2>Anteprima — <?= h(trim($anteprima['utente']['nome'].' '.$anteprima['utente']['cognome'])) ?></h2><p>Operazione: <strong><?= h(['crea'=>'nuovo programma','modifica'=>'modifica futura','annulla'=>'annullamento nel periodo','chiusure'=>'annullamento giorni di chiusura','giorno'=>'annullamento giornata o fascia'][$c['azione']]) ?></strong>. Periodo <?= h(dataIt($c['data_da'])) ?> – <?= h(dataIt($c['data_a'])) ?>.</p>
 <p><strong><?= count($anteprima['inserire']) ?></strong> fasce da registrare; <strong><?= count($anteprima['annullare']) ?></strong> da annullare; <strong><?= count($anteprima['duplicati']) ?></strong> già presenti; <strong><?= count($anteprima['chiusure']) ?></strong> giorni di chiusura esclusi.</p>
 <?php foreach (['conflitti'=>'Conflitti da risolvere','chiusure'=>'Giorni esclusi per chiusura','duplicati'=>'Fasce già presenti','annullare'=>'Fasce da annullare','inserire'=>'Fasce da registrare'] as $key=>$titolo): if (!$anteprima[$key]) continue; ?>
 <details <?= $key==='conflitti' ? 'open' : '' ?>><summary><?= h($titolo) ?> (<?= count($anteprima[$key]) ?>)</summary><div class="table-wrap hr-allatt-scroll"><table><thead><tr><th>Giorno</th><th>Dettaglio</th></tr></thead><tbody><?php foreach ($anteprima[$key] as $r): ?><tr><td><?= h(dataIt($r['giorno'] ?? $r['data_da'])) ?></td><td><?= h($r['motivo'] ?? $r['descrizione'] ?? (substr((string)$r['ora_da'],0,5).'–'.substr((string)$r['ora_a'],0,5))) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
 <?php endforeach; ?>
 <?php if ($anteprima['conflitti']): ?><div class="alert alert-error">Nessuna richiesta sarà salvata finché i conflitti non saranno risolti.</div><?php elseif ($anteprima['inserire'] || $anteprima['annullare']): ?>
 <form method="post"><input type="hidden" name="csrf_token" value="<?= h(hrRegoleCsrfToken()) ?>"><input type="hidden" name="azione" value="conferma"><input type="hidden" name="token_anteprima" value="<?= h($token) ?>"><input type="hidden" name="id_utente" value="<?= (int)$c['id_utente'] ?>"><input type="hidden" name="id_programma" value="<?= (int)$c['id_programma'] ?>"><button type="submit" class="btn">Conferma <?= count($anteprima['inserire']) ?> inserimenti e <?= count($anteprima['annullare']) ?> annullamenti</button></form>
 <?php else: ?><div class="info-box">Nessuna modifica da salvare.</div><?php endif; ?></div>
 <?php endif; ?>
 <div class="card card-wide"><h2>Programmi registrati</h2><div class="table-wrap"><table><thead><tr><th>Programma</th><th>Dipendente</th><th>Periodo</th><th>Fasce attive</th><th>Fasce future in chiusura</th><th>Gestione</th></tr></thead><tbody><?php foreach ($programmi as $r): ?><tr><td>#<?= (int)$r['id_programma'] ?></td><td><?= h(trim($r['nome'].' '.$r['cognome'])) ?></td><td><?= h(dataIt($r['data_da']).' – '.dataIt($r['data_a'])) ?></td><td><?= (int)$r['fasce_attive'] ?></td><td><?= (int)$r['fasce_in_chiusura'] ?><?= (int)$r['fasce_in_chiusura'] > 0 ? ' — da verificare' : '' ?></td><td><a class="btn btn-light" href="allattamento_programmato.php?id_utente=<?= (int)$r['id_utente'] ?>&amp;programma=<?= (int)$r['id_programma'] ?>">Apri programma</a></td></tr><?php endforeach; ?><?php if (!$programmi): ?><tr><td colspan="6">Nessun programma registrato.</td></tr><?php endif; ?></tbody></table></div></div>
 <?php if ($programma): ?>
 <div class="card card-wide"><h2>Gestisci programma #<?= $idProgramma ?></h2><?php if ((int)$programma['fasce_in_chiusura'] > 0): ?><div class="alert alert-error"><?= (int)$programma['fasce_in_chiusura'] ?> fasce future ricadono in una chiusura aziendale. Usa “Annulla fasce nei giorni di chiusura aziendale” per verificarle e annullarle insieme.</div><?php endif; ?><p class="meta">Le fasce annullate restano nello storico. Puoi annullare un periodo o interrompere il programma annullando le fasce dalla data scelta fino al termine. Le altre richieste del dipendente non vengono modificate.</p>
 <?php if ($scrittura): foreach (['annulla'=>'Annulla fasce nel periodo / interrompi programma','giorno'=>'Annulla una giornata','chiusure'=>'Annulla fasce nei giorni di chiusura aziendale'] as $azione=>$label): ?>
 <details><summary><?= h($label) ?></summary><form method="post"><input type="hidden" name="csrf_token" value="<?= h(hrRegoleCsrfToken()) ?>"><input type="hidden" name="azione" value="anteprima"><input type="hidden" name="azione_programma" value="<?= $azione ?>"><input type="hidden" name="id_programma" value="<?= $idProgramma ?>"><input type="hidden" name="id_utente" value="<?= $idUtente ?>"><div class="hr-allatt-fasce"><div><label>Dal<input type="date" name="data_da" min="<?= h($oggi) ?>" value="<?= h(max($oggi,$programma['data_da'])) ?>" required></label></div><div><label>Al<input type="date" name="data_a" min="<?= h($oggi) ?>" value="<?= h($azione==='giorno' ? max($oggi,$programma['data_da']) : max($oggi,$programma['data_a'])) ?>" required></label></div></div><div><button type="submit" class="btn btn-light">Mostra anteprima annullamento</button></div></form></details>
 <?php endforeach; endif; ?>
 <details open><summary>Fasce registrate e storico delle richieste</summary><div class="table-wrap hr-allatt-scroll"><table><thead><tr><th>Giorno</th><th>Fascia</th><th>Stato</th><th>Gestione</th></tr></thead><tbody><?php foreach ($richieste as $r): ?><tr><td><?= h(dataIt($r['data_da'])) ?></td><td><?= h(substr((string)$r['ora_da'],0,5).'–'.substr((string)$r['ora_a'],0,5)) ?></td><td><?= h($r['stato']) ?></td><td><?php if ($scrittura && $r['data_da'] >= $oggi && in_array($r['stato'],['APPROVATA','IN_ATTESA','BOZZA'],true)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(hrRegoleCsrfToken()) ?>"><input type="hidden" name="azione" value="anteprima"><input type="hidden" name="azione_programma" value="giorno"><input type="hidden" name="id_programma" value="<?= $idProgramma ?>"><input type="hidden" name="id_utente" value="<?= $idUtente ?>"><input type="hidden" name="id_richiesta" value="<?= (int)$r['id_richiesta'] ?>"><input type="hidden" name="data_da" value="<?= h($r['data_da']) ?>"><input type="hidden" name="data_a" value="<?= h($r['data_da']) ?>"><button class="btn btn-light" type="submit">Annulla fascia</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></details>
 <details><summary>Storico del programma</summary><div class="table-wrap"><table><thead><tr><th>Quando</th><th>Operatore</th><th>Operazione</th><th>Risultato</th></tr></thead><tbody><?php foreach ($storico as $r): $d=json_decode($r['dettagli_json'],true) ?: []; ?><tr><td><?= h($r['data_azione']) ?></td><td><?= h(trim((string)$r['nome'].' '.(string)$r['cognome'])) ?></td><td><?= h($r['azione']) ?></td><td><?= (int)($d['risultato']['inserite'] ?? 0) ?> inserite, <?= (int)($d['risultato']['annullate'] ?? 0) ?> annullate; dal <?= h(dataIt((string)($d['config']['data_da'] ?? ''))) ?> al <?= h(dataIt((string)($d['config']['data_a'] ?? ''))) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
 </div>
 <?php endif; ?>
</div>
<script>
document.getElementById('filtro_dipendente').addEventListener('change',function(){window.location.href='allattamento_programmato.php?id_utente='+encodeURIComponent(this.value);});
</script>
<?php layoutFooter(); ?>
