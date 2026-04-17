<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $dbHost, $dbName, $dbUser, $dbPass;

    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        http_response_code(500);
        die('Errore di connessione al database.');
    }

    return $pdo;
}

/*
 * Retrocompatibilità:
 * se qualche file vecchio usa ancora direttamente $pdo dopo require_once db.php,
 * continuiamo a renderlo disponibile.
 */
$pdo = db();