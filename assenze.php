<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('assenze');

$pdo = db();
$idUtenteLoggato = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$puoScrivere = haPermessoScrittura('assenze');
$puoLeggereApprovazioni = haPermessoLettura('approvazioni_assenze');
$puoLeggereCalendario = haPermessoLettura('calendario_assenze');
$puoConfigurare = haPermessoLettura('configurazione_assenze');

$errore = '';
$messaggio = '';
$tipologie = [];
$richieste = [];
$riepilogo = [
    'totali' => 0,
    'in_attesa' => 0,
    'approvate' => 0,
    'rifiutate' => 0,
];
$utentiGestibili = [];
$utenteSelezionato = null;

$form = [
    'id_utente' => '',
    'id_tipologia_evento' => '',
    'modalita' => 'giorni',
    'data_da' => '',
    'data_a' => '',
    'ora_da' => '',
    'ora_a' => '',
    'oggetto' => '',
    'note_richiedente' => '',
];

function h(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

function hrGeneraCodiceRichiesta(PDO $pdo): string
{
    $prefisso = 'HR';

    try {
        $stmt = $pdo->prepare('SELECT valore FROM hr_configurazioni WHERE codice = :codice AND attivo = 1 LIMIT 1');
        $stmt->execute(['codice' => 'HR_RICHIESTE_CODICE_PREFISSO']);
        $valore = trim((string)($stmt->fetchColumn() ?: ''));
        if ($valore !== '') {
            $prefisso = strtoupper($valore);
        }
    } catch (Throwable $e) {
    }

    return $prefisso . '-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function hrTrovaResponsabileDiretto(PDO $pdo, int $idUtente): ?int
{
    $sql = "
        SELECT ro.id_utente_collegato
        FROM hr_relazioni_organizzative ro
        INNER JOIN hr_tipi_relazione_organizzativa tro
            ON tro.id_tipo_relazione = ro.id_tipo_relazione
           AND tro.codice IN ('RESPONSABILE_DIRETTO', 'RESPONSABILE_FUNZIONALE')
           AND tro.attivo = 1
        WHERE ro.id_utente = :id_utente
          AND ro.attiva = 1
          AND ro.data_inizio <= CURDATE()
          AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
        ORDER BY ro.data_inizio DESC, ro.id_relazione_organizzativa DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id_utente' => $idUtente]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int)$id : null;
}

function hrIdStatoRichiesta(PDO $pdo, string $codice): int
{
    static $cache = [];

    if (isset($cache[$codice])) {
        return $cache[$codice];
    }

    $stmt = $pdo->prepare('SELECT id_stato_richiesta FROM hr_stati_richiesta WHERE codice = :codice LIMIT 1');
    $stmt->execute(['codice' => $codice]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        throw new RuntimeException('Stato richiesta non trovato: ' . $codice);
    }

    $cache[$codice] = (int)$id;
    return $cache[$codice];
}

function hrIdCanaleNotifica(PDO $pdo, string $codice): ?int
{
    static $cache = [];

    if (array_key_exists($codice, $cache)) {
        return $cache[$codice];
    }

    $stmt = $pdo->prepare('SELECT id_canale_notifica FROM hr_canali_notifica WHERE codice = :codice AND attivo = 1 LIMIT 1');
    $stmt->execute(['codice' => $codice]);
    $id = $stmt->fetchColumn();
    $cache[$codice] = $id === false ? null : (int)$id;

    return $cache[$codice];
}

function hrCreaNotificaWeb(PDO $pdo, string $tipoEvento, string $titolo, string $messaggio, ?string $link, ?int $idRichiesta, ?int $creatoDa, array $destinatari): void
{
    $destinatari = array_values(array_unique(array_filter(array_map('intval', $destinatari), static fn (int $v): bool => $v > 0)));
    if (count($destinatari) === 0) {
        return;
    }

    $idCanaleWeb = hrIdCanaleNotifica($pdo, 'WEB');
    if ($idCanaleWeb === null) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO hr_notifiche (tipo_evento, titolo, messaggio, link, id_richiesta, creato_da) VALUES (:tipo_evento, :titolo, :messaggio, :link, :id_richiesta, :creato_da)');
    $stmt->execute([
        'tipo_evento' => $tipoEvento,
        'titolo' => $titolo,
        'messaggio' => $messaggio,
        'link' => $link,
        'id_richiesta' => $idRichiesta,
        'creato_da' => $creatoDa,
    ]);

    $idNotifica = (int)$pdo->lastInsertId();

    $stmtDest = $pdo->prepare('INSERT INTO hr_notifiche_destinatari (id_notifica, id_utente, id_canale_notifica, inviata, letta, data_invio) VALUES (:id_notifica, :id_utente, :id_canale_notifica, 1, 0, NOW())');
    foreach ($destinatari as $idUtenteDest) {
        $stmtDest->execute([
            'id_notifica' => $idNotifica,
            'id_utente' => $idUtenteDest,
            'id_canale_notifica' => $idCanaleWeb,
        ]);
    }
}

function hrUtenteAttivo(PDO $pdo, int $idUtente): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id_utente,
                CONCAT(TRIM(COALESCE(nome, '')), CASE WHEN TRIM(COALESCE(cognome, '')) <> '' THEN CONCAT(' ', TRIM(cognome)) ELSE '' END) AS nominativo
         FROM aut_utenti
         WHERE id_utente = :id_utente
           AND attivo = 1
         LIMIT 1"
    );
    $stmt->execute(['id_utente' => $idUtente]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    if (trim((string)$row['nominativo']) === '') {
        $row['nominativo'] = 'Utente #' . (int)$row['id_utente'];
    }

    return $row;
}

function hrHaRecapitoEmailPersonale(PDO $pdo, int $idUtente): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM hr_recapiti_utenti ru
         INNER JOIN hr_tipi_recapito tr ON tr.id_tipo_recapito = ru.id_tipo_recapito
         WHERE ru.id_utente = :id_utente
           AND ru.attivo = 1
           AND tr.codice = 'EMAIL_PERSONALE'
           AND TRIM(COALESCE(ru.valore, '')) <> ''
         LIMIT 1"
    );
    $stmt->execute(['id_utente' => $idUtente]);

    return (bool)$stmt->fetchColumn();
}

