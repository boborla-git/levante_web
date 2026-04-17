<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$logName = 'ack_note_ordini_fornitori';

try {
    syncRequireMethod('POST');
    syncRequireToken();

    $payload = syncReadJsonBody();
    $pdo = syncPdo();

    $pingOnly = !empty($payload['ping_only']);

    if ($pingOnly) {
        syncUpdateState(
            $pdo,
            'ordini_fornitori_note_export',
            0,
            'OK',
            'Nessuna nota da esportare'
        );

        syncLog($logName, 'Ping ricevuto: nessuna nota da esportare');

        syncResponse(200, [
            'ok' => true,
            'ping_only' => true,
            'righe_aggiornate' => 0
        ]);
    }

    $ids = $payload['ids'] ?? null;

    if (!is_array($ids) || count($ids) === 0) {
        syncResponse(400, [
            'ok' => false,
            'errore' => 'Lista ids mancante o vuota'
        ]);
    }

    $idsPuliti = [];

    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $idsPuliti[] = $id;
        }
    }

    $idsPuliti = array_values(array_unique($idsPuliti));

    if (count($idsPuliti) === 0) {
        syncResponse(400, [
            'ok' => false,
            'errore' => 'Nessun id valido ricevuto'
        ]);
    }

    $placeholders = implode(',', array_fill(0, count($idsPuliti), '?'));

    $sql = "
        UPDATE ordini_fornitori_note_storico
        SET importato_locale = 1,
            data_import_locale = NOW()
        WHERE id_evento IN ($placeholders)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($idsPuliti);

    $righeAggiornate = $stmt->rowCount();

    syncUpdateState(
        $pdo,
        'ordini_fornitori_note_export',
        $righeAggiornate,
        'OK',
        'ACK completato'
    );

    syncLog($logName, 'ACK completato. ID ricevuti: ' . count($idsPuliti) . ' - righe aggiornate: ' . $righeAggiornate);

    syncResponse(200, [
        'ok' => true,
        'ids_ricevuti' => count($idsPuliti),
        'righe_aggiornate' => $righeAggiornate
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            syncUpdateState($pdo, 'ordini_fornitori_note_export', 0, 'ERRORE', $e->getMessage());
        } catch (Throwable $ignore) {
        }
    }

    syncLog($logName, 'ERRORE FATALE: ' . $e->getMessage());

    syncResponse(500, [
        'ok' => false,
        'errore' => $e->getMessage()
    ]);
}