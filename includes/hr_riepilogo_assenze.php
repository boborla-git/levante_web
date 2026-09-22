<?php
declare(strict_types=1);

require_once __DIR__ . '/hr_email.php';

if (!function_exists('hrRiepilogoAssenzeConfigAttiva')) {
    function hrRiepilogoAssenzeConfigAttiva(PDO $pdo): bool
    {
        $stmt = $pdo->query("SELECT valore FROM hr_configurazioni WHERE codice='HR_RIEPILOGO_ASSENZE_ATTIVO' AND attivo=1 LIMIT 1");
        return trim((string)($stmt->fetchColumn() ?: '0')) === '1';
    }
}

if (!function_exists('hrRiepilogoAssenzeEmailLavoro')) {
    function hrRiepilogoAssenzeEmailLavoro(PDO $pdo, int $idUtente): ?string
    {
        $stmt = $pdo->prepare(
            "SELECT ru.valore
             FROM hr_recapiti_utenti ru
             INNER JOIN hr_tipi_recapito tr
                ON tr.id_tipo_recapito = ru.id_tipo_recapito
               AND tr.attivo = 1
               AND tr.codice = 'EMAIL_LAVORO'
             WHERE ru.id_utente = :id_utente
               AND ru.attivo = 1
               AND ru.verificato = 1
             ORDER BY ru.principale DESC, ru.id_recapito_utente DESC
             LIMIT 1"
        );
        $stmt->execute(['id_utente' => $idUtente]);
        $email = hrEmailValida((string)($stmt->fetchColumn() ?: ''));
        return $email;
    }
}

if (!function_exists('hrRiepilogoAssenzeEmailAdmin')) {
    function hrRiepilogoAssenzeEmailAdmin(PDO $pdo): ?string
    {
        $stmt = $pdo->query(
            "SELECT id_utente
             FROM aut_utenti
             WHERE attivo = 1
               AND LOWER(username) = 'admin'
             LIMIT 1"
        );
        $idUtente = (int)($stmt->fetchColumn() ?: 0);

        return $idUtente > 0 ? hrRiepilogoAssenzeEmailLavoro($pdo, $idUtente) : null;
    }
}

if (!function_exists('hrRiepilogoAssenzeBccAdminAttiva')) {
    function hrRiepilogoAssenzeBccAdminAttiva(PDO $pdo): bool
    {
        $stmt = $pdo->query(
            "SELECT valore
             FROM hr_configurazioni
             WHERE codice = 'HR_RIEPILOGO_ASSENZE_BCC_ADMIN'
               AND attivo = 1
             LIMIT 1"
        );

        return trim((string)($stmt->fetchColumn() ?: '0')) === '1';
    }
}