function hrUtentiNelPerimetro(PDO $pdo, int $idUtenteLoggato, bool $puoConfigurare): array
{
    $utenti = [];
    $self = hrUtenteAttivo($pdo, $idUtenteLoggato);
    if ($self) {
        $utenti[(int)$self['id_utente']] = $self;
    }

    if ($puoConfigurare) {
        $stmt = $pdo->query(
            "SELECT id_utente,
                    CONCAT(TRIM(COALESCE(nome, '')), CASE WHEN TRIM(COALESCE(cognome, '')) <> '' THEN CONCAT(' ', TRIM(cognome)) ELSE '' END) AS nominativo
             FROM aut_utenti
             WHERE attivo = 1
             ORDER BY nome, cognome, username"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (trim((string)$row['nominativo']) === '') {
                $row['nominativo'] = 'Utente #' . (int)$row['id_utente'];
            }
            $utenti[(int)$row['id_utente']] = $row;
        }

        uasort($utenti, static fn(array $a, array $b): int => strcmp((string)$a['nominativo'], (string)$b['nominativo']));
        return $utenti;
    }

    $stmtDiretti = $pdo->prepare(
        "SELECT DISTINCT u.id_utente,
                CONCAT(TRIM(COALESCE(u.nome, '')), CASE WHEN TRIM(COALESCE(u.cognome, '')) <> '' THEN CONCAT(' ', TRIM(u.cognome)) ELSE '' END) AS nominativo
         FROM hr_relazioni_organizzative ro
         INNER JOIN hr_tipi_relazione_organizzativa tro
            ON tro.id_tipo_relazione = ro.id_tipo_relazione
           AND tro.codice IN ('RESPONSABILE_DIRETTO', 'RESPONSABILE_FUNZIONALE')
           AND tro.attivo = 1
         INNER JOIN aut_utenti u ON u.id_utente = ro.id_utente
         WHERE ro.id_utente_collegato = :id_utente
           AND ro.attiva = 1
           AND ro.data_inizio <= CURDATE()
           AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
           AND u.attivo = 1"
    );
    $stmtDiretti->execute(['id_utente' => $idUtenteLoggato]);
    foreach ($stmtDiretti->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (trim((string)$row['nominativo']) === '') {
            $row['nominativo'] = 'Utente #' . (int)$row['id_utente'];
        }
        $utenti[(int)$row['id_utente']] = $row;
    }

    uasort($utenti, static fn(array $a, array $b): int => strcmp((string)$a['nominativo'], (string)$b['nominativo']));
    return $utenti;
}

function hrClasseStato(string $codice): string
{
    if ($codice === 'APPROVATA') {
        return 'status-ok';
    }
    if ($codice === 'IN_ATTESA') {
        return 'status-wait';
    }
    if ($codice === 'RIFIUTATA') {
        return 'status-ko';
    }
    return 'status-neutral';
}

