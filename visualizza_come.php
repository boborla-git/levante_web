<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediLogin();

$pdo = db();
$errore = '';

function vcH(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function vcCsrfToken(): string
{
    if (empty($_SESSION['visualizza_come_csrf'])) {
        $_SESSION['visualizza_come_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['visualizza_come_csrf'];
}

function vcVerificaCsrf(): void
{
    $atteso = (string)($_SESSION['visualizza_come_csrf'] ?? '');
    $ricevuto = (string)($_POST['csrf'] ?? '');
    if ($atteso === '' || !hash_equals($atteso, $ricevuto)) {
        throw new RuntimeException('Sessione non valida. Ricarica la pagina e riprova.');
    }
}

function vcCampiSessioneIdentita(): array
{
    return [
        'utente_id','id_utente','username','nome','cognome','nome_completo','email',
        'deve_cambiare_password','ruoli','ruoli_descrizioni','ha_ruoli',
        'ruolo_attivo','ruolo_attivo_descrizione','ruolo','permessi','utente_senza_ruolo'
    ];
}

function vcSnapshotSessione(): array
{
    $snapshot = [];
    foreach (vcCampiSessioneIdentita() as $campo) {
        if (array_key_exists($campo, $_SESSION)) {
            $snapshot[$campo] = $_SESSION[$campo];
        }
    }
    return $snapshot;
}

function vcRipristinaSnapshot(array $snapshot): void
{
    foreach (vcCampiSessioneIdentita() as $campo) {
        unset($_SESSION[$campo]);
    }
    foreach ($snapshot as $campo => $valore) {
        $_SESSION[$campo] = $valore;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        vcVerificaCsrf();
        $azione = (string)($_POST['azione'] ?? '');

        if ($azione === 'termina') {
            if (!impersonazioneAttiva()) {
                throw new RuntimeException('Nessuna visualizzazione come utente attiva.');
            }

            $origine = $_SESSION['impersonazione_origine'];
            vcRipristinaSnapshot($origine);
            unset($_SESSION['impersonazione_attiva'], $_SESSION['impersonazione_origine'], $_SESSION['impersonazione_target_id'], $_SESSION['impersonazione_target_nome']);
            session_regenerate_id(true);
            registraLogAccesso('azione.admin.visualizza_come', 'write', 'terminata');
            header('Location: index.php');
            exit;
        }

        if ($azione === 'avvia') {
            if (!utentePuoImpersonare()) {
                http_response_code(403);
                die('Accesso negato.');
            }

            $idTarget = (int)($_POST['id_utente'] ?? 0);
            $idCorrente = (int)($_SESSION['id_utente'] ?? 0);
            if ($idTarget <= 0 || $idTarget === $idCorrente) {
                throw new RuntimeException('Seleziona un altro utente attivo.');
            }

            $stmt = $pdo->prepare("SELECT id_utente, username, nome, cognome FROM aut_utenti WHERE id_utente=:id AND attivo=1 LIMIT 1");
            $stmt->execute(['id'=>$idTarget]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                throw new RuntimeException('Utente non trovato o non attivo.');
            }

            $origine = vcSnapshotSessione();
            $_SESSION['impersonazione_origine'] = $origine;
            $_SESSION['impersonazione_attiva'] = 1;
            $_SESSION['impersonazione_target_id'] = $idTarget;
            $_SESSION['impersonazione_target_nome'] = trim((string)$target['nome'].' '.(string)$target['cognome']);

            caricaContestoUtenteSessione($idTarget);

            // Mantiene i metadati dell'impersonazione dopo il caricamento del target.
            $_SESSION['impersonazione_origine'] = $origine;
            $_SESSION['impersonazione_attiva'] = 1;
            $_SESSION['impersonazione_target_id'] = $idTarget;
            $_SESSION['impersonazione_target_nome'] = trim((string)$target['nome'].' '.(string)$target['cognome']);

            session_regenerate_id(true);
            registraLogAccesso('azione.admin.visualizza_come', 'write', 'avviata');
            header('Location: index.php');
            exit;
        }
    } catch (Throwable $e) {
        $errore = $e->getMessage();
    }
}

if (impersonazioneAttiva()) {
    layoutHeader('Visualizza come utente');
    ?>
    <div class="card card-wide">
        <h1>Visualizza come utente</h1>
        <p>Stai visualizzando Levante come <strong><?= vcH((string)($_SESSION['nome_completo'] ?? $_SESSION['username'] ?? 'utente')) ?></strong>.</p>
        <p class="meta">La modalita e' in sola lettura: puoi verificare menu, pagine e dati visibili, ma non puoi salvare modifiche a nome dell'utente.</p>
        <?php if ($errore !== ''): ?><div class="alert alert-error"><?= vcH($errore) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= vcH(vcCsrfToken()) ?>">
            <input type="hidden" name="azione" value="termina">
            <button class="btn btn-primary" type="submit">Torna amministratore</button>
        </form>
    </div>
    <?php
    layoutFooter();
    exit;
}

if (!utentePuoImpersonare()) {
    http_response_code(403);
    die('Accesso negato.');
}

$utenti = [];
$stmt = $pdo->prepare("
    SELECT
        u.id_utente,
        u.username,
        u.nome,
        u.cognome,
        GROUP_CONCAT(DISTINCT r.codice_ruolo ORDER BY r.ordinamento SEPARATOR ', ') AS ruoli
    FROM aut_utenti u
    LEFT JOIN aut_utenti_ruoli ur
        ON ur.id_utente=u.id_utente
       AND ur.attivo=1
       AND (ur.data_fine IS NULL OR ur.data_fine>=NOW())
    LEFT JOIN aut_ruoli r
        ON r.id_ruolo=ur.id_ruolo
       AND r.attivo=1
    WHERE u.attivo=1
      AND u.id_utente<>:id_corrente
    GROUP BY u.id_utente,u.username,u.nome,u.cognome
    ORDER BY
        CASE WHEN COALESCE(u.cognome,'')='' THEN 1 ELSE 0 END,
        u.cognome,u.nome,u.username
");
$stmt->execute(['id_corrente'=>(int)($_SESSION['id_utente'] ?? 0)]);
$utenti=$stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

layoutHeader('Visualizza come utente');
?>
<link rel="stylesheet" href="/assets/admin.css">
<div class="card card-wide">
    <div class="section-head">
        <div>
            <h1>Visualizza come utente</h1>
            <div class="meta">Funzione riservata al ruolo <code>admin_portale</code>. Non serve conoscere o modificare la password dell'utente.</div>
        </div>
    </div>

    <?php if ($errore !== ''): ?><div class="alert alert-error"><?= vcH($errore) ?></div><?php endif; ?>

    <div class="info-box" style="margin-bottom:16px">
        La sessione di controllo e' <strong>in sola lettura</strong>. Vedrai menu, permessi e informazioni con l'ambito dell'utente scelto, senza poter registrare operazioni a suo nome.
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= vcH(vcCsrfToken()) ?>">
        <input type="hidden" name="azione" value="avvia">
        <div class="form-group">
            <label for="id_utente">Utente da visualizzare</label>
            <select name="id_utente" id="id_utente" required>
                <option value="">Seleziona utente...</option>
                <?php foreach ($utenti as $u):
                    $nome=trim((string)$u['cognome'].' '.(string)$u['nome']);
                    if($nome==='') $nome=(string)$u['username'];
                    $ruoli=trim((string)($u['ruoli'] ?? ''));
                ?>
                <option value="<?= (int)$u['id_utente'] ?>"><?= vcH($nome) ?> — <?= vcH((string)$u['username']) ?><?= $ruoli!=='' ? ' · '.vcH($ruoli) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit"><i class="la la-user-secret" aria-hidden="true"></i> Visualizza come utente</button>
    </form>
</div>
<?php layoutFooter(); ?>
