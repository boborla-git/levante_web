<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/hr_riepilogo_assenze.php';

richiediPermessoLettura('configurazione_assenze');

$pdo = db();
$puoScrivere = haPermessoScrittura('configurazione_assenze');
$messaggio = '';
$errore = '';
$utenti = [];

function h(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        $azione = trim((string)($_POST['azione'] ?? 'salva_destinatario'));

        if ($azione === 'invia_test_admin') {
            $esitoTest = hrRiepilogoAssenzeInviaTestAdmin($pdo, date('Y-m-d'));
            if ((int)$esitoTest['errori'] > 0) {
                throw new RuntimeException((string)($esitoTest['motivo'] ?? 'Invio di prova non riuscito.'));
            }
            $messaggio = 'Invio di prova eseguito: controlla l\'email di lavoro dell\'Amministratore. Sono state inviate due email, una con motivi HR e una senza motivi.';
        } elseif ($azione === 'salva_destinatario') {
            $idUtente = (int)($_POST['id_utente'] ?? 0);
            $livello = strtoupper(trim((string)($_POST['livello_dettaglio'] ?? 'NESSUNO')));

            if ($idUtente <= 0) {
                throw new RuntimeException('Utente non valido.');
            }

        $stmtUtente = $pdo->prepare("SELECT LOWER(username) FROM aut_utenti WHERE id_utente=:id_utente AND attivo=1 LIMIT 1");
        $stmtUtente->execute(['id_utente' => $idUtente]);
        $username = (string)($stmtUtente->fetchColumn() ?: '');
        if ($username === '' || $username === 'admin') {
            throw new RuntimeException('Utente non configurabile.');
        }

        if ($livello === 'NESSUNO') {
            $pdo->prepare("DELETE FROM hr_riepilogo_assenze_destinatari WHERE id_utente=:id_utente")
                ->execute(['id_utente' => $idUtente]);
        } elseif (in_array($livello, ['BASE', 'HR'], true)) {
            if (hrRiepilogoAssenzeEmailLavoro($pdo, $idUtente) === null) {
                throw new RuntimeException('Per abilitare il riepilogo è necessaria una email di lavoro verificata.');
            }

            if ($livello === 'HR') {
                $stmtRuolo = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM aut_utenti_ruoli ur
                     INNER JOIN aut_ruoli r
                        ON r.id_ruolo=ur.id_ruolo
                       AND r.attivo=1
                     WHERE ur.id_utente=:id_utente
                       AND ur.attivo=1
                       AND (ur.data_fine IS NULL OR ur.data_fine>=NOW())
                       AND r.codice_ruolo IN ('hr_responsabile_personale','direzione_visibilita_hr')"
                );
                $stmtRuolo->execute(['id_utente' => $idUtente]);
                if ((int)$stmtRuolo->fetchColumn() === 0) {
                    throw new RuntimeException('Il riepilogo con motivi è riservato ai ruoli HR/Direzione autorizzati.');
                }
            }

            $stmtSalva = $pdo->prepare(
                "INSERT INTO hr_riepilogo_assenze_destinatari
                    (id_utente, livello_dettaglio, attivo, aggiornato_da, data_aggiornamento)
                 VALUES
                    (:id_utente, :livello, 1, :operatore, NOW())
                 ON DUPLICATE KEY UPDATE
                    livello_dettaglio=VALUES(livello_dettaglio),
                    attivo=1,
                    aggiornato_da=VALUES(aggiornato_da),
                    data_aggiornamento=NOW()"
            );
            $stmtSalva->execute([
                'id_utente' => $idUtente,
                'livello' => $livello,
                'operatore' => (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0),
            ]);
        } else {
            throw new RuntimeException('Livello non valido.');
        }

            $messaggio = 'Destinatari del riepilogo aggiornati.';
        } else {
            throw new RuntimeException('Operazione non valida.');
        }
    }

    $utenti = $pdo->query(
        "SELECT
            u.id_utente,
            u.nome,
            u.cognome,
            u.username,
            d.livello_dettaglio,
            EXISTS(
                SELECT 1
                FROM aut_utenti_ruoli ur
                INNER JOIN aut_ruoli r
                   ON r.id_ruolo=ur.id_ruolo
                  AND r.attivo=1
                WHERE ur.id_utente=u.id_utente
                  AND ur.attivo=1
                  AND (ur.data_fine IS NULL OR ur.data_fine>=NOW())
                  AND r.codice_ruolo IN ('hr_responsabile_personale','direzione_visibilita_hr')
            ) AS puo_hr
         FROM aut_utenti u
         LEFT JOIN hr_riepilogo_assenze_destinatari d
            ON d.id_utente=u.id_utente
           AND d.attivo=1
         WHERE u.attivo=1
           AND LOWER(u.username)<>'admin'
         ORDER BY u.cognome,u.nome,u.username"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errore = $e->getMessage();
}

