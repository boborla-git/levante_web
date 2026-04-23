<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('configurazione_assenze');

$pdo = db();
$puoScrivere = haPermessoScrittura('configurazione_assenze');
$errore = '';
$messaggio = '';
$utenti = [];
$gruppi = [];
$appartenenze = [];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'nuovo_gruppo') {
            $codice = strtoupper(trim((string)($_POST['codice'] ?? '')));
            $nome = trim((string)($_POST['nome'] ?? ''));
            $descrizione = trim((string)($_POST['descrizione'] ?? ''));
            if ($codice === '' || $nome === '') {
                throw new RuntimeException('Codice e nome gruppo sono obbligatori.');
            }
            $stmt = $pdo->prepare('INSERT INTO hr_gruppi_lavoro (codice, nome, descrizione, attivo) VALUES (:codice, :nome, :descrizione, 1)');
            $stmt->execute([
                'codice' => $codice,
                'nome' => $nome,
                'descrizione' => $descrizione !== '' ? $descrizione : null,
            ]);
            header('Location: gruppi_lavoro.php?ok_gruppo=1');
            exit;
        }

        if ($azione === 'nuova_appartenenza') {
            $idGruppo = (int)($_POST['id_gruppo_lavoro'] ?? 0);
            $idUtente = (int)($_POST['id_utente'] ?? 0);
            $ruolo = trim((string)($_POST['ruolo_nel_gruppo'] ?? ''));
            $dataInizio = trim((string)($_POST['data_inizio'] ?? ''));
            $dataFine = trim((string)($_POST['data_fine'] ?? ''));
            if ($idGruppo <= 0 || $idUtente <= 0 || $dataInizio === '') {
                throw new RuntimeException('Compila tutti i campi obbligatori per l’appartenenza.');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO hr_gruppi_utenti (id_gruppo_lavoro, id_utente, ruolo_nel_gruppo, data_inizio, data_fine, attivo)
                 VALUES (:id_gruppo_lavoro, :id_utente, :ruolo_nel_gruppo, :data_inizio, :data_fine, 1)'
            );
            $stmt->execute([
                'id_gruppo_lavoro' => $idGruppo,
                'id_utente' => $idUtente,
                'ruolo_nel_gruppo' => $ruolo !== '' ? $ruolo : null,
                'data_inizio' => $dataInizio,
                'data_fine' => $dataFine !== '' ? $dataFine : null,
            ]);
            header('Location: gruppi_lavoro.php?ok_membro=1');
            exit;
        }

        if ($azione === 'disattiva_appartenenza') {
            $id = (int)($_POST['id_gruppo_utente'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Appartenenza non valida.');
            }
            $stmt = $pdo->prepare('UPDATE hr_gruppi_utenti SET attivo = 0, data_fine = COALESCE(data_fine, CURDATE()) WHERE id_gruppo_utente = :id');
            $stmt->execute(['id' => $id]);
            header('Location: gruppi_lavoro.php?chiusa=1');
            exit;
        }
    }

    if (isset($_GET['ok_gruppo'])) {
        $messaggio = 'Gruppo creato correttamente.';
    } elseif (isset($_GET['ok_membro'])) {
        $messaggio = 'Appartenenza salvata correttamente.';
    } elseif (isset($_GET['chiusa'])) {
        $messaggio = 'Appartenenza chiusa correttamente.';
    }

    $utenti = $pdo->query(
        "SELECT id_utente, username, CONCAT(COALESCE(nome,''), ' ', COALESCE(cognome,'')) AS nominativo
         FROM aut_utenti
         WHERE attivo = 1
         ORDER BY nominativo, username"
    )->fetchAll();

    $gruppi = $pdo->query('SELECT * FROM hr_gruppi_lavoro ORDER BY attivo DESC, nome')->fetchAll();

    $appartenenze = $pdo->query(
        "SELECT gu.*, gl.nome AS gruppo_nome, gl.codice AS gruppo_codice,
                CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,''), ' (', u.username, ')') AS utente
         FROM hr_gruppi_utenti gu
         INNER JOIN hr_gruppi_lavoro gl ON gl.id_gruppo_lavoro = gu.id_gruppo_lavoro
         INNER JOIN aut_utenti u ON u.id_utente = gu.id_utente
         ORDER BY gu.attivo DESC, gl.nome, u.cognome, u.nome"
    )->fetchAll();
} catch (Throwable $e) {
    $errore = $e->getMessage();
}

layoutHeader('Gruppi di lavoro');
?>
<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Gruppi di lavoro</h1>
            <div class="meta">Crea i team operativi e assegna gli utenti ai gruppi per il calendario condiviso. Il gruppo è una relazione tra pari: l'etichetta nel gruppo è solo informativa.</div>
        </div>
        <div class="section-head-actions"><a class="btn btn-light" href="configurazione_assenze.php">Torna alla configurazione</a></div>
    </div>
</div>

<?php if ($errore !== ''): ?><div class="errore"><?= h($errore) ?></div><?php endif; ?>
<?php if ($messaggio !== ''): ?><div class="ok"><?= h($messaggio) ?></div><?php endif; ?>

