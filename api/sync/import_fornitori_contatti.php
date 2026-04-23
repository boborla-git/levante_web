<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$logName = 'import_fornitori_contatti';

try {
    syncRequireMethod('POST');
    syncRequireToken();

    $payload = syncReadJsonBody();
    $righe = $payload['righe'] ?? null;

    if (!is_array($righe)) {
        syncResponse(400, [
            'ok' => false,
            'errore' => 'Chiave "righe" mancante o non valida'
        ]);
    }

    $dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

    if ($dryRun) {
        syncResponse(200, [
            'ok' => true,
            'dry_run' => true,
            'modulo' => 'fornitori_contatti',
            'righe_ricevute' => count($righe),
            'prima_riga' => $righe[0] ?? null
        ]);
    }

    syncLog($logName, 'Righe ricevute: ' . count($righe));

    $pdo = syncPdo();
    $pdo->beginTransaction();

    // DELETE invece di TRUNCATE: TRUNCATE su MySQL fa implicit commit.
    $pdo->exec('DELETE FROM fornitori_contatti');

    $sql = "
        INSERT INTO fornitori_contatti (
            fornitore,
            telefono,
            email,
            indirizzo
        ) VALUES (
            :fornitore,
            :telefono,
            :email,
            :indirizzo
        )
    ";

    $stmt = $pdo->prepare($sql);
    $conteggio = 0;

    foreach ($righe as $idx => $riga) {
        try {
            $fornitore = syncNullIfEmpty($riga['Fornitore'] ?? null);

            if ($fornitore === null) {
                continue;
            }

            $stmt->execute([
                'fornitore' => mb_substr($fornitore, 0, 100),
                'telefono' => ($tmp = syncNullIfEmpty($riga['Telefono'] ?? null)) !== null ? mb_substr($tmp, 0, 50) : null,
                'email' => ($tmp = syncNullIfEmpty($riga['Email'] ?? null)) !== null ? mb_substr($tmp, 0, 400) : null,
                'indirizzo' => ($tmp = syncNullIfEmpty($riga['Indirizzo'] ?? null)) !== null ? mb_substr($tmp, 0, 500) : null,
            ]);

            $conteggio++;
        } catch (Throwable $eRiga) {
            syncLog($logName, 'Errore riga indice ' . $idx . ': ' . $eRiga->getMessage());
            syncLog($logName, 'Contenuto riga: ' . json_encode($riga, JSON_UNESCAPED_UNICODE));
            throw $eRiga;
        }
    }

    $pdo->commit();

    syncUpdateState(
        $pdo,
        'fornitori_contatti',
        $conteggio,
        'OK',
        'Import completato'
    );

    syncLog($logName, 'Import completato. Righe inserite: ' . $conteggio);

    syncResponse(200, [
        'ok' => true,
        'modulo' => 'fornitori_contatti',
        'righe_importate' => $conteggio
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            syncUpdateState($pdo, 'fornitori_contatti', 0, 'ERRORE', $e->getMessage());
        } catch (Throwable $ignore) {
        }
    }

    syncLog($logName, 'ERRORE FATALE: ' . $e->getMessage());

    syncResponse(500, [
        'ok' => false,
        'errore' => $e->getMessage()
    ]);
}