<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('cambio_iban');

$pdo = db();
$idUtenteLoggato = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$puoScrivere = haPermessoScrittura('cambio_iban');
$puoGestire = haPermessoScrittura('hr_comunicazioni') || haPermessoLettura('configurazione_assenze');
$messaggio = '';
$errore = '';
$richieste = [];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrIbanNormalizza(string $iban): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($iban)) ?? '');
}

function hrIbanValido(string $iban): bool
{
    $iban = hrIbanNormalizza($iban);
    return preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) === 1;
}

function hrIbanFormatta(string $iban): string
{
    return trim(chunk_split(hrIbanNormalizza($iban), 4, ' '));
}

function hrIbanGeneraCodice(): string
{
    return 'IBAN-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function hrIbanStatoBadge(string $stato): string
{
    $stato = strtoupper(trim($stato));
    if ($stato === 'CHIUSA') {
        return renderHrStatusBadge('APPROVATA', 'Chiusa');
    }
    if ($stato === 'ANNULLATA') {
        return renderHrStatusBadge('ANNULLATA', 'Annullata');
    }
    if ($stato === 'PRESA_IN_CARICO') {
        return renderHrStatusBadge('IN_ATTESA', 'Presa in carico');
    }
    return renderHrStatusBadge('IN_ATTESA', 'Inviata');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'nuova_richiesta') {
            if (!$puoScrivere) {
                throw new RuntimeException('Non hai i permessi per inviare la richiesta.');
            }

            $intestatario = trim((string)($_POST['intestatario'] ?? ''));
            $iban = hrIbanNormalizza((string)($_POST['iban'] ?? ''));
            $banca = trim((string)($_POST['banca'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($intestatario === '') {
                throw new RuntimeException('Indica l’intestatario del conto.');
            }
            if (!hrIbanValido($iban)) {
                throw new RuntimeException('IBAN non valido. Controlla il codice inserito.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO hr_richieste_iban
                 (codice_richiesta, id_utente, intestatario, iban, banca, note, stato, data_richiesta)
                 VALUES
                 (:codice_richiesta, :id_utente, :intestatario, :iban, :banca, :note, :stato, NOW())'
            );
            $stmt->execute([
                'codice_richiesta' => hrIbanGeneraCodice(),
                'id_utente' => $idUtenteLoggato,
                'intestatario' => $intestatario,
                'iban' => $iban,
                'banca' => $banca !== '' ? $banca : null,
                'note' => $note !== '' ? $note : null,
                'stato' => 'INVIATA',
            ]);

            header('Location: cambio_iban.php?ok=1');
            exit;
        }

        if ($azione === 'aggiorna_stato') {
            if (!$puoGestire) {
                throw new RuntimeException('Non hai i permessi per gestire le richieste IBAN.');
            }

            $idRichiesta = (int)($_POST['id_richiesta_iban'] ?? 0);
            $stato = strtoupper(trim((string)($_POST['stato'] ?? '')));
            $noteHr = trim((string)($_POST['note_hr'] ?? ''));

            if ($idRichiesta <= 0) {
                throw new RuntimeException('Richiesta non valida.');
            }
            if (!in_array($stato, ['INVIATA', 'PRESA_IN_CARICO', 'CHIUSA', 'ANNULLATA'], true)) {
                throw new RuntimeException('Stato non valido.');
            }

            $stmt = $pdo->prepare(
                'UPDATE hr_richieste_iban
                 SET stato = :stato,
                     presa_in_carico_da = :presa_in_carico_da,
                     note_hr = :note_hr,
                     data_chiusura = CASE WHEN :stato_chiusura IN (\'CHIUSA\', \'ANNULLATA\') THEN NOW() ELSE NULL END
                 WHERE id_richiesta_iban = :id_richiesta_iban'
            );
            $stmt->execute([
                'stato' => $stato,
                'presa_in_carico_da' => $idUtenteLoggato > 0 ? $idUtenteLoggato : null,
                'note_hr' => $noteHr !== '' ? $noteHr : null,
                'stato_chiusura' => $stato,
                'id_richiesta_iban' => $idRichiesta,
            ]);

            header('Location: cambio_iban.php?gestita=1');
            exit;
        }
    }

    if (isset($_GET['ok'])) {
        $messaggio = 'Richiesta cambio IBAN inviata correttamente.';
    } elseif (isset($_GET['gestita'])) {
        $messaggio = 'Richiesta IBAN aggiornata correttamente.';
    }

    if ($puoGestire) {
        $stmt = $pdo->query(
            "SELECT ri.*,
                    u.username,
                    CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,'')) AS nominativo,
                    g.username AS gestore_username,
                    CONCAT(COALESCE(g.nome,''), ' ', COALESCE(g.cognome,'')) AS gestore_nominativo
             FROM hr_richieste_iban ri
             INNER JOIN aut_utenti u ON u.id_utente = ri.id_utente
             LEFT JOIN aut_utenti g ON g.id_utente = ri.presa_in_carico_da
             ORDER BY ri.data_richiesta DESC, ri.id_richiesta_iban DESC"
        );
        $richieste = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare(
            'SELECT ri.*,
                    NULL AS username,
                    NULL AS nominativo,
                    NULL AS gestore_username,
                    NULL AS gestore_nominativo
             FROM hr_richieste_iban ri
             WHERE ri.id_utente = :id_utente
             ORDER BY ri.data_richiesta DESC, ri.id_richiesta_iban DESC'
        );
        $stmt->execute(['id_utente' => $idUtenteLoggato]);
        $richieste = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $errore = $e->getMessage();
}

layoutHeader('Cambio IBAN');
?>

<link rel="stylesheet" href="/assets/hr.css">

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Cambio IBAN</h1>
            <div class="meta">Invio e gestione delle richieste di variazione delle coordinate bancarie.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="hr_comunicazioni.php"><i class="la la-bullhorn" aria-hidden="true"></i> HR Comunicazioni</a>
        </div>
    </div>
</div>

<?php if ($messaggio !== ''): ?><div class="alert alert-success"><?= h($messaggio) ?></div><?php endif; ?>
<?php if ($errore !== ''): ?><div class="alert alert-error"><?= h($errore) ?></div><?php endif; ?>

<?php if ($puoScrivere): ?>
    <div class="card card-compact">
        <h2>Nuova richiesta</h2>
        <div class="section-intro">Compila il modulo con le nuove coordinate. HR prenderà in carico la richiesta e aggiornerà lo stato.</div>
        <form method="post" class="hr-profile-form">
            <input type="hidden" name="azione" value="nuova_richiesta">
            <div class="hr-profile-form-grid">
                <div class="form-group hr-profile-form-wide">
                    <label for="intestatario">Intestatario conto</label>
                    <input type="text" id="intestatario" name="intestatario" maxlength="180" required>
                </div>
                <div class="form-group hr-profile-form-wide">
                    <label for="iban">Nuovo IBAN</label>
                    <input type="text" id="iban" name="iban" maxlength="40" placeholder="IT00 A0000 0000 0000 0000 0000 000" required>
                </div>
                <div class="form-group hr-profile-form-wide">
                    <label for="banca">Banca</label>
                    <input type="text" id="banca" name="banca" maxlength="180">
                </div>
            </div>
            <div class="form-group">
                <label for="note">Note</label>
                <textarea id="note" name="note" rows="3" placeholder="Eventuali note per HR"></textarea>
            </div>
            <div class="hr-profile-form-actions">
                <button type="submit" class="btn btn-primary"><i class="la la-paper-plane" aria-hidden="true"></i> Invia richiesta</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card card-wide">
    <div class="section-head">
        <div>
            <h2><?= $puoGestire ? 'Richieste cambio IBAN' : 'Le tue richieste cambio IBAN' ?></h2>
            <div class="meta"><?= count($richieste) ?> richieste trovate.</div>
        </div>
    </div>

    <?php if (!$richieste): ?>
        <div class="info-box">Non sono presenti richieste cambio IBAN.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Codice</th>
                    <?php if ($puoGestire): ?><th>Dipendente</th><?php endif; ?>
                    <th>Intestatario</th>
                    <th>IBAN</th>
                    <th>Banca</th>
                    <th>Stato</th>
                    <th>Data</th>
                    <th>Note</th>
                    <?php if ($puoGestire): ?><th>Gestione</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($richieste as $richiesta): ?>
                    <?php
                    $dipendente = trim((string)($richiesta['nominativo'] ?? ''));
                    if ($dipendente === '') {
                        $dipendente = trim((string)($richiesta['username'] ?? ''));
                    }
                    ?>
                    <tr>
                        <td><strong><?= h((string)$richiesta['codice_richiesta']) ?></strong></td>
                        <?php if ($puoGestire): ?><td><?= h($dipendente) ?></td><?php endif; ?>
                        <td><?= h((string)$richiesta['intestatario']) ?></td>
                        <td><?= h(hrIbanFormatta((string)$richiesta['iban'])) ?></td>
                        <td><?= h((string)($richiesta['banca'] ?? '')) ?></td>
                        <td><?= hrIbanStatoBadge((string)$richiesta['stato']) ?></td>
                        <td><?= h((string)$richiesta['data_richiesta']) ?></td>
                        <td><?= nl2br(h((string)($richiesta['note'] ?? ''))) ?></td>
                        <?php if ($puoGestire): ?>
                            <td>
                                <details>
                                    <summary>Gestisci</summary>
                                    <form method="post" class="hr-profile-form" style="margin-top:10px; min-width:240px;">
                                        <input type="hidden" name="azione" value="aggiorna_stato">
                                        <input type="hidden" name="id_richiesta_iban" value="<?= (int)$richiesta['id_richiesta_iban'] ?>">
                                        <div class="form-group">
                                            <label>Stato</label>
                                            <select name="stato">
                                                <?php foreach (['INVIATA' => 'Inviata', 'PRESA_IN_CARICO' => 'Presa in carico', 'CHIUSA' => 'Chiusa', 'ANNULLATA' => 'Annullata'] as $codice => $label): ?>
                                                    <option value="<?= h($codice) ?>" <?= (string)$richiesta['stato'] === $codice ? 'selected' : '' ?>><?= h($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Note HR</label>
                                            <textarea name="note_hr" rows="3"><?= h((string)($richiesta['note_hr'] ?? '')) ?></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Salva</button>
                                    </form>
                                </details>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php layoutFooter(); ?>
