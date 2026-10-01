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
        $valore = trim((string)preg_replace('/[\r\n]+/', ' ', $valore));
        if ($valore === '') return '';
        // RFC 2047, anche senza mbstring. Ogni parola resta sotto 75 byte
        // e ogni carattere UTF-8 resta intero nel proprio blocco.
        $caratteri = preg_split('//u', $valore, -1, PREG_SPLIT_NO_EMPTY);
        if ($caratteri === false) $caratteri = str_split($valore);
        $parti = [];
        $blocco = '';
        foreach ($caratteri as $carattere) {
            if (strlen($blocco . $carattere) > 42) {
                $parti[] = '=?UTF-8?B?' . base64_encode($blocco) . '?=';
                $blocco = '';
            }
            $blocco .= $carattere;
        }
        if ($blocco !== '') $parti[] = '=?UTF-8?B?' . base64_encode($blocco) . '?=';
        return implode("\r\n ", $parti);
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
             ORDER BY
                CASE tr.codice
                    WHEN 'EMAIL_LAVORO' THEN 0
                    WHEN 'EMAIL_PERSONALE' THEN 1
                    ELSE 2
                END,
                ru.principale DESC,
                ru.id_recapito_utente ASC"
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
    function hrEmailRichiestePresentiNelPeriodo(PDO $pdo, ?int $idRichiesta, ?int $idResponsabile = null): array
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

        $filtroResponsabile = '';
        $params = [
            'id_richiesta' => $idRichiesta,
            'data_da' => $range['data_da_min'],
            'data_a' => $range['data_a_max'],
        ];

        if ($idResponsabile !== null && $idResponsabile > 0) {
            $filtroResponsabile = "
               AND EXISTS (
                    SELECT 1
                    FROM hr_relazioni_organizzative ro
                    INNER JOIN hr_tipi_relazione_organizzativa tro
                       ON tro.id_tipo_relazione = ro.id_tipo_relazione
                      AND tro.attivo = 1
                      AND tro.codice IN ('RESPONSABILE_DIRETTO', 'RESPONSABILE_FUNZIONALE')
                    WHERE ro.id_utente = r.id_utente_richiedente
                      AND ro.id_utente_collegato = :id_responsabile
                      AND ro.attiva = 1
                      AND ro.data_inizio <= CURDATE()
                      AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
               )";
            $params['id_responsabile'] = $idResponsabile;
        }

        $stmt = $pdo->prepare(
            "SELECT
                r.id_richiesta,
                TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS persona,
                CASE
                    WHEN te.mostra_dettaglio_responsabili = 1 THEN te.descrizione
                    ELSE 'Assenza/permesso'
                END AS tipologia,
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
               {$filtroResponsabile}
             GROUP BY
                r.id_richiesta,
                persona,
                te.descrizione,
                te.mostra_dettaglio_responsabili,
                sr.descrizione,
                sr.codice
             ORDER BY MIN(p.data_da) ASC, persona ASC"
        );

        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('hrEmailStileTesto')) {
    function hrEmailStileTesto(int $dimensione = 13, string $colore = '#0f172a', bool $grassetto = false): string
    {
        return 'font-family:Arial,Helvetica,sans-serif;font-size:' . $dimensione . 'px;'
            . 'line-height:' . ($dimensione + 5) . 'px;font-weight:' . ($grassetto ? '700' : '400') . ';'
            . 'color:' . $colore . ';text-align:left;mso-line-height-rule:at-least;';
    }
}

if (!function_exists('hrEmailStileCella')) {
    function hrEmailStileCella(bool $intestazione = false, bool $grassetto = false): string
    {
        return 'padding:9px 10px;vertical-align:top;background:#ffffff;'
            . 'border-bottom:' . ($intestazione ? '2px solid #cbd5e1;' : '1px solid #e5e7eb;')
            . 'word-wrap:break-word;overflow-wrap:anywhere;'
            . hrEmailStileTesto($intestazione ? 12 : 13, $intestazione ? '#475569' : '#0f172a', $intestazione || $grassetto);
    }
}