try {
    $stmtTipologie = $pdo->query(
        "SELECT
            id_tipologia_evento,
            codice,
            descrizione,
            richiede_approvazione,
            approvazione_obbligatoria,
            consente_giorni,
            consente_ore
         FROM hr_tipologie_evento
         WHERE attivo = 1
         ORDER BY ordinamento, descrizione"
    );
    $tipologie = $stmtTipologie->fetchAll(PDO::FETCH_ASSOC);

    $utentiGestibili = hrUtentiNelPerimetro($pdo, $idUtenteLoggato, $puoConfigurare);

    $idUtenteTarget = 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $idUtenteTarget = (int)($_POST['id_utente'] ?? 0);
    } else {
        $idUtenteTarget = (int)($_GET['id_utente'] ?? 0);
    }
    if ($idUtenteTarget <= 0 || !isset($utentiGestibili[$idUtenteTarget])) {
        $idUtenteTarget = $idUtenteLoggato;
    }

    $utenteSelezionato = $utentiGestibili[$idUtenteTarget] ?? hrUtenteAttivo($pdo, $idUtenteTarget);
    $isDelegato = $idUtenteTarget !== $idUtenteLoggato;
    $form['id_utente'] = (string)$idUtenteTarget;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            http_response_code(403);
            die('Accesso negato.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'nuova_richiesta') {
            foreach ($form as $chiave => $valore) {
                if (isset($_POST[$chiave])) {
                    $form[$chiave] = trim((string)$_POST[$chiave]);
                }
            }

            $idUtenteTarget = (int)$form['id_utente'];
            if ($idUtenteTarget <= 0 || !isset($utentiGestibili[$idUtenteTarget])) {
                throw new RuntimeException('Non puoi inserire richieste per il dipendente selezionato.');
            }

            $utenteSelezionato = $utentiGestibili[$idUtenteTarget] ?? hrUtenteAttivo($pdo, $idUtenteTarget);
            $isDelegato = $idUtenteTarget !== $idUtenteLoggato;

            $idTipologia = (int)$form['id_tipologia_evento'];
            $modalita = $form['modalita'] === 'ore' ? 'ore' : 'giorni';
            $dataDa = $form['data_da'];
            $dataA = $modalita === 'ore' ? $dataDa : ($form['data_a'] !== '' ? $form['data_a'] : $dataDa);
            $oraDa = $form['ora_da'];
            $oraA = $form['ora_a'];
            $oggetto = $form['oggetto'];
            $noteRichiedente = $form['note_richiedente'];

            if ($idTipologia <= 0) {
                throw new RuntimeException('Seleziona una tipologia valida.');
            }
            if ($dataDa === '') {
                throw new RuntimeException('Inserisci il giorno iniziale.');
            }
            if ($dataA === '') {
                throw new RuntimeException('Inserisci il giorno finale.');
            }
            if ($dataA < $dataDa) {
                throw new RuntimeException('Il giorno finale non può essere precedente al giorno iniziale.');
            }
            if ($modalita === 'ore') {
                if ($oraDa === '' || $oraA === '') {
                    throw new RuntimeException('Per le richieste a ore devi indicare dalle ore e alle ore.');
                }
                if ($oraA <= $oraDa) {
                    throw new RuntimeException("L'orario finale deve essere successivo all'orario iniziale.");
                }
                if ($dataDa !== $dataA) {
                    throw new RuntimeException('La modalità a ore richiede un solo giorno.');
                }
            } else {
                $oraDa = '';
                $oraA = '';
            }

            $tipologiaSelezionata = null;
            foreach ($tipologie as $tipologia) {
                if ((int)$tipologia['id_tipologia_evento'] === $idTipologia) {
                    $tipologiaSelezionata = $tipologia;
                    break;
                }
            }
            if ($tipologiaSelezionata === null) {
                throw new RuntimeException('Tipologia non trovata.');
            }
            if ($modalita === 'giorni' && (int)$tipologiaSelezionata['consente_giorni'] !== 1) {
                throw new RuntimeException('Questa tipologia non consente richieste a giorni.');
            }
            if ($modalita === 'ore' && (int)$tipologiaSelezionata['consente_ore'] !== 1) {
                throw new RuntimeException('Questa tipologia non consente richieste a ore.');
            }

            if (!$isDelegato && !hrHaRecapitoEmailPersonale($pdo, $idUtenteTarget)) {
                throw new RuntimeException('Per inserire richieste personali devi avere almeno una email personale attiva nei recapiti HR.');
            }

            $richiedeApprovazione = (int)$tipologiaSelezionata['richiede_approvazione'] === 1;
            $idResponsabile = hrTrovaResponsabileDiretto($pdo, $idUtenteTarget);
            $codiceStato = 'APPROVATA';
            if (!$isDelegato && $richiedeApprovazione && $idResponsabile !== null) {
                $codiceStato = 'IN_ATTESA';
            }

            $idStato = hrIdStatoRichiesta($pdo, $codiceStato);
            $codiceRichiesta = hrGeneraCodiceRichiesta($pdo);
            $minutiTotali = null;

            if ($modalita === 'ore') {
                $inizio = strtotime($dataDa . ' ' . $oraDa);
                $fine = strtotime($dataA . ' ' . $oraA);
                if ($inizio !== false && $fine !== false) {
                    $minutiTotali = (int)round(($fine - $inizio) / 60);
                }
            }

            $pdo->beginTransaction();

            $stmtIns = $pdo->prepare(
                "INSERT INTO hr_richieste (
                    codice_richiesta,
                    id_utente_richiedente,
                    id_tipologia_evento,
                    id_stato_richiesta,
                    id_responsabile_corrente,
                    oggetto,
                    note_richiedente,
                    data_invio,
                    data_chiusura,
                    data_aggiornamento,
                    origine
                ) VALUES (
                    :codice_richiesta,
                    :id_utente_richiedente,
                    :id_tipologia_evento,
                    :id_stato_richiesta,
                    :id_responsabile_corrente,
                    :oggetto,
                    :note_richiedente,
                    NOW(),
                    :data_chiusura,
                    NOW(),
                    'web'
                )"
            );
            $stmtIns->execute([
                'codice_richiesta' => $codiceRichiesta,
                'id_utente_richiedente' => $idUtenteTarget,
                'id_tipologia_evento' => $idTipologia,
                'id_stato_richiesta' => $idStato,
                'id_responsabile_corrente' => $idResponsabile,
                'oggetto' => $oggetto !== '' ? $oggetto : null,
                'note_richiedente' => $noteRichiedente !== '' ? $noteRichiedente : null,
                'data_chiusura' => $codiceStato === 'APPROVATA' ? date('Y-m-d H:i:s') : null,
            ]);

            $idRichiesta = (int)$pdo->lastInsertId();

            $stmtPeriodo = $pdo->prepare(
                "INSERT INTO hr_richieste_periodi (
                    id_richiesta, tipo_periodo, data_da, data_a, ora_da, ora_a, giornata_intera, minuti_totali, ordinamento, note
                ) VALUES (
                    :id_richiesta, :tipo_periodo, :data_da, :data_a, :ora_da, :ora_a, :giornata_intera, :minuti_totali, 1, NULL
                )"
            );
            $stmtPeriodo->execute([
                'id_richiesta' => $idRichiesta,
                'tipo_periodo' => strtoupper($modalita),
                'data_da' => $dataDa,
                'data_a' => $dataA,
                'ora_da' => $oraDa !== '' ? $oraDa : null,
                'ora_a' => $oraA !== '' ? $oraA : null,
                'giornata_intera' => $modalita === 'giorni' ? 1 : 0,
                'minuti_totali' => $minutiTotali,
            ]);

            $stmtStorico = $pdo->prepare('INSERT INTO hr_richieste_storico (id_richiesta, azione, id_utente_azione, dettagli, origine) VALUES (:id_richiesta, :azione, :id_utente_azione, :dettagli, :origine)');
            $stmtStorico->execute([
                'id_richiesta' => $idRichiesta,
                'azione' => 'CREAZIONE',
                'id_utente_azione' => $idUtenteLoggato,
                'dettagli' => $isDelegato
                    ? 'Richiesta creata da operatore delegato per il dipendente selezionato.'
                    : "Richiesta creata dall'utente.",
                'origine' => 'web',
            ]);

            if ($codiceStato === 'IN_ATTESA' && $idResponsabile !== null) {
                $stmtStorico->execute([
                    'id_richiesta' => $idRichiesta,
                    'azione' => 'INVIO',
                    'id_utente_azione' => $idUtenteLoggato,
                    'dettagli' => 'Richiesta inviata al responsabile per approvazione.',
                    'origine' => 'web',
                ]);

                $stmtApp = $pdo->prepare(
                    "INSERT INTO hr_richieste_approvazioni (
                        id_richiesta, livello_approvazione, id_approvatore_assegnato, stato_approvazione, data_assegnazione
                    ) VALUES (
                        :id_richiesta, 1, :id_approvatore_assegnato, 'IN_ATTESA', NOW()
                    )"
                );
                $stmtApp->execute([
                    'id_richiesta' => $idRichiesta,
                    'id_approvatore_assegnato' => $idResponsabile,
                ]);

                hrCreaNotificaWeb(
                    $pdo,
                    'RICHIESTA_ASSENZA_DA_APPROVARE',
                    'Nuova richiesta da approvare',
                    'Hai una nuova richiesta di assenza o permesso da valutare.',
                    '/approvazioni_assenze.php',
                    $idRichiesta,
                    $idUtenteLoggato,
                    [$idResponsabile]
                );

                hrCreaNotificaWeb(
                    $pdo,
                    'RICHIESTA_ASSENZA_REGISTRATA',
                    'Richiesta registrata',
                    'La tua richiesta è stata registrata correttamente ed è in attesa di approvazione.',
                    '/assenze.php',
                    $idRichiesta,
                    $idUtenteLoggato,
                    [$idUtenteTarget]
                );

                $pdo->commit();
                header('Location: assenze.php?ok=1&id_utente=' . $idUtenteTarget);
                exit;
            }

            $stmtStorico->execute([
                'id_richiesta' => $idRichiesta,
                'azione' => 'APPROVAZIONE_AUTOMATICA',
                'id_utente_azione' => $idUtenteLoggato,
                'dettagli' => $isDelegato
                    ? 'Richiesta inserita da responsabile/HR e approvata automaticamente.'
                    : 'Richiesta registrata come approvata in automatico.',
                'origine' => 'web',
            ]);

            hrCreaNotificaWeb(
                $pdo,
                'RICHIESTA_ASSENZA_REGISTRATA',
                $isDelegato ? 'Richiesta registrata e approvata' : 'Richiesta registrata',
                $isDelegato
                    ? 'È stata registrata per te una richiesta già approvata.'
                    : 'La tua richiesta è stata registrata correttamente.',
                '/assenze.php',
                $idRichiesta,
                $idUtenteLoggato,
                [$idUtenteTarget]
            );

            $pdo->commit();
            header('Location: assenze.php?ok=2&id_utente=' . $idUtenteTarget);
            exit;
        }

        if ($azione === 'annulla_richiesta') {
            $idRichiesta = (int)($_POST['id_richiesta'] ?? 0);
            $idUtenteTarget = (int)($_POST['id_utente'] ?? $idUtenteTarget);
            if ($idRichiesta <= 0) {
                throw new RuntimeException('Richiesta non valida.');
            }
            if (!isset($utentiGestibili[$idUtenteTarget])) {
                throw new RuntimeException('Dipendente non valido.');
            }

            $stmtCheck = $pdo->prepare(
                "SELECT r.id_richiesta, sr.codice AS stato_codice
                 FROM hr_richieste r
                 INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
                 WHERE r.id_richiesta = :id_richiesta
                   AND r.id_utente_richiedente = :id_utente
                 LIMIT 1"
            );
            $stmtCheck->execute([
                'id_richiesta' => $idRichiesta,
                'id_utente' => $idUtenteTarget,
            ]);
            $riga = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$riga) {
                throw new RuntimeException('Richiesta non trovata.');
            }
            if (!in_array((string)$riga['stato_codice'], ['BOZZA', 'IN_ATTESA', 'APPROVATA'], true)) {
                throw new RuntimeException('La richiesta non può essere annullata nello stato attuale.');
            }

            $idStatoAnnullata = hrIdStatoRichiesta($pdo, 'ANNULLATA');
            $pdo->beginTransaction();

            $stmtUpd = $pdo->prepare('UPDATE hr_richieste SET id_stato_richiesta = :id_stato_richiesta, annullata_da_richiedente = 1, data_chiusura = NOW(), data_aggiornamento = NOW() WHERE id_richiesta = :id_richiesta');
            $stmtUpd->execute([
                'id_stato_richiesta' => $idStatoAnnullata,
                'id_richiesta' => $idRichiesta,
            ]);

            $stmtAppUpd = $pdo->prepare("UPDATE hr_richieste_approvazioni SET stato_approvazione = 'ANNULLATA', data_risposta = NOW() WHERE id_richiesta = :id_richiesta AND stato_approvazione = 'IN_ATTESA'");
            $stmtAppUpd->execute(['id_richiesta' => $idRichiesta]);

            $stmtStorico = $pdo->prepare('INSERT INTO hr_richieste_storico (id_richiesta, azione, id_utente_azione, dettagli, origine) VALUES (:id_richiesta, :azione, :id_utente_azione, :dettagli, :origine)');
            $stmtStorico->execute([
                'id_richiesta' => $idRichiesta,
                'azione' => 'ANNULLAMENTO',
                'id_utente_azione' => $idUtenteLoggato,
                'dettagli' => 'Richiesta annullata dal richiedente o da operatore autorizzato.',
                'origine' => 'web',
            ]);

            $pdo->commit();
            header('Location: assenze.php?annullata=1&id_utente=' . $idUtenteTarget);
            exit;
        }
    }

    if (isset($_GET['ok']) && $_GET['ok'] === '1') {
        $messaggio = 'Richiesta registrata correttamente.';
    } elseif (isset($_GET['ok']) && $_GET['ok'] === '2') {
        $messaggio = 'Richiesta registrata e approvata automaticamente per il dipendente selezionato.';
    } elseif (isset($_GET['annullata']) && $_GET['annullata'] === '1') {
        $messaggio = 'Richiesta annullata correttamente.';
    }

    $stmtRiepilogo = $pdo->prepare(
        "SELECT
            COUNT(*) AS totali,
            SUM(CASE WHEN sr.codice = 'IN_ATTESA' THEN 1 ELSE 0 END) AS in_attesa,
            SUM(CASE WHEN sr.codice = 'APPROVATA' THEN 1 ELSE 0 END) AS approvate,
            SUM(CASE WHEN sr.codice = 'RIFIUTATA' THEN 1 ELSE 0 END) AS rifiutate
         FROM hr_richieste r
         INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
         WHERE r.id_utente_richiedente = :id_utente"
    );
    $stmtRiepilogo->execute(['id_utente' => $idUtenteTarget]);
    $riepilogoDb = $stmtRiepilogo->fetch(PDO::FETCH_ASSOC);
    if ($riepilogoDb) {
        $riepilogo = [
            'totali' => (int)($riepilogoDb['totali'] ?? 0),
            'in_attesa' => (int)($riepilogoDb['in_attesa'] ?? 0),
            'approvate' => (int)($riepilogoDb['approvate'] ?? 0),
            'rifiutate' => (int)($riepilogoDb['rifiutate'] ?? 0),
        ];
    }

    $stmtRichieste = $pdo->prepare(
        "SELECT
            r.id_richiesta,
            r.codice_richiesta,
            r.oggetto,
            r.note_richiedente,
            DATE_FORMAT(r.data_creazione, '%d/%m/%Y %H:%i:%s') AS data_creazione_fmt,
            sr.codice AS stato_codice,
            sr.descrizione AS stato,
            te.descrizione AS tipologia,
            p.tipo_periodo,
            DATE_FORMAT(p.data_da, '%d/%m/%Y') AS data_da,
            DATE_FORMAT(p.data_a, '%d/%m/%Y') AS data_a,
            TIME_FORMAT(p.ora_da, '%H:%i') AS ora_da,
            TIME_FORMAT(p.ora_a, '%H:%i') AS ora_a,
            CONCAT(TRIM(COALESCE(ureq.nome, '')), CASE WHEN TRIM(COALESCE(ureq.cognome, '')) <> '' THEN CONCAT(' ', TRIM(ureq.cognome)) ELSE '' END) AS richiedente,
            CONCAT(TRIM(COALESCE(uresp.nome, '')), CASE WHEN TRIM(COALESCE(uresp.cognome, '')) <> '' THEN CONCAT(' ', TRIM(uresp.cognome)) ELSE '' END) AS responsabile
         FROM hr_richieste r
         INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
         INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
         LEFT JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta AND p.ordinamento = 1
         LEFT JOIN aut_utenti ureq ON ureq.id_utente = r.id_utente_richiedente
         LEFT JOIN aut_utenti uresp ON uresp.id_utente = r.id_responsabile_corrente
         WHERE r.id_utente_richiedente = :id_utente
         ORDER BY r.data_creazione DESC, r.id_richiesta DESC"
    );
    $stmtRichieste->execute(['id_utente' => $idUtenteTarget]);
    $richieste = $stmtRichieste->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errore = $e->getMessage();
}

