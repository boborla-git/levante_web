<?php
declare(strict_types=1);

if (!function_exists('hrEmailConfigValore')) {
    function hrEmailConfigValore(PDO $pdo, string $codice, ?string $default = null): ?string
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
        $mittente = hrEmailConfigValore($pdo, 'HR_EMAIL_MITTENTE', 'no-reply@raviolispa.org');
        $nomeMittente = hrEmailConfigValore($pdo, 'HR_EMAIL_NOME_MITTENTE', 'Ravioli S.p.A. - Portale HR');

        $mittente = trim((string)$mittente);
        if (!filter_var($mittente, FILTER_VALIDATE_EMAIL)) {
            $mittente = 'no-reply@raviolispa.org';
        }

        $nomeMittente = trim((string)$nomeMittente);
        if ($nomeMittente === '') {
            $nomeMittente = 'Ravioli S.p.A. - Portale HR';
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
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
    }
}



if (!function_exists('hrEmailFormatoPeriodoRichiesta')) {
    function hrEmailFormatoPeriodoRichiesta(array $dettaglio): string
    {
        $dataDa = trim((string)($dettaglio['data_da_fmt'] ?? ''));
        $dataA = trim((string)($dettaglio['data_a_fmt'] ?? ''));
        $tipoPeriodo = strtoupper(trim((string)($dettaglio['tipo_periodo'] ?? 'GIORNI')));
        $oraDa = trim((string)($dettaglio['ora_da_fmt'] ?? ''));
        $oraA = trim((string)($dettaglio['ora_a_fmt'] ?? ''));
        $periodo = $dataDa;
        if ($dataA !== '' && $dataA !== $dataDa) {
            $periodo .= ' - ' . $dataA;
        }
        if ($tipoPeriodo === 'ORE' && $oraDa !== '' && $oraA !== '') {
            $periodo .= ' ' . $oraDa . '-' . $oraA;
        } elseif ($tipoPeriodo !== 'ORE') {
            $periodo .= ' giornata intera';
        }
        return trim($periodo);
    }
}

if (!function_exists('hrEmailDettaglioRichiesta')) {
    function hrEmailDettaglioRichiesta(PDO $pdo, int $idRichiesta): ?array
    {
        if ($idRichiesta <= 0) {
            return null;
        }
        try {
            $sql = "
                SELECT r.id_richiesta, r.codice_richiesta, r.oggetto, r.note_richiedente,
                       r.id_utente_richiedente, r.id_responsabile_corrente,
                       TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS richiedente,
                       te.descrizione AS tipologia, sr.descrizione AS stato,
                       UPPER(COALESCE(p.tipo_periodo, 'GIORNI')) AS tipo_periodo,
                       DATE_FORMAT(p.data_da, '%d/%m/%Y') AS data_da_fmt,
                       DATE_FORMAT(p.data_a, '%d/%m/%Y') AS data_a_fmt,
                       p.data_da, p.data_a,
                       TIME_FORMAT(p.ora_da, '%H:%i') AS ora_da_fmt,
                       TIME_FORMAT(p.ora_a, '%H:%i') AS ora_a_fmt
                FROM hr_richieste r
                INNER JOIN aut_utenti u ON u.id_utente = r.id_utente_richiedente
                INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
                INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
                INNER JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
                WHERE r.id_richiesta = :id_richiesta
                ORDER BY p.ordinamento, p.id_periodo
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id_richiesta' => $idRichiesta]);
            $dettaglio = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$dettaglio) {
                return null;
            }
            $dettaglio['richiedente'] = trim((string)$dettaglio['richiedente']) !== ''
                ? trim((string)$dettaglio['richiedente'])
                : 'Utente #' . (int)$dettaglio['id_utente_richiedente'];
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
        $righe[] = '- Codice: ' . trim((string)($dettaglio['codice_richiesta'] ?? ''));
        $righe[] = '- Richiedente: ' . trim((string)($dettaglio['richiedente'] ?? ''));
        $righe[] = '- Tipologia: ' . trim((string)($dettaglio['tipologia'] ?? ''));
        $righe[] = '- Periodo: ' . hrEmailFormatoPeriodoRichiesta($dettaglio);
        $oggetto = trim((string)($dettaglio['oggetto'] ?? ''));
        if ($oggetto !== '') {
            $righe[] = '- Oggetto: ' . $oggetto;
        }
        $note = trim((string)($dettaglio['note_richiedente'] ?? ''));
        if ($note !== '') {
            $righe[] = '- Note: ' . $note;
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
                SELECT r.id_richiesta, r.codice_richiesta, r.id_utente_richiedente,
                       TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS richiedente,
                       te.descrizione AS tipologia, sr.descrizione AS stato,
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
            $richiedente = trim((string)($riga['richiedente'] ?? ''));
            if ($richiedente === '') {
                $richiedente = 'Utente #' . (int)($riga['id_utente_richiedente'] ?? 0);
            }
            $righe[] = '- ' . $richiedente
                . ': ' . trim((string)($riga['tipologia'] ?? ''))
                . ', ' . hrEmailFormatoPeriodoRichiesta($riga)
                . ', stato: ' . trim((string)($riga['stato'] ?? ''));
        }
        return implode("\n", $righe);
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

            $nome = hrEmailNomeUtente($pdo, $idUtente);
            $dettaglioRichiesta = $idRichiesta !== null ? hrEmailDettaglioRichiesta($pdo, $idRichiesta) : null;

            $corpo = "Buongiorno " . $nome . ",\n\n";
            $corpo .= $messaggio . "\n\n";

            if (is_array($dettaglioRichiesta)) {
                $corpo .= hrEmailTestoDettaglioRichiesta($dettaglioRichiesta) . "\n\n";

                if ($tipoEvento === 'RICHIESTA_ASSENZA_DA_APPROVARE_EMAIL') {
                    $corpo .= hrEmailTestoRichiestePresenti(
                        hrEmailRichiestePresentiPeriodo($pdo, $dettaglioRichiesta)
                    ) . "\n\n";
                }
            }

            $corpo .= "Puoi aprire il portale da qui:\n" . $linkCompleto . "\n\n";
            $corpo .= "Messaggio automatico del portale HR Ravioli S.p.A.";

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
