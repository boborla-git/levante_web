<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('configurazione_assenze');

$pdo = db();
$puoScrivere = haPermessoScrittura('configurazione_assenze');
$errore = '';
$messaggio = '';
$utenti = [];
$gruppi = [];
$appartenenze = [];
$membriPerGruppo = [];
$riepilogo = [
    'gruppi_totali' => 0,
    'gruppi_attivi' => 0,
    'membri_attivi' => 0,
    'membri_test' => 0,
];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrTeamLabelUtente(array $row, string $prefix = ''): string
{
    $nome = trim((string)($row[$prefix . 'nome'] ?? ''));
    $cognome = trim((string)($row[$prefix . 'cognome'] ?? ''));
    $username = trim((string)($row[$prefix . 'username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);

    if ($nominativo !== '') {
        return $nominativo;
    }

    return $username;
}

function hrTeamIsTestUsername(?string $username): bool
{
    return strpos((string)$username, 'test_') === 0;
}

function hrTeamBadge(string $label, string $type = 'soft'): string
{
    $class = 'hr-team-badge';
    if ($type !== '') {
        $class .= ' hr-team-badge-' . preg_replace('/[^a-z0-9_-]/i', '', $type);
    }
    return '<span class="' . h($class) . '">' . h($label) . '</span>';
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
                throw new RuntimeException('Codice e nome team sono obbligatori.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO hr_gruppi_lavoro (codice, nome, descrizione, attivo)
                 VALUES (:codice, :nome, :descrizione, 1)'
            );
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
            if ($dataFine !== '' && $dataFine < $dataInizio) {
                throw new RuntimeException('La data fine non può precedere la data inizio.');
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

            $stmt = $pdo->prepare(
                'UPDATE hr_gruppi_utenti
                 SET attivo = 0,
                     data_fine = COALESCE(data_fine, CURDATE())
                 WHERE id_gruppo_utente = :id'
            );
            $stmt->execute(['id' => $id]);

            header('Location: gruppi_lavoro.php?chiusa=1');
            exit;
        }
    }

    if (isset($_GET['ok_gruppo'])) {
        $messaggio = 'Team creato correttamente.';
    } elseif (isset($_GET['ok_membro'])) {
        $messaggio = 'Appartenenza salvata correttamente.';
    } elseif (isset($_GET['chiusa'])) {
        $messaggio = 'Appartenenza chiusa correttamente.';
    }

    $utenti = $pdo->query(
        "SELECT id_utente, username, nome, cognome,
                CONCAT(COALESCE(nome,''), ' ', COALESCE(cognome,'')) AS nominativo
         FROM aut_utenti
         WHERE attivo = 1
         ORDER BY cognome, nome, username"
    )->fetchAll(PDO::FETCH_ASSOC);

    $gruppi = $pdo->query(
        'SELECT gl.*,
                COUNT(CASE WHEN gu.attivo = 1 AND (gu.data_fine IS NULL OR gu.data_fine >= CURDATE()) THEN 1 END) AS membri_attivi,
                COUNT(CASE WHEN gu.attivo = 0 OR (gu.data_fine IS NOT NULL AND gu.data_fine < CURDATE()) THEN 1 END) AS membri_chiusi
         FROM hr_gruppi_lavoro gl
         LEFT JOIN hr_gruppi_utenti gu ON gu.id_gruppo_lavoro = gl.id_gruppo_lavoro
         GROUP BY gl.id_gruppo_lavoro, gl.codice, gl.nome, gl.descrizione, gl.attivo
         ORDER BY gl.attivo DESC, gl.nome'
    )->fetchAll(PDO::FETCH_ASSOC);

    $appartenenze = $pdo->query(
        "SELECT gu.*, gl.nome AS gruppo_nome, gl.codice AS gruppo_codice,
                u.username, u.nome, u.cognome,
                CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,''), ' (', u.username, ')') AS utente
         FROM hr_gruppi_utenti gu
         INNER JOIN hr_gruppi_lavoro gl ON gl.id_gruppo_lavoro = gu.id_gruppo_lavoro
         INNER JOIN aut_utenti u ON u.id_utente = gu.id_utente
         ORDER BY gu.attivo DESC, gl.nome, u.cognome, u.nome, u.username"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($gruppi as $gruppo) {
        $riepilogo['gruppi_totali']++;
        if ((int)$gruppo['attivo'] === 1) {
            $riepilogo['gruppi_attivi']++;
        }
        $membriPerGruppo[(int)$gruppo['id_gruppo_lavoro']] = [];
    }

    foreach ($appartenenze as $appartenenza) {
        $idGruppo = (int)$appartenenza['id_gruppo_lavoro'];
        if (!isset($membriPerGruppo[$idGruppo])) {
            $membriPerGruppo[$idGruppo] = [];
        }
        $membriPerGruppo[$idGruppo][] = $appartenenza;

        $attiva = (int)$appartenenza['attivo'] === 1 &&
            ($appartenenza['data_fine'] === null || (string)$appartenenza['data_fine'] >= date('Y-m-d'));
        if ($attiva) {
            $riepilogo['membri_attivi']++;
            if (hrTeamIsTestUsername((string)$appartenenza['username'])) {
                $riepilogo['membri_test']++;
            }
        }
    }
} catch (Throwable $e) {
    $errore = $e->getMessage();
}

