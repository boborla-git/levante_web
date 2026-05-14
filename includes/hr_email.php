<?php
declare(strict_types=1);

/**
 * Helper email HR.
 *
 * Regola di progetto:
 * le email HR sono un "golden master" UX e non devono degradare a testo plain.
 *
 * Il template HTML deve mantenere:
 * - intestazione "Portale HR Ravioli S.p.A."
 * - saluto personalizzato
 * - dettaglio richiesta tabellare
 * - badge stato
 * - pulsante/CTA "Apri richiesta nel portale"
 * - eventuale sezione "Assenze/richieste già presenti nel periodo" per l'approvatore
 * - footer con codice richiesta
 *
 * Nota tecnica:
 * il markup usa tabelle e stili inline per compatibilita' con Outlook, Aruba Webmail,
 * Gmail e client che ignorano CSS moderni.
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
            'from_name' => $fromName !== '' ? $fromName : 'Ravioli S.p.A. - Portale HR',
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

if (!function_exists('hrEmailH')) {
    function hrEmailH(?string $valore): string
    {
        return htmlspecialchars((string)$valore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

if (!function_exists('hrEmailNomeUtente')) {
    function hrEmailNomeUtente(PDO $pdo, int $idUtente): string
    {
        if ($idUtente <= 0) {
            return '';
        }

        $stmt = $pdo->prepare(
            "SELECT TRIM(CONCAT(COALESCE(nome, ''), ' ', COALESCE(cognome, ''))) AS nominativo
             FROM aut_utenti
             WHERE id_utente = :id_utente
             LIMIT 1"
        );
        $stmt->execute(['id_utente' => $idUtente]);

        return trim((string)($stmt->fetchColumn() ?: ''));
    }
}

if (!function_exists('hrEmailDataIt')) {
    function hrEmailDataIt(?string $data): string
    {
        $data = trim((string)$data);

        if ($data === '' || $data === '0000-00-00') {
            return '';
        }

        $ts = strtotime($data);
        if ($ts === false) {
            return $data;
        }

        return date('d/m/Y', $ts);
    }
}

if (!function_exists('hrEmailOraIt')) {
    function hrEmailOraIt(?string $ora): string
    {
        $ora = trim((string)$ora);

        if ($ora === '') {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}/', $ora, $m) === 1) {
            return $m[0];
        }

        return $ora;
    }
}

if (!function_exists('hrEmailPeriodoTesto')) {
    function hrEmailPeriodoTesto(array $periodi): string
    {
        $righe = [];

        foreach ($periodi as $periodo) {
            $tipo = strtoupper((string)($periodo['tipo_periodo'] ?? ''));
            $dataDa = hrEmailDataIt($periodo['data_da'] ?? '');
            $dataA = hrEmailDataIt($periodo['data_a'] ?? '');

            if ($tipo === 'ORE') {
                $oraDa = hrEmailOraIt($periodo['ora_da'] ?? '');
                $oraA = hrEmailOraIt($periodo['ora_a'] ?? '');

                if ($dataDa !== '' && $oraDa !== '' && $oraA !== '') {
                    $righe[] = $dataDa . ' · ' . $oraDa . ' / ' . $oraA;
                } elseif ($dataDa !== '') {
                    $righe[] = $dataDa;
                }

                continue;
            }

            if ($dataDa !== '' && $dataA !== '' && $dataA !== $dataDa) {
                $righe[] = $dataDa . ' - ' . $dataA . ' · giornata intera';
            } elseif ($dataDa !== '') {
                $righe[] = $dataDa . ' · giornata intera';
            }
        }

        return implode('<br>', array_map('hrEmailH', $righe));
    }
}

if (!function_exists('hrEmailStatoBadge')) {
    function hrEmailStatoBadge(string $statoCodice, string $statoDescrizione): string
    {
        $codice = strtoupper(trim($statoCodice));
        $descrizione = trim($statoDescrizione) !== '' ? trim($statoDescrizione) : $codice;

        $bg = '#e5e7eb';
        $fg = '#374151';
        $border = '#d1d5db';

        if ($codice === 'IN_ATTESA') {
            $bg = '#fff3cd';
            $fg = '#9a6700';
            $border = '#ffe08a';
        } elseif ($codice === 'APPROVATA') {
            $bg = '#d1f5df';
            $fg = '#137333';
            $border = '#a8e6bd';
        } elseif ($codice === 'RIFIUTATA') {
            $bg = '#fde2e2';
            $fg = '#b42318';
            $border = '#fac5c5';
        } elseif ($codice === 'ANNULLATA') {
            $bg = '#eef2f7';
            $fg = '#475569';
            $border = '#d8dee9';
        }

        return '<span style="display:inline-block; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:16px; font-weight:700; padding:3px 10px; border-radius:999px; background:' . $bg . '; color:' . $fg . '; border:1px solid ' . $border . ';">'
            . hrEmailH($descrizione)
            . '</span>';
    }
}

if (!function_exists('hrEmailRichiestaDettaglio')) {
    function hrEmailRichiestaDettaglio(PDO $pdo, ?int $idRichiesta): ?array
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
                TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS richiedente,
                te.codice AS tipologia_codice,
                te.descrizione AS tipologia,
                sr.codice AS stato_codice,
                sr.descrizione AS stato
             FROM hr_richieste r
             INNER JOIN aut_utenti u
                ON u.id_utente = r.id_utente_richiedente
             INNER JOIN hr_tipologie_evento te
                ON te.id_tipologia_evento = r.id_tipologia_evento
             INNER JOIN hr_stati_richiesta sr
                ON sr.id_stato_richiesta = r.id_stato_richiesta
             WHERE r.id_richiesta = :id_richiesta
             LIMIT 1"
        );
        $stmt->execute(['id_richiesta' => $idRichiesta]);
        $richiesta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$richiesta) {
            return null;
        }

        $stmtPeriodi = $pdo->prepare(
            "SELECT tipo_periodo, data_da, data_a, ora_da, ora_a, giornata_intera
             FROM hr_richieste_periodi
             WHERE id_richiesta = :id_richiesta
             ORDER BY data_da ASC, ora_da ASC, id_richiesta_periodo ASC"
        );
        $stmtPeriodi->execute(['id_richiesta' => $idRichiesta]);
        $richiesta['periodi'] = $stmtPeriodi->fetchAll(PDO::FETCH_ASSOC);

        return $richiesta;
    }
}

if (!function_exists('hrEmailRichiestePresentiNelPeriodo')) {
    function hrEmailRichiestePresentiNelPeriodo(PDO $pdo, ?int $idRichiesta): array
    {
        if ($idRichiesta === null || $idRichiesta <= 0) {
            return [];
        }

        $stmtRange = $pdo->prepare(
            "SELECT MIN(data_da) AS data_da_min, MAX(data_a) AS data_a_max
             FROM hr_richieste_periodi
             WHERE id_richiesta = :id_richiesta"
        );
        $stmtRange->execute(['id_richiesta' => $idRichiesta]);
        $range = $stmtRange->fetch(PDO::FETCH_ASSOC);

        if (!$range || empty($range['data_da_min']) || empty($range['data_a_max'])) {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT
                r.id_richiesta,
                TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS persona,
                te.descrizione AS tipologia,
                sr.descrizione AS stato,
                sr.codice AS stato_codice,
                MIN(p.data_da) AS data_da,
                MAX(p.data_a) AS data_a,
                MIN(p.ora_da) AS ora_da,
                MAX(p.ora_a) AS ora_a,
                MAX(CASE WHEN p.tipo_periodo = 'ORE' THEN 1 ELSE 0 END) AS ha_ore
             FROM hr_richieste r
             INNER JOIN aut_utenti u
                ON u.id_utente = r.id_utente_richiedente
             INNER JOIN hr_tipologie_evento te
                ON te.id_tipologia_evento = r.id_tipologia_evento
             INNER JOIN hr_stati_richiesta sr
                ON sr.id_stato_richiesta = r.id_stato_richiesta
             INNER JOIN hr_richieste_periodi p
                ON p.id_richiesta = r.id_richiesta
             WHERE r.id_richiesta <> :id_richiesta
               AND sr.codice IN ('IN_ATTESA', 'APPROVATA')
               AND p.data_da <= :data_a
               AND p.data_a >= :data_da
             GROUP BY
                r.id_richiesta,
                persona,
                te.descrizione,
                sr.descrizione,
                sr.codice
             ORDER BY MIN(p.data_da) ASC, persona ASC
             LIMIT 10"
        );

        $stmt->execute([
            'id_richiesta' => $idRichiesta,
            'data_da' => $range['data_da_min'],
            'data_a' => $range['data_a_max'],
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('hrEmailRigaTabella')) {
    function hrEmailRigaTabella(string $label, string $valoreHtml): string
    {
        if (trim(strip_tags($valoreHtml)) === '') {
            return '';
        }

        return '<tr>'
            . '<td width="170" valign="top" style="width:170px; padding:9px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:18px; color:#475569; font-weight:700;">' . hrEmailH($label) . '</td>'
            . '<td valign="top" style="padding:9px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:18px; color:#0f172a;">' . $valoreHtml . '</td>'
            . '</tr>';
    }
}

if (!function_exists('hrEmailDettaglioHtml')) {
    function hrEmailDettaglioHtml(?array $richiesta): string
    {
        if ($richiesta === null) {
            return '';
        }

        $periodo = hrEmailPeriodoTesto($richiesta['periodi'] ?? []);
        $statoBadge = hrEmailStatoBadge(
            (string)($richiesta['stato_codice'] ?? ''),
            (string)($richiesta['stato'] ?? '')
        );

        $righe = '';
        $righe .= hrEmailRigaTabella('Richiedente', hrEmailH((string)($richiesta['richiedente'] ?? '')));
        $righe .= hrEmailRigaTabella('Tipologia', hrEmailH((string)($richiesta['tipologia'] ?? '')));
        $righe .= hrEmailRigaTabella('Periodo', $periodo);
        $righe .= hrEmailRigaTabella('Stato attuale', $statoBadge);
        $righe .= hrEmailRigaTabella('Oggetto', nl2br(hrEmailH((string)($richiesta['oggetto'] ?? ''))));
        $righe .= hrEmailRigaTabella('Note richiedente', nl2br(hrEmailH((string)($richiesta['note_richiedente'] ?? ''))));

        if ($righe === '') {
            return '';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; table-layout:fixed; width:100%;">'
            . $righe
            . '</table>';
    }
}

if (!function_exists('hrEmailRichiestePresentiHtml')) {
    function hrEmailRichiestePresentiHtml(array $righe): string
    {
        if (count($righe) === 0) {
            return '';
        }

        $html = ''
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; table-layout:fixed; width:100%; margin-top:6px;">'
            . '<tr>'
            . '<th align="left" width="28%" style="padding:8px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:16px; color:#475569; font-weight:700;">Persona</th>'
            . '<th align="left" width="22%" style="padding:8px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:16px; color:#475569; font-weight:700;">Tipologia</th>'
            . '<th align="left" width="30%" style="padding:8px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:16px; color:#475569; font-weight:700;">Periodo</th>'
            . '<th align="left" width="20%" style="padding:8px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:16px; color:#475569; font-weight:700;">Stato</th>'
            . '</tr>';

        foreach ($righe as $riga) {
            $periodo = hrEmailDataIt($riga['data_da'] ?? '');
            $dataA = hrEmailDataIt($riga['data_a'] ?? '');

            if ($dataA !== '' && $dataA !== $periodo) {
                $periodo .= ' - ' . $dataA;
            }

            if ((int)($riga['ha_ore'] ?? 0) === 1) {
                $oraDa = hrEmailOraIt($riga['ora_da'] ?? '');
                $oraA = hrEmailOraIt($riga['ora_a'] ?? '');
                if ($oraDa !== '' && $oraA !== '') {
                    $periodo .= ' · ' . $oraDa . ' / ' . $oraA;
                }
            } else {
                $periodo .= ' · giornata intera';
            }

            $html .= '<tr>'
                . '<td valign="top" style="padding:8px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:18px; color:#0f172a; font-weight:700;">' . hrEmailH((string)($riga['persona'] ?? '')) . '</td>'
                . '<td valign="top" style="padding:8px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:18px; color:#0f172a;">' . hrEmailH((string)($riga['tipologia'] ?? '')) . '</td>'
                . '<td valign="top" style="padding:8px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:18px; color:#0f172a;">' . hrEmailH($periodo) . '</td>'
                . '<td valign="top" style="padding:8px 10px; border-bottom:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:18px; color:#0f172a;">' . hrEmailStatoBadge((string)($riga['stato_codice'] ?? ''), (string)($riga['stato'] ?? '')) . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }
}

if (!function_exists('hrEmailTestoPrincipale')) {
    function hrEmailTestoPrincipale(string $messaggio): string
    {
        $messaggio = trim($messaggio);

        if ($messaggio === '') {
            return '';
        }

        return '<p style="margin:0 0 18px 0; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:21px; color:#0f172a;">'
            . nl2br(hrEmailH($messaggio))
            . '</p>';
    }
}

if (!function_exists('hrEmailHtml')) {
    function hrEmailHtml(
        PDO $pdo,
        string $titolo,
        string $messaggio,
        ?string $link = null,
        ?int $idRichiesta = null,
        ?string $tipoEvento = null,
        ?int $idDestinatario = null
    ): string {
        $config = hrEmailConfig($pdo);
        $url = hrUrlAssoluto($pdo, $link);
        $richiesta = hrEmailRichiestaDettaglio($pdo, $idRichiesta);
        $nomeDestinatario = $idDestinatario !== null ? hrEmailNomeUtente($pdo, $idDestinatario) : '';

        if ($nomeDestinatario === '' && $richiesta !== null && !empty($richiesta['richiedente'])) {
            $nomeDestinatario = (string)$richiesta['richiedente'];
        }

        $saluto = $nomeDestinatario !== ''
            ? 'Buongiorno <strong>' . hrEmailH($nomeDestinatario) . '</strong>,'
            : 'Buongiorno,';

        $titoloPulito = trim($titolo) !== '' ? trim($titolo) : 'Notifica HR';
        $tipoEvento = strtoupper(trim((string)$tipoEvento));
        $mostraPresenti = str_contains($tipoEvento, 'DA_APPROVARE');

        $presentiHtml = '';
        if ($mostraPresenti && $idRichiesta !== null) {
            $presenti = hrEmailRichiestePresentiNelPeriodo($pdo, $idRichiesta);
            if (count($presenti) > 0) {
                $presentiHtml = ''
                    . '<tr><td style="padding:18px 0 6px 0; font-family:Arial,Helvetica,sans-serif;">'
                    . '<h3 style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:18px; line-height:24px; color:#005bd3; font-weight:700;">Assenze/richieste già presenti nel periodo</h3>'
                    . '</td></tr>'
                    . '<tr><td style="padding:0 0 18px 0;">'
                    . hrEmailRichiestePresentiHtml($presenti)
                    . '</td></tr>';
            }
        }

        $codice = '';
        if ($richiesta !== null && !empty($richiesta['codice_richiesta'])) {
            $codice = (string)$richiesta['codice_richiesta'];
        }

        $ctaHtml = '';
        if ($url !== '') {
            $ctaHtml = ''
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; margin:18px 0 0 0;">'
                . '<tr>'
                . '<td bgcolor="#005bd3" style="border-radius:4px; background:#005bd3;">'
                . '<a href="' . hrEmailH($url) . '" style="display:inline-block; padding:10px 16px; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:18px; color:#ffffff; text-decoration:none; font-weight:700;">↗ Apri richiesta nel portale</a>'
                . '</td>'
                . '</tr>'
                . '</table>';
        }

        $footerCodice = $codice !== ''
            ? '<br>Codice: ' . hrEmailH($codice)
            : '';

        return '<!doctype html>'
            . '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . hrEmailH($titoloPulito) . '</title></head>'
            . '<body style="margin:0; padding:0; background:#ffffff; font-family:Arial,Helvetica,sans-serif; color:#0f172a;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; width:100%; background:#ffffff;">'
            . '<tr>'
            . '<td align="center" style="padding:36px 16px; font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="760" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; width:760px; max-width:760px;">'
            . '<tr><td style="padding:0 0 18px 0; font-family:Arial,Helvetica,sans-serif;">'
            . '<h1 style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:22px; line-height:28px; color:#0f172a; font-weight:700;">Portale HR Ravioli S.p.A.</h1>'
            . '</td></tr>'
            . '<tr><td style="padding:0 0 12px 0; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:21px; color:#0f172a;">' . $saluto . '</td></tr>'
            . '<tr><td style="padding:0 0 4px 0;">' . hrEmailTestoPrincipale($messaggio) . '</td></tr>'
            . '<tr><td style="padding:4px 0 8px 0; font-family:Arial,Helvetica,sans-serif;">'
            . '<h2 style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:18px; line-height:24px; color:#005bd3; font-weight:700;">Dettaglio richiesta</h2>'
            . '</td></tr>'
            . '<tr><td style="padding:0 0 0 0;">' . hrEmailDettaglioHtml($richiesta) . '</td></tr>'
            . $presentiHtml
            . '<tr><td style="padding:0 0 18px 0;">' . $ctaHtml . '</td></tr>'
            . '<tr><td style="padding:0; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:18px; color:#64748b;">'
            . 'Messaggio automatico del portale HR Ravioli S.p.A.' . $footerCodice
            . '</td></tr>'
            . '</table>'
            . '</td>'
            . '</tr>'
            . '</table>'
            . '</body></html>';
    }
}

if (!function_exists('hrEmailTestoPlain')) {
    function hrEmailTestoPlain(PDO $pdo, string $messaggio, ?string $link = null, ?int $idRichiesta = null): string
    {
        $testo = trim($messaggio);
        $url = hrUrlAssoluto($pdo, $link);

        if ($url !== '') {
            $testo .= "\n\nApri richiesta nel portale:\n" . $url;
        }

        $richiesta = hrEmailRichiestaDettaglio($pdo, $idRichiesta);
        if ($richiesta !== null && !empty($richiesta['codice_richiesta'])) {
            $testo .= "\n\nCodice: " . (string)$richiesta['codice_richiesta'];
        }

        return $testo;
    }
}

if (!function_exists('hrInviaEmail')) {
    function hrInviaEmail(
        PDO $pdo,
        string $destinatario,
        string $oggetto,
        string $messaggioTesto,
        ?string $link = null,
        ?int $idRichiesta = null,
        ?string $tipoEvento = null,
        ?int $idDestinatario = null
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
        $fromName = hrEmailEncodeHeader((string)$config['from_name']);
        $subject = hrEmailEncodeHeader($oggetto);

        $html = hrEmailHtml($pdo, $oggetto, $messaggioTesto, $link, $idRichiesta, $tipoEvento, $idDestinatario);

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
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
            ? @mail($to, $subject, $html, implode("\r\n", $headers), $parametri)
            : @mail($to, $subject, $html, implode("\r\n", $headers));

        return [
            'inviata' => (bool)$ok,
            'motivo' => $ok ? null : 'Invio mail() non riuscito.',
        ];
    }
}
