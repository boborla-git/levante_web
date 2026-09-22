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

// Aruba pianifica i cron in UTC. Il job deve quindi essere richiamato sia alle
// 05:45 sia alle 06:45 UTC dal lunedi al venerdi. Solo una delle due chiamate
// cade nella finestra italiana delle 07:45, a seconda di ora legale/solare.
// L'altra viene ignorata. In questo modo non serve modificare il cron due volte l'anno.
$minutiLocali = ((int)$oraRoma->format('H') * 60) + (int)$oraRoma->format('i');
$finestraDa = (7 * 60) + 30;  // 07:30 Europe/Rome
$finestraA = (8 * 60) + 15;   // 08:15 Europe/Rome

if ($minutiLocali < $finestraDa || $minutiLocali > $finestraA) {
    exit('Fuori finestra invio: ora italiana ' . $oraRoma->format('H:i') . " - nessun invio\n");
}

$esito = hrRiepilogoAssenzeInvia($pdo, $oggi, 'MATTINO', null);

echo 'Riepilogo assenze ' . $oggi
    . ' - inviate: ' . (int)$esito['inviate']
    . ' - errori: ' . (int)$esito['errori']
    . ' - saltate: ' . (int)$esito['saltate']
    . "\n";
