<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/hr_recapiti.php';

richiediPermessoScrittura('utenti');

$errore = '';

$username = trim((string)($_POST['username'] ?? ''));
$nome = trim((string)($_POST['nome'] ?? ''));
$cognome = trim((string)($_POST['cognome'] ?? ''));
$matricola = trim((string)($_POST['matricola'] ?? ''));
$qualificaInps = strtoupper(trim((string)($_POST['qualifica_inps'] ?? '')));
$emailLavoro = trim((string)($_POST['email_lavoro'] ?? ''));
$emailPersonale = trim((string)($_POST['email_personale'] ?? ''));
$idRuolo = (int)($_POST['id_ruolo'] ?? 0);
$idResponsabile = (int)($_POST['id_responsabile'] ?? 0);
$attivo = isset($_POST['attivo']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 1 : 0;

function nuovoUtenteH(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

try {
    $stmtRuoli = $pdo->query(
        "SELECT id_ruolo, codice_ruolo, descrizione
         FROM aut_ruoli
         WHERE attivo = 1
         ORDER BY ordinamento, codice_ruolo"
    );
    $ruoliDisponibili = $stmtRuoli->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        foreach ($ruoliDisponibili as $ruolo) {
            if (strtolower(trim((string)$ruolo['codice_ruolo'])) === 'interno_base') {
                $idRuolo = (int)$ruolo['id_ruolo'];
                break;
            }
        }
    }

    $responsabiliDisponibili = $pdo->query(
        "SELECT
            u.id_utente,
            u.username,
            TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS nominativo
         FROM aut_utenti u
         WHERE u.attivo = 1
           AND LOWER(TRIM(u.username)) NOT IN ('admin', 'amministratore')
         ORDER BY u.cognome, u.nome, u.username"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento dei dati necessari alla creazione utente.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confermaPassword = (string)($_POST['conferma_password'] ?? '');

    if ($username === '' || $password === '') {
        $errore = 'Username e password sono obbligatori.';
    } elseif ($nome === '' || $cognome === '') {
        $errore = 'Nome e cognome sono obbligatori.';
    } elseif (!in_array($qualificaInps, ['OPERAIO', 'IMPIEGATO'], true)) {
        $errore = 'Seleziona la Qualifica INPS del dipendente.';
    } elseif (mb_strlen($password) < 6) {
        $errore = 'La password iniziale deve contenere almeno 6 caratteri.';
    } elseif ($password !== $confermaPassword) {
        $errore = 'La password e la conferma non coincidono.';
    } elseif ($emailLavoro !== '' && !filter_var($emailLavoro, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Email di lavoro non valida.';
    } elseif ($emailPersonale !== '' && !filter_var($emailPersonale, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Email personale non valida.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtDuplicato = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM aut_utenti
                 WHERE LOWER(TRIM(username)) = LOWER(TRIM(:username))"
            );
            $stmtDuplicato->execute(['username' => $username]);
            if ((int)$stmtDuplicato->fetchColumn() > 0) {
                throw new RuntimeException('Username già presente.');
            }

            if ($matricola !== '') {
                $stmtMatricola = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM hr_profili_dipendenti
                     WHERE matricola = :matricola"
                );
                $stmtMatricola->execute(['matricola' => $matricola]);
                if ((int)$stmtMatricola->fetchColumn() > 0) {
                    throw new RuntimeException('Matricola già presente.');
                }
            }

            if ($idRuolo > 0) {
                $stmtRuolo = $pdo->prepare(
                    "SELECT id_ruolo
                     FROM aut_ruoli
                     WHERE id_ruolo = :id_ruolo
                       AND attivo = 1"
                );
                $stmtRuolo->execute(['id_ruolo' => $idRuolo]);
                if (!$stmtRuolo->fetchColumn()) {
                    throw new RuntimeException('Ruolo selezionato non valido.');
                }
            }

            if ($idResponsabile > 0) {
                $stmtResponsabile = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM aut_utenti
                     WHERE id_utente = :id_utente
                       AND attivo = 1
                       AND LOWER(TRIM(username)) NOT IN ('admin', 'amministratore')"
                );
                $stmtResponsabile->execute(['id_utente' => $idResponsabile]);
                if ((int)$stmtResponsabile->fetchColumn() !== 1) {
                    throw new RuntimeException('Responsabile selezionato non valido o non attivo.');
                }
            }

            $stmtTipoUtente = $pdo->query(
                "SELECT id_tipo_utente
                 FROM aut_tipi_utente
                 WHERE codice = 'interno'
                 LIMIT 1"
            );
            $idTipoUtente = (int)$stmtTipoUtente->fetchColumn();
            if ($idTipoUtente <= 0) {
                throw new RuntimeException('Tipo utente interno non trovato.');
            }

            $emailAccount = $emailLavoro !== '' ? $emailLavoro : ($emailPersonale !== '' ? $emailPersonale : null);
            if ($emailAccount !== null) {
                $stmtEmail = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM aut_utenti
                     WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email))"
                );
                $stmtEmail->execute(['email' => $emailAccount]);
                if ((int)$stmtEmail->fetchColumn() > 0) {
                    throw new RuntimeException('L\'email principale è già associata a un altro account.');
                }
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Impossibile generare hash password.');
            }

            $stmtAut = $pdo->prepare(
                "INSERT INTO aut_utenti
                (
                    username,
                    password_hash,
                    email,
                    nome,
                    cognome,
                    id_tipo_utente,
                    attivo,
                    deve_cambiare_password,
                    lingua_preferita,
                    locale_preferito,
                    fuso_orario,
                    data_creazione,
                    data_aggiornamento,
                    note,
                    email_notifiche,
                    sms_notifiche,
                    email_verificata,
                    telefono_verificato
                )
                VALUES
                (
                    :username,
                    :password_hash,
                    :email,
                    :nome,
                    :cognome,
                    :id_tipo_utente,
                    :attivo,
                    1,
                    'it',
                    'it-IT',
                    'Europe/Rome',
                    NOW(),
                    NOW(),
                    'Creato da interfaccia web',
                    1,
                    0,
                    :email_verificata,
                    0
                )"
            );

            $stmtAut->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
                'email' => $emailAccount,
                'nome' => $nome,
                'cognome' => $cognome,
                'id_tipo_utente' => $idTipoUtente,
                'attivo' => $attivo,
                'email_verificata' => $emailAccount !== null ? 1 : 0,
            ]);

            $idUtente = (int)$pdo->lastInsertId();
            if ($idUtente <= 0) {
                throw new RuntimeException('Impossibile recuperare ID utente creato.');
            }

            $stmtProfilo = $pdo->prepare(
                "INSERT INTO hr_profili_dipendenti
                    (id_utente, matricola, qualifica_inps, attivo, data_creazione, data_aggiornamento)
                 VALUES
                    (:id_utente, :matricola, :qualifica_inps, 1, NOW(), NOW())"
            );
            $stmtProfilo->execute([
                'id_utente' => $idUtente,
                'matricola' => $matricola !== '' ? $matricola : null,
                'qualifica_inps' => $qualificaInps,
            ]);

            if ($idRuolo > 0) {
                $stmtRuoloUtente = $pdo->prepare(
                    "INSERT INTO aut_utenti_ruoli
                    (
                        id_utente,
                        id_ruolo,
                        data_inizio,
                        data_fine,
                        attivo
                    )
                    VALUES
                    (
                        :id_utente,
                        :id_ruolo,
                        NOW(),
                        NULL,
                        1
                    )"
                );
                $stmtRuoloUtente->execute([
                    'id_utente' => $idUtente,
                    'id_ruolo' => $idRuolo,
                ]);
            }

            if ($idResponsabile > 0) {
                $stmtTipoRelazione = $pdo->query(
                    "SELECT id_tipo_relazione
                     FROM hr_tipi_relazione_organizzativa
                     WHERE attivo = 1
                       AND codice IN ('RESPONSABILE_FUNZIONALE', 'RESPONSABILE_DIRETTO')
                     ORDER BY CASE
                         WHEN codice = 'RESPONSABILE_FUNZIONALE' THEN 0
                         WHEN codice = 'RESPONSABILE_DIRETTO' THEN 1
                         ELSE 2
                     END
                     LIMIT 1"
                );
                $idTipoRelazione = (int)$stmtTipoRelazione->fetchColumn();
                if ($idTipoRelazione <= 0) {
                    throw new RuntimeException('Tipo relazione responsabile non configurato.');
                }

                $stmtRelazione = $pdo->prepare(
                    "INSERT INTO hr_relazioni_organizzative
                        (id_utente, id_utente_collegato, id_tipo_relazione, data_inizio, data_fine, attiva, note)
                     VALUES
                        (:id_utente, :id_responsabile, :id_tipo_relazione, CURDATE(), NULL, 1, 'Assegnata in creazione utente')"
                );
                $stmtRelazione->execute([
                    'id_utente' => $idUtente,
                    'id_responsabile' => $idResponsabile,
                    'id_tipo_relazione' => $idTipoRelazione,
                ]);
            }

            $idOperatore = (int)($_SESSION['utente_id'] ?? 0);
            if ($emailLavoro !== '') {
                hrRecapitiSalva($pdo, $idUtente, 'EMAIL_LAVORO', $emailLavoro, $idOperatore, true);
            }
            if ($emailPersonale !== '') {
                hrRecapitiSalva($pdo, $idUtente, 'EMAIL_PERSONALE', $emailPersonale, $idOperatore, true);
            }

            $pdo->commit();

            header('Location: utenti.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $messaggioTecnico = trim($e->getMessage());
            $erroriGestiti = [
                'Username già presente.',
                'Matricola già presente.',
                'Ruolo selezionato non valido.',
                'Responsabile selezionato non valido o non attivo.',
                'Tipo utente interno non trovato.',
                'L\'email principale è già associata a un altro account.',
                'Tipo relazione responsabile non configurato.',
            ];
            $errore = in_array($messaggioTecnico, $erroriGestiti, true)
                ? $messaggioTecnico
                : 'Impossibile creare l\'utente. Controlla i dati inseriti e riprova.';
        }
    }
}

