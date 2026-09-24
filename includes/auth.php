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
            email,
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
        SELECT ar.codice_ruolo, ar.descrizione
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
    $ruoliDescrizioni = [];
    while ($rigaRuolo = $stmtRuoli->fetch()) {
        $codiceRuolo = trim((string)($rigaRuolo['codice_ruolo'] ?? ''));
        $descrizioneRuolo = trim((string)($rigaRuolo['descrizione'] ?? ''));
        if ($codiceRuolo !== '') {
            $ruoli[] = $codiceRuolo;
        }
        if ($descrizioneRuolo !== '') {
            $ruoliDescrizioni[] = $descrizioneRuolo;
        }
    }

    $nome = trim((string)$utente['nome']);
    $cognome = trim((string)$utente['cognome']);
    $nomeCompleto = trim($nome . ' ' . $cognome);

    $_SESSION['utente_id'] = (int)$utente['id_utente'];
    $_SESSION['id_utente'] = (int)$utente['id_utente'];
    $_SESSION['username'] = (string)$utente['username'];
    $_SESSION['nome'] = $nome;
    $_SESSION['cognome'] = $cognome;
    $_SESSION['nome_completo'] = $nomeCompleto !== '' ? $nomeCompleto : (string)$utente['username'];
    $_SESSION['email'] = (string)($utente['email'] ?? '');
    $_SESSION['ruolo'] = $ruoli[0] ?? '';
    $_SESSION['ruoli'] = $ruoli;
    $_SESSION['ruoli_descrizioni'] = $ruoliDescrizioni;
    $_SESSION['ha_ruoli'] = count($ruoli) > 0;
    $_SESSION['ruolo_attivo'] = $ruoli[0] ?? null;
    $_SESSION['ruolo_attivo_descrizione'] = $ruoliDescrizioni[0] ?? null;
    $_SESSION['deve_cambiare_password'] = (int)$utente['deve_cambiare_password'];

    // Ricostruisce la mappa legacy come nel login reale, ma con una query
    // limitata alle sole pagine e ai soli permessi consentiti. Serve perche'
    // alcune pagine usano ancora il fallback legacy view/edit.
    $permessiLegacy = [];
    $stmtPermessiLegacy = $pdo->prepare("
        SELECT
            ars.codice_risorsa,
            arp.permesso
        FROM aut_utenti_ruoli ur
        INNER JOIN aut_ruoli r
            ON r.id_ruolo = ur.id_ruolo
           AND r.attivo = 1
        INNER JOIN aut_ruoli_permessi arp
            ON arp.id_ruolo = r.id_ruolo
           AND arp.consentito = 1
           AND arp.permesso IN ('view','read','edit','write','create','delete','execute')
        INNER JOIN aut_risorse ars
            ON ars.id_risorsa = arp.id_risorsa
           AND ars.attivo = 1
           AND ars.codice_risorsa LIKE 'pagina.%'
        WHERE ur.id_utente = :id_utente
          AND ur.attivo = 1
          AND (ur.data_fine IS NULL OR ur.data_fine >= NOW())
    ");
    $stmtPermessiLegacy->execute(['id_utente' => $idUtente]);

    while ($rigaPermesso = $stmtPermessiLegacy->fetch(PDO::FETCH_ASSOC)) {
        $codiceRisorsa = (string)($rigaPermesso['codice_risorsa'] ?? '');
        $permesso = strtolower(trim((string)($rigaPermesso['permesso'] ?? '')));
        $codiceModulo = substr($codiceRisorsa, 7);

        if ($codiceModulo === '') {
            continue;
        }

        if (in_array($permesso, ['edit','write','create','delete','execute'], true)) {
            $permessiLegacy[$codiceModulo] = 'write';
        } elseif (!isset($permessiLegacy[$codiceModulo]) && in_array($permesso, ['view','read'], true)) {
            $permessiLegacy[$codiceModulo] = 'read';
        }
    }

    $_SESSION['permessi'] = $permessiLegacy;
    $_SESSION['utente_senza_ruolo'] = count($ruoli) === 0 ? 1 : 0;
}

function impersonazioneAttiva(): bool
{
    return !empty($_SESSION['impersonazione_attiva'])
        && isset($_SESSION['impersonazione_origine'])
        && is_array($_SESSION['impersonazione_origine']);
}

function utentePuoImpersonare(): bool
{
    if (impersonazioneAttiva()) {
        return false;
    }

    $ruoli = $_SESSION['ruoli'] ?? [];
    return is_array($ruoli) && in_array('admin_portale', $ruoli, true);
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
        !impersonazioneAttiva() &&
        isset($_SESSION['deve_cambiare_password']) &&
        (int)$_SESSION['deve_cambiare_password'] === 1 &&
        $paginaCorrente !== 'cambia_password.php' &&
        $paginaCorrente !== 'logout.php'
    ) {
        header('Location: cambia_password.php');
        exit;
    }

    // "Visualizza come" e' volutamente in sola lettura: l'amministratore vede
    // menu, dati e controlli dell'utente, ma non puo' produrre modifiche a suo nome.
    if (
        impersonazioneAttiva() &&
        strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' &&
        !in_array($paginaCorrente, ['visualizza_come.php', 'logout.php'], true)
    ) {
        http_response_code(403);
        die('Modalita Visualizza come: operazioni di modifica disabilitate. Torna amministratore per eseguire operazioni.');
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
