<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Verifica se l'utente corrente ha un permesso su una risorsa.
 * Ritorna true/false.
 * Se il nuovo modello non trova nulla, usa il fallback legacy.
 */
function haPermesso(string $codiceRisorsa, string $permesso = 'view'): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $idUtente = 0;

    if (isset($_SESSION['id_utente']) && (int)$_SESSION['id_utente'] > 0) {
        $idUtente = (int)$_SESSION['id_utente'];
    } elseif (isset($_SESSION['utente_id']) && (int)$_SESSION['utente_id'] > 0) {
        $idUtente = (int)$_SESSION['utente_id'];
    }

    if ($idUtente <= 0) {
        return false;
    }

    $esitoNuovo = haPermessoNuovoModello($idUtente, $codiceRisorsa, $permesso);

    if ($esitoNuovo !== null) {
        return $esitoNuovo;
    }

    return haPermessoModelloAttuale($codiceRisorsa, $permesso);
}

/**
 * Ritorna:
 * - true  => permesso trovato e consentito
 * - false => permesso trovato ma negato
 * - null  => nessuna regola nuova trovata
 */
function haPermessoNuovoModello(int $idUtente, string $codiceRisorsa, string $permesso): ?bool
{
    $pdo = db();

    $sql = "
        SELECT 
            MAX(CASE WHEN arp.consentito = 1 THEN 1 ELSE 0 END) AS consentito_massimo,
            COUNT(*) AS righe_trovate
        FROM aut_utenti_ruoli aur
        INNER JOIN aut_ruoli ar
            ON ar.id_ruolo = aur.id_ruolo
           AND ar.attivo = 1
        INNER JOIN aut_ruoli_permessi arp
            ON arp.id_ruolo = ar.id_ruolo
        INNER JOIN aut_risorse ars
            ON ars.id_risorsa = arp.id_risorsa
           AND ars.attivo = 1
        WHERE aur.id_utente = :id_utente
          AND aur.attivo = 1
          AND (aur.data_fine IS NULL OR aur.data_fine >= NOW())
          AND ars.codice_risorsa = :codice_risorsa
          AND arp.permesso = :permesso
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_utente' => $idUtente,
        ':codice_risorsa' => $codiceRisorsa,
        ':permesso' => $permesso,
    ]);

    $riga = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$riga) {
        return null;
    }

    $righeTrovate = (int)($riga['righe_trovate'] ?? 0);

    if ($righeTrovate === 0) {
        return null;
    }

    return (int)($riga['consentito_massimo'] ?? 0) === 1;
}

/**
 * Fallback al sistema attuale.
 * Richiama funzioni definite in auth.php.
 */
function haPermessoModelloAttuale(string $codiceRisorsa, string $permesso): bool
{
    $pagina = normalizzaCodiceVecchio($codiceRisorsa);

    if ($pagina === '') {
        return false;
    }

    if ($permesso === 'view') {
        return function_exists('haPermessoLetturaLegacy')
            ? haPermessoLetturaLegacy($pagina)
            : false;
    }

    if (in_array($permesso, ['edit', 'write', 'create', 'delete', 'execute'], true)) {
        return function_exists('haPermessoScritturaLegacy')
            ? haPermessoScritturaLegacy($pagina)
            : false;
    }

    return false;
}

function normalizzaCodiceVecchio(string $codiceRisorsa): string
{
    if (strpos($codiceRisorsa, 'pagina.') === 0) {
        return substr($codiceRisorsa, 7);
    }

    return '';
}

function registraLogAccesso(string $codiceRisorsa, string $azione, string $esito): void
{
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $pdo = db();

        $idUtente = null;
        if (isset($_SESSION['id_utente']) && (int)$_SESSION['id_utente'] > 0) {
            $idUtente = (int)$_SESSION['id_utente'];
        } elseif (isset($_SESSION['utente_id']) && (int)$_SESSION['utente_id'] > 0) {
            $idUtente = (int)$_SESSION['utente_id'];
        }

        $username = isset($_SESSION['username']) ? (string)$_SESSION['username'] : null;
        $indirizzoIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $sql = "
            INSERT INTO aut_log_accessi
            (
                id_utente,
                username,
                codice_risorsa,
                azione,
                esito,
                indirizzo_ip,
                user_agent,
                data_evento
            )
            VALUES
            (
                :id_utente,
                :username,
                :codice_risorsa,
                :azione,
                :esito,
                :indirizzo_ip,
                :user_agent,
                NOW()
            )
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_utente' => $idUtente,
            ':username' => $username,
            ':codice_risorsa' => $codiceRisorsa,
            ':azione' => $azione,
            ':esito' => $esito,
            ':indirizzo_ip' => $indirizzoIp,
            ':user_agent' => $userAgent,
        ]);
    } catch (Throwable $e) {
        // mai bloccare il sito per errore di log
    }
}