$scopeLabel = $utenteSelezionato ? (string)$utenteSelezionato['nominativo'] : ('Utente #' . $idUtenteTarget);
$infoRecapitoMancante = (!$isDelegato && !hrHaRecapitoEmailPersonale($pdo, $idUtenteTarget));

layoutHeader('Assenze e permessi');
?>

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Assenze e permessi</h1>
            <div class="meta">
                Gestisci richieste per te stesso oppure, se il tuo profilo lo consente, inseriscile direttamente per collaboratori e dipendenti del tuo perimetro.
            </div>
        </div>
        <div class="section-head-actions">
            <?php if ($puoLeggereCalendario): ?>
                <a class="btn btn-light" href="calendario_assenze.php">Apri calendario</a>
            <?php endif; ?>
            <?php if ($puoLeggereApprovazioni): ?>
                <a class="btn btn-light" href="approvazioni_assenze.php">Apri approvazioni</a>
            <?php endif; ?>
            <?php if ($puoConfigurare): ?>
                <a class="btn btn-light" href="configurazione_assenze.php">Configura modulo</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($errore !== ''): ?>
    <div class="errore"><?= h($errore) ?></div>
<?php endif; ?>

<?php if ($messaggio !== ''): ?>
    <div class="ok"><?= h($messaggio) ?></div>