layoutHeader('Riepilogo assenze email');
?>
<div class="page-container">
    <section class="card card-wide">
        <div class="section-head">
            <div>
                <h1>Riepilogo assenze via email</h1>
                <div class="meta">Definisci chi riceve il riepilogo giornaliero. Viene usata esclusivamente l'email di lavoro verificata.</div>
            </div>
            <div class="section-head-actions">
                <?php if ($puoScrivere): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="azione" value="invia_test_admin">
                        <button class="btn btn-primary" type="submit">Invia prova ad Amministratore</button>
                    </form>
                <?php endif; ?>
                <a class="btn btn-light" href="configurazione_assenze.php">Torna alla configurazione</a>
            </div>
        </div>
    </section>

    <?php if ($messaggio !== ''): ?>
        <div class="alert alert-success"><?= h($messaggio) ?></div>
    <?php endif; ?>
    <?php if ($errore !== ''): ?>
        <div class="alert alert-error"><?= h($errore) ?></div>
    <?php endif; ?>

    <section class="card card-wide">
        <p class="meta">Il livello <strong>Senza motivi</strong> invia il riepilogo generale con nominativo e periodo/orario. Per i ruoli HR/Direzione autorizzati, <strong>Entrambe (generale + HR)</strong> invia due email distinte: una senza motivi e una riservata con la tipologia visibile a HR.</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Dipendente</th>
                    <th>Email di lavoro</th>
                    <th>Riepilogo</th>
                    <th>Azioni</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($utenti as $utente): ?>
                    <?php
                    $emailLavoro = hrRiepilogoAssenzeEmailLavoro($pdo, (int)$utente['id_utente']);
                    $livello = strtoupper((string)($utente['livello_dettaglio'] ?? 'NESSUNO'));
                    ?>
                    <tr>
                        <form method="post">
                            <td>
                                <strong><?= h(trim((string)$utente['nome'] . ' ' . (string)$utente['cognome']) ?: (string)$utente['username']) ?></strong>
                                <input type="hidden" name="azione" value="salva_destinatario">
                                <input type="hidden" name="id_utente" value="<?= (int)$utente['id_utente'] ?>">
                            </td>
                            <td>
                                <?php if ($emailLavoro !== null): ?>
                                    <?= h($emailLavoro) ?>
                                <?php else: ?>
                                    <span class="meta">Non disponibile / non verificata</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="livello_dettaglio" <?= $emailLavoro !== null ? '' : 'disabled' ?>>
                                    <option value="NESSUNO" <?= $livello === 'NESSUNO' ? 'selected' : '' ?>>Non inviare</option>
                                    <option value="BASE" <?= $livello === 'BASE' ? 'selected' : '' ?>>Senza motivi</option>
                                    <?php if ((int)$utente['puo_hr'] === 1): ?>
                                        <option value="HR" <?= $livello === 'HR' ? 'selected' : '' ?>>Entrambe (generale + HR)</option>
                                    <?php endif; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($puoScrivere): ?>
                                    <button class="btn btn-primary" type="submit" <?= $emailLavoro !== null ? '' : 'disabled' ?>>Salva</button>
                                <?php else: ?>
                                    <span class="meta">Sola lettura</span>
                                <?php endif; ?>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php layoutFooter(); ?>
