<?php
declare(strict_types=1);

/**
 * Helper email HR.
 *
 * Le email HR devono mantenere il template HTML consolidato:
 * - intestazione "Portale HR Ravioli S.p.A."
 * - saluto personalizzato
 * - dettaglio richiesta in tabella
 * - badge stato
 * - CTA "Apri richiesta nel portale"
 * - footer con codice richiesta
 * - eventuale sezione sovrapposizioni per il responsabile
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

if (!function_exists('hrEmailHtml')) {
    function hrEmailHtml(?string $valore): string
    {
        return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
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

if (!function_exists('hrEmailNominativoUtente')) {
    function hrEmailNominativoUtente(PDO $pdo, ?int $idUtente): string
    {
        if ($idUtente === null || $idUtente <= 0) {
            return '';
        }

        $stmt = $pdo->prepare(
            "SELECT nome, cognome, username
             FROM aut_utenti
             WHERE id_utente = :id_utente
             LIMIT 1"
        );
        $stmt->execute(['id_utente' => $idUtente]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return '';
        }

        $nome = trim((string)($row['nome'] ?? ''));
        $cognome = trim((string)($row['cognome'] ?? ''));
        $nominativo = trim($nome . ' ' . $cognome);

        if ($nominativo !== '') {
            return $nominativo;
        }

        return trim((string)($row['username'] ?? ''));
    }
}

if (!function_exists('hrEmailPeriodoRichiesta')) {
    function hrEmailPeriodoRichiesta(array $row): string
    {
        $dataDa = (string)($row['data_da_fmt'] ?? '');
        $dataA = (string)($row['data_a_fmt'] ?? '');
        $tipoPeriodo = strtoupper((string)($row['tipo_periodo'] ?? ''));
        $oraDa = (string)($row['ora_da_fmt'] ?? '');
        $oraA = (string)($row['ora_a_fmt'] ?? '');

        if ($dataA === '' || $dataA === $dataDa) {
            $periodo = $dataDa;
        } else {
            $periodo = $dataDa . ' - ' . $dataA;
        }

        if ($tipoPeriodo === 'ORE' && $oraDa !== '' && $oraA !== '') {
            $periodo .= ' - ' . $oraDa . ' / ' . $oraA;
        } else {
            $periodo .= ' - giornata intera';
        }

        return $periodo;
    }
}

if (!function_exists('hrEmailDettaglioRichiesta')) {
    function hrEmailDettaglioRichiesta(PDO $pdo, ?int $idRichiesta): ?array
    {
        if ($idRichiesta === null || $idRichiesta <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT
                r.id_richiesta,
                r.codice_richiesta,
                r.oggetto,
                r.note_richiedente,
                r.id_utente_richiedente,
                te.descrizione AS tipologia,
                sr.codice AS codice_stato,
                sr.descrizione AS stato,
                CONCAT(TRIM(COALESCE(u.nome, '')), CASE WHEN TRIM(COALESCE(u.cognome, '')) <> '' THEN CONCAT(' ', TRIM(u.cognome)) ELSE '' END) AS richiedente,
                p.tipo_periodo,
                DATE_FORMAT(p.data_da, '%d/%m/%Y') AS data_da_fmt,
                DATE_FORMAT(p.data_a, '%d/%m/%Y') AS data_a_fmt,
                p.data_da,
                p.data_a,
                TIME_FORMAT(p.ora_da, '%H:%i') AS ora_da_fmt,
                TIME_FORMAT(p.ora_a, '%H:%i') AS ora_a_fmt
             FROM hr_richieste r
             INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
             INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
             INNER JOIN aut_utenti u ON u.id_utente = r.id_utente_richiedente
             LEFT JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
             WHERE r.id_richiesta = :id_richiesta
             ORDER BY p.ordinamento ASC, p.id_richiesta_periodo ASC
             LIMIT 1"
        );
        $stmt->execute(['id_richiesta' => $idRichiesta]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('hrEmailRichiestePresentiNelPeriodo')) {
    function hrEmailRichiestePresentiNelPeriodo(PDO $pdo, array $richiesta): array
    {
        $idRichiesta = (int)($richiesta['id_richiesta'] ?? 0);
        $dataDa = (string)($richiesta['data_da'] ?? '');
        $dataA = (string)($richiesta['data_a'] ?? '');

        if ($idRichiesta <= 0 || $dataDa === '' || $dataA === '') {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT
                CONCAT(TRIM(COALESCE(u.nome, '')), CASE WHEN TRIM(COALESCE(u.cognome, '')) <> '' THEN CONCAT(' ', TRIM(u.cognome)) ELSE '' END) AS persona,
                te.descrizione AS tipologia,
                sr.descrizione AS stato,
                p.tipo_periodo,
                DATE_FORMAT(p.data_da, '%d/%m/%Y') AS data_da_fmt,
                DATE_FORMAT(p.data_a, '%d/%m/%Y') AS data_a_fmt,
                TIME_FORMAT(p.ora_da, '%H:%i') AS ora_da_fmt,
                TIME_FORMAT(p.ora_a, '%H:%i') AS ora_a_fmt
             FROM hr_richieste r
             INNER JOIN aut_utenti u ON u.id_utente = r.id_utente_richiedente
             INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
             INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
             INNER JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
             WHERE r.id_richiesta <> :id_richiesta
               AND sr.codice IN ('IN_ATTESA', 'APPROVATA')
               AND p.data_da <= :data_a
               AND p.data_a >= :data_da
             ORDER BY p.data_da ASC, p.ora_da ASC, persona ASC
             LIMIT 10"
        );
        $stmt->execute([
            'id_richiesta' => $idRichiesta,
            'data_da' => $dataDa,
            'data_a' => $dataA,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('hrEmailBadgeStatoHtml')) {
    function hrEmailBadgeStatoHtml(string $codiceStato, string $stato): string
    {
        $colore = '#b45309';
        $sfondo = '#fef3c7';

        if ($codiceStato === 'APPROVATA') {
            $colore = '#15803d';
            $sfondo = '#dcfce7';
        } elseif ($codiceStato === 'RIFIUTATA') {
            $colore = '#b91c1c';
            $sfondo = '#fee2e2';
        } elseif ($codiceStato === 'ANNULLATA') {
            $colore = '#475569';
            $sfondo = '#e2e8f0';
        }

        return '<span style="display:inline-block;padding:3px 8px;border-radius:999px;font-weight:700;font-size:12px;color:' . $colore . ';background:' . $sfondo . ';">'
            . hrEmailHtml($stato)
            . '</span>';
    }
}

if (!function_exists('hrEmailRigaDettaglioHtml')) {
    function hrEmailRigaDettaglioHtml(string $etichetta, string $valoreHtml): string
    {
        return '<tr>'
            . '<td style="width:150px;padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;font-weight:700;font-size:13px;">' . hrEmailHtml($etichetta) . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#111827;font-size:13px;">' . $valoreHtml . '</td>'
            . '</tr>';
    }
}

if (!function_exists('hrEmailCreaCorpoRichiesta')) {
    function hrEmailCreaCorpoRichiesta(
        PDO $pdo,
        string $tipoEvento,
        string $titolo,
        string $messaggio,
        ?string $link,
        ?int $idRichiesta,
        ?int $idDestinatario
    ): array {
        $dettaglio = hrEmailDettaglioRichiesta($pdo, $idRichiesta);
        $saluto = hrEmailNominativoUtente($pdo, $idDestinatario);
        $url = hrUrlAssoluto($pdo, $link);
        $config = hrEmailConfig($pdo);

        if ($saluto === '') {
            $saluto = 'utente';
        }

        $testo = "Portale HR Ravioli S.p.A.\n\n";
        $testo .= 'Buongiorno ' . $saluto . ",\n\n";
        $testo .= trim($messaggio) . "\n";

        $html = '<!doctype html><html><head><meta charset="UTF-8"><title>' . hrEmailHtml($titolo) . '</title></head>';
        $html .= '<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#111827;">';
        $html .= '<div style="max-width:900px;margin:0 auto;padding:24px 28px;">';
        $html .= '<h2 style="margin:0 0 18px 0;font-size:20px;line-height:1.3;color:#111827;">Portale HR Ravioli S.p.A.</h2>';
        $html .= '<p style="margin:0 0 12px 0;font-size:14px;line-height:1.5;">Buongiorno <strong>' . hrEmailHtml($saluto) . '</strong>,</p>';
        $html .= '<p style="margin:0 0 20px 0;font-size:14px;line-height:1.5;">' . nl2br(hrEmailHtml(trim($messaggio))) . '</p>';

        if ($dettaglio !== null) {
            $periodo = hrEmailPeriodoRichiesta($dettaglio);
            $testo .= "\nDettaglio richiesta\n";
            $testo .= 'Richiedente: ' . (string)$dettaglio['richiedente'] . "\n";
            $testo .= 'Tipologia: ' . (string)$dettaglio['tipologia'] . "\n";
            $testo .= 'Periodo: ' . $periodo . "\n";
            $testo .= 'Stato attuale: ' . (string)$dettaglio['stato'] . "\n";

            $html .= '<h3 style="margin:18px 0 10px 0;font-size:17px;line-height:1.3;color:#0057d8;">Dettaglio richiesta</h3>';
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 20px 0;">';
            $html .= hrEmailRigaDettaglioHtml('Richiedente', hrEmailHtml((string)$dettaglio['richiedente']));
            $html .= hrEmailRigaDettaglioHtml('Tipologia', hrEmailHtml((string)$dettaglio['tipologia']));
            $html .= hrEmailRigaDettaglioHtml('Periodo', hrEmailHtml($periodo));
            $html .= hrEmailRigaDettaglioHtml('Stato attuale', hrEmailBadgeStatoHtml((string)$dettaglio['codice_stato'], (string)$dettaglio['stato']));

            if (trim((string)($dettaglio['oggetto'] ?? '')) !== '') {
                $testo .= 'Oggetto: ' . (string)$dettaglio['oggetto'] . "\n";
                $html .= hrEmailRigaDettaglioHtml('Oggetto', hrEmailHtml((string)$dettaglio['oggetto']));
            }

            if (trim((string)($dettaglio['note_richiedente'] ?? '')) !== '') {
                $testo .= 'Note richiedente: ' . (string)$dettaglio['note_richiedente'] . "\n";
                $html .= hrEmailRigaDettaglioHtml('Note richiedente', nl2br(hrEmailHtml((string)$dettaglio['note_richiedente'])));
            }

            $html .= '</table>';

            if (str_contains($tipoEvento, 'DA_APPROVARE')) {
                $sovrapposizioni = hrEmailRichiestePresentiNelPeriodo($pdo, $dettaglio);

                if (count($sovrapposizioni) > 0) {
                    $html .= '<h3 style="margin:18px 0 10px 0;font-size:17px;line-height:1.3;color:#0057d8;">Assenze/richieste già presenti nel periodo</h3>';
                    $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 20px 0;">';
                    $html .= '<tr>';
                    foreach (['Persona', 'Tipologia', 'Periodo', 'Stato'] as $testata) {
                        $html .= '<th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;font-weight:700;font-size:13px;">' . hrEmailHtml($testata) . '</th>';
                    }
                    $html .= '</tr>';

                    $testo .= "\nAssenze/richieste già presenti nel periodo\n";
                    foreach ($sovrapposizioni as $sovrapposizione) {
                        $periodoSovrapposto = hrEmailPeriodoRichiesta($sovrapposizione);
                        $testo .= '- ' . (string)$sovrapposizione['persona'] . ' | ' . (string)$sovrapposizione['tipologia'] . ' | ' . $periodoSovrapposto . ' | ' . (string)$sovrapposizione['stato'] . "\n";
                        $html .= '<tr>';
                        $html .= '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;"><strong>' . hrEmailHtml((string)$sovrapposizione['persona']) . '</strong></td>';
                        $html .= '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;">' . hrEmailHtml((string)$sovrapposizione['tipologia']) . '</td>';
                        $html .= '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;">' . hrEmailHtml($periodoSovrapposto) . '</td>';
                        $html .= '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;">' . hrEmailHtml((string)$sovrapposizione['stato']) . '</td>';
                        $html .= '</tr>';
                    }
                    $html .= '</table>';
                }
            }

            if (trim((string)$dettaglio['codice_richiesta']) !== '') {
                $testo .= 'Codice: ' . (string)$dettaglio['codice_richiesta'] . "\n";
            }
        }

        if ($url !== '') {
            $testo .= "\nApri richiesta nel portale:\n" . $url . "\n";
            $html .= '<p style="margin:16px 0 20px 0;">';
            $html .= '<a href="' . hrEmailHtml($url) . '" style="display:inline-block;background:#0057d8;color:#ffffff;text-decoration:none;font-weight:700;font-size:13px;padding:9px 14px;border-radius:4px;">↗ Apri richiesta nel portale</a>';
            $html .= '</p>';
        }

        $html .= '<p style="margin:22px 0 4px 0;font-size:12px;line-height:1.5;color:#64748b;">Messaggio automatico del portale HR Ravioli S.p.A.</p>';

        if ($dettaglio !== null && trim((string)($dettaglio['codice_richiesta'] ?? '')) !== '') {
            $html .= '<p style="margin:0;font-size:12px;line-height:1.5;color:#64748b;">Codice: ' . hrEmailHtml((string)$dettaglio['codice_richiesta']) . '</p>';
        } else {
            $html .= '<p style="margin:0;font-size:12px;line-height:1.5;color:#64748b;">' . hrEmailHtml((string)$config['from_name']) . '</p>';
        }

        $html .= '</div></body></html>';

        $testo .= "\n--\n" . (string)$config['from_name'];

        return [
            'testo' => $testo,
            'html' => $html,
        ];
    }
}

if (!function_exists('hrInviaEmail')) {
    function hrInviaEmail(
        PDO $pdo,
        string $destinatario,
        string $oggetto,
        string $messaggioTesto,
        ?string $link = null,
        ?string $messaggioHtml = null
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

        if ($messaggioHtml === null) {
            if ($url !== '') {
                $messaggioTesto .= "\n\nApri nel portale:\n" . $url;
            }

            $messaggioTesto .= "\n\n--\n" . (string)$config['from_name'];
        }

        $fromName = hrEmailEncodeHeader((string)$config['from_name']);
        $subject = hrEmailEncodeHeader($oggetto);

        $headers = [
            'MIME-Version: 1.0',
            $messaggioHtml !== null ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'X-Mailer: Ravioli Portale HR',
        ];

        $corpo = $messaggioHtml !== null ? $messaggioHtml : $messaggioTesto;

        $parametri = '';
        if ($fromEmail !== '') {
            $parametri = '-f' . $fromEmail;
        }

        $ok = $parametri !== ''
            ? @mail($to, $subject, $corpo, implode("\r\n", $headers), $parametri)
            : @mail($to, $subject, $corpo, implode("\r\n", $headers));

        return [
            'inviata' => (bool)$ok,
            'motivo' => $ok ? null : 'Invio mail() non riuscito.',
        ];
    }
}