<?php endif; ?>

<div class="card card-compact">
    <div class="meta">
        <strong>Ambito corrente:</strong> <?= h($scopeLabel) ?>
        <?php if ($isDelegato): ?>
            · inserimento delegato con approvazione automatica
        <?php else: ?>
            · richiesta personale
        <?php endif; ?>
    </div>

    <?php if ($isDelegato): ?>
        <div class="info-box" style="margin-top:16px;">
            Le richieste inserite per un altro dipendente vengono registrate come già approvate, con storico dell'operatore che le ha create.
        </div>
    <?php elseif ($infoRecapitoMancante): ?>
        <div class="errore" style="margin-top:16px;">
            Per inserire richieste personali devi avere almeno una email personale attiva nei recapiti HR.
        </div>
    <?php endif; ?>
</div>

<div class="dashboard-grid" style="margin-bottom: 22px;">
    <div class="dashboard-box"><h3>Richieste totali</h3><div class="kpi-number"><?= (int)$riepilogo['totali'] ?></div></div>
    <div class="dashboard-box"><h3>In attesa</h3><div class="kpi-number"><?= (int)$riepilogo['in_attesa'] ?></div></div>
    <div class="dashboard-box"><h3>Approvate</h3><div class="kpi-number"><?= (int)$riepilogo['approvate'] ?></div></div>
    <div class="dashboard-box"><h3>Rifiutate</h3><div class="kpi-number"><?= (int)$riepilogo['rifiutate'] ?></div></div>
