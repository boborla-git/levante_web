<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$logName = 'import_ordini_fornitori_aperti';

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
            'modulo' => 'ordini_fornitori_aperti',
            'righe_ricevute' => count($righe),
            'prima_riga' => $righe[0] ?? null
        ]);
    }

    syncLog($logName, 'Righe ricevute: ' . count($righe));

    $pdo = syncPdo();
    $pdo->beginTransaction();

    // DELETE invece di TRUNCATE: TRUNCATE su MySQL fa implicit commit.
    $pdo->exec('DELETE FROM ordini_fornitori_aperti');

    $sql = "
        INSERT INTO ordini_fornitori_aperti (
            n_ordine,
            data_ordine,
            n_riga,
            fornitore,
            data_consegna,
            articolo,
            descrizione,
            q_ordinata,
            q_consegnata,
            q_residua,
            progr_1,
            progr_2,
            nota,
            ultimo_sollecito_mail
        ) VALUES (
            :n_ordine,
            :data_ordine,
            :n_riga,
            :fornitore,
            :data_consegna,
            :articolo,
            :descrizione,
            :q_ordinata,
            :q_consegnata,
            :q_residua,
            :progr_1,
            :progr_2,
            :nota,
            :ultimo_sollecito_mail
        )
    ";

    $stmt = $pdo->prepare($sql);
    $conteggio = 0;

    foreach ($righe as $idx => $riga) {
        try {
            $nOrdine = trim((string)($riga['N_Ordine'] ?? ''));
            if ($nOrdine === '') {
                throw new RuntimeException('N_Ordine vuoto');
            }

            $stmt->execute([
                'n_ordine' => mb_substr($nOrdine, 0, 15),
                'data_ordine' => syncDateOrDefault($riga['Data_Ordine'] ?? null, '1000-01-01'),
                'n_riga' => syncNumber($riga['N_Riga'] ?? 0),
                'fornitore' => ($tmp = syncNullIfEmpty($riga['Fornitore'] ?? null)) !== null ? mb_substr($tmp, 0, 100) : null,
                'data_consegna' => syncDateOrNull($riga['Data_Consegna'] ?? null),
                'articolo' => ($tmp = syncNullIfEmpty($riga['Articolo'] ?? null)) !== null ? mb_substr($tmp, 0, 15) : null,
                'descrizione' => ($tmp = syncNullIfEmpty($riga['Descrizione'] ?? null)) !== null ? mb_substr($tmp, 0, 200) : null,
                'q_ordinata' => syncNumber($riga['Q_Ordinata'] ?? 0),
                'q_consegnata' => syncNumber($riga['Q_Consegnata'] ?? 0),
                'q_residua' => syncNumber($riga['Q_Residua'] ?? 0),
                'progr_1' => (int)($riga['progr_1'] ?? 0),
                'progr_2' => ($riga['progr_2'] ?? null) === '' || !isset($riga['progr_2']) ? null : (int)$riga['progr_2'],
                'nota' => ($tmp = syncNullIfEmpty($riga['nota'] ?? null)) !== null ? mb_substr($tmp, 0, 500) : null,
                'ultimo_sollecito_mail' => syncDateOrNull($riga['Ultimo_Sollecito_Mail'] ?? null),
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
        'ordini_fornitori_aperti',
        $conteggio,
        'OK',
        'Import completato'
    );

    syncLog($logName, 'Import completato. Righe inserite: ' . $conteggio);

    syncResponse(200, [
        'ok' => true,
        'modulo' => 'ordini_fornitori_aperti',
        'righe_importate' => $conteggio
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            syncUpdateState($pdo, 'ordini_fornitori_aperti', 0, 'ERRORE', $e->getMessage());
        } catch (Throwable $ignore) {
        }
    }

    syncLog($logName, 'ERRORE FATALE: ' . $e->getMessage());

    syncResponse(500, [
        'ok' => false,
        'errore' => $e->getMessage()
    ]);
}