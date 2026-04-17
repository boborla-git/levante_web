<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$logName = 'export_note_ordini_fornitori';

try {
    syncRequireMethod('GET');
    syncRequireToken();

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 500;
    if ($limit <= 0) {
        $limit = 500;
    }
    if ($limit > 5000) {
        $limit = 5000;
    }

    $pdo = syncPdo();

    $sql = "
        SELECT
            id_evento,
            n_ordine,
            n_riga,
            fornitore,
            articolo,
            nota_ravioli,
            nota_fornitore,
            data_evento,
            utente_modifica,
            origine,
            importante
        FROM ordini_fornitori_note_storico
        WHERE importato_locale = 0
        ORDER BY id_evento ASC
        LIMIT :limite
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $righe = $stmt->fetchAll();

    syncLog($logName, 'Righe esportate: ' . count($righe));

    syncResponse(200, [
        'ok' => true,
        'modulo' => 'ordini_fornitori_note_storico',
        'conteggio' => count($righe),
        'righe' => $righe
    ]);
} catch (Throwable $e) {
    syncLog($logName, 'ERRORE FATALE: ' . $e->getMessage());

    syncResponse(500, [
        'ok' => false,
        'errore' => $e->getMessage()
    ]);
}