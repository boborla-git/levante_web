<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['utente_id']) && (int)$_SESSION['utente_id'] > 0) {
    header('Location: index.php');
    exit;
}

$errore = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $errore = 'Inserisci username e password.';
    } else {
        try {
            $sql = "
                SELECT
                    u.id_utente,
                    u.username,
                    u.password_hash,
                    u.nome,
                    u.cognome,
                    u.email,
                    u.attivo,
                    u.deve_cambiare_password
                FROM aut_utenti u
                WHERE u.username = :username
                  AND u.attivo = 1
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':username' => $username,
            ]);

            $utente = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$utente) {
                $errore = 'Credenziali non valide.';
            } elseif (!password_verify($password, (string)$utente['password_hash'])) {
                $errore = 'Credenziali non valide.';
            } else {
                $stmtRuoli = $pdo->prepare("
                    SELECT
                        r.id_ruolo,
                        r.codice_ruolo,
                        r.descrizione
                    FROM aut_utenti_ruoli ur
                    INNER JOIN aut_ruoli r
                        ON r.id_ruolo = ur.id_ruolo
                    WHERE ur.id_utente = :id_utente
                      AND ur.attivo = 1
                      AND (ur.data_fine IS NULL OR ur.data_fine >= NOW())
                      AND r.attivo = 1
                    ORDER BY r.ordinamento, r.codice_ruolo
                ");

                $stmtRuoli->execute([
                    ':id_utente' => (int)$utente['id_utente'],
                ]);

                $ruoli = $stmtRuoli->fetchAll(PDO::FETCH_ASSOC);

                $ruoliCodice = [];
                $ruoliDescrizioni = [];

                foreach ($ruoli as $ruolo) {
                    $codiceRuolo = trim((string)($ruolo['codice_ruolo'] ?? ''));
                    $descrizioneRuolo = trim((string)($ruolo['descrizione'] ?? ''));

                    if ($codiceRuolo !== '') {
                        $ruoliCodice[] = $codiceRuolo;
                    }

                    if ($descrizioneRuolo !== '') {
                        $ruoliDescrizioni[] = $descrizioneRuolo;
                    }
                }

                session_regenerate_id(true);

                $_SESSION['utente_id'] = (int)$utente['id_utente'];
                $_SESSION['id_utente'] = (int)$utente['id_utente'];
                $_SESSION['username'] = (string)$utente['username'];
                $_SESSION['nome'] = trim((string)$utente['nome']);
                $_SESSION['cognome'] = trim((string)$utente['cognome']);
                $_SESSION['nome_completo'] = trim(
                    ((string)$utente['nome']) . ' ' . ((string)$utente['cognome'])
                );
                $_SESSION['email'] = (string)($utente['email'] ?? '');
                $_SESSION['deve_cambiare_password'] = (int)$utente['deve_cambiare_password'];

                $_SESSION['ruoli'] = $ruoliCodice;
                $_SESSION['ruoli_descrizioni'] = $ruoliDescrizioni;
                $_SESSION['ha_ruoli'] = count($ruoliCodice) > 0;
                $_SESSION['ruolo_attivo'] = $ruoliCodice[0] ?? null;
                $_SESSION['ruolo_attivo_descrizione'] = $ruoliDescrizioni[0] ?? null;

                $ruoloLegacy = $ruoliCodice[0] ?? '';
                $_SESSION['ruolo'] = $ruoloLegacy;

                $permessiLegacy = [];
                $stmtPermessiLegacy = $pdo->prepare("
                    SELECT
                        ars.codice_risorsa,
                        arp.permesso,
                        arp.consentito
                    FROM aut_utenti_ruoli ur
                    INNER JOIN aut_ruoli r
                        ON r.id_ruolo = ur.id_ruolo
                       AND r.attivo = 1
                    INNER JOIN aut_ruoli_permessi arp
                        ON arp.id_ruolo = r.id_ruolo
                    INNER JOIN aut_risorse ars
                        ON ars.id_risorsa = arp.id_risorsa
                       AND ars.attivo = 1
                    WHERE ur.id_utente = :id_utente
                      AND ur.attivo = 1
                      AND (ur.data_fine IS NULL OR ur.data_fine >= NOW())
                ");

                $stmtPermessiLegacy->execute([
                    ':id_utente' => (int)$utente['id_utente'],
                ]);

                while ($rigaPermesso = $stmtPermessiLegacy->fetch(PDO::FETCH_ASSOC)) {
                    $codiceRisorsa = (string)($rigaPermesso['codice_risorsa'] ?? '');
                    $permesso = (string)($rigaPermesso['permesso'] ?? '');
                    $consentito = (int)($rigaPermesso['consentito'] ?? 0) === 1;

                    if (!$consentito) {
                        continue;
                    }

                    if (strpos($codiceRisorsa, 'pagina.') !== 0) {
                        continue;
                    }

                    $codiceModulo = substr($codiceRisorsa, 7);

                    if ($codiceModulo === '') {
                        continue;
                    }

                    if (in_array($permesso, ['edit', 'write', 'create', 'delete', 'execute'], true)) {
                        $permessiLegacy[$codiceModulo] = 'write';
                    } elseif (!isset($permessiLegacy[$codiceModulo]) && $permesso === 'view') {
                        $permessiLegacy[$codiceModulo] = 'read';
                    }
                }

                $_SESSION['permessi'] = $permessiLegacy;

                try {
                    $stmtUpdate = $pdo->prepare("
                        UPDATE aut_utenti
                        SET data_ultima_login = NOW(),
                            data_aggiornamento = NOW()
                        WHERE id_utente = :id_utente
                    ");
                    $stmtUpdate->execute([
                        ':id_utente' => (int)$utente['id_utente'],
                    ]);
                } catch (Throwable $e) {
                    // non bloccare il login per questo
                }

                if ((int)$utente['deve_cambiare_password'] === 1) {
                    header('Location: cambia_password.php');
                    exit;
                }

                header('Location: index.php');
                exit;
            }
        } catch (Throwable $e) {
            $errore = 'Errore durante l\'accesso. Riprova più tardi.';
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Area Riservata - Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="icon" type="image/png" href="/assets/favicon.png">
</head>
<body class="login-page">
    <div class="login-panel">
        <div class="login-logo">
            <img src="/assets/img/logo-ravioli.png" alt="Ravioli S.p.A.">
        </div>

        <h1 class="login-title">Accesso riservato agli utenti interni</h1>

        <div class="login-card">
            <?php if ($errore !== ''): ?>
                <div class="errore"><?= htmlspecialchars($errore, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input
                        type="text"
                        name="username"
                        id="username"
                        value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                        required
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        name="password"
                        id="password"
                        required
                    >
                </div>

                <button type="submit" class="btn">Accedi</button>
            </form>
        </div>

        <div class="login-note">Accesso riservato agli utenti autorizzati.</div>
    </div>
</body>
</html>