</div>

<div class="card card-form">
    <h2>Nuova richiesta</h2>

    <?php if (!$puoScrivere): ?>
        <div class="info-box">Il tuo profilo può consultare la pagina ma non inserire richieste.</div>
    <?php else: ?>
        <form method="post" action="assenze.php" id="form-richiesta-assenza">
            <input type="hidden" name="azione" value="nuova_richiesta">

            <div class="hr-request-layout">
                <div class="hr-request-row hr-request-row-primary">
                    <?php if (count($utentiGestibili) > 1): ?>
                    <div class="form-group hr-field-dipendente">
                        <label for="id_utente"><strong>Dipendente</strong></label>
                        <select name="id_utente" id="id_utente" onchange="window.location.href='assenze.php?id_utente=' + encodeURIComponent(this.value)">
                            <?php foreach ($utentiGestibili as $u): ?>
                                <option value="<?= (int)$u['id_utente'] ?>" <?= (int)$u['id_utente'] === $idUtenteTarget ? 'selected' : '' ?>>
                                    <?= h((string)$u['nominativo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <div class="form-group hr-field-dipendente">
                        <label for="dipendente_visualizzato"><strong>Dipendente</strong></label>
                        <input type="hidden" name="id_utente" value="<?= (int)$idUtenteTarget ?>">
                        <input type="text" id="dipendente_visualizzato" value="<?= h($scopeLabel) ?>" readonly>
                    </div>
                    <?php endif; ?>

                    <div class="form-group hr-field-tipologia">
                        <label for="id_tipologia_evento">Tipologia</label>
                        <select name="id_tipologia_evento" id="id_tipologia_evento" required>
                            <option value="">Seleziona...</option>
                            <?php foreach ($tipologie as $tipologia): ?>
                                <option value="<?= (int)$tipologia['id_tipologia_evento'] ?>" <?= (int)$form['id_tipologia_evento'] === (int)$tipologia['id_tipologia_evento'] ? 'selected' : '' ?>>
                                    <?= h((string)$tipologia['descrizione']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group hr-field-modalita">
                        <label for="modalita">Modalità</label>
                        <select name="modalita" id="modalita">
                            <option value="giorni" <?= $form['modalita'] === 'giorni' ? 'selected' : '' ?>>Giorni</option>
                            <option value="ore" <?= $form['modalita'] === 'ore' ? 'selected' : '' ?>>Ore</option>
                        </select>
                    </div>

                    <div class="form-group hr-field-data" id="gruppo_data_da">
                        <label for="data_da" id="label_data_da">Dal giorno</label>
                        <input class="control-standard" type="date" name="data_da" id="data_da" value="<?= h($form['data_da']) ?>" required>
                    </div>

                    <div class="form-group hr-field-data" id="gruppo_data_a">
                        <label for="data_a" id="label_data_a">Al giorno</label>
                        <input class="control-standard" type="date" name="data_a" id="data_a" value="<?= h($form['data_a']) ?>">
                    </div>

                    <div class="form-group hr-field-time" id="gruppo_ora_da">
                        <label for="ora_da" id="label_ora_da">Dalle ore</label>
                        <input class="control-standard" type="time" name="ora_da" id="ora_da" value="<?= h($form['ora_da']) ?>">
                    </div>

                    <div class="form-group hr-field-time" id="gruppo_ora_a">
                        <label for="ora_a" id="label_ora_a">Alle ore</label>
                        <input class="control-standard" type="time" name="ora_a" id="ora_a" value="<?= h($form['ora_a']) ?>">
                    </div>
                </div>

                <div class="hr-request-row hr-request-row-secondary">
                    <div class="form-group hr-field-oggetto">
                        <label for="oggetto">Oggetto breve</label>
                        <input type="text" name="oggetto" id="oggetto" maxlength="150" value="<?= h($form['oggetto']) ?>">
                    </div>

                    <div class="form-group hr-field-note">
                        <label for="note_richiedente">Note del richiedente</label>
                        <textarea name="note_richiedente" id="note_richiedente"><?= h($form['note_richiedente']) ?></textarea>
                    </div>

                    <div class="actions hr-request-submit">
                        <button type="submit" <?= $infoRecapitoMancante ? 'disabled' : '' ?>>Registra richiesta</button>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card card-wide">
    <h2>Storico richieste</h2>

    <?php if (count($richieste) === 0): ?>
        <div class="meta">Non ci sono ancora richieste per il dipendente selezionato.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Codice</th>
                        <th>Utente</th>
                        <th>Tipologia</th>
                        <th>Periodo</th>
                        <th>Stato</th>
                        <th>Responsabile</th>
                        <th>Creata il</th>
                        <th>Oggetto / note</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($richieste as $r): ?>
                        <?php
                        $periodo = (string)$r['data_da'];
                        if ((string)$r['data_a'] !== '' && (string)$r['data_a'] !== (string)$r['data_da']) {
                            $periodo .= ' → ' . (string)$r['data_a'];
                        }
                        if ((string)$r['tipo_periodo'] === 'ORE' && (string)$r['ora_da'] !== '' && (string)$r['ora_a'] !== '') {
                            $periodo .= '<br><span class="meta">' . h((string)$r['ora_da']) . ' - ' . h((string)$r['ora_a']) . '</span>';
                        }
                        $annullabile = in_array((string)$r['stato_codice'], ['BOZZA', 'IN_ATTESA', 'APPROVATA'], true);
                        ?>
                        <tr>
                            <td><strong><?= h((string)$r['codice_richiesta']) ?></strong></td>
                            <td><?= h((string)$r['richiedente']) ?></td>
                            <td><?= h((string)$r['tipologia']) ?></td>
                            <td><?= $periodo ?></td>
                            <td><span class="status-badge <?= hrClasseStato((string)$r['stato_codice']) ?>"><?= h((string)$r['stato']) ?></span></td>
                            <td><?= h(trim((string)$r['responsabile']) !== '' ? (string)$r['responsabile'] : 'Nessun responsabile') ?></td>
                            <td><?= h((string)$r['data_creazione_fmt']) ?></td>
                            <td>
                                <?php if (trim((string)$r['oggetto']) !== ''): ?>
                                    <strong><?= h((string)$r['oggetto']) ?></strong><br>
                                <?php endif; ?>
                                <?php if (trim((string)$r['note_richiedente']) !== ''): ?>
                                    <?= nl2br(h((string)$r['note_richiedente'])) ?>
                                <?php else: ?>
                                    <span class="meta">Nessuna nota</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($puoScrivere && $annullabile): ?>
                                    <form method="post" action="assenze.php" onsubmit="return confirm('Confermi l\'annullamento della richiesta?');">
                                        <input type="hidden" name="azione" value="annulla_richiesta">
                                        <input type="hidden" name="id_richiesta" value="<?= (int)$r['id_richiesta'] ?>">
                                        <input type="hidden" name="id_utente" value="<?= (int)$idUtenteTarget ?>">
                                        <button type="submit" class="btn-light">Annulla</button>
                                    </form>
                                <?php else: ?>
                                    <span class="meta">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const modalita = document.getElementById('modalita');
    if (!modalita) {
        return;
    }

    const gruppoDataA = document.getElementById('gruppo_data_a');
    const gruppoOraDa = document.getElementById('gruppo_ora_da');
    const gruppoOraA = document.getElementById('gruppo_ora_a');

    const dataDa = document.getElementById('data_da');
    const dataA = document.getElementById('data_a');
    const oraDa = document.getElementById('ora_da');
    const oraA = document.getElementById('ora_a');
    const labelDataDa = document.getElementById('label_data_da');

    function toggleBlock(element, show) {
        if (!element) return;
        element.classList.toggle('is-hidden', !show);
    }

    function aggiornaCampi() {
        const isOre = modalita.value === 'ore';

        toggleBlock(gruppoDataA, !isOre);
        toggleBlock(gruppoOraDa, isOre);
        toggleBlock(gruppoOraA, isOre);

        if (labelDataDa) {
            labelDataDa.textContent = isOre ? 'Giorno' : 'Dal giorno';
        }

        dataA.required = !isOre;
        oraDa.required = isOre;
        oraA.required = isOre;

        if (isOre) {
            dataA.value = dataDa.value;
        } else {
            if (!dataA.value) {
                dataA.value = dataDa.value;
            }
            oraDa.value = '';
            oraA.value = '';
        }
    }

    modalita.addEventListener('change', aggiornaCampi);
    dataDa.addEventListener('change', function () {
        if (modalita.value === 'ore') {
            dataA.value = dataDa.value;
        }
    });

    aggiornaCampi();
})();
</script>

<?php layoutFooter(); ?>
