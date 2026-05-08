<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('recapiti_utenti');

$pdo = db();
$puoScrivere = haPermessoScrittura('recapiti_utenti');
$errore = '';
$messaggio = '';
$utenti = [];
$tipiRecapito = [];
$recapiti = [];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrRecapitoValido(string $codiceTipo, string $valore): bool
{
    if (strpos($codiceTipo, 'EMAIL_') === 0) {
        return filter_var($valore, FILTER_VALIDATE_EMAIL) !== false;
    }

    if (strpos($codiceTipo, 'CELLULARE_') === 0) {
        return preg_match('/^[0-9 +().\-]{6,30}$/', $valore) === 1;
    }

    return $valore !== '';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'salva_recapito') {
            $idRecapito = (int)($_POST['id_recapito_utente'] ?? 0);
            $idUtente = (int)($_POST['id_utente'] ?? 0);
            $idTipo = (int)($_POST['id_tipo_recapito'] ?? 0);
            $valore = trim((string)($_POST['valore'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $principale = isset($_POST['principale']) ? 1 : 0;
            $verificato = isset($_POST['verificato']) ? 1 : 0;
            $attivo = isset($_POST['attivo']) ? 1 : 0;

            if ($idUtente <= 0 || $idTipo <= 0 || $valore === '') {
                throw new RuntimeException('Utente, tipo recapito e valore sono obbligatori.');
            }

            $stmtTipo = $pdo->prepare('SELECT codice FROM hr_tipi_recapito WHERE id_tipo_recapito = :id AND attivo = 1');
            $stmtTipo->execute(['id' => $idTipo]);
            $codiceTipo = (string)($stmtTipo->fetchColumn() ?: '');
            if ($codiceTipo === '') {
                throw new RuntimeException('Tipo recapito non valido.');
            }

            if (!hrRecapitoValido($codiceTipo, $valore)) {
                throw new RuntimeException('Il valore del recapito non è valido per il tipo selezionato.');
            }

            $pdo->beginTransaction();

            if ($principale === 1) {
                $stmtReset = $pdo->prepare(
                    'UPDATE hr_recapiti_utenti
                     SET principale = 0
                     WHERE id_utente = :id_utente
                       AND id_tipo_recapito = :id_tipo_recapito
                       AND (:id_recapito_utente = 0 OR id_recapito_utente <> :id_recapito_utente)'
                );
                $stmtReset->execute([
                    'id_utente' => $idUtente,
                    'id_tipo_recapito' => $idTipo,
                    'id_recapito_utente' => $idRecapito,
                ]);
            }

            if ($idRecapito > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE hr_recapiti_utenti
                     SET id_utente = :id_utente,
                         id_tipo_recapito = :id_tipo_recapito,
                         valore = :valore,
                         principale = :principale,
                         verificato = :verificato,
                         attivo = :attivo,
                         note = :note
                     WHERE id_recapito_utente = :id_recapito_utente'
                );
                $stmt->execute([
                    'id_utente' => $idUtente,
                    'id_tipo_recapito' => $idTipo,
                    'valore' => $valore,
                    'principale' => $principale,
                    'verificato' => $verificato,
                    'attivo' => $attivo,
                    'note' => $note !== '' ? $note : null,
                    'id_recapito_utente' => $idRecapito,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO hr_recapiti_utenti
                     (id_utente, id_tipo_recapito, valore, principale, verificato, attivo, note)
                     VALUES (:id_utente, :id_tipo_recapito, :valore, :principale, :verificato, :attivo, :note)'
                );
                $stmt->execute([
                    'id_utente' => $idUtente,
                    'id_tipo_recapito' => $idTipo,
                    'valore' => $valore,
                    'principale' => $principale,
                    'verificato' => $verificato,
                    'attivo' => $attivo,
                    'note' => $note !== '' ? $note : null,
                ]);
            }

            $pdo->commit();
            header('Location: recapiti_utenti.php?ok=1');
            exit;
        }

        if ($azione === 'disattiva_recapito') {
            $idRecapito = (int)($_POST['id_recapito_utente'] ?? 0);
            if ($idRecapito <= 0) {
                throw new RuntimeException('Recapito non valido.');
            }

            $stmt = $pdo->prepare('UPDATE hr_recapiti_utenti SET attivo = 0, principale = 0 WHERE id_recapito_utente = :id');
            $stmt->execute(['id' => $idRecapito]);
            header('Location: recapiti_utenti.php?disattivato=1');
            exit;
        }
    }

    if (isset($_GET['ok'])) {
        $messaggio = 'Recapito salvato correttamente.';
    } elseif (isset($_GET['disattivato'])) {
        $messaggio = 'Recapito disattivato correttamente.';
    }

    $utenti = $pdo->query(
        "SELECT id_utente, username, nome, cognome,
                TRIM(CONCAT(COALESCE(nome,''), ' ', COALESCE(cognome,''))) AS nominativo
         FROM aut_utenti
         WHERE attivo = 1
         ORDER BY cognome, nome, username"
    )->fetchAll();

    $tipiRecapito = $pdo->query('SELECT * FROM hr_tipi_recapito WHERE attivo = 1 ORDER BY id_tipo_recapito')->fetchAll();

    $recapiti = $pdo->query(
        "SELECT ru.*, tr.codice AS tipo_codice, tr.descrizione AS tipo_descrizione,
                u.username,
                TRIM(CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,''))) AS nominativo
         FROM hr_recapiti_utenti ru
         INNER JOIN hr_tipi_recapito tr ON tr.id_tipo_recapito = ru.id_tipo_recapito
         INNER JOIN aut_utenti u ON u.id_utente = ru.id_utente
         ORDER BY u.cognome, u.nome, u.username, tr.id_tipo_recapito, ru.principale DESC, ru.attivo DESC"
    )->fetchAll();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errore = $e->getMessage();
}

