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

if (!function_exists('hrEmailWorkflowAttivo')) {
    /**
     * Interruttore specifico per gli invii email automatici del workflow HR.
     *
     * L'invio reale avviene solo se sono attive entrambe:
     * - HR_NOTIFICA_EMAIL_ATTIVA = 1
     * - HR_EMAIL_WORKFLOW_ATTIVO = 1
     */
    function hrEmailWorkflowAttivo(PDO $pdo): bool
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $config = hrEmailConfig($pdo);

        if (!$config['attiva']) {
            $cache = false;
            return false;
        }

        $stmt = $pdo->prepare(
            "SELECT valore
             FROM hr_configurazioni
             WHERE codice = 'HR_EMAIL_WORKFLOW_ATTIVO'
               AND attivo = 1
             LIMIT 1"
        );
        $stmt->execute();

        $cache = trim((string)($stmt->fetchColumn() ?: '0')) === '1';

        return $cache;
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
     * Regola recapiti:
     * - il richiedente riceve le proprie conferme sulla EMAIL_PERSONALE verificata;
     * - responsabili/HR che ricevono informazioni per ruolo usano la EMAIL_LAVORO verificata.
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

        if (!hrEmailWorkflowAttivo($pdo)) {
            $riepilogo['tentate'] = count($destinatari);
            $riepilogo['saltate'] = count($destinatari);
            $riepilogo['errori'][] = 'Invio email workflow HR non attivo.';
            return $riepilogo;
        }

        $idCanaleEmail = hrIdCanaleNotifica($pdo, 'EMAIL');
        if ($idCanaleEmail === null) {
            $riepilogo['errori'][] = 'Canale EMAIL non configurato o non attivo.';
            return $riepilogo;
        }

        $idRichiedente = 0;
        if ($idRichiesta !== null && $idRichiesta > 0) {
            $stmtRichiedente = $pdo->prepare('SELECT id_utente_richiedente FROM hr_richieste WHERE id_richiesta = :id_richiesta LIMIT 1');
            $stmtRichiedente->execute(['id_richiesta' => $idRichiesta]);
            $idRichiedente = (int)($stmtRichiedente->fetchColumn() ?: 0);
        }

        $stmtRecapito = $pdo->prepare(
            "SELECT ru.valore
             FROM hr_recapiti_utenti ru
             INNER JOIN hr_tipi_recapito tr
                ON tr.id_tipo_recapito = ru.id_tipo_recapito
               AND tr.attivo = 1
               AND tr.codice = :codice_recapito
             WHERE ru.id_utente = :id_utente
               AND ru.attivo = 1
               AND ru.verificato = 1
               AND TRIM(COALESCE(ru.valore, '')) <> ''
             ORDER BY ru.principale DESC, ru.id_recapito_utente DESC
             LIMIT 1"
        );

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

            $codiceRecapito = ($idRichiedente > 0 && $idUtenteDest === $idRichiedente)
                ? 'EMAIL_PERSONALE'
                : 'EMAIL_LAVORO';

            $stmtRecapito->execute([
                'codice_recapito' => $codiceRecapito,
                'id_utente' => $idUtenteDest,
            ]);
            $email = hrEmailValida((string)($stmtRecapito->fetchColumn() ?: ''));

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
                    'errore_invio' => 'Nessuna ' . ($codiceRecapito === 'EMAIL_PERSONALE' ? 'email personale' : 'email di lavoro') . ' verificata disponibile.',
                ]);
                continue;
            }

            $esito = hrInviaEmail(
                $pdo,
                $email,
                $titolo,
                $messaggio,
                $link,
                $idRichiesta,
                $tipoEvento,
                $idUtenteDest
            );

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
}
