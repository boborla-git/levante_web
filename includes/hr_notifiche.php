<?php
declare(strict_types=1);

require_once __DIR__ . '/hr_email.php';

if (!function_exists('hrIdCanaleNotifica')) {
    function hrIdCanaleNotifica(PDO $pdo, string $codice): ?int
    {
        static $cache = [];

        $codice = strtoupper(trim($codice));

        if ($codice === '') {
            return null;
        }

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

if (!function_exists('hrCreaNotificaEmailPerUtenti')) {
    /**
     * Crea e invia notifiche email HR per una lista di utenti.
     *
     * Nota importante:
     * questa funzione NON viene richiamata automaticamente da questo file.
     * Le pagine HR la useranno solo quando decideremo insieme di attivare
     * il canale email sui singoli eventi.
     *
     * Restituisce un riepilogo:
     * - tentate
     * - inviate
     * - saltate
     * - errori
     */
    function hrCreaNotificaEmailPerUtenti(
        PDO $pdo,
        string $tipoEvento,
        string $titolo,
        string $messaggio,
        ?string $link,
        ?int $idRichiesta,
        ?int $creatoDa,
        array $destinatari
    ): array {
        $riepilogo = [
            'tentate' => 0,
            'inviate' => 0,
            'saltate' => 0,
            'errori' => [],
        ];

        $destinatari = array_values(array_unique(array_filter(
            array_map('intval', $destinatari),
            static fn (int $v): bool => $v > 0
        )));

        if (count($destinatari) === 0) {
            return $riepilogo;
        }

        $idCanaleEmail = hrIdCanaleNotifica($pdo, 'EMAIL');

        if ($idCanaleEmail === null) {
            $riepilogo['errori'][] = 'Canale EMAIL non configurato o non attivo.';
            return $riepilogo;
        }

        $emailDestinatari = hrEmailDestinatariUtenti($pdo, $destinatari);

        $stmtNotifica = $pdo->prepare(
            'INSERT INTO hr_notifiche
                (tipo_evento, titolo, messaggio, link, id_richiesta, creato_da)
             VALUES
                (:tipo_evento, :titolo, :messaggio, :link, :id_richiesta, :creato_da)'
        );

        $stmtDest = $pdo->prepare(
            'INSERT INTO hr_notifiche_destinatari
                (id_notifica, id_utente, id_canale_notifica, inviata, letta, data_invio, errore_invio)
             VALUES
                (:id_notifica, :id_utente, :id_canale_notifica, :inviata, 0, :data_invio, :errore_invio)'
        );

        foreach ($destinatari as $idUtenteDest) {
            $riepilogo['tentate']++;

            $email = $emailDestinatari[$idUtenteDest] ?? null;

            if ($email === null) {
                $riepilogo['saltate']++;

                $stmtNotifica->execute([
                    'tipo_evento' => $tipoEvento,
                    'titolo' => $titolo,
                    'messaggio' => $messaggio,
                    'link' => $link,
                    'id_richiesta' => $idRichiesta,
                    'creato_da' => $creatoDa,
                ]);

                $stmtDest->execute([
                    'id_notifica' => (int)$pdo->lastInsertId(),
                    'id_utente' => $idUtenteDest,
                    'id_canale_notifica' => $idCanaleEmail,
                    'inviata' => 0,
                    'data_invio' => null,
                    'errore_invio' => 'Nessun indirizzo email valido trovato per l\'utente.',
                ]);

                continue;
            }

            $esito = hrInviaEmail($pdo, $email, $titolo, $messaggio, $link);

            $stmtNotifica->execute([
                'tipo_evento' => $tipoEvento,
                'titolo' => $titolo,
                'messaggio' => $messaggio,
                'link' => $link,
                'id_richiesta' => $idRichiesta,
                'creato_da' => $creatoDa,
            ]);

            $stmtDest->execute([
                'id_notifica' => (int)$pdo->lastInsertId(),
                'id_utente' => $idUtenteDest,
                'id_canale_notifica' => $idCanaleEmail,
                'inviata' => $esito['inviata'] ? 1 : 0,
                'data_invio' => $esito['inviata'] ? date('Y-m-d H:i:s') : null,
                'errore_invio' => $esito['inviata'] ? null : (string)($esito['motivo'] ?? 'Invio email non riuscito.'),
            ]);

            if ($esito['inviata']) {
                $riepilogo['inviate']++;
            } else {
                $riepilogo['saltate']++;
                $riepilogo['errori'][] = (string)($esito['motivo'] ?? 'Invio email non riuscito.');
            }
        }

        return $riepilogo;
    }
}
