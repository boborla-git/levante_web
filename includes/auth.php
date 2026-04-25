<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/autorizzazioni.php';
require_once __DIR__ . '/db.php';

function utenteAutenticato(): bool
{
    return isset($_SESSION['utente_id']) && (int)$_SESSION['utente_id'] > 0;
}

function caricaContestoUtenteSessione(int $idUtente): void
{
    $pdo = db();

    $stmtUtente = $pdo->prepare("
        SELECT
            id_utente,
            username,
            nome,
            cognome,
            attivo,
            deve_cambiare_password
        FROM aut_utenti
        WHERE id_utente = :id_utente
        LIMIT 1
    ");
    $stmtUtente->execute(['id_utente' => $idUtente]);
    $utente = $stmtUtente->fetch();

    if (!$utente || (int)$utente['attivo'] !== 1) {
        throw new RuntimeException('Utente non attivo o non trovato.');
    }

    $stmtRuoli = $pdo->prepare("
        SELECT ar.codice_ruolo
        FROM aut_utenti_ruoli aur
        INNER JOIN aut_ruoli ar
            ON ar.id_ruolo = aur.id_ruolo
            AND ar.attivo = 1
        WHERE aur.id_utente = :id_utente
          AND aur.attivo = 1
          AND (aur.data_fine IS NULL OR aur.data_fine >= NOW())
        ORDER BY ar.ordinamento, ar.codice_ruolo
    ");
    $stmtRuoli->execute(['id_utente' => $idUtente]);

    $ruoli = [];
    while ($rigaRuolo = $stmtRuoli->fetch()) {
        $codiceRuolo = trim((string)($rigaRuolo['codice_ruolo'] ?? ''));
        if ($codiceRuolo !== '') {
            $ruoli[] = $codiceRuolo;
        }
    }

    $nomeCompleto = trim(((string)$utente['nome']) . ' ' . ((string)$utente['cognome']));

    $_SESSION['utente_id'] = (int)$utente['id_utente'];
    $_SESSION['id_utente'] = (int)$utente['id_utente'];
    $_SESSION['username'] = (string)$utente['username'];
    $_SESSION['nome'] = $nomeCompleto !== '' ? $nomeCompleto : (string)$utente['username'];
    $_SESSION['ruolo'] = $ruoli[0] ?? '';
    $_SESSION['ruoli'] = $ruoli;
    $_SESSION['deve_cambiare_password'] = (int)$utente['deve_cambiare_password'];
    $_SESSION['permessi'] = [];
    $_SESSION['utente_senza_ruolo'] = count($ruoli) === 0 ? 1 : 0;
}

function utenteSenzaRuolo(): bool
{
    return isset($_SESSION['utente_senza_ruolo']) && (int)$_SESSION['utente_senza_ruolo'] === 1;
}

function richiediLogin(): void
{
    if (!utenteAutenticato()) {
        header('Location: login.php');
        exit;
    }

    $paginaCorrente = basename($_SERVER['PHP_SELF'] ?? '');

    if (
        isset($_SESSION['deve_cambiare_password']) &&
        (int)$_SESSION['deve_cambiare_password'] === 1 &&
        $paginaCorrente !== 'cambia_password.php' &&
        $paginaCorrente !== 'logout.php'
    ) {
        header('Location: cambia_password.php');
        exit;
    }
}

function livelloPermesso(string $codiceModulo): string
{
    if (!isset($_SESSION['permessi']) || !is_array($_SESSION['permessi'])) {
        return 'none';
    }

    return (string)($_SESSION['permessi'][$codiceModulo] ?? 'none');
}

function haPermessoLetturaLegacy(string $codiceModulo): bool
{
    $livello = livelloPermesso($codiceModulo);
    return in_array($livello, ['read', 'write'], true);
}

function haPermessoScritturaLegacy(string $codiceModulo): bool
{
    return livelloPermesso($codiceModulo) === 'write';
}

function haPermessoLettura(string $codiceModulo): bool
{
    return haPermesso('pagina.' . $codiceModulo, 'read');
}

function haPermessoScrittura(string $codiceModulo): bool
{
    return haPermesso('pagina.' . $codiceModulo, 'write');
}

function richiediPermessoLettura(string $codiceModulo): void
{
    richiediLogin();

    if (!haPermessoLettura($codiceModulo)) {
        registraLogAccesso('pagina.' . $codiceModulo, 'read', 'negato');
        http_response_code(403);
        die('Accesso negato.');
    }

    registraLogAccesso('pagina.' . $codiceModulo, 'read', 'consentito');
}

function richiediPermessoScrittura(string $codiceModulo): void
{
    richiediLogin();

    if (!haPermessoScrittura($codiceModulo)) {
        registraLogAccesso('pagina.' . $codiceModulo, 'write', 'negato');
        http_response_code(403);
        die('Accesso negato.');
    }

    registraLogAccesso('pagina.' . $codiceModulo, 'write', 'consentito');
}
