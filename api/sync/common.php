<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');

function syncResponse(int $httpCode, array $data): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function syncLogPath(string $name): string
{
    $dir = __DIR__ . '/../../logs';

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/' . $name . '.log';
}

function syncLog(string $name, string $message): void
{
    $file = syncLogPath($name);
    @file_put_contents(
        $file,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function syncRequireMethod(string $method): void
{
    $current = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');

    if ($current !== strtoupper($method)) {
        syncResponse(405, [
            'ok' => false,
            'errore' => 'Metodo non consentito'
        ]);
    }
}

function syncRequireToken(): void
{
    global $syncToken;

    $tokenRicevuto = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';

    if (!hash_equals((string)$syncToken, (string)$tokenRicevuto)) {
        syncResponse(401, [
            'ok' => false,
            'errore' => 'Token non valido'
        ]);
    }
}

function syncReadJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        syncResponse(400, [
            'ok' => false,
            'errore' => 'Body richiesta vuoto'
        ]);
    }

    if (!mb_check_encoding($raw, 'UTF-8')) {
        syncResponse(400, [
            'ok' => false,
            'errore' => 'Body richiesta non in UTF-8 valido'
        ]);
    }

    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        syncResponse(400, [
            'ok' => false,
            'errore' => 'JSON non valido: ' . json_last_error_msg()
        ]);
    }

    return $payload;
}

function syncPdo(): PDO
{
    global $dbHost, $dbName, $dbUser, $dbPass;

    return new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function syncNullIfEmpty($value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function syncNumber($value): string
{
    if ($value === null || $value === '') {
        return '0';
    }

    return str_replace(',', '.', trim((string)$value));
}

function syncDateOrNull($value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);

    if ($value === '' || $value === '0000-00-00') {
        return null;
    }

    return $value;
}

function syncDateOrDefault($value, string $default): string
{
    $v = syncDateOrNull($value);
    return $v ?? $default;
}

function syncUpdateState(
    PDO $pdo,
    string $modulo,
    ?int $righeImportate,
    string $esito,
    ?string $messaggio = null
): void {
    $sql = "
        INSERT INTO sync_stato (
            modulo,
            ultimo_aggiornamento,
            righe_importate,
            esito,
            messaggio
        ) VALUES (
            :modulo,
            NOW(),
            :righe_importate,
            :esito,
            :messaggio
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'modulo' => $modulo,
        'righe_importate' => $righeImportate,
        'esito' => $esito,
        'messaggio' => $messaggio !== null ? mb_substr($messaggio, 0, 255) : null,
    ]);
}