if (!function_exists('hrEmailTabellaHtml')) {
    /** Celle HTML gia' escapate; larghezze percentuali con somma 100. */
    function hrEmailTabellaHtml(array $colonne, array $righe): string
    {
        $html = '<table class="hr-email-data" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;table-layout:fixed;'
            . hrEmailStileTesto() . '"><colgroup>';
        foreach ($colonne as $colonna) {
            $larghezza = (int)$colonna['larghezza'];
            $html .= '<col width="' . $larghezza . '%" style="width:' . $larghezza . '%;">';
        }
        $html .= '</colgroup><thead><tr>';
        foreach ($colonne as $colonna) {
            $html .= '<th scope="col" align="left" valign="top" style="'
                . hrEmailStileCella(true) . '">' . hrEmailH((string)$colonna['titolo']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($righe as $riga) {
            $html .= '<tr>';
            foreach ($riga as $cella) {
                $html .= '<td align="left" valign="top" style="' . hrEmailStileCella(false, !empty($cella['grassetto'])) . '">'
                    . (string)$cella['html'] . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }
}

if (!function_exists('hrEmailPulsanteHtml')) {
    function hrEmailPulsanteHtml(string $url, string $etichetta): string
    {
        if ($url === '') return '';
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:18px 0 0 0;'
            . hrEmailStileTesto() . '"><tr><td align="left" bgcolor="#005bd3" style="background:#005bd3;border-radius:4px;'
            . hrEmailStileTesto() . '"><a href="' . hrEmailH($url) . '" style="display:inline-block;padding:10px 16px;text-decoration:none;'
            . hrEmailStileTesto(13, '#ffffff', true) . '">' . hrEmailH($etichetta) . '</a></td></tr></table>';
    }
}

if (!function_exists('hrEmailCorniceHtml')) {
    /** Unica cornice per workflow, riepiloghi e verifica recapito. */
    function hrEmailCorniceHtml(string $titolo, string $contenutoHtml, string $footerExtraHtml = ''): string
    {
        $font = hrEmailStileTesto();
        return '<!doctype html><html lang="it"><head><meta charset="UTF-8">'
            . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . hrEmailH($titolo) . '</title>'
            . '<style>@media only screen and (max-width:480px){.hr-email-body{padding:16px 12px!important}.hr-email-data th,.hr-email-data td{padding:8px 6px!important;font-size:12px!important;line-height:17px!important}.hr-email-data td span{padding:3px 4px!important}}</style></head>'
            . '<body style="margin:0;padding:0;background:#f8fafc;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;' . $font . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8fafc" style="width:100%;border-collapse:collapse;background:#f8fafc;' . $font . '">'
            . '<tr><td align="center" style="padding:24px 12px;">'
            . '<!--[if mso]><table role="presentation" width="720" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->'
            . '<div style="width:100%;max-width:720px;margin:0 auto;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:720px;border-collapse:collapse;background:#ffffff;border:1px solid #e2e8f0;' . $font . '">'
            . '<tr><td align="left" style="padding:22px 24px;border-bottom:1px solid #e2e8f0;' . $font . '">'
            . '<h1 style="margin:0;padding:0;' . hrEmailStileTesto(20, '#0f172a', true) . '">Portale HR Ravioli S.p.A.</h1>'
            . '<p style="margin:6px 0 0 0;padding:0;' . hrEmailStileTesto(14, '#475569') . '">' . hrEmailH($titolo) . '</p>'
            . '</td></tr><tr><td class="hr-email-body" align="left" style="padding:20px 24px;' . $font . '">' . $contenutoHtml . '</td></tr>'
            . '<tr><td align="left" style="padding:14px 24px;border-top:1px solid #e2e8f0;' . hrEmailStileTesto(12, '#64748b') . '">'
            . 'Messaggio automatico del Portale HR Ravioli S.p.A.' . $footerExtraHtml . '</td></tr>'
            . '</table></div><!--[if mso]></td></tr></table><![endif]-->'
            . '</td></tr></table></body></html>';
    }
}

if (!function_exists('hrEmailInviaHtml')) {
    /** MIME base64: nessun tag, entita' o carattere UTF-8 puo' essere spezzato dal trasporto. */
    function hrEmailInviaHtml(array $config, string $destinatario, string $oggetto, string $html, ?string $bcc = null): bool
    {
        $to = hrEmailValida($destinatario);
        $from = hrEmailValida((string)($config['from_email'] ?? ''));
        if (empty($config['attiva']) || $to === null || $from === null) return false;
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'From: ' . hrEmailEncodeHeader((string)$config['from_name']) . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: Ravioli Portale HR',
        ];
        $bcc = hrEmailValida($bcc);
        if ($bcc !== null && strcasecmp($bcc, $to) !== 0) $headers[] = 'Bcc: ' . $bcc;
        $corpo = chunk_split(base64_encode($html), 76, "\r\n");
        return (bool)@mail($to, hrEmailEncodeHeader($oggetto), $corpo, implode("\r\n", $headers), '-f' . $from);
    }
}


if (!function_exists('hrEmailRigaTabella')) {
    function hrEmailRigaTabella(string $label, string $valoreHtml): string
    {
        if (trim(strip_tags($valoreHtml)) === '') return '';
        return '<tr><td align="left" valign="top" width="28%" style="width:28%;' . hrEmailStileCella(false, true) . '">'
            . hrEmailH($label) . '</td><td align="left" valign="top" style="' . hrEmailStileCella() . '">'
            . $valoreHtml . '</td></tr>';
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

        $colonne = [
            ['titolo' => 'Persona', 'larghezza' => 28],
            ['titolo' => 'Tipologia', 'larghezza' => 22],
            ['titolo' => 'Periodo', 'larghezza' => 30],
            ['titolo' => 'Stato', 'larghezza' => 20],
        ];
        $celle = [];

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

            $celle[] = [
                ['html' => hrEmailH((string)($riga['persona'] ?? '')), 'grassetto' => true],
                ['html' => hrEmailH((string)($riga['tipologia'] ?? ''))],
                ['html' => hrEmailH($periodo)],
                ['html' => hrEmailStatoBadge((string)($riga['stato_codice'] ?? ''), (string)($riga['stato'] ?? ''))],
            ];
        }

        return hrEmailTabellaHtml($colonne, $celle);
    }
}

if (!function_exists('hrEmailTestoPrincipale')) {
    function hrEmailTestoPrincipale(string $messaggio): string
    {
        $messaggio = trim($messaggio);
        if ($messaggio === '') return '';
        return '<p style="margin:0 0 18px 0;' . hrEmailStileTesto(14) . '">'
            . nl2br(hrEmailH($messaggio)) . '</p>';
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
        $mostraPresenti = str_contains($tipoEvento, 'DA_APPROVARE')
            || str_contains($tipoEvento, 'INFORMATIVA_RESPONSABILE');
        $contenuto = '<p style="margin:0 0 12px 0;' . hrEmailStileTesto(14) . '">' . $saluto . '</p>'
            . hrEmailTestoPrincipale($messaggio);
        if ($richiesta !== null) {
            $contenuto .= '<h2 style="margin:4px 0 8px 0;' . hrEmailStileTesto(18, '#005bd3', true) . '">Dettaglio richiesta</h2>'
                . hrEmailDettaglioHtml($richiesta);
        }
        if ($mostraPresenti && $idRichiesta !== null) {
            $presenti = hrEmailRichiestePresentiNelPeriodo($pdo, $idRichiesta, $idDestinatario);
            if (count($presenti) > 0) {
                $contenuto .= '<h3 style="margin:18px 0 6px 0;' . hrEmailStileTesto(18, '#005bd3', true) . '">Altre assenze/richieste già presenti nel periodo</h3>'
                    . hrEmailRichiestePresentiHtml($presenti);
            }
        }
        $contenuto .= hrEmailPulsanteHtml($url, 'Apri richiesta nel portale');
        $codice = $richiesta !== null ? (string)($richiesta['codice_richiesta'] ?? '') : '';
        return hrEmailCorniceHtml($titoloPulito, $contenuto, $codice !== '' ? '<br>Codice: ' . hrEmailH($codice) : '');
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

        $html = hrEmailHtml($pdo, $oggetto, $messaggioTesto, $link, $idRichiesta, $tipoEvento, $idDestinatario);
        $ok = hrEmailInviaHtml($config, $to, trim($oggetto), $html);

        return [
            'inviata' => (bool)$ok,
            'motivo' => $ok ? null : 'Invio mail() non riuscito.',
        ];
    }
}

