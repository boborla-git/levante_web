<?php

declare(strict_types=1);

if (!function_exists('hrEmailConfigValore')) {
    function hrEmailConfigValore(PDO $pdo, string $codice, string $default = ''): string
    {
        try {
            $stmt = $pdo->prepare("SELECT valore FROM hr_configurazioni WHERE codice = :codice AND attivo = 1 LIMIT 1");
            $stmt->execute(['codice' => $codice]);
            $valore = $stmt->fetchColumn();
            if ($valore === false) {
                return $default;
            }
            $valore = trim((string)$valore);
            return $valore !== '' ? $valore : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('hrEmailNotificheAttive')) {
    function hrEmailNotificheAttive(PDO $pdo): bool
    {
        return hrEmailConfigValore($pdo, 'HR_NOTIFICA_EMAIL_ATTIVA', '0') === '1';
    }
}

if (!function_exists('hrEmailIdCanale')) {
    function hrEmailIdCanale(PDO $pdo): ?int
    {
        static $idCanale = null;
        static $caricato = false;

        if ($caricato) {
            return $idCanale;
        }

        $caricato = true;

        try {
            $stmt = $pdo->prepare("SELECT id_canale_notifica FROM hr_canali_notifica WHERE codice = 'EMAIL' AND attivo = 1 LIMIT 1");
            $stmt->execute();
            $id = $stmt->fetchColumn();
            $idCanale = $id === false ? null : (int)$id;
            return $idCanale;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('hrEmailUtente')) {
    function hrEmailUtente(PDO $pdo, int $idUtente, string $preferenza = 'lavoro'): ?string
    {
        if ($idUtente <= 0) {
            return null;
        }

        $ordine = strtoupper($preferenza) === 'PERSONALE'
            ? "FIELD(tr.codice, 'EMAIL_PERSONALE', 'EMAIL_LAVORO')"
            : "FIELD(tr.codice, 'EMAIL_LAVORO', 'EMAIL_PERSONALE')";

        try {
            $sql = "
                SELECT TRIM(ru.valore) AS email
                FROM hr_recapiti_utenti ru
                INNER JOIN hr_tipi_recapito tr ON tr.id_tipo_recapito = ru.id_tipo_recapito
                WHERE ru.id_utente = :id_utente
                  AND ru.attivo = 1
                  AND tr.attivo = 1
                  AND tr.codice IN ('EMAIL_LAVORO', 'EMAIL_PERSONALE')
                  AND TRIM(COALESCE(ru.valore, '')) <> ''
                ORDER BY ru.principale DESC, {$ordine}, ru.id_recapito_utente ASC
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id_utente' => $idUtente]);
            $email = $stmt->fetchColumn();
            if ($email === false) {
                return null;
            }
            $email = trim((string)$email);
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('hrEmailNomeUtente')) {
    function hrEmailNomeUtente(PDO $pdo, int $idUtente): string
    {
        if ($idUtente <= 0) {
            return 'utente';
        }

        try {
            $stmt = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(nome, ''), ' ', COALESCE(cognome, ''))) AS nominativo FROM aut_utenti WHERE id_utente = :id_utente LIMIT 1");
            $stmt->execute(['id_utente' => $idUtente]);
            $nominativo = trim((string)($stmt->fetchColumn() ?: ''));
            return $nominativo !== '' ? $nominativo : 'utente #' . $idUtente;
        } catch (Throwable $e) {
            return 'utente #' . $idUtente;
        }
    }
}

if (!function_exists('hrEmailInviaRaw')) {
    function hrEmailInviaRaw(PDO $pdo, string $destinatario, string $oggetto, string $corpo): bool
    {
        $mittente = trim((string)hrEmailConfigValore($pdo, 'HR_EMAIL_MITTENTE', 'no-reply@raviolispa.org'));
        $nomeMittente = trim((string)hrEmailConfigValore($pdo, 'HR_EMAIL_NOME_MITTENTE', 'Ravioli S.p.A. - Portale HR'));

        if (!filter_var($mittente, FILTER_VALIDATE_EMAIL)) {
            $mittente = 'no-reply@raviolispa.org';
        }
        if ($nomeMittente === '') {
            $nomeMittente = 'Ravioli S.p.A. - Portale HR';
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . (function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($nomeMittente, 'UTF-8') : $nomeMittente) . ' <' . $mittente . '>',
            'Reply-To: ' . $mittente,
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        $oggetto = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($oggetto, 'UTF-8') : $oggetto;
        return @mail($destinatario, $oggetto, $corpo, implode("\r\n", $headers));
    }
}

if (!function_exists('hrEmailRegistraEsito')) {
    function hrEmailRegistraEsito(PDO $pdo, string $tipoEvento, string $titolo, string $messaggio, ?string $link, ?int $idRichiesta, ?int $creatoDa, int $idUtente, bool $inviata, ?string $errore = null): void
    {
        $idCanaleEmail = hrEmailIdCanale($pdo);
        if ($idCanaleEmail === null || $idUtente <= 0) {
            return;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO hr_notifiche (tipo_evento, titolo, messaggio, link, id_richiesta, creato_da) VALUES (:tipo_evento, :titolo, :messaggio, :link, :id_richiesta, :creato_da)");
            $stmt->execute([
                'tipo_evento' => $tipoEvento,
                'titolo' => $titolo,
                'messaggio' => $messaggio,
                'link' => $link,
                'id_richiesta' => $idRichiesta,
                'creato_da' => $creatoDa,
            ]);

            $idNotifica = (int)$pdo->lastInsertId();
            $stmtDest = $pdo->prepare("INSERT INTO hr_notifiche_destinatari (id_notifica, id_utente, id_canale_notifica, inviata, letta, data_invio, errore_invio) VALUES (:id_notifica, :id_utente, :id_canale_notifica, :inviata, 1, NOW(), :errore_invio)");
            $stmtDest->execute([
                'id_notifica' => $idNotifica,
                'id_utente' => $idUtente,
                'id_canale_notifica' => $idCanaleEmail,
                'inviata' => $inviata ? 1 : 0,
                'errore_invio' => $errore,
            ]);
        } catch (Throwable $e) {
            // La registrazione dell'esito email non deve bloccare il flusso HR.
        }
    }
}

if (!function_exists('hrEmailValoreTesto')) {
    function hrEmailValoreTesto(?string $valore, string $fallback = '-'): string
    {
        $valore = trim((string)$valore);
        return $valore !== '' ? $valore : $fallback;
    }
}

if (!function_exists('hrEmailFormatoPeriodoRichiesta')) {
    function hrEmailFormatoPeriodoRichiesta(array $dettaglio): string
    {
        $dataDa = hrEmailValoreTesto($dettaglio['data_da_fmt'] ?? null, '');
        $dataA = hrEmailValoreTesto($dettaglio['data_a_fmt'] ?? null, '');
        $tipoPeriodo = strtoupper(hrEmailValoreTesto($dettaglio['tipo_periodo'] ?? null, 'GIORNI'));
        $oraDa = hrEmailValoreTesto($dettaglio['ora_da_fmt'] ?? null, '');
        $oraA = hrEmailValoreTesto($dettaglio['ora_a_fmt'] ?? null, '');

        if ($dataDa === '') {
            return '-';
        }

        $periodo = $dataDa;
        if ($dataA !== '' && $dataA !== $dataDa) {
            $periodo .= ' - ' . $dataA;
        }

        if ($tipoPeriodo === 'ORE' && $oraDa !== '' && $oraA !== '') {
            $periodo .= ' dalle ' . $oraDa . ' alle ' . $oraA;
        } elseif ($tipoPeriodo !== 'ORE') {
            $periodo .= ' - giornata intera';
        }

        return $periodo;
    }
}

if (!function_exists('hrEmailDettaglioRichiesta')) {
    function hrEmailDettaglioRichiesta(PDO $pdo, int $idRichiesta): ?array
    {
        if ($idRichiesta <= 0) {
            return null;
        }

        try {
            $sqlRichiesta = "
                SELECT
                    r.id_richiesta,
                    r.codice_richiesta,
                    r.oggetto,
                    r.note_richiedente,
                    r.id_utente_richiedente,
                    r.id_responsabile_corrente,
                    TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS richiedente,
                    te.descrizione AS tipologia,
                    sr.descrizione AS stato
                FROM hr_richieste r
                INNER JOIN aut_utenti u ON u.id_utente = r.id_utente_richiedente
                INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
                INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
                WHERE r.id_richiesta = :id_richiesta
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sqlRichiesta);
            $stmt->execute(['id_richiesta' => $idRichiesta]);
            $dettaglio = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$dettaglio) {
                return null;
            }

            $sqlPeriodo = "
                SELECT
                    UPPER(COALESCE(tipo_periodo, 'GIORNI')) AS tipo_periodo,
                    DATE_FORMAT(data_da, '%d/%m/%Y') AS data_da_fmt,
                    DATE_FORMAT(data_a, '%d/%m/%Y') AS data_a_fmt,
                    data_da,
                    data_a,
                    TIME_FORMAT(ora_da, '%H:%i') AS ora_da_fmt,
                    TIME_FORMAT(ora_a, '%H:%i') AS ora_a_fmt
                FROM hr_richieste_periodi
                WHERE id_richiesta = :id_richiesta
                ORDER BY ordinamento ASC, id_richiesta_periodo ASC
                LIMIT 1
            ";
            $stmtPeriodo = $pdo->prepare($sqlPeriodo);
            $stmtPeriodo->execute(['id_richiesta' => $idRichiesta]);
            $periodo = $stmtPeriodo->fetch(PDO::FETCH_ASSOC);
            if (is_array($periodo)) {
                $dettaglio = array_merge($dettaglio, $periodo);
            }

            $dettaglio['richiedente'] = hrEmailValoreTesto(
                $dettaglio['richiedente'] ?? null,
                'Utente #' . (int)($dettaglio['id_utente_richiedente'] ?? 0)
            );

            return $dettaglio;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('hrEmailTestoDettaglioRichiesta')) {
    function hrEmailTestoDettaglioRichiesta(array $dettaglio): string
    {
        $righe = [];
        $righe[] = 'Dettaglio richiesta:';
        $righe[] = '- Codice: ' . hrEmailValoreTesto($dettaglio['codice_richiesta'] ?? null);
        $righe[] = '- Richiedente: ' . hrEmailValoreTesto($dettaglio['richiedente'] ?? null);
        $righe[] = '- Tipologia: ' . hrEmailValoreTesto($dettaglio['tipologia'] ?? null);
        $righe[] = '- Periodo: ' . hrEmailFormatoPeriodoRichiesta($dettaglio);
        $righe[] = '- Stato attuale: ' . hrEmailValoreTesto($dettaglio['stato'] ?? null);

        $oggetto = trim((string)($dettaglio['oggetto'] ?? ''));
        if ($oggetto !== '') {
            $righe[] = '- Oggetto: ' . $oggetto;
        }

        $note = trim((string)($dettaglio['note_richiedente'] ?? ''));
        if ($note !== '') {
            $righe[] = '- Note richiedente: ' . $note;
        }

        return implode("\n", $righe);
    }
}

if (!function_exists('hrEmailRichiestePresentiPeriodo')) {
    function hrEmailRichiestePresentiPeriodo(PDO $pdo, array $dettaglio, int $limite = 8): array
    {
        $idRichiesta = (int)($dettaglio['id_richiesta'] ?? 0);
        $idResponsabile = (int)($dettaglio['id_responsabile_corrente'] ?? 0);
        $dataDa = trim((string)($dettaglio['data_da'] ?? ''));
        $dataA = trim((string)($dettaglio['data_a'] ?? ''));

        if ($idRichiesta <= 0 || $idResponsabile <= 0 || $dataDa === '' || $dataA === '') {
            return [];
        }

        try {
            $limite = max(1, min(20, $limite));
            $sql = "
                SELECT
                    r.id_richiesta,
                    r.codice_richiesta,
                    r.id_utente_richiedente,
                    TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS richiedente,
                    te.descrizione AS tipologia,
                    sr.descrizione AS stato,
                    UPPER(COALESCE(p.tipo_periodo, 'GIORNI')) AS tipo_periodo,
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
                  AND r.id_responsabile_corrente = :id_responsabile
                  AND sr.codice IN ('IN_ATTESA', 'APPROVATA')
                  AND p.data_da <= :data_a
                  AND p.data_a >= :data_da
                ORDER BY p.data_da ASC, u.cognome ASC, u.nome ASC, r.id_richiesta ASC
                LIMIT {$limite}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'id_richiesta' => $idRichiesta,
                'id_responsabile' => $idResponsabile,
                'data_da' => $dataDa,
                'data_a' => $dataA,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('hrEmailTestoRichiestePresenti')) {
    function hrEmailTestoRichiestePresenti(array $richiestePresenti): string
    {
        if (count($richiestePresenti) === 0) {
            return "Assenze/richieste gia presenti nel periodo:\n- Nessuna altra richiesta approvata o in attesa trovata nel periodo.";
        }

        $righe = ['Assenze/richieste gia presenti nel periodo:'];
        foreach ($richiestePresenti as $riga) {
            $richiedente = hrEmailValoreTesto($riga['richiedente'] ?? null, 'Utente #' . (int)($riga['id_utente_richiedente'] ?? 0));
            $righe[] = '- ' . $richiedente
                . ': ' . hrEmailValoreTesto($riga['tipologia'] ?? null)
                . ', ' . hrEmailFormatoPeriodoRichiesta($riga)
                . ', stato: ' . hrEmailValoreTesto($riga['stato'] ?? null);
        }

        return implode("\n", $righe);
    }
}



if (!function_exists('hrEmailHtml')) {
    function hrEmailHtml(?string $valore): string
    {
        return htmlspecialchars((string)$valore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('hrEmailStatoBadge')) {
    function hrEmailStatoBadge(?string $stato): string
    {
        $testo = hrEmailValoreTesto($stato, '-');
        $normalizzato = mb_strtolower($testo, 'UTF-8');
        $bg = '#e8f5e9';
        $border = '#b7e1c1';
        $color = '#087333';

        if (strpos($normalizzato, 'rifiut') !== false) {
            $bg = '#fdeaea';
            $border = '#f5b5b5';
            $color = '#b42318';
        } elseif (strpos($normalizzato, 'attesa') !== false || strpos($normalizzato, 'pend') !== false) {
            $bg = '#fff7df';
            $border = '#f3d58b';
            $color = '#9a6700';
        } elseif (strpos($normalizzato, 'annull') !== false) {
            $bg = '#f1f5f9';
            $border = '#cbd5e1';
            $color = '#475569';
        }

        return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:' . $bg . ';border:1px solid ' . $border . ';color:' . $color . ';font-weight:700;font-size:13px;">' . hrEmailHtml($testo) . '</span>';
    }
}

if (!function_exists('hrEmailRigaDettaglioHtml')) {
    function hrEmailRigaDettaglioHtml(string $etichetta, ?string $valore, bool $isHtml = false): string
    {
        $contenuto = $isHtml ? (string)$valore : hrEmailHtml(hrEmailValoreTesto($valore));
        return '<tr>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;width:160px;font-weight:700;vertical-align:top;">' . hrEmailHtml($etichetta) . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#0f172a;vertical-align:top;">' . $contenuto . '</td>'
            . '</tr>';
    }
}

if (!function_exists('hrEmailDettaglioRichiestaHtml')) {
    function hrEmailDettaglioRichiestaHtml(array $dettaglio): string
    {
        $html = '<div style="margin:18px 0 0 0;border:1px solid #dbe3ef;border-radius:12px;overflow:hidden;background:#ffffff;">';
        $html .= '<div style="padding:12px 14px;background:#f8fafc;border-bottom:1px solid #dbe3ef;font-weight:800;color:#0f172a;">Dettaglio richiesta</div>';
        $html .= '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;font-size:14px;">';
        $html .= hrEmailRigaDettaglioHtml('Codice', $dettaglio['codice_richiesta'] ?? null);
        $html .= hrEmailRigaDettaglioHtml('Richiedente', $dettaglio['richiedente'] ?? null);
        $html .= hrEmailRigaDettaglioHtml('Tipologia', $dettaglio['tipologia'] ?? null);
        $html .= hrEmailRigaDettaglioHtml('Periodo', hrEmailFormatoPeriodoRichiesta($dettaglio));
        $html .= hrEmailRigaDettaglioHtml('Stato attuale', hrEmailStatoBadge($dettaglio['stato'] ?? null), true);

        $oggetto = trim((string)($dettaglio['oggetto'] ?? ''));
        if ($oggetto !== '') {
            $html .= hrEmailRigaDettaglioHtml('Oggetto', $oggetto);
        }

        $note = trim((string)($dettaglio['note_richiedente'] ?? ''));
        if ($note !== '') {
            $html .= hrEmailRigaDettaglioHtml('Note richiedente', nl2br(hrEmailHtml($note)), true);
        }

        $html .= '</table></div>';
        return $html;
    }
}

if (!function_exists('hrEmailRichiestePresentiHtml')) {
    function hrEmailRichiestePresentiHtml(array $richiestePresenti): string
    {
        $html = '<div style="margin:18px 0 0 0;border:1px solid #dbe3ef;border-radius:12px;overflow:hidden;background:#ffffff;">';
        $html .= '<div style="padding:12px 14px;background:#f8fafc;border-bottom:1px solid #dbe3ef;font-weight:800;color:#0f172a;">Assenze/richieste già presenti nel periodo</div>';

        if (count($richiestePresenti) === 0) {
            $html .= '<div style="padding:14px;color:#475569;">Nessuna altra richiesta approvata o in attesa trovata nel periodo.</div></div>';
            return $html;
        }

        $html .= '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;font-size:14px;">';
        $html .= '<tr style="background:#f8fafc;">'
            . '<th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;">Persona</th>'
            . '<th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;">Tipologia</th>'
            . '<th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;">Periodo</th>'
            . '<th align="left" style="padding:8px 10px;border-bottom:1px solid #e5e7eb;color:#475569;">Stato</th>'
            . '</tr>';
        foreach ($richiestePresenti as $riga) {
            $richiedente = hrEmailValoreTesto($riga['richiedente'] ?? null, 'Utente #' . (int)($riga['id_utente_richiedente'] ?? 0));
            $html .= '<tr>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;color:#0f172a;font-weight:700;">' . hrEmailHtml($richiedente) . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;color:#0f172a;">' . hrEmailHtml(hrEmailValoreTesto($riga['tipologia'] ?? null)) . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;color:#0f172a;">' . hrEmailHtml(hrEmailFormatoPeriodoRichiesta($riga)) . '</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;color:#0f172a;">' . hrEmailStatoBadge($riga['stato'] ?? null) . '</td>'
                . '</tr>';
        }
        $html .= '</table></div>';
        return $html;
    }
}

if (!function_exists('hrEmailCorpoNotifica')) {
    function hrEmailCorpoNotifica(PDO $pdo, int $idUtenteDestinatario, string $tipoEvento, string $messaggio, ?string $linkCompleto, ?int $idRichiesta): string
    {
        $nome = hrEmailNomeUtente($pdo, $idUtenteDestinatario);
        $dettaglioRichiesta = $idRichiesta !== null ? hrEmailDettaglioRichiesta($pdo, $idRichiesta) : null;

        $html = '<!doctype html><html><head><meta charset="UTF-8"></head>';
        $html .= '<body style="margin:0;padding:0;background:#f3f6fa;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">';
        $html .= '<div style="max-width:720px;margin:0 auto;padding:24px;">';
        $html .= '<div style="background:#ffffff;border:1px solid #dbe3ef;border-radius:16px;padding:22px;">';
        $html .= '<div style="font-size:18px;font-weight:800;color:#0f172a;margin-bottom:16px;">Portale HR Ravioli S.p.A.</div>';
        $html .= '<p style="font-size:15px;line-height:1.55;margin:0 0 14px 0;">Buongiorno <strong>' . hrEmailHtml($nome) . '</strong>,</p>';
        $html .= '<p style="font-size:15px;line-height:1.55;margin:0 0 14px 0;">' . nl2br(hrEmailHtml(trim($messaggio))) . '</p>';

        if (is_array($dettaglioRichiesta)) {
            $html .= hrEmailDettaglioRichiestaHtml($dettaglioRichiesta);

            if (in_array($tipoEvento, ['RICHIESTA_ASSENZA_DA_APPROVARE_EMAIL', 'RICHIESTA_ASSENZA_DA_APPROVARE'], true)) {
                $html .= hrEmailRichiestePresentiHtml(
                    hrEmailRichiestePresentiPeriodo($pdo, $dettaglioRichiesta)
                );
            }
        } elseif ($idRichiesta !== null && $idRichiesta > 0) {
            $html .= '<div style="margin:18px 0;padding:14px;border:1px solid #f3d58b;background:#fff7df;border-radius:12px;color:#9a6700;">Dettaglio richiesta non disponibile. ID tecnico: ' . (int)$idRichiesta . '</div>';
        }

        if ($linkCompleto !== null && trim($linkCompleto) !== '') {
            $html .= '<div style="margin-top:22px;">';
            $html .= '<a href="' . hrEmailHtml($linkCompleto) . '" style="display:inline-block;background:#0057d8;color:#ffffff;text-decoration:none;font-weight:800;padding:11px 16px;border-radius:10px;">Apri richiesta nel portale</a>';
            $html .= '<div style="font-size:12px;color:#64748b;margin-top:8px;">' . hrEmailHtml($linkCompleto) . '</div>';
            $html .= '</div>';
        }

        $html .= '<p style="font-size:12px;line-height:1.5;color:#64748b;margin:22px 0 0 0;">Messaggio automatico del portale HR Ravioli S.p.A.</p>';
        $html .= '</div></div></body></html>';
        return $html;
    }
}

if (!function_exists('hrEmailInviaNotifica')) {
    function hrEmailInviaNotifica(PDO $pdo, string $tipoEvento, string $titolo, string $messaggio, ?string $link, ?int $idRichiesta, ?int $creatoDa, array $destinatari, string $preferenzaEmail = 'lavoro'): void
    {
        if (!hrEmailNotificheAttive($pdo)) {
            return;
        }

        $destinatari = array_values(array_unique(array_filter(array_map('intval', $destinatari), static fn (int $v): bool => $v > 0)));
        if (count($destinatari) === 0) {
            return;
        }

        $baseUrl = rtrim((string)hrEmailConfigValore($pdo, 'HR_URL_PORTALE', 'https://www.raviolispa.org'), '/');
        $linkCompleto = $link !== null && trim($link) !== ''
            ? $baseUrl . '/' . ltrim(trim($link), '/')
            : $baseUrl;

        foreach ($destinatari as $idUtente) {
            $email = hrEmailUtente($pdo, $idUtente, $preferenzaEmail);
            if ($email === null) {
                hrEmailRegistraEsito($pdo, $tipoEvento, $titolo, $messaggio, $link, $idRichiesta, $creatoDa, $idUtente, false, 'Nessun recapito email attivo valido.');
                continue;
            }

            $corpo = hrEmailCorpoNotifica($pdo, $idUtente, $tipoEvento, $messaggio, $linkCompleto, $idRichiesta);
            $inviata = hrEmailInviaRaw($pdo, $email, $titolo, $corpo);

            hrEmailRegistraEsito(
                $pdo,
                $tipoEvento,
                $titolo,
                $messaggio,
                $link,
                $idRichiesta,
                $creatoDa,
                $idUtente,
                $inviata,
                $inviata ? null : 'Invio mail non riuscito tramite funzione mail().'
            );
        }
    }
}