layoutHeader('Team e gruppi di lavoro');
?>
<link rel="stylesheet" href="/assets/hr.css">


<div class="card card-compact hr-team-hero">
    <div class="section-head">
        <div>
            <h1>Team e gruppi di lavoro</h1>
            <div class="meta">Vista organizzativa dei team operativi: collaborazione, appartenenze e calendario condiviso. I team non sostituiscono la gerarchia responsabile-collaboratore.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="configurazione_assenze.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla configurazione</a>
        </div>
    </div>
</div>

<section class="hr-team-summary">
    <span><strong><?= (int)$riepilogo['gruppi_totali'] ?></strong> team censiti</span>
    <span><strong><?= (int)$riepilogo['gruppi_attivi'] ?></strong> team attivi</span>
    <span><strong><?= (int)$riepilogo['membri_attivi'] ?></strong> appartenenze attive</span>
    <span><strong><?= (int)$riepilogo['membri_test'] ?></strong> appartenenze test</span>
</section>

<?php if ($errore !== ''): ?><div class="alert alert-error"><?= h($errore) ?></div><?php endif; ?>
<?php if ($messaggio !== ''): ?><div class="alert alert-success"><?= h($messaggio) ?></div><?php endif; ?>

<div class="hr-team-stack">
    <section class="card card-form">
        <h2>Nuovo team</h2>
        <div class="meta">Crea un contenitore operativo. La responsabilità gerarchica resta gestita nella pagina Relazioni organizzative.</div>
        <?php if (!$puoScrivere): ?>
            <div class="info-box">Il tuo profilo può consultare ma non modificare i team.</div>
        <?php else: ?>
            <form method="post" action="gruppi_lavoro.php">
                <input type="hidden" name="azione" value="nuovo_gruppo">
                <div class="hr-team-form-grid">
                    <div class="form-group"><label for="codice">Codice</label><input type="text" name="codice" id="codice" maxlength="50" required></div>
                    <div class="form-group"><label for="nome">Nome team</label><input type="text" name="nome" id="nome" maxlength="100" required></div>
                    <div class="form-group"><label for="descrizione">Descrizione</label><input type="text" name="descrizione" id="descrizione" maxlength="255" placeholder="Esempio: gruppo operativo produzione, ufficio, progetto..."></div>
                    <div class="form-group"><label aria-hidden="true">&nbsp;</label><button type="submit" class="btn btn-primary">Salva team</button></div>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="card card-form">
        <h2>Nuova appartenenza</h2>
        <div class="meta">Aggiungi una persona a un team. L’etichetta nel gruppo è informativa, non crea una gerarchia.</div>
        <?php if ($puoScrivere): ?>
            <form method="post" action="gruppi_lavoro.php">
                <input type="hidden" name="azione" value="nuova_appartenenza">
                <div class="hr-team-membership-grid">
                    <div class="form-group">
                        <label for="id_gruppo_lavoro">Team</label>
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
                    <div class="form-group"><label for="ruolo_nel_gruppo">Etichetta nel team</label><input type="text" name="ruolo_nel_gruppo" id="ruolo_nel_gruppo" maxlength="50" placeholder="Referente, membro, supporto..."></div>
                    <div class="form-group"><label for="data_inizio">Data inizio</label><input type="date" name="data_inizio" id="data_inizio" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="form-group"><label for="data_fine">Data fine</label><input type="date" name="data_fine" id="data_fine"></div>
                    <div class="form-group"><label aria-hidden="true">&nbsp;</label><button type="submit" class="btn btn-primary">Salva appartenenza</button></div>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="card card-wide">
        <div class="hr-team-toolbar">
            <div>
                <h2>Mappa team</h2>
                <div class="meta">Vista per team: mostra membri attivi e appartenenze operative.</div>
            </div>
            <div class="form-group hr-filter-search-group">
                <label for="teamSearch">Filtro rapido</label>
                <input type="search" id="teamSearch" data-card-filter="teamCard" placeholder="Cerca team, membro, etichetta...">
            </div>
        </div>

        <div class="hr-team-grid" id="teamGrid">
            <?php foreach ($gruppi as $gruppo): ?>
                <?php
                $idGruppo = (int)$gruppo['id_gruppo_lavoro'];
                $membri = $membriPerGruppo[$idGruppo] ?? [];
                $membriAttivi = array_filter($membri, static function (array $m): bool {
                    return (int)$m['attivo'] === 1 && ($m['data_fine'] === null || (string)$m['data_fine'] >= date('Y-m-d'));
                });
                $searchText = strtolower(trim((string)$gruppo['codice'] . ' ' . (string)$gruppo['nome'] . ' ' . (string)$gruppo['descrizione']));
                foreach ($membriAttivi as $membroSearch) {
                    $searchText .= ' ' . strtolower((string)$membroSearch['utente'] . ' ' . (string)$membroSearch['ruolo_nel_gruppo']);
                }
                ?>
                <article class="hr-team-card" data-card-filter-item="teamCard" data-search-text="<?= h($searchText) ?>">
                    <div class="hr-team-card-head">
                        <div class="hr-team-card-title">
                            <strong><?= h((string)$gruppo['nome']) ?></strong>
                            <div class="hr-team-card-badges">
                                <?= hrTeamBadge((int)$gruppo['attivo'] === 1 ? 'Attivo' : 'Disattivo', (int)$gruppo['attivo'] === 1 ? 'active' : 'closed') ?>
                                <?= hrTeamBadge(count($membriAttivi) . ' membri', 'role') ?>
                            </div>
                        </div>
                        <div class="hr-team-count"><span><?= count($membriAttivi) ?></span><small>membri</small></div>
                    </div>
                    <div class="hr-team-card-body">
                        <div class="hr-team-desc"><?= trim((string)$gruppo['descrizione']) !== '' ? h((string)$gruppo['descrizione']) : 'Descrizione non indicata.' ?></div>

                        <?php if (count($membriAttivi) === 0): ?>
                            <div class="hr-team-empty">Nessun membro attivo.</div>
                        <?php else: ?>
                            <div class="hr-team-member-list">
                                <?php foreach ($membriAttivi as $membro): ?>
                                    <?php $isTest = hrTeamIsTestUsername((string)$membro['username']); ?>
                                    <div class="hr-team-member">
                                        <span class="hr-team-member-icon"><i class="la la-user-friends" aria-hidden="true"></i></span>
                                        <div class="hr-team-member-main">
                                            <strong><?= h(hrTeamLabelUtente($membro)) ?></strong>
                                            <div class="hr-team-member-tags">
                                                <?php if (trim((string)$membro['ruolo_nel_gruppo']) !== ''): ?><?= hrTeamBadge((string)$membro['ruolo_nel_gruppo'], 'role') ?><?php endif; ?>
                                                <?php if ($isTest): ?><?= hrTeamBadge('Test', 'test') ?><?php endif; ?>
                                                <?= hrTeamBadge('Dal ' . (string)$membro['data_inizio'], 'soft') ?>
                                            </div>
                                        </div>
                                        <?php if ($puoScrivere): ?>
                                            <form method="post" action="gruppi_lavoro.php" onsubmit="return confirm('Chiudere questa appartenenza?');">
                                                <input type="hidden" name="azione" value="disattiva_appartenenza">
                                                <input type="hidden" name="id_gruppo_utente" value="<?= (int)$membro['id_gruppo_utente'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="la la-times" aria-hidden="true"></i> Chiudi</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="hr-team-card-foot">
                        <span class="meta">Team operativo, non gerarchico</span>
                        <a class="btn btn-sm btn-light" href="relazioni_organizzative.php"><i class="la la-sitemap" aria-hidden="true"></i> Relazioni</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card card-wide hr-team-archive">
        <div class="hr-filter-toolbar">
            <div>
                <h2>Archivio appartenenze</h2>
                <div class="meta">Archivio completo: include appartenenze attive e chiuse.</div>
            </div>
            <div class="form-group hr-filter-search-group">
                <label for="appartenenzeSearch">Filtro rapido</label>
                <input type="search" id="appartenenzeSearch" data-table-filter="appartenenzeTable" placeholder="Cerca in tutte le colonne...">
            </div>
        </div>
        <div class="table-wrap">
            <table id="appartenenzeTable">
                <thead><tr><th>Team</th><th>Utente</th><th>Etichetta</th><th>Periodo</th><th>Stato</th><th>Azioni</th></tr></thead>
                <tbody>
                <?php foreach ($appartenenze as $a): ?>
                    <?php
                    $attiva = (int)$a['attivo'] === 1 && ($a['data_fine'] === null || (string)$a['data_fine'] >= date('Y-m-d'));
                    $isTest = hrTeamIsTestUsername((string)$a['username']);
                    ?>
                    <tr>
                        <td><span class="group-icon"><i class="la la-users" aria-hidden="true"></i></span><strong><?= h((string)$a['gruppo_nome']) ?></strong><br><span class="meta"><?= h((string)$a['gruppo_codice']) ?></span></td>
                        <td><strong><?= h(hrTeamLabelUtente($a)) ?></strong><?= $isTest ? '<br>' . hrTeamBadge('Test', 'test') : '' ?></td>
                        <td><?= h((string)$a['ruolo_nel_gruppo']) ?></td>
                        <td><?= h((string)$a['data_inizio']) ?><?= $a['data_fine'] ? ' → ' . h((string)$a['data_fine']) : '' ?></td>
                        <td><?= renderHrStatusBadge($attiva ? 'ATTIVA' : 'CHIUSA', $attiva ? 'Attiva' : 'Chiusa') ?></td>
                        <td>
                            <?php if ($puoScrivere && $attiva): ?>
                                <form method="post" action="gruppi_lavoro.php" onsubmit="return confirm('Chiudere questa appartenenza?');">
                                    <input type="hidden" name="azione" value="disattiva_appartenenza">
                                    <input type="hidden" name="id_gruppo_utente" value="<?= (int)$a['id_gruppo_utente'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="la la-times" aria-hidden="true"></i> Chiudi</button>
                                </form>
                            <?php else: ?><span class="meta">-</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script src="/assets/hr-common.js"></script>

<?php layoutFooter(); ?>
