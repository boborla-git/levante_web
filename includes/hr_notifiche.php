<?php
declare(strict_types=1);

/**
 * Helper comuni per le notifiche HR.
 *
 * Contratto funzionale:
 * - crea solo notifiche web se il canale WEB è attivo;
 * - ignora destinatari vuoti o non validi;
 * - non genera duplicati nella stessa chiamata;
 * - non deve interrompere il workflow se non ci sono destinatari validi.
 */

if (!function_exists('hrIdCanaleNotifica')) {
    function hrIdCanaleNotifica(PDO $pdo, string $codice): ?int
    {
        static $cache = [];

        if (array_key_exists($codice, $cache)) {
            return $cache[$codice];
        }

        $stmt = $pdo->prepare(
            'SELECT id_canale_notifica
             FROM hr_canali_notifica
             WHERE codice = :codice
               AND attivo = 1
             LIMIT 1'
        );
        $stmt->execute(['codice' => $codice]);
        $id = $stmt->fetchColumn();

        $cache[$codice] = $id === false ? null : (int)$id;
        return $cache[$codice];
    }
}

if (!function_exists('hrCreaNotificaWeb')) {
    function hrCreaNotificaWeb(
        PDO $pdo,
        string $tipoEvento,
        string $titolo,
        string $messaggio,
        ?string $link,
        ?int $idRichiesta,
        ?int $creatoDa,
        array $destinatari
    ): void {
        $destinatari = array_values(array_unique(array_filter(
            array_map('intval', $destinatari),
            static fn (int $v): bool => $v > 0
        )));

        if (count($destinatari) === 0) {
            return;
        }

        $idCanaleWeb = hrIdCanaleNotifica($pdo, 'WEB');
        if ($idCanaleWeb === null) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO hr_notifiche
                (tipo_evento, titolo, messaggio, link, id_richiesta, creato_da)
             VALUES
                (:tipo_evento, :titolo, :messaggio, :link, :id_richiesta, :creato_da)'
        );
        $stmt->execute([
            'tipo_evento' => $tipoEvento,
            'titolo' => $titolo,
            'messaggio' => $messaggio,
            'link' => $link,
            'id_richiesta' => $idRichiesta,
            'creato_da' => $creatoDa,
        ]);

        $idNotifica = (int)$pdo->lastInsertId();

        $stmtDest = $pdo->prepare(
            'INSERT INTO hr_notifiche_destinatari
                (id_notifica, id_utente, id_canale_notifica, inviata, letta, data_invio)
             VALUES
                (:id_notifica, :id_utente, :id_canale_notifica, 1, 0, NOW())'
        );

        foreach ($destinatari as $idUtenteDest) {
            $stmtDest->execute([
                'id_notifica' => $idNotifica,
                'id_utente' => $idUtenteDest,
                'id_canale_notifica' => $idCanaleWeb,
            ]);
        }
    }
}
