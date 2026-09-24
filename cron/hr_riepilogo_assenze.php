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

$oraRoma = hrRiepilogoAssenzeNow();
$oggi = $oraRoma->format('Y-m-d');

if ((int)$oraRoma->format('N') > 5) {
    exit("Weekend: nessun invio\n");
}

// Aruba pianifica i cron in UTC. Per mantenere l'invio alle 09:00 italiane
// sia con ora legale sia con ora solare, il job deve essere richiamato alle
// 07:00 e alle 08:00 UTC dal lunedi al venerdi. Solo una delle due chiamate
// cade nella finestra italiana delle 09:00; l'altra viene ignorata.
// In questo modo non serve modificare il cron due volte l'anno.
$minutiLocali = ((int)$oraRoma->format('H') * 60) + (int)$oraRoma->format('i');
$finestraDa = (8 * 60) + 55;  // 08:55 Europe/Rome
$finestraA = (9 * 60) + 15;   // 09:15 Europe/Rome

if ($minutiLocali < $finestraDa || $minutiLocali > $finestraA) {
    exit('Fuori finestra invio: ora italiana ' . $oraRoma->format('H:i') . " - nessun invio\n");
}

$esito = hrRiepilogoAssenzeInvia($pdo, $oggi, 'MATTINO', null);

echo 'Riepilogo assenze ' . $oggi
    . ' - inviate: ' . (int)$esito['inviate']
    . ' - errori: ' . (int)$esito['errori']
    . ' - saltate: ' . (int)$esito['saltate']
    . "\n";