layoutHeader('Recapiti utenti');
?>
<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Recapiti utenti</h1>
            <div class="meta">Gestisci email e recapiti usati dal modulo HR per notifiche, autorizzazioni e comunicazioni operative.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="configurazione_assenze.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla configurazione</a>
        </div>
    </div>
</div>

<?php if ($errore !== ''): ?><div class="errore"><?= h($errore) ?></div><?php endif; ?>
<?php if ($messaggio !== ''): ?><div class="ok"><?= h($messaggio) ?></div><?php endif; ?>

<div class="card card-form">
    <h2>Nuovo recapito</h2>
    <?php if (!$puoScrivere): ?>
        <div class="info-box">Il tuo profilo può consultare ma non modificare i recapiti.</div>
    <?php else: ?>
    <form method="post" action="recapiti_utenti.php">
        <input type="hidden" name="azione" value="salva_recapito">
        <input type="hidden" name="id_recapito_utente" value="0">
        <div class="hr-admin-grid">
            <div class="form-group">
                <label for="id_utente">Utente</label>
                <select name="id_utente" id="id_utente" required>
                    <option value="">Seleziona...</option>
                    <?php foreach ($utenti as $u): ?>
                        <?php $nome = trim((string)$u['nominativo']) !== '' ? (string)$u['nominativo'] : (string)$u['username']; ?>
                        <option value="<?= (int)$u['id_utente'] ?>"><?= h($nome) ?> (<?= h((string)$u['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="id_tipo_recapito">Tipo</label>
                <select name="id_tipo_recapito" id="id_tipo_recapito" required>
                    <option value="">Seleziona...</option>
                    <?php foreach ($tipiRecapito as $tipo): ?>
                        <option value="<?= (int)$tipo['id_tipo_recapito'] ?>"><?= h((string)$tipo['descrizione']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="valore">Valore</label>
                <input type="text" name="valore" id="valore" maxlength="255" required>
            </div>
            <div class="form-group hr-col-span-2">
                <label for="note">Note</label>
                <input type="text" name="note" id="note" maxlength="255">
            </div>
            <div class="form-group">
                <label>Opzioni</label>
                <label><input type="checkbox" name="principale" value="1" checked> principale</label>
                <label><input type="checkbox" name="verificato" value="1" checked> verificato</label>
                <label><input type="checkbox" name="attivo" value="1" checked> attivo</label>
            </div>
        </div>
        <div class="actions"><button type="submit" class="btn btn-primary"><i class="la la-save" aria-hidden="true"></i> Salva recapito</button></div>
    </form>
    <?php endif; ?>
</div>

<div class="card card-wide">
    <h2>Recapiti registrati</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Utente</th>
                <th>Tipo</th>
                <th>Valore</th>
                <th>Opzioni</th>
                <th>Note</th>
                <th>Azioni</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recapiti as $r): ?>
                <?php
                $nome = trim((string)$r['nominativo']) !== '' ? (string)$r['nominativo'] : (string)$r['username'];
                $isAttivo = (int)$r['attivo'] === 1;
                ?>
                <tr>
                    <form method="post" action="recapiti_utenti.php">
                        <td>
                            <strong><?= h($nome) ?></strong><br>
                            <span class="meta"><?= h((string)$r['username']) ?></span>
                            <input type="hidden" name="azione" value="salva_recapito">
                            <input type="hidden" name="id_recapito_utente" value="<?= (int)$r['id_recapito_utente'] ?>">
                            <input type="hidden" name="id_utente" value="<?= (int)$r['id_utente'] ?>">
                            <input type="hidden" name="id_tipo_recapito" value="<?= (int)$r['id_tipo_recapito'] ?>">
                        </td>
                        <td><?= h((string)$r['tipo_descrizione']) ?></td>
                        <td><input type="text" name="valore" value="<?= h((string)$r['valore']) ?>" maxlength="255" <?= $puoScrivere ? '' : 'readonly' ?>></td>
                        <td>
                            <div class="hr-flag-list">
                                <label><input type="checkbox" name="principale" value="1" <?= (int)$r['principale'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> principale</label>
                                <label><input type="checkbox" name="verificato" value="1" <?= (int)$r['verificato'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> verificato</label>
                                <label><input type="checkbox" name="attivo" value="1" <?= $isAttivo ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> attivo</label>
                            </div>
                        </td>
                        <td><input type="text" name="note" value="<?= h((string)($r['note'] ?? '')) ?>" maxlength="255" <?= $puoScrivere ? '' : 'readonly' ?>></td>
                        <td>
                            <?php if ($puoScrivere): ?>
                                <button type="submit" class="btn btn-sm btn-primary"><i class="la la-save" aria-hidden="true"></i> Salva</button>
                                <?php if ($isAttivo): ?>
                                    <button type="submit" class="btn btn-sm btn-outline-primary" name="azione" value="disattiva_recapito" onclick="return confirm('Disattivare questo recapito?');"><i class="la la-times" aria-hidden="true"></i> Disattiva</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="meta">-</span>
                            <?php endif; ?>
                        </td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php layoutFooter(); ?>
