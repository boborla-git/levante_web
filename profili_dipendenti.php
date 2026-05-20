<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('profili_dipendenti');

$pdo = db();
$puoScrivere = haPermessoScrittura('profili_dipendenti');
$errore = '';
$messaggio = '';
$profili = [];
$reparti = [];
$centriCosto = [];
$riepilogo = [
    'profili_totali' => 0,
    'profili_reali' => 0,
    'profili_test' => 0,
    'profili_compilati' => 0,
];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrProfiloData(?string $valore): ?string
{
    $valore = trim((string)$valore);
    if ($valore === '') {
        return null;
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valore) ? $valore : null;
}

function hrProfiloLabelUtente(array $profilo): string
{
    $nome = trim((string)($profilo['nome'] ?? ''));
    $cognome = trim((string)($profilo['cognome'] ?? ''));
    $username = trim((string)($profilo['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);

    if ($nominativo !== '') {
        return $nominativo . ' (' . $username . ')';
    }

    return $username;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'salva_profilo') {
            $idProfilo = (int)($_POST['id_profilo_dipendente'] ?? 0);
            if ($idProfilo <= 0) {
                throw new RuntimeException('Profilo dipendente non valido.');
            }

            $idReparto = (int)($_POST['id_reparto'] ?? 0);
            $idCentroCosto = (int)($_POST['id_centro_costo'] ?? 0);
            $matricola = trim((string)($_POST['matricola'] ?? ''));
            $mansione = trim((string)($_POST['mansione'] ?? ''));
            $dataAssunzione = hrProfiloData((string)($_POST['data_assunzione'] ?? ''));
            $dataCessazione = hrProfiloData((string)($_POST['data_cessazione'] ?? ''));
            $noteHr = trim((string)($_POST['note_hr'] ?? ''));

            if ($dataAssunzione !== null && $dataCessazione !== null && $dataCessazione < $dataAssunzione) {
                throw new RuntimeException('La data di cessazione non può precedere la data di assunzione.');
            }

            $stmt = $pdo->prepare(
                'UPDATE hr_profili_dipendenti
                 SET matricola = :matricola,
                     mansione = :mansione,
                     id_reparto = :id_reparto,
                     id_centro_costo = :id_centro_costo,
                     data_assunzione = :data_assunzione,
                     data_cessazione = :data_cessazione,
                     note_hr = :note_hr,
                     attivo = :attivo,
                     data_aggiornamento = NOW()
                 WHERE id_profilo_dipendente = :id_profilo_dipendente'
            );
            $stmt->execute([
                'matricola' => $matricola !== '' ? $matricola : null,
                'mansione' => $mansione !== '' ? $mansione : null,
                'id_reparto' => $idReparto > 0 ? $idReparto : null,
                'id_centro_costo' => $idCentroCosto > 0 ? $idCentroCosto : null,
                'data_assunzione' => $dataAssunzione,
                'data_cessazione' => $dataCessazione,
                'note_hr' => $noteHr !== '' ? $noteHr : null,
                'attivo' => isset($_POST['attivo']) ? 1 : 0,
                'id_profilo_dipendente' => $idProfilo,
            ]);

            header('Location: profili_dipendenti.php?ok=1');
            exit;
        }
    }

    if (isset($_GET['ok'])) {
        $messaggio = 'Profilo dipendente aggiornato correttamente.';
    }

    // Allineamento prudente: crea eventuali profili mancanti per utenti attivi senza assegnare dati HR.
    $pdo->exec(
        'INSERT INTO hr_profili_dipendenti (id_utente, attivo)
         SELECT u.id_utente, 1
         FROM aut_utenti u
         WHERE u.attivo = 1
           AND NOT EXISTS (
               SELECT 1
               FROM hr_profili_dipendenti p
               WHERE p.id_utente = u.id_utente
           )'
    );

    $reparti = $pdo->query('SELECT id_reparto, codice, nome FROM hr_reparti WHERE attivo = 1 ORDER BY ordinamento, nome')->fetchAll(PDO::FETCH_ASSOC);
    $centriCosto = $pdo->query('SELECT id_centro_costo, codice, nome FROM hr_centri_costo WHERE attivo = 1 ORDER BY ordinamento, nome')->fetchAll(PDO::FETCH_ASSOC);

    $profili = $pdo->query(
        'SELECT *
         FROM v_hr_profili_dipendenti
         ORDER BY utente_test, cognome, nome, username'
    )->fetchAll(PDO::FETCH_ASSOC);

    $riepilogo['profili_totali'] = count($profili);
    foreach ($profili as $profilo) {
        if ((int)$profilo['utente_test'] === 1) {
            $riepilogo['profili_test']++;
        } else {
            $riepilogo['profili_reali']++;
        }

        if (
            trim((string)($profilo['matricola'] ?? '')) !== '' ||
            trim((string)($profilo['mansione'] ?? '')) !== '' ||
            trim((string)($profilo['reparto'] ?? '')) !== '' ||
            trim((string)($profilo['centro_costo'] ?? '')) !== ''
        ) {
            $riepilogo['profili_compilati']++;
        }
    }
} catch (Throwable $e) {
    $errore = $e->getMessage();
}

layoutHeader('Profili dipendenti');
?>

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Profili dipendenti</h1>
            <div class="meta">Gestione organizzativa HR separata dagli utenti di login: reparto, centro di costo, mansione, matricola e note interne.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="configurazione_assenze.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla configurazione</a>
        </div>
    </div>
</div>

<section class="hr-config-summary">
    <span><strong><?= (int)$riepilogo['profili_totali'] ?></strong> profili</span>
    <span><strong><?= (int)$riepilogo['profili_reali'] ?></strong> utenti reali</span>
    <span><strong><?= (int)$riepilogo['profili_test'] ?></strong> utenti test</span>
    <span><strong><?= (int)$riepilogo['profili_compilati'] ?></strong> profili compilati</span>
</section>

<?php if ($errore !== ''): ?><div class="alert alert-error"><?= h($errore) ?></div><?php endif; ?>
<?php if ($messaggio !== ''): ?><div class="alert alert-success"><?= h($messaggio) ?></div><?php endif; ?>

<div class="card card-wide">
    <div class="hr-filter-toolbar">
        <div>
            <h2>Elenco profili</h2>
            <div class="meta">Filtra rapidamente per nome, username, reparto, centro di costo, mansione o matricola.</div>
        </div>
        <div class="form-group hr-filter-search-group">
            <label for="profiliSearch">Filtro rapido</label>
            <input type="search" id="profiliSearch" data-table-filter="profiliTable" placeholder="Cerca in tutte le colonne...">
        </div>
    </div>

    <div class="table-wrap">
        <table id="profiliTable">
            <thead>
            <tr>
                <th>Dipendente</th>
                <th>Matricola</th>
                <th>Mansione</th>
                <th>Reparto</th>
                <th>Centro di costo</th>
                <th>Date</th>
                <th>Note HR</th>
                <th>Stato</th>
                <th>Azioni</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($profili as $profilo): ?>
                <?php
                $idProfilo = (int)$profilo['id_profilo_dipendente'];
                $isTest = (int)$profilo['utente_test'] === 1;
                ?>
                <tr>
                    <form method="post" action="profili_dipendenti.php">
                        <td>
                            <strong><?= h(hrProfiloLabelUtente($profilo)) ?></strong><br>
                            <span class="meta"><?= $isTest ? 'Utente test' : 'Utente reale' ?></span>
                            <input type="hidden" name="azione" value="salva_profilo">
                            <input type="hidden" name="id_profilo_dipendente" value="<?= $idProfilo ?>">
                        </td>
                        <td><input type="text" name="matricola" value="<?= h((string)($profilo['matricola'] ?? '')) ?>" maxlength="50" <?= $puoScrivere ? '' : 'readonly' ?>></td>
                        <td><input type="text" name="mansione" value="<?= h((string)($profilo['mansione'] ?? '')) ?>" maxlength="150" <?= $puoScrivere ? '' : 'readonly' ?>></td>
                        <td>
                            <select name="id_reparto" <?= $puoScrivere ? '' : 'disabled' ?>>
                                <option value="">Non assegnato</option>
                                <?php foreach ($reparti as $reparto): ?>
                                    <option value="<?= (int)$reparto['id_reparto'] ?>" <?= (int)($profilo['id_reparto'] ?? 0) === (int)$reparto['id_reparto'] ? 'selected' : '' ?>>
                                        <?= h((string)$reparto['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="meta"><?= h((string)($profilo['codice_reparto'] ?? '')) ?></div>
                        </td>
                        <td>
                            <select name="id_centro_costo" <?= $puoScrivere ? '' : 'disabled' ?>>
                                <option value="">Non assegnato</option>
                                <?php foreach ($centriCosto as $centroCosto): ?>
                                    <option value="<?= (int)$centroCosto['id_centro_costo'] ?>" <?= (int)($profilo['id_centro_costo'] ?? 0) === (int)$centroCosto['id_centro_costo'] ? 'selected' : '' ?>>
                                        <?= h((string)$centroCosto['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="meta"><?= h((string)($profilo['codice_centro_costo'] ?? '')) ?></div>
                        </td>
                        <td>
                            <label class="meta" for="assunzione_<?= $idProfilo ?>">Assunzione</label>
                            <input type="date" id="assunzione_<?= $idProfilo ?>" name="data_assunzione" value="<?= h((string)($profilo['data_assunzione'] ?? '')) ?>" <?= $puoScrivere ? '' : 'readonly' ?>>
                            <label class="meta" for="cessazione_<?= $idProfilo ?>">Cessazione</label>
                            <input type="date" id="cessazione_<?= $idProfilo ?>" name="data_cessazione" value="<?= h((string)($profilo['data_cessazione'] ?? '')) ?>" <?= $puoScrivere ? '' : 'readonly' ?>>
                        </td>
                        <td><textarea name="note_hr" rows="2" <?= $puoScrivere ? '' : 'readonly' ?>><?= h((string)($profilo['note_hr'] ?? '')) ?></textarea></td>
                        <td>
                            <?= renderHrStatusBadge((int)$profilo['profilo_attivo'] === 1 ? 'ATTIVO' : 'DISATTIVO', (int)$profilo['profilo_attivo'] === 1 ? 'Attivo' : 'Disattivo') ?><br>
                            <?php if ($isTest): ?><?= renderHrStatusBadge('TEST', 'Test') ?><?php endif; ?>
                            <label class="meta"><input type="checkbox" name="attivo" value="1" <?= (int)$profilo['profilo_attivo'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> profilo attivo</label>
                        </td>
                        <td>
                            <button type="submit" class="btn btn-sm btn-primary" <?= $puoScrivere ? '' : 'disabled' ?>>
                                <i class="la la-save" aria-hidden="true"></i> Salva
                            </button>
                        </td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php layoutFooter(); ?>
