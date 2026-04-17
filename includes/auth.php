<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/autorizzazioni.php';

function utenteAutenticato(): bool
{
    return isset($_SESSION['utente_id']) && (int)$_SESSION['utente_id'] > 0;
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
    return haPermesso('pagina.' . $codiceModulo, 'view');
}

function haPermessoScrittura(string $codiceModulo): bool
{
    return haPermesso('pagina.' . $codiceModulo, 'edit');
}

function richiediPermessoLettura(string $codiceModulo): void
{
    richiediLogin();

    if (!haPermessoLettura($codiceModulo)) {
        registraLogAccesso('pagina.' . $codiceModulo, 'view', 'negato');
        http_response_code(403);
        die('Accesso negato.');
    }

    registraLogAccesso('pagina.' . $codiceModulo, 'view', 'consentito');
}

function richiediPermessoScrittura(string $codiceModulo): void
{
    richiediLogin();

    if (!haPermessoScrittura($codiceModulo)) {
        registraLogAccesso('pagina.' . $codiceModulo, 'edit', 'negato');
        http_response_code(403);
        die('Accesso negato.');
    }

    registraLogAccesso('pagina.' . $codiceModulo, 'edit', 'consentito');
}