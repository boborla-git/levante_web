<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hr_riepilogo_assenze.php';

$pdo = db();

if (PHP_SAPI !== 'cli') {
    $token = trim((string)($_GET['token'] ?? ''));
    $stmt = $pdo->query("SELECT valore FROM hr_configurazioni WHERE codice='HR_CRON_TOKEN' AND attivo=1 LIMIT 1");
    $atteso = trim((string)($stmt->fetchColumn() ?: ''));

    if ($atteso === '' || !hash_equals($atteso, $token)) {
        http_response_code(403);
        exit('Accesso negato');
    }
}

header('Content-Type: text/plain; charset=UTF-8');

$oggi = date('Y-m-d');
if ((int)date('N') > 5) {
    exit("Weekend: nessun invio\n");
}

$esito = hrRiepilogoAssenzeInvia($pdo, $oggi, 'MATTINO', null);

echo 'Riepilogo assenze ' . $oggi
    . ' - inviate: ' . (int)$esito['inviate']
    . ' - errori: ' . (int)$esito['errori']
    . ' - saltate: ' . (int)$esito['saltate']
    . "\n";
