<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('ordini_fornitori_aperti');

$errore = '';
$messaggio = '';
$ordini = [];
$ultimeNote = [];
$fornitori = [];
$fornitoreSelezionato = trim((string)($_GET['fornitore'] ?? ''));
$dataAggiornamento = 'N/D';
$esitoAggiornamento = 'N/D';
$righeImportate = null;
$messaggioSync = '';
$contattoFornitore = null;

$puoScrivere = haPermessoScrittura('ordini_fornitori_aperti');
$usernameLoggato = trim((string)($_SESSION['username'] ?? ''));
if ($usernameLoggato === '') {
    $usernameLoggato = 'sconosciuto';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            http_response_code(403);
            die('Accesso negato.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'salva_note') {
            $nOrdine = trim((string)($_POST['n_ordine'] ?? ''));
            $nRiga = trim((string)($_POST['n_riga'] ?? ''));
            $fornitorePost = trim((string)($_POST['fornitore'] ?? ''));
            $articoloPost = trim((string)($_POST['articolo'] ?? ''));
            $notaRavioli = trim((string)($_POST['nota_ravioli'] ?? ''));
            $notaFornitore = trim((string)($_POST['nota_fornitore'] ?? ''));

            if ($nOrdine === '' || $nRiga === '') {
                throw new RuntimeException('Ordine o riga mancanti.');
            }

            $stmtEsistente = $pdo->prepare("
                SELECT nota_ravioli, nota_fornitore
                FROM ordini_fornitori_note
                WHERE n_ordine = :n_ordine
                  AND n_riga = :n_riga
                LIMIT 1
            ");
            $stmtEsistente->execute([
                'n_ordine' => $nOrdine,
                'n_riga' => $nRiga,
            ]);
            $esistente = $stmtEsistente->fetch(PDO::FETCH_ASSOC);

            $notaRavioliAttuale = trim((string)($esistente['nota_ravioli'] ?? ''));
            $notaFornitoreAttuale = trim((string)($esistente['nota_fornitore'] ?? ''));

            $modificaReale =
                ($notaRavioli !== $notaRavioliAttuale) ||
                ($notaFornitore !== $notaFornitoreAttuale);

            if ($modificaReale) {
                $sqlUpsert = "
                    INSERT INTO ordini_fornitori_note (
                        n_ordine,
                        n_riga,
                        nota_ravioli,
                        nota_fornitore,
                        data_evento,
                        utente_modifica,
                        origine,
                        importante
                    ) VALUES (
                        :n_ordine,
                        :n_riga,
                        :nota_ravioli,
                        :nota_fornitore,
                        NOW(),
                        :utente_modifica,
                        'web',
                        0
                    )
                    ON DUPLICATE KEY UPDATE
                        nota_ravioli = VALUES(nota_ravioli),
                        nota_fornitore = VALUES(nota_fornitore),
                        data_evento = NOW(),
                        utente_modifica = VALUES(utente_modifica),
                        origine = 'web'
                ";

                $stmtUpsert = $pdo->prepare($sqlUpsert);
                $stmtUpsert->execute([
                    'n_ordine' => $nOrdine,
                    'n_riga' => $nRiga,
                    'nota_ravioli' => $notaRavioli !== '' ? $notaRavioli : null,
                    'nota_fornitore' => $notaFornitore !== '' ? $notaFornitore : null,
                    'utente_modifica' => $usernameLoggato,
                ]);

                $sqlStorico = "
                    INSERT INTO ordini_fornitori_note_storico (
                        n_ordine,
                        n_riga,
                        fornitore,
                        articolo,
                        nota_ravioli,
                        nota_fornitore,
                        data_evento,
                        utente_modifica,
                        importato_locale,
                        data_import_locale,
                        origine,
                        importante
                    ) VALUES (
                        :n_ordine,
                        :n_riga,
                        :fornitore,
                        :articolo,
                        :nota_ravioli,
                        :nota_fornitore,
                        NOW(),
                        :utente_modifica,
                        0,
                        NULL,
                        'web',
                        0
                    )
                ";

                $stmtStorico = $pdo->prepare($sqlStorico);
                $stmtStorico->execute([
                    'n_ordine' => $nOrdine,
                    'n_riga' => $nRiga,
                    'fornitore' => $fornitorePost !== '' ? $fornitorePost : null,
                    'articolo' => $articoloPost !== '' ? $articoloPost : null,
                    'nota_ravioli' => $notaRavioli !== '' ? $notaRavioli : null,
                    'nota_fornitore' => $notaFornitore !== '' ? $notaFornitore : null,
                    'utente_modifica' => $usernameLoggato,
                ]);

                $query = [];
                if ($fornitoreSelezionato !== '') {
                    $query['fornitore'] = $fornitoreSelezionato;
                }
                $query['ok'] = '1';

                header('Location: ordini_fornitori_aperti.php?' . http_build_query($query));
                exit;
            }

            $query = [];
            if ($fornitoreSelezionato !== '') {
                $query['fornitore'] = $fornitoreSelezionato;
            }
            $query['nomod'] = '1';

            header('Location: ordini_fornitori_aperti.php?' . http_build_query($query));
            exit;
        }
    }

    if (isset($_GET['ok']) && $_GET['ok'] === '1') {
        $messaggio = 'Note salvate correttamente.';
    } elseif (isset($_GET['nomod']) && $_GET['nomod'] === '1') {
        $messaggio = 'Nessuna modifica da salvare.';
    }

    $stmtFornitori = $pdo->query("
        SELECT DISTINCT fornitore
        FROM ordini_fornitori_aperti
        WHERE fornitore IS NOT NULL
          AND fornitore <> ''
        ORDER BY fornitore
    ");
    $fornitori = $stmtFornitori->fetchAll(PDO::FETCH_COLUMN);

    try {
        $stmtSync = $pdo->prepare("
            SELECT ultimo_aggiornamento, esito, righe_importate, messaggio
            FROM sync_stato
            WHERE modulo = :modulo
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtSync->execute(['modulo' => 'ordini_fornitori_aperti']);
        $sync = $stmtSync->fetch(PDO::FETCH_ASSOC);

        if ($sync) {
            $dataAggiornamento = (string)($sync['ultimo_aggiornamento'] ?? 'N/D');
            $esitoAggiornamento = (string)($sync['esito'] ?? 'N/D');
            $righeImportate = $sync['righe_importate'] !== null ? (int)$sync['righe_importate'] : null;
            $messaggioSync = (string)($sync['messaggio'] ?? '');
        }
    } catch (Throwable $e) {
        // non blocco la pagina
    }

    if ($fornitoreSelezionato !== '') {
        $stmtContatto = $pdo->prepare("
            SELECT fornitore, telefono, email, indirizzo
            FROM fornitori_contatti
            WHERE TRIM(fornitore) = TRIM(:fornitore)
            LIMIT 1
        ");
        $stmtContatto->execute(['fornitore' => $fornitoreSelezionato]);
        $contattoFornitore = $stmtContatto->fetch(PDO::FETCH_ASSOC) ?: null;

        $sqlOrdini = "
            SELECT
                o.n_ordine,
                o.n_riga,
                o.data_consegna,
                o.articolo,
                o.descrizione,
                o.q_ordinata,
                o.q_residua,
                o.nota,
                o.progr_1,
                o.progr_2,
                o.ultimo_sollecito_mail,
                n.nota_ravioli,
                n.nota_fornitore,
                n.data_evento,
                n.utente_modifica,
                n.origine,
                o.fornitore
            FROM ordini_fornitori_aperti o
            LEFT JOIN ordini_fornitori_note n
                ON n.n_ordine = o.n_ordine
               AND n.n_riga = o.n_riga
            WHERE o.q_residua > 0
              AND o.fornitore = :fornitore
            ORDER BY
                CASE
                    WHEN o.progr_1 = 1 AND o.ultimo_sollecito_mail IS NOT NULL AND o.ultimo_sollecito_mail <> '9999-12-31' THEN 0
                    WHEN o.progr_1 = 1 THEN 1
                    WHEN o.progr_1 = 2 AND o.ultimo_sollecito_mail IS NOT NULL AND o.ultimo_sollecito_mail <> '9999-12-31' THEN 2
                    WHEN o.progr_1 = 2 THEN 3
                    ELSE 4
                END ASC,
                o.progr_2 DESC,
                o.data_consegna ASC,
                o.n_ordine ASC,
                o.n_riga ASC
        ";

        $stmtOrdini = $pdo->prepare($sqlOrdini);
        $stmtOrdini->execute(['fornitore' => $fornitoreSelezionato]);
        $ordini = $stmtOrdini->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sqlUltimeNote = "
            SELECT
                n.n_ordine,
                n.n_riga,
                n.nota_ravioli,
                n.nota_fornitore,
                n.data_evento,
                n.utente_modifica,
                n.origine,
                o.fornitore,
                o.articolo
            FROM ordini_fornitori_note n
            INNER JOIN ordini_fornitori_aperti o
                ON o.n_ordine = n.n_ordine
               AND o.n_riga = n.n_riga
            ORDER BY n.data_evento DESC
            LIMIT 30
        ";

        $stmtUltimeNote = $pdo->query($sqlUltimeNote);
        $ultimeNote = $stmtUltimeNote->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $errore = 'Errore nel caricamento della pagina ordini fornitori aperti: ' . $e->getMessage();
}

layoutHeader('Ordini fornitori aperti');
?>

<style>
    .ofa-wrap table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .ofa-wrap th,
    .ofa-wrap td {
        border: 1px solid #d7dbe1;
        padding: 8px 10px;
        vertical-align: top;
    }

    .ofa-wrap th {
        background: #f1f3f5;
        text-align: left;
        font-weight: 700;
        color: #111827;
    }

    .ofa-red {
        color: #c00000;
        font-weight: 600;
    }

    .ofa-green {
        color: #0a6a0a;
        font-weight: 600;
    }

    .ofa-note-input {
        width: 100%;
        box-sizing: border-box;
        padding: 6px 8px;
        height: 32px;
        border: 1px solid #bfc7d1;
        border-radius: 3px;
        font-size: 14px;
    }

    .ofa-mini {
        font-size: 12px;
        color: #6b7280;
        margin-top: 4px;
        line-height: 1.35;
    }

    .ofa-contact-box {
        border: 1px solid #d7dbe1;
        padding: 12px 14px;
        margin: 14px 0 18px 0;
        background: #fff;
    }

    .ofa-toolbar {
        margin-bottom: 18px;
    }

    .ofa-toolbar form {
        margin: 0;
    }

    .ofa-toolbar-row {
        display: flex;
        align-items: flex-end;
        gap: 12px;
        flex-wrap: wrap;
    }

    .ofa-toolbar-row .form-group {
        margin: 0;
        min-width: 320px;
    }

    .ofa-toolbar-row .form-group label {
        display: block;
        margin-bottom: 6px;
        font-weight: 700;
    }

    .ofa-toolbar-row select {
        min-width: 320px;
    }

    .ofa-toolbar-row .btn-inline {
        margin-top: 0;
        align-self: flex-end;
    }
</style>

<div class="card card-wide ofa-wrap">
    <h1>Ordini fornitori aperti</h1>

    <div class="meta" style="margin-bottom:12px;">
        <strong>Ultimo aggiornamento:</strong> <?= htmlspecialchars($dataAggiornamento) ?>
        &nbsp; | &nbsp;
        <strong>Esito:</strong> <?= htmlspecialchars($esitoAggiornamento) ?>
        <?php if ($righeImportate !== null): ?>
            &nbsp; | &nbsp;
            <strong>Righe importate:</strong> <?= $righeImportate ?>
        <?php endif; ?>
    </div>

    <?php if ($messaggioSync !== ''): ?>
        <div class="meta meta-msg" style="margin-bottom:18px;">
            <strong>Messaggio:</strong> <?= htmlspecialchars($messaggioSync) ?>
        </div>
    <?php endif; ?>

    <?php if ($messaggio !== ''): ?>
        <div class="successo" style="margin-bottom:18px;">
            <?= htmlspecialchars($messaggio) ?>
        </div>
    <?php endif; ?>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php else: ?>

        <div class="ofa-toolbar">
            <form method="get" id="formFornitore">
                <div class="ofa-toolbar-row">
                    <div class="form-group">
                        <label for="fornitore">Fornitore</label>
                        <select name="fornitore" id="fornitore" onchange="document.getElementById('formFornitore').submit();">
                            <option value="">-- seleziona fornitore --</option>
                            <?php foreach ($fornitori as $fornitore): ?>
                                <option value="<?= htmlspecialchars((string)$fornitore) ?>" <?= $fornitore === $fornitoreSelezionato ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$fornitore) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <a href="ordini_fornitori_aperti.php" class="btn btn-inline" style="text-decoration:none;">Pagina iniziale</a>
                </div>
            </form>
        </div>

        <?php if ($fornitoreSelezionato === ''): ?>

            <h2 style="margin-top:0;">Ultime 30 note inserite</h2>

            <?php if (count($ultimeNote) === 0): ?>
                <div class="meta">Nessuna nota disponibile.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Fornitore</th>
                            <th>Articolo</th>
                            <th>N. Ordine</th>
                            <th>Riga</th>
                            <th>Nota Ravioli</th>
                            <th>Nota Fornitore</th>
                            <th>Data evento</th>
                            <th>Utente</th>
                            <th>Origine</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimeNote as $r): ?>
                            <tr>
                                <td>
                                    <a href="ordini_fornitori_aperti.php?fornitore=<?= urlencode((string)$r['fornitore']) ?>">
                                        <?= htmlspecialchars((string)$r['fornitore']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars((string)($r['articolo'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)$r['n_ordine']) ?></td>
                                <td><?= htmlspecialchars((string)$r['n_riga']) ?></td>
                                <td><?= htmlspecialchars((string)($r['nota_ravioli'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['nota_fornitore'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['data_evento'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['utente_modifica'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($r['origine'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php else: ?>

            <div class="meta" style="margin-bottom:14px;">
                <strong>Fornitore selezionato:</strong> <?= htmlspecialchars($fornitoreSelezionato) ?>
            </div>

            <?php if (!empty($contattoFornitore)): ?>
                <div class="ofa-contact-box">
                    <?php if (!empty($contattoFornitore['telefono'])): ?>
                        <div><strong>Telefono:</strong> <?= htmlspecialchars((string)$contattoFornitore['telefono']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($contattoFornitore['email'])): ?>
                        <div><strong>Email:</strong> <?= htmlspecialchars((string)$contattoFornitore['email']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($contattoFornitore['indirizzo'])): ?>
                        <div><strong>Indirizzo:</strong> <?= htmlspecialchars((string)$contattoFornitore['indirizzo']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (count($ordini) === 0): ?>
                <div class="meta">Nessun ordine aperto trovato per il fornitore selezionato.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>N. Ordine</th>
                            <th>Riga</th>
                            <th>Data Consegna</th>
                            <th>Articolo</th>
                            <th>Descrizione</th>
                            <th>Q. Ordinata</th>
                            <th>Q. Residua</th>
                            <th>Nota</th>
                            <th>Nota Ravioli</th>
                            <th>Nota Fornitore</th>
                            <th>Salva</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ordini as $row): ?>
                            <?php
                            $classeColore = '';
                            if ((int)$row['progr_1'] === 1) {
                                $classeColore = 'ofa-red';
                            } elseif ((int)$row['progr_1'] === 2) {
                                $classeColore = 'ofa-green';
                            }
                            ?>
                            <tr>
                                <td class="<?= $classeColore ?>"><?= htmlspecialchars((string)$row['n_ordine']) ?></td>
                                <td class="<?= $classeColore ?>"><?= htmlspecialchars((string)$row['n_riga']) ?></td>
                                <td class="<?= $classeColore ?>"><?= htmlspecialchars((string)$row['data_consegna']) ?></td>
                                <td class="<?= $classeColore ?>"><?= htmlspecialchars((string)$row['articolo']) ?></td>
                                <td class="<?= $classeColore ?>"><?= htmlspecialchars((string)$row['descrizione']) ?></td>
                                <td class="<?= $classeColore ?>" style="text-align:right;"><?= number_format((float)$row['q_ordinata'], 0, ',', '.') ?></td>
                                <td class="<?= $classeColore ?>" style="text-align:right;"><?= number_format((float)$row['q_residua'], 0, ',', '.') ?></td>
                                <td class="<?= $classeColore ?>"><?= htmlspecialchars((string)($row['nota'] ?? '')) ?></td>

                                <form method="post">
                                    <input type="hidden" name="azione" value="salva_note">
                                    <input type="hidden" name="n_ordine" value="<?= htmlspecialchars((string)$row['n_ordine']) ?>">
                                    <input type="hidden" name="n_riga" value="<?= htmlspecialchars((string)$row['n_riga']) ?>">
                                    <input type="hidden" name="fornitore" value="<?= htmlspecialchars((string)($row['fornitore'] ?? '')) ?>">
                                    <input type="hidden" name="articolo" value="<?= htmlspecialchars((string)($row['articolo'] ?? '')) ?>">

                                    <td>
                                        <input
                                            type="text"
                                            name="nota_ravioli"
                                            value="<?= htmlspecialchars((string)($row['nota_ravioli'] ?? '')) ?>"
                                            class="ofa-note-input"
                                        >
                                    </td>

                                    <td>
                                        <input
                                            type="text"
                                            name="nota_fornitore"
                                            value="<?= htmlspecialchars((string)($row['nota_fornitore'] ?? '')) ?>"
                                            class="ofa-note-input"
                                        >
                                        <div class="ofa-mini">
                                            <?= !empty($row['data_evento']) ? 'Ultimo evento: ' . htmlspecialchars((string)$row['data_evento']) : '' ?>
                                            <?php if (!empty($row['utente_modifica'])): ?>
                                                <br>Utente: <?= htmlspecialchars((string)$row['utente_modifica']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td style="white-space:nowrap;">
                                        <?php if ($puoScrivere): ?>
                                            <button type="submit">Salva</button>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>
</div>