layoutHeader('Nuovo utente');
?>
<link rel="stylesheet" href="/assets/admin.css">

<div class="card card-wide">
    <div class="section-head">
        <div>
            <h1>Nuovo utente</h1>
            <div class="meta">Crea account, profilo HR, recapiti e relazione organizzativa in un'unica operazione.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="utenti.php"><i class="la la-arrow-left" aria-hidden="true"></i> Gestione utenti</a>
        </div>
    </div>
</div>

<?php if ($errore !== ''): ?>
    <?php renderAdminAlert($errore, 'danger'); ?>
<?php endif; ?>

<form method="post" autocomplete="off" class="admin-new-user-form">
    <div class="card card-wide admin-new-user-section">
        <div class="admin-new-user-heading">
            <h2>Identità</h2>
            <div class="meta">Dati principali dell'account e del dipendente.</div>
        </div>
        <div class="admin-new-user-grid admin-new-user-grid-4">
            <div class="form-group">
                <label for="username">Username *</label>
                <input
                    type="text"
                    name="username"
                    id="username"
                    value="<?= nuovoUtenteH($username) ?>"
                    autocomplete="off"
                    required
                >
            </div>

            <div class="form-group">
                <label for="nome">Nome *</label>
                <input
                    type="text"
                    id="nome"
                    name="nome"
                    value="<?= nuovoUtenteH($nome) ?>"
                    autocomplete="off"
                    required
                >
            </div>

            <div class="form-group">
                <label for="cognome">Cognome *</label>
                <input
                    type="text"
                    id="cognome"
                    name="cognome"
                    value="<?= nuovoUtenteH($cognome) ?>"
                    autocomplete="off"
                    required
                >
            </div>

            <div class="form-group">
                <label for="matricola">Matricola</label>
                <input
                    type="text"
                    id="matricola"
                    name="matricola"
                    value="<?= nuovoUtenteH($matricola) ?>"
                    autocomplete="off"
                >
            </div>
        </div>
    </div>

    <div class="card card-wide admin-new-user-section">
        <div class="admin-new-user-heading">
            <h2>Inquadramento</h2>
            <div class="meta">Qualifica, ruolo portale e responsabile organizzativo.</div>
        </div>
        <div class="admin-new-user-grid admin-new-user-grid-3">
            <div class="form-group">
                <label for="qualifica_inps">Qual. INPS *</label>
                <select id="qualifica_inps" name="qualifica_inps" required>
                    <option value="">Seleziona...</option>
                    <option value="OPERAIO" <?= $qualificaInps === 'OPERAIO' ? 'selected' : '' ?>>Operaio</option>
                    <option value="IMPIEGATO" <?= $qualificaInps === 'IMPIEGATO' ? 'selected' : '' ?>>Impiegato</option>
                </select>
            </div>

            <div class="form-group">
                <label for="id_ruolo">Ruolo portale</label>
                <select id="id_ruolo" name="id_ruolo">
                    <option value="0">Nessun ruolo</option>
                    <?php foreach ($ruoliDisponibili as $ruolo): ?>
                        <option value="<?= (int)$ruolo['id_ruolo'] ?>" <?= $idRuolo === (int)$ruolo['id_ruolo'] ? 'selected' : '' ?>>
                            <?= nuovoUtenteH((string)$ruolo['codice_ruolo']) ?>
                            <?php if (trim((string)$ruolo['descrizione']) !== ''): ?>
                                - <?= nuovoUtenteH((string)$ruolo['descrizione']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="id_responsabile">Responsabile</label>
                <select id="id_responsabile" name="id_responsabile">
                    <option value="0">Nessun responsabile</option>
                    <?php foreach ($responsabiliDisponibili as $responsabile): ?>
                        <?php
                        $nominativoResponsabile = trim((string)($responsabile['nominativo'] ?? ''));
                        $labelResponsabile = $nominativoResponsabile !== ''
                            ? $nominativoResponsabile
                            : (string)$responsabile['username'];
                        ?>
                        <option value="<?= (int)$responsabile['id_utente'] ?>" <?= $idResponsabile === (int)$responsabile['id_utente'] ? 'selected' : '' ?>>
                            <?= nuovoUtenteH($labelResponsabile) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label class="checkbox-inline admin-new-user-active">
            <input type="checkbox" name="attivo" value="1" <?= $attivo === 1 ? 'checked' : '' ?>>
            Utente attivo
        </label>
    </div>

    <div class="card card-wide admin-new-user-section">
        <div class="admin-new-user-heading">
            <h2>Recapiti</h2>
            <div class="meta">La mail di lavoro ha priorità per le notifiche; in assenza viene usata quella personale.</div>
        </div>
        <div class="admin-new-user-grid admin-new-user-grid-2">
            <div class="form-group">
                <label for="email_lavoro">Email di lavoro</label>
                <input
                    type="email"
                    id="email_lavoro"
                    name="email_lavoro"
                    value="<?= nuovoUtenteH($emailLavoro) ?>"
                    autocomplete="off"
                >
            </div>

            <div class="form-group">
                <label for="email_personale">Email personale</label>
                <input
                    type="email"
                    id="email_personale"
                    name="email_personale"
                    value="<?= nuovoUtenteH($emailPersonale) ?>"
                    autocomplete="off"
                >
            </div>
        </div>
    </div>

    <div class="card card-wide admin-new-user-section">
        <div class="admin-new-user-heading">
            <h2>Accesso</h2>
            <div class="meta">La password iniziale deve contenere almeno 6 caratteri. Al primo accesso il cambio password sarà obbligatorio.</div>
        </div>
        <div class="admin-new-user-grid admin-new-user-grid-2">
            <div class="form-group">
                <label for="password">Password iniziale *</label>
                <input
                    type="password"
                    name="password"
                    id="password"
                    minlength="6"
                    autocomplete="new-password"
                    required
                >
            </div>

            <div class="form-group">
                <label for="conferma_password">Conferma password iniziale *</label>
                <input
                    type="password"
                    name="conferma_password"
                    id="conferma_password"
                    minlength="6"
                    autocomplete="new-password"
                    required
                >
            </div>
        </div>
    </div>

    <div class="admin-new-user-actions">
        <button type="submit" class="btn btn-primary">
            <i class="la la-user-plus" aria-hidden="true"></i> Crea utente
        </button>
        <a class="btn btn-light" href="utenti.php">Annulla</a>
    </div>
</form>

<?php layoutFooter(); ?>
