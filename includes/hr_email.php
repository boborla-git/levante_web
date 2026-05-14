<?php
declare(strict_types=1);

/**
 * Helper email HR.
 *
 * Questo file prepara l'infrastruttura di invio email HR usando SOLO le
 * configurazioni gia presenti in hr_configurazioni:
 *
 * - HR_NOTIFICA_EMAIL_ATTIVA
 * - HR_EMAIL_FROM
 * - HR_EMAIL_FROM_NAME
 * - HR_EMAIL_MITTENTE
 * - HR_EMAIL_NOME_MITTENTE
 * - HR_URL_PORTALE
 *
 * Nota importante:
 * il file NON invia email da solo. Le funzioni vanno richiamate
 * esplicitamente dalle pagine HR quando il flusso verra attivato.
 */

if (!function_exists('hrEmailConfig')) {
    function hrEmailConfig(PDO $pdo): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $codici = [
            'HR_NOTIFICA_EMAIL_ATTIVA',
            'HR_EMAIL_FROM',
            'HR_EMAIL_FROM_NAME',
            'HR_EMAIL_MITTENTE',
            'HR_EMAIL_NOME_MITTENTE',
            'HR_URL_PORTALE',
        ];

        $placeholders = implode(',', array_fill(0, count($codici), '?'));
        $stmt = $pdo->prepare(
            "SELECT codice, valore
             FROM hr_configurazioni
             WHERE attivo = 1
               AND codice IN ($placeholders)"
        );
        $stmt->execute($codici);

        $valori = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $valori[(string)$row['codice']] = trim((string)($row['valore'] ?? ''));
        }

        $fromEmail = $valori['HR_EMAIL_FROM'] ?? '';
        if ($fromEmail === '') {
            $fromEmail = $valori['HR_EMAIL_MITTENTE'] ?? '';
        }

        $fromName = $valori['HR_EMAIL_FROM_NAME'] ?? '';
        if ($fromName === '') {
            $fromName = $valori['HR_EMAIL_NOME_MITTENTE'] ?? '';
        }

        $baseUrl = rtrim($valori['HR_URL_PORTALE'] ?? '', '/');

        $cache = [
            'attiva' => ($valori['HR_NOTIFICA_EMAIL_ATTIVA'] ?? '0') === '1',
            'from_email' => $fromEmail,
            'from_name' => $fromName !== '' ? $fromName : 'Portale HR',
            'base_url' => $baseUrl,
        ];

        return $cache;
    }
}

if (!function_exists('hrEmailEncodeHeader')) {
    function hrEmailEncodeHeader(string $valore): string
    {
        $valore = trim($valore);

        if ($valore === '') {
            return '';
        }

        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($valore, 'UTF-8', 'B', "\r\n");
        }

        return $valore;
    }
}

if (!function_exists('hrEmailValida')) {
    function hrEmailValida(?string $email): ?string
    {
        $email = trim((string)$email);

        if ($email === '') {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}

if (!function_exists('hrUrlAssoluto')) {
    function hrUrlAssoluto(PDO $pdo, ?string $link): string
    {
        $link = trim((string)$link);

        if ($link === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $link) === 1) {
            return $link;
        }

        $config = hrEmailConfig($pdo);
        $baseUrl = (string)$config['base_url'];

        if ($baseUrl === '') {
            return $link;
        }

        return $baseUrl . '/' . ltrim($link, '/');
    }
}

if (!function_exists('hrEmailDestinatariUtenti')) {
    function hrEmailDestinatariUtenti(PDO $pdo, array $idUtenti): array
    {
        $idUtenti = array_values(array_unique(array_filter(
            array_map('intval', $idUtenti),
            static fn (int $id): bool => $id > 0
        )));

        if (count($idUtenti) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($idUtenti), '?'));
        $destinatari = [];

        /*
         * Priorita:
         * 1. recapiti HR attivi EMAIL_LAVORO / EMAIL_PERSONALE
         * 2. email anagrafica aut_utenti.email
         */
        $stmtRecapiti = $pdo->prepare(
            "SELECT ru.id_utente, ru.valore
             FROM hr_recapiti_utenti ru
             INNER JOIN hr_tipi_recapito tr
                ON tr.id_tipo_recapito = ru.id_tipo_recapito
               AND tr.attivo = 1
               AND tr.codice IN ('EMAIL_LAVORO', 'EMAIL_PERSONALE')
             WHERE ru.attivo = 1
               AND ru.id_utente IN ($placeholders)
             ORDER BY ru.principale DESC, ru.id_recapito_utente ASC"
        );
        $stmtRecapiti->execute($idUtenti);

        foreach ($stmtRecapiti->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $idUtente = (int)$row['id_utente'];

            if (isset($destinatari[$idUtente])) {
                continue;
            }

            $email = hrEmailValida($row['valore'] ?? null);
            if ($email !== null) {
                $destinatari[$idUtente] = $email;
            }
        }

        $stmtUtenti = $pdo->prepare(
            "SELECT id_utente, email
             FROM aut_utenti
             WHERE attivo = 1
               AND id_utente IN ($placeholders)"
        );
        $stmtUtenti->execute($idUtenti);

        foreach ($stmtUtenti->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $idUtente = (int)$row['id_utente'];

            if (isset($destinatari[$idUtente])) {
                continue;
            }

            $email = hrEmailValida($row['email'] ?? null);
            if ($email !== null) {
                $destinatari[$idUtente] = $email;
            }
        }

        return $destinatari;
    }
}

if (!function_exists('hrInviaEmail')) {
    function hrInviaEmail(
        PDO $pdo,
        string $destinatario,
        string $oggetto,
        string $messaggioTesto,
        ?string $link = null
    ): array {
        $config = hrEmailConfig($pdo);

        if (!$config['attiva']) {
            return [
                'inviata' => false,
                'motivo' => 'Email HR disattivate da configurazione.',
            ];
        }

        $to = hrEmailValida($destinatario);
        if ($to === null) {
            return [
                'inviata' => false,
                'motivo' => 'Destinatario email non valido.',
            ];
        }

        $fromEmail = hrEmailValida((string)$config['from_email']);
        if ($fromEmail === null) {
            return [
                'inviata' => false,
                'motivo' => 'Mittente email HR non configurato correttamente.',
            ];
        }

        $oggetto = trim($oggetto);
        $messaggioTesto = trim($messaggioTesto);
        $url = hrUrlAssoluto($pdo, $link);

        if ($url !== '') {
            $messaggioTesto .= "\n\nApri nel portale:\n" . $url;
        }

        $messaggioTesto .= "\n\n--\n" . (string)$config['from_name'];

        $fromName = hrEmailEncodeHeader((string)$config['from_name']);
        $subject = hrEmailEncodeHeader($oggetto);

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'X-Mailer: Ravioli Portale HR',
        ];

        $parametri = '';
        if ($fromEmail !== '') {
            $parametri = '-f' . $fromEmail;
        }

        $ok = $parametri !== ''
            ? @mail($to, $subject, $messaggioTesto, implode("\r\n", $headers), $parametri)
            : @mail($to, $subject, $messaggioTesto, implode("\r\n", $headers));

        return [
            'inviata' => (bool)$ok,
            'motivo' => $ok ? null : 'Invio mail() non riuscito.',
        ];
    }
}