if (!function_exists('hrRiepilogoAssenzeDestinatari')) {
    function hrRiepilogoAssenzeDestinatari(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT d.id_utente, d.livello_dettaglio, u.nome, u.cognome, u.username
             FROM hr_riepilogo_assenze_destinatari d
             INNER JOIN aut_utenti u
                ON u.id_utente = d.id_utente
               AND u.attivo = 1
             WHERE d.attivo = 1
               AND LOWER(u.username) <> 'admin'
             ORDER BY u.cognome, u.nome, u.username"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('hrRiepilogoAssenzeRighe')) {
    function hrRiepilogoAssenzeRighe(PDO $pdo, string $data): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                r.id_richiesta,
                u.id_utente,
                u.nome,
                u.cognome,
                u.username,
                te.descrizione AS tipologia,
                te.descrizione_calendario,
                te.mostra_dettaglio_hr,
                sp.descrizione_breve AS stato_presenza_breve,
                sp.descrizione AS stato_presenza,
                p.tipo_periodo,
                p.data_da,
                p.data_a,
                p.ora_da,
                p.ora_a
             FROM hr_richieste r
             INNER JOIN hr_stati_richiesta sr
                ON sr.id_stato_richiesta = r.id_stato_richiesta
               AND sr.codice = 'APPROVATA'
             INNER JOIN hr_richieste_periodi p
                ON p.id_richiesta = r.id_richiesta
             INNER JOIN hr_tipologie_evento te
                ON te.id_tipologia_evento = r.id_tipologia_evento
               AND te.attivo = 1
               AND te.visibile_calendario = 1
             INNER JOIN hr_stati_presenza sp
                ON sp.id_stato_presenza = te.id_stato_presenza
             INNER JOIN aut_utenti u
                ON u.id_utente = r.id_utente_richiedente
               AND u.attivo = 1
             WHERE p.data_da <= :data_riepilogo
               AND p.data_a >= :data_riepilogo
             ORDER BY u.cognome, u.nome, u.username, p.ora_da, r.id_richiesta"
        );
        $stmt->execute(['data_riepilogo' => $data]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('hrRiepilogoAssenzePeriodo')) {
    function hrRiepilogoAssenzePeriodo(array $riga): string
    {
        $tipo = strtoupper(trim((string)($riga['tipo_periodo'] ?? '')));
        $dataDa = trim((string)($riga['data_da'] ?? ''));
        $dataA = trim((string)($riga['data_a'] ?? ''));

        if ($tipo === 'ORE') {
            $oraDa = substr((string)($riga['ora_da'] ?? ''), 0, 5);
            $oraA = substr((string)($riga['ora_a'] ?? ''), 0, 5);
            return ($oraDa !== '' && $oraA !== '') ? $oraDa . ' - ' . $oraA : 'A ore';
        }

        if ($dataDa !== '' && $dataA !== '' && $dataDa !== $dataA) {
            $da = DateTimeImmutable::createFromFormat('Y-m-d', $dataDa);
            $a = DateTimeImmutable::createFromFormat('Y-m-d', $dataA);
            if ($da && $a) {
                return 'Dal ' . $da->format('d/m/Y') . ' al ' . $a->format('d/m/Y');
            }
        }

        return 'Giornata intera';
    }
}

if (!function_exists('hrRiepilogoAssenzeMotivo')) {
    function hrRiepilogoAssenzeMotivo(array $riga, string $livello): string
    {
        if ($livello !== 'HR') {
            return '';
        }

        if ((int)($riga['mostra_dettaglio_hr'] ?? 1) === 1) {
            $motivo = trim((string)($riga['descrizione_calendario'] ?? ''));
            if ($motivo === '') {
                $motivo = trim((string)($riga['tipologia'] ?? ''));
            }
            if ($motivo !== '') {
                return $motivo;
            }
        }

        $motivo = trim((string)($riga['stato_presenza_breve'] ?? ''));
        if ($motivo === '') {
            $motivo = trim((string)($riga['stato_presenza'] ?? ''));
        }
        return $motivo !== '' ? $motivo : 'Assente';
    }
}

if (!function_exists('hrRiepilogoAssenzeHtml')) {
    function hrRiepilogoAssenzeHtml(string $data, array $righe, string $livello): string
    {
        $dataObj = DateTimeImmutable::createFromFormat('Y-m-d', $data);
        $dataTitolo = $dataObj ? $dataObj->format('d/m/Y') : $data;
        $h = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $intestazione = '<th align="left" style="padding:9px 10px;border-bottom:2px solid #cbd5e1;font:12px Arial,sans-serif;color:#475569">Dipendente</th>';
        if ($livello === 'HR') {
            $intestazione .= '<th align="left" style="padding:9px 10px;border-bottom:2px solid #cbd5e1;font:12px Arial,sans-serif;color:#475569">Motivo</th>';
        }
        $intestazione .= '<th align="left" style="padding:9px 10px;border-bottom:2px solid #cbd5e1;font:12px Arial,sans-serif;color:#475569">Periodo</th>';

        $corpo = '';
        foreach ($righe as $riga) {
            $nominativo = trim((string)($riga['nome'] ?? '') . ' ' . (string)($riga['cognome'] ?? ''));
            if ($nominativo === '') {
                $nominativo = (string)($riga['username'] ?? '');
            }

            $corpo .= '<tr>';
            $corpo .= '<td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;font:13px Arial,sans-serif;color:#0f172a;font-weight:700">' . $h($nominativo) . '</td>';
            if ($livello === 'HR') {
                $corpo .= '<td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;font:13px Arial,sans-serif;color:#0f172a">' . $h(hrRiepilogoAssenzeMotivo($riga, $livello)) . '</td>';
            }
            $corpo .= '<td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;font:13px Arial,sans-serif;color:#0f172a">' . $h(hrRiepilogoAssenzePeriodo($riga)) . '</td>';
            $corpo .= '</tr>';
        }

        if ($corpo === '') {
            $corpo = '<tr><td colspan="' . ($livello === 'HR' ? '3' : '2') . '" style="padding:14px 10px;font:13px Arial,sans-serif;color:#475569">Nessuna assenza prevista per oggi.</td></tr>';
        }

        return '<!doctype html><html><body style="margin:0;background:#f8fafc;color:#0f172a">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">'
            . '<table role="presentation" width="720" cellpadding="0" cellspacing="0" style="width:720px;max-width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:8px">'
            . '<tr><td style="padding:22px 24px;border-bottom:1px solid #e2e8f0">'
            . '<div style="font:700 20px Arial,sans-serif">Portale HR Ravioli S.p.A.</div>'
            . '<div style="margin-top:6px;font:14px Arial,sans-serif;color:#475569">Assenze del ' . $h($dataTitolo) . '</div>'
            . '</td></tr><tr><td style="padding:20px 24px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr>' . $intestazione . '</tr>' . $corpo . '</table>'
            . '</td></tr><tr><td style="padding:14px 24px;border-top:1px solid #e2e8f0;font:12px Arial,sans-serif;color:#64748b">Messaggio automatico del Portale HR Ravioli S.p.A.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}

if (!function_exists('hrRiepilogoAssenzeGiaInviata')) {
    function hrRiepilogoAssenzeGiaInviata(PDO $pdo, string $data, string $tipo, int $idUtente, ?int $idRichiesta): bool
    {
        $sql = "SELECT COUNT(*)
                FROM hr_riepilogo_assenze_invi
                WHERE data_riepilogo = :data_riepilogo
                  AND tipo_invio = :tipo_invio
                  AND id_utente_destinatario = :id_utente
                  AND esito = 'INVIATA'";
        $params = [
            'data_riepilogo' => $data,
            'tipo_invio' => $tipo,
            'id_utente' => $idUtente,
        ];

        if ($tipo === 'AGGIORNAMENTO' && $idRichiesta !== null) {
            $sql .= ' AND id_richiesta_trigger = :id_richiesta';
            $params['id_richiesta'] = $idRichiesta;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('hrRiepilogoAssenzeLog')) {
    function hrRiepilogoAssenzeLog(
        PDO $pdo,
        string $data,
        string $tipo,
        int $idUtente,
        string $email,
        string $livello,
        string $oggetto,
        bool $ok,
        ?string $errore,
        ?int $idRichiesta
    ): void {
        $stmt = $pdo->prepare(
            "INSERT INTO hr_riepilogo_assenze_invi
                (data_riepilogo, tipo_invio, id_utente_destinatario, email_destinatario, livello_dettaglio, oggetto, esito, errore_invio, id_richiesta_trigger, data_invio)
             VALUES
                (:data_riepilogo, :tipo_invio, :id_utente, :email, :livello, :oggetto, :esito, :errore, :id_richiesta, NOW())"
        );
        $stmt->execute([
            'data_riepilogo' => $data,
            'tipo_invio' => $tipo,
            'id_utente' => $idUtente,
            'email' => $email,
            'livello' => $livello,
            'oggetto' => $oggetto,
            'esito' => $ok ? 'INVIATA' : 'ERRORE',
            'errore' => $errore,
            'id_richiesta' => $idRichiesta,
        ]);
    }
}

if (!function_exists('hrRiepilogoAssenzeInvia')) {
    function hrRiepilogoAssenzeInvia(PDO $pdo, string $data, string $tipo = 'MATTINO', ?int $idRichiestaTrigger = null): array
    {
        if (!hrRiepilogoAssenzeConfigAttiva($pdo)) {
            return ['inviate' => 0, 'errori' => 0, 'saltate' => 0, 'motivo' => 'Riepilogo disattivato'];
        }

        $tipo = strtoupper($tipo);
        $destinatari = hrRiepilogoAssenzeDestinatari($pdo);
        $righe = hrRiepilogoAssenzeRighe($pdo, $data);
        $config = hrEmailConfig($pdo);
        $fromEmail = hrEmailValida((string)$config['from_email']);

        if (!$config['attiva'] || $fromEmail === null) {
            return ['inviate' => 0, 'errori' => count($destinatari), 'saltate' => 0, 'motivo' => 'Configurazione email non valida'];
        }

        $dataObj = DateTimeImmutable::createFromFormat('Y-m-d', $data);
        $dataOggetto = $dataObj ? $dataObj->format('d-m-Y') : $data;
        $oggetto = 'Assenze del ' . $dataOggetto;
        if ($tipo === 'AGGIORNAMENTO') {
            $oggetto .= ' - Aggiornato alle ' . date('H:i');
        }

        $risultato = ['inviate' => 0, 'errori' => 0, 'saltate' => 0];
        $bccAdmin = hrRiepilogoAssenzeBccAdminAttiva($pdo) ? hrRiepilogoAssenzeEmailAdmin($pdo) : null;
        $bccGiaUsataPerLivello = [];

        foreach ($destinatari as $destinatario) {
            $idUtente = (int)$destinatario['id_utente'];
            $livello = strtoupper((string)$destinatario['livello_dettaglio']) === 'HR' ? 'HR' : 'BASE';

            if (hrRiepilogoAssenzeGiaInviata($pdo, $data, $tipo, $idUtente, $idRichiestaTrigger)) {
                $risultato['saltate']++;
                continue;
            }

            $email = hrRiepilogoAssenzeEmailLavoro($pdo, $idUtente);
            if ($email === null) {
                $risultato['errori']++;
                hrRiepilogoAssenzeLog($pdo, $data, $tipo, $idUtente, '', $livello, $oggetto, false, 'Email di lavoro verificata non disponibile.', $idRichiestaTrigger);
                continue;
            }

            $html = hrRiepilogoAssenzeHtml($data, $righe, $livello);
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'From: ' . hrEmailEncodeHeader((string)$config['from_name']) . ' <' . $fromEmail . '>',
                'Reply-To: ' . $fromEmail,
                'X-Mailer: Ravioli Portale HR',
            ];

            if ($bccAdmin !== null && empty($bccGiaUsataPerLivello[$livello]) && strcasecmp($bccAdmin, $email) !== 0) {
                $headers[] = 'Bcc: ' . $bccAdmin;
                $bccGiaUsataPerLivello[$livello] = true;
            }

            $ok = @mail(
                $email,
                hrEmailEncodeHeader($oggetto),
                $html,
                implode("\r\n", $headers),
                '-f' . $fromEmail
            );

            hrRiepilogoAssenzeLog(
                $pdo,
                $data,
                $tipo,
                $idUtente,
                $email,
                $livello,
                $oggetto,
                (bool)$ok,
                $ok ? null : 'Invio mail() non riuscito.',
                $idRichiestaTrigger
            );

            if ($ok) {
                $risultato['inviate']++;
            } else {
                $risultato['errori']++;
            }
        }

        return $risultato;
    }
}

if (!function_exists('hrRiepilogoAssenzeRichiestaIncludeData')) {
    function hrRiepilogoAssenzeRichiestaIncludeData(PDO $pdo, int $idRichiesta, string $data): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM hr_richieste_periodi
             WHERE id_richiesta = :id_richiesta
               AND data_da <= :data_riepilogo
               AND data_a >= :data_riepilogo"
        );
        $stmt->execute([
            'id_richiesta' => $idRichiesta,
            'data_riepilogo' => $data,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('hrRiepilogoAssenzeInviaAggiornamentoSeNecessario')) {
    function hrRiepilogoAssenzeInviaAggiornamentoSeNecessario(PDO $pdo, int $idRichiesta): void
    {
        if ($idRichiesta <= 0) {
            return;
        }

        $oggi = date('Y-m-d');
        if (!hrRiepilogoAssenzeRichiestaIncludeData($pdo, $idRichiesta, $oggi)) {
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM hr_riepilogo_assenze_invi
             WHERE data_riepilogo = :data_riepilogo
               AND tipo_invio = 'MATTINO'
               AND esito = 'INVIATA'"
        );
        $stmt->execute(['data_riepilogo' => $oggi]);
        if ((int)$stmt->fetchColumn() === 0) {
            return;
        }

        hrRiepilogoAssenzeInvia($pdo, $oggi, 'AGGIORNAMENTO', $idRichiesta);
    }
}