<div class="card card-form">
    <h2>Nuovo gruppo</h2>
    <form method="post" action="gruppi_lavoro.php">
        <input type="hidden" name="azione" value="nuovo_gruppo">
        <div class="hr-admin-grid">
            <div class="form-group"><label for="codice">Codice</label><input type="text" name="codice" id="codice" maxlength="50" required></div>
            <div class="form-group hr-col-span-2"><label for="nome">Nome gruppo</label><input type="text" name="nome" id="nome" maxlength="100" required></div>
            <div class="form-group hr-col-span-3"><label for="descrizione">Descrizione</label><input type="text" name="descrizione" id="descrizione" maxlength="255"></div>
        </div>
        <div class="actions"><button type="submit">Salva gruppo</button></div>
    </form>
</div>

<div class="card card-form">
    <style>.group-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#eef8ff;color:#0b7285;border:1px solid #c5e8ef;font-weight:700;margin-right:6px;}</style>
    <h2>Nuova appartenenza</h2>
    <div class="meta">Nel gruppo non introduci gerarchie: l'eventuale etichetta serve solo a descrivere il contesto operativo.</div>
    <form method="post" action="gruppi_lavoro.php">
        <input type="hidden" name="azione" value="nuova_appartenenza">
        <div class="hr-admin-grid">
            <div class="form-group">
                <label for="id_gruppo_lavoro">Gruppo</label>
                <select name="id_gruppo_lavoro" id="id_gruppo_lavoro" required>
                    <option value="">Seleziona...</option>
                    <?php foreach ($gruppi as $g): if ((int)$g['attivo'] !== 1) continue; ?>
                        <option value="<?= (int)$g['id_gruppo_lavoro'] ?>"><?= h((string)$g['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="id_utente">Utente</label>
                <select name="id_utente" id="id_utente" required>
                    <option value="">Seleziona...</option>
                    <?php foreach ($utenti as $u): ?>
                        <option value="<?= (int)$u['id_utente'] ?>"><?= h(trim((string)$u['nominativo']) !== '' ? (string)$u['nominativo'] : (string)$u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label for="ruolo_nel_gruppo">Etichetta nel gruppo</label><input type="text" name="ruolo_nel_gruppo" id="ruolo_nel_gruppo" maxlength="50"></div>
            <div class="form-group"><label for="data_inizio">Data inizio</label><input type="date" name="data_inizio" id="data_inizio" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group"><label for="data_fine">Data fine</label><input type="date" name="data_fine" id="data_fine"></div>
        </div>
        <div class="actions"><button type="submit">Salva appartenenza</button></div>
    </form>
</div>

<div class="card card-wide">
    <h2>Gruppi censiti</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Codice</th><th>Nome</th><th>Descrizione</th><th>Stato</th></tr></thead>
            <tbody>
            <?php foreach ($gruppi as $g): ?>
                <tr>
                    <td><strong><?= h((string)$g['codice']) ?></strong></td>
                    <td><?= h((string)$g['nome']) ?></td>
                    <td><?= h((string)$g['descrizione']) ?></td>
                    <td><span class="status-badge <?= (int)$g['attivo'] === 1 ? 'status-ok' : 'status-neutral' ?>"><?= (int)$g['attivo'] === 1 ? 'Attivo' : 'Disattivo' ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card card-wide">
    <h2>Appartenenze ai gruppi</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Gruppo</th><th>Utente</th><th>Etichetta</th><th>Periodo</th><th>Stato</th><th>Azioni</th></tr></thead>
            <tbody>
            <?php foreach ($appartenenze as $a): ?>
                <tr>
                    <td><span class="group-icon">⇄</span><strong><?= h((string)$a['gruppo_nome']) ?></strong><br><span class="meta"><?= h((string)$a['gruppo_codice']) ?></span></td>
                    <td><?= h((string)$a['utente']) ?></td>
                    <td><?= h((string)$a['ruolo_nel_gruppo']) ?></td>
                    <td><?= h((string)$a['data_inizio']) ?><?= $a['data_fine'] ? ' → ' . h((string)$a['data_fine']) : '' ?></td>
                    <td><span class="status-badge <?= (int)$a['attivo'] === 1 ? 'status-ok' : 'status-neutral' ?>"><?= (int)$a['attivo'] === 1 ? 'Attiva' : 'Chiusa' ?></span></td>
                    <td>
                        <?php if ($puoScrivere && (int)$a['attivo'] === 1): ?>
                            <form method="post" action="gruppi_lavoro.php" onsubmit="return confirm('Chiudere questa appartenenza?');">
                                <input type="hidden" name="azione" value="disattiva_appartenenza">
                                <input type="hidden" name="id_gruppo_utente" value="<?= (int)$a['id_gruppo_utente'] ?>">
                                <button type="submit" class="btn-light">Chiudi</button>
                            </form>
                        <?php else: ?><span class="meta">-</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layoutFooter(); ?>
