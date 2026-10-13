<?php
declare(strict_types=1);
require_once __DIR__ . '/hr_regole_assenze.php';

if (!function_exists('hrAllattOggi')) {
    function hrAllattOggi(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
    }
}

function hrAllattRichiediOperatore(): void
{
    if (!hrRegoleOperatoreHr() || !haPermessoScrittura('benefici_hr') || !haPermessoScrittura('assenze')) {
        throw new RuntimeException('La programmazione dell’allattamento è riservata a HR/admin con permessi di scrittura su diritti e assenze.');
    }
}

function hrAllattConfig(array $input, ?string $oggi = null): array
{
    $c = ['azione' => (string)($input['azione_programma'] ?? 'crea'),
        'id_programma' => (int)($input['id_programma'] ?? 0), 'id_utente' => (int)($input['id_utente'] ?? 0),
        'data_da' => (string)($input['data_da'] ?? ''), 'data_a' => (string)($input['data_a'] ?? ''),
        'id_richiesta' => (int)($input['id_richiesta'] ?? 0), 'giorni' => [], 'fasce' => []];
    if (!in_array($c['azione'], ['crea', 'modifica', 'annulla', 'chiusure', 'giorno'], true)) throw new RuntimeException('Azione non valida.');
    if ($c['id_utente'] <= 0 || ($c['azione'] !== 'crea' && $c['id_programma'] <= 0)) throw new RuntimeException('Seleziona un dipendente e un programma valido.');
    hrRegoleValidaDate($c['data_da'], $c['data_a']);
    if ($c['data_da'] < ($oggi ?? hrAllattOggi())) throw new RuntimeException('La programmazione modifica soltanto oggi e i giorni futuri.');
    if ((new DateTimeImmutable($c['data_da']))->diff(new DateTimeImmutable($c['data_a']))->days > 731) throw new RuntimeException('Programma al massimo due anni alla volta.');
    if ($c['azione'] === 'giorno' && $c['data_da'] !== $c['data_a']) throw new RuntimeException('Seleziona una sola giornata.');
    if (in_array($c['azione'], ['crea', 'modifica'], true)) {
        $raw = $input['giorni'] ?? [];
        if (!is_array($raw)) throw new RuntimeException('Giorni non validi.');
        foreach ($raw as $g) {
            if (!preg_match('/^[1-7]$/', (string)$g)) throw new RuntimeException('Giorni non validi.');
            $c['giorni'][] = (int)$g;
        }
        $c['giorni'] = array_values(array_unique($c['giorni'])); sort($c['giorni']);
        if (!$c['giorni']) throw new RuntimeException('Seleziona almeno un giorno della settimana.');
        $da = $input['ora_da'] ?? []; $a = $input['ora_a'] ?? [];
        if (!is_array($da) || !is_array($a) || count($da) !== count($a) || count($da) > 4) throw new RuntimeException('Fasce orarie non valide.');
        $totale = 0;
        foreach ($da as $i => $inizio) {
            $fine = (string)($a[$i] ?? ''); $inizio = (string)$inizio;
            if ($inizio === '' && $fine === '') continue;
            foreach ([$inizio, $fine] as $ora) {
                if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $ora, $m)
                    || (int)$m[2] % 15 !== 0 || $ora < '08:00' || $ora > '17:00') {
                    throw new RuntimeException('Indica fasce tra le 08:00 e le 17:00, a intervalli di 15 minuti.');
                }
            }
            if ($fine <= $inizio) throw new RuntimeException('Ogni fascia deve avere un orario finale successivo a quello iniziale.');
            $minuti = hrAllattMinuti($fine) - hrAllattMinuti($inizio);
            $totale += $minuti;
            $c['fasce'][] = ['ora_da' => $inizio, 'ora_a' => $fine, 'minuti' => $minuti];
        }
        usort($c['fasce'], static fn($a, $b) => strcmp($a['ora_da'], $b['ora_da']));
        if (!$c['fasce'] || $totale > 120) throw new RuntimeException('Indica almeno una fascia, per un massimo complessivo di 2 ore al giorno.');
        for ($i = 1; $i < count($c['fasce']); $i++) {
            if ($c['fasce'][$i]['ora_da'] < $c['fasce'][$i - 1]['ora_a']) throw new RuntimeException('Le fasce del programma non possono sovrapporsi.');
        }
    }
    return $c;
}

function hrAllattMinuti(string $ora): int { return (int)substr($ora, 0, 2) * 60 + (int)substr($ora, 3, 2); }
function hrAllattChiusura(string $giorno, array $chiusure): ?array
{
    foreach ($chiusure as $r) if ($r['data_da'] <= $giorno && $r['data_a'] >= $giorno) return $r;
    return null;
}

// Pianificatore puro, condiviso da anteprima e conferma transazionale.
function hrAllattPiano(array $c, array $chiusure, array $esistenti): array
{
    $p = ['config' => $c, 'inserire' => [], 'duplicati' => [], 'chiusure' => [], 'conflitti' => [], 'annullare' => []];
    foreach ($esistenti as $r) {
        if ((int)($r['id_programma'] ?? 0) !== $c['id_programma'] || !$c['id_programma']) continue;
        if ($r['data_da'] < $c['data_da']) continue;
        $togli = $c['azione'] === 'modifica'
            || ($c['azione'] === 'annulla' && $r['data_da'] <= $c['data_a'])
            || ($c['azione'] === 'chiusure' && $r['data_da'] <= $c['data_a'] && hrAllattChiusura($r['data_da'], $chiusure) !== null)
            || ($c['azione'] === 'giorno' && $r['data_da'] === $c['data_da']
                && (!$c['id_richiesta'] || (int)$r['id_richiesta'] === $c['id_richiesta']));
        if ($togli) $p['annullare'][(int)$r['id_richiesta']] = $r;
    }
    $p['annullare'] = array_values($p['annullare']);
    $ids = array_column($p['annullare'], 'id_richiesta');
    $rimasti = array_values(array_filter($esistenti, static fn($r) => !in_array($r['id_richiesta'], $ids)));
    if (!in_array($c['azione'], ['crea', 'modifica'], true)) return $p;
    $fine = new DateTimeImmutable($c['data_a']);
    for ($d = new DateTimeImmutable($c['data_da']); $d <= $fine; $d = $d->modify('+1 day')) {
        if (!in_array((int)$d->format('N'), $c['giorni'], true)) continue;
        $g = $d->format('Y-m-d'); $chiusura = hrAllattChiusura($g, $chiusure);
        if ($chiusura !== null) { $p['chiusure'][] = ['giorno' => $g, 'descrizione' => $chiusura['descrizione']]; continue; }
        $minutiUsati = 0;
        foreach ($rimasti as $r) {
            if ($r['data_da'] > $g || $r['data_a'] < $g || $r['codice_tipologia'] !== 'ALLATTAMENTO'
                || !in_array($r['stato_codice'], ['IN_ATTESA', 'APPROVATA'], true)) continue;
            $minutiUsati += $r['tipo_periodo'] === 'ORE' && $r['data_da'] === $r['data_a'] && $r['ora_da'] !== null && $r['ora_a'] > $r['ora_da']
                ? hrAllattMinuti($r['ora_a']) - hrAllattMinuti($r['ora_da']) : 120;
        }
        $nuoviMinuti = 0;
        foreach ($c['fasce'] as $fascia) {
            $nuovo = $fascia + ['data_da' => $g, 'data_a' => $g, 'tipo_periodo' => 'ORE'];
            $duplicata = false;
            foreach ($rimasti as $r) {
                if ($r['data_da'] > $g || $r['data_a'] < $g) continue;
                $stessa = $r['codice_tipologia'] === 'ALLATTAMENTO' && $r['tipo_periodo'] === 'ORE'
                    && $r['data_da'] === $g && $r['data_a'] === $g
                    && substr((string)$r['ora_da'], 0, 5) === $fascia['ora_da'] && substr((string)$r['ora_a'], 0, 5) === $fascia['ora_a']
                    && in_array($r['stato_codice'], ['IN_ATTESA', 'APPROVATA'], true);
                if ($stessa) { $duplicata = true; continue; }
                if ($r['tipo_periodo'] !== 'ORE' || $r['ora_da'] === null || $r['ora_a'] === null
                    || (substr((string)$r['ora_da'], 0, 5) < $fascia['ora_a'] && substr((string)$r['ora_a'], 0, 5) > $fascia['ora_da'])) {
                    $p['conflitti'][] = ['giorno' => $g, 'motivo' => 'Sovrapposizione con richiesta ' . $r['codice_richiesta']];
                }
            }
            if ($duplicata) $p['duplicati'][] = $nuovo;
            else { $p['inserire'][] = $nuovo; $nuoviMinuti += $fascia['minuti']; }
        }
        if ($minutiUsati + $nuoviMinuti > 120) $p['conflitti'][] = ['giorno' => $g, 'motivo' => 'Superamento delle 2 ore complessive di allattamento.'];
    }
    return $p;
}

function hrAllattPrepara(PDO $pdo, array $c): array
{
    $soloAttivi = in_array($c['azione'], ['crea', 'modifica'], true) ? ' AND u.attivo = 1 AND hp.attivo = 1' : '';
    $stmt = $pdo->prepare('SELECT u.id_utente, u.nome, u.cognome, u.username FROM aut_utenti u
        LEFT JOIN hr_profili_dipendenti hp ON hp.id_utente = u.id_utente
        WHERE u.id_utente = :utente' . $soloAttivi . ' LIMIT 1');
    $stmt->execute(['utente' => $c['id_utente']]);
    $utente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$utente) throw new RuntimeException('Dipendente non attivo o senza profilo HR.');
    $programma = null;
    if ($c['id_programma']) {
        $stmt = $pdo->prepare('SELECT * FROM hr_allattamento_programmi WHERE id_programma = :programma AND id_utente = :utente');
        $stmt->execute(['programma' => $c['id_programma'], 'utente' => $c['id_utente']]);
        $programma = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$programma || $c['azione'] === 'crea') throw new RuntimeException('Programma non valido per il dipendente selezionato.');
        if ($c['azione'] === 'modifica' && $c['data_da'] < $programma['data_da']) throw new RuntimeException('La modifica non può precedere l’inizio del programma.');
    }
    $beneficio = null;
    if (in_array($c['azione'], ['crea', 'modifica'], true)) {
        $stmt = $pdo->prepare("SELECT data_inizio, data_fine FROM hr_benefici_utenti WHERE id_utente = :utente
            AND codice_beneficio = 'ALLATTAMENTO' AND attivo = 1 LIMIT 1");
        $stmt->execute(['utente' => $c['id_utente']]); $beneficio = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$beneficio || !$beneficio['data_fine'] || $c['data_da'] < $beneficio['data_inizio'] || $c['data_a'] > $beneficio['data_fine']) {
            throw new RuntimeException('Il periodo deve rientrare interamente nel diritto Allattamento attivo, con data iniziale e finale assegnate da HR.');
        }
    }
    // Legge anche le date successive al nuovo termine: la modifica deve poter interrompere la coda precedente.
    $stmt = $pdo->prepare("SELECT r.id_richiesta, r.codice_richiesta, te.codice AS codice_tipologia,
        sr.codice AS stato_codice, p.tipo_periodo, p.data_da, p.data_a, p.ora_da, p.ora_a, leg.id_programma
        FROM hr_richieste r INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
        INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
        INNER JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
        LEFT JOIN hr_allattamento_programma_richieste leg ON leg.id_richiesta = r.id_richiesta
        WHERE r.id_utente_richiedente = :utente AND sr.codice IN ('BOZZA', 'IN_ATTESA', 'APPROVATA')
        AND p.data_a >= :inizio ORDER BY p.data_da, p.ora_da, r.id_richiesta");
    $stmt->execute(['utente' => $c['id_utente'], 'inizio' => $c['data_da']]);
    $p = hrAllattPiano($c, hrRegoleChiusure($pdo), $stmt->fetchAll(PDO::FETCH_ASSOC));
    $p['utente'] = $utente; $p['beneficio'] = $beneficio; $p['programma'] = $programma;
    return $p;
}

function hrAllattImpronta(array $p): string
{
    return hash('sha256', json_encode($p, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function hrAllattIdStato(PDO $pdo, string $codice): int
{
    $s = $pdo->prepare('SELECT id_stato_richiesta FROM hr_stati_richiesta WHERE codice = :codice AND attivo = 1 LIMIT 1');
    $s->execute(['codice' => $codice]); $id = (int)$s->fetchColumn();
    if (!$id) throw new RuntimeException('Stato richieste non configurato: ' . $codice);
    return $id;
}

// Richiede una transazione e il mutex comune già acquisito dal chiamante.
function hrAllattSalva(PDO $pdo, array $p, int $operatore): array
{
    hrAllattRichiediOperatore();
    if (!$pdo->inTransaction() || $operatore <= 0) throw new LogicException('Salvataggio non transazionale o operatore mancante.');
    if ($p['conflitti']) throw new RuntimeException('Risolvi i conflitti e ripeti l’anteprima.');
    if (!$p['inserire'] && !$p['annullare']) throw new RuntimeException('Nessuna modifica da salvare: le fasce sono già presenti o tutte escluse.');
    $c = $p['config']; $id = $c['id_programma'];
    $cfg = json_encode(['giorni' => $c['giorni'], 'fasce' => $c['fasce']], JSON_THROW_ON_ERROR);
    if (!$id) {
        $s = $pdo->prepare('INSERT INTO hr_allattamento_programmi
            (id_utente, data_da, data_a, decorrenza_config, configurazione_json, creato_da, aggiornato_da)
            VALUES (:utente, :inizio, :fine, :decorrenza, :config, :creatore, :operatore)');
        $s->execute(['utente' => $c['id_utente'], 'inizio' => $c['data_da'], 'fine' => $c['data_a'],
            'decorrenza' => $c['data_da'], 'config' => $cfg, 'creatore' => $operatore, 'operatore' => $operatore]);
        $id = (int)$pdo->lastInsertId();
    } elseif ($c['azione'] === 'modifica') {
        $s = $pdo->prepare('UPDATE hr_allattamento_programmi SET data_a = :fine, decorrenza_config = :decorrenza,
            configurazione_json = :config, aggiornato_da = :operatore, data_aggiornamento = NOW() WHERE id_programma = :programma');
        $s->execute(['fine' => $c['data_a'], 'decorrenza' => $c['data_da'], 'config' => $cfg, 'operatore' => $operatore, 'programma' => $id]);
    }
    $storico = $pdo->prepare('INSERT INTO hr_richieste_storico (id_richiesta, azione, id_utente_azione, dettagli, origine)
        VALUES (:richiesta, :azione, :operatore, :dettagli, \'web\')');
    $ris = ['id_programma' => $id, 'id_utente' => $c['id_utente'], 'inserite' => 0, 'annullate' => 0, 'trigger_oggi' => 0];
    if ($p['annullare']) {
        $stato = hrAllattIdStato($pdo, 'ANNULLATA');
        $upd = $pdo->prepare('UPDATE hr_richieste SET id_stato_richiesta = :stato, annullata_da_richiedente = 0,
            data_chiusura = NOW(), data_aggiornamento = NOW() WHERE id_richiesta = :richiesta');
        $app = $pdo->prepare("UPDATE hr_richieste_approvazioni SET stato_approvazione = 'ANNULLATA', data_risposta = NOW()
            WHERE id_richiesta = :richiesta AND stato_approvazione = 'IN_ATTESA'");
        foreach ($p['annullare'] as $r) {
            $rid = (int)$r['id_richiesta']; $upd->execute(['stato' => $stato, 'richiesta' => $rid]); $app->execute(['richiesta' => $rid]);
            $storico->execute(['richiesta' => $rid, 'azione' => 'ANNULLAMENTO', 'operatore' => $operatore,
                'dettagli' => 'Programma allattamento #' . $id . ': ' . $c['azione'] . ' da HR/admin.']);
            $ris['annullate']++;
            if ($r['data_da'] === hrAllattOggi()) $ris['trigger_oggi'] = $rid;
        }
    }
    if ($p['inserire']) {
        // Verifica l’intero insieme in una volta: considera anche la somma delle nuove fasce dello stesso giorno.
        hrRegoleVerificaRichiesta($pdo, $c['id_utente'], 'ALLATTAMENTO', $p['inserire']);
        $s = $pdo->query("SELECT id_tipologia_evento FROM hr_tipologie_evento WHERE codice = 'ALLATTAMENTO' AND attivo = 1 AND consente_ore = 1 LIMIT 1");
        $tipo = (int)$s->fetchColumn(); if (!$tipo) throw new RuntimeException('Tipologia Allattamento a ore non configurata.');
        $stato = hrAllattIdStato($pdo, 'APPROVATA');
        $s = $pdo->prepare("SELECT ro.id_utente_collegato FROM hr_relazioni_organizzative ro
            INNER JOIN hr_tipi_relazione_organizzativa tro ON tro.id_tipo_relazione = ro.id_tipo_relazione
            AND tro.codice IN ('RESPONSABILE_DIRETTO', 'RESPONSABILE_FUNZIONALE') AND tro.attivo = 1
            WHERE ro.id_utente = :utente AND ro.attiva = 1 AND ro.data_inizio <= CURDATE()
            AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE()) ORDER BY ro.data_inizio DESC, ro.id_relazione_organizzativa DESC LIMIT 1");
        $s->execute(['utente' => $c['id_utente']]); $responsabile = $s->fetchColumn() ?: null;
        $ins = $pdo->prepare("INSERT INTO hr_richieste (codice_richiesta, id_utente_richiedente, id_tipologia_evento,
            id_stato_richiesta, id_responsabile_corrente, data_invio, data_chiusura, data_aggiornamento, origine)
            VALUES (:codice, :utente, :tipo, :stato, :responsabile, NOW(), NOW(), NOW(), 'web')");
        $periodo = $pdo->prepare("INSERT INTO hr_richieste_periodi (id_richiesta, tipo_periodo, data_da, data_a,
            ora_da, ora_a, giornata_intera, minuti_totali, ordinamento)
            VALUES (:richiesta, 'ORE', :inizio, :fine, :ora_da, :ora_a, 0, :minuti, 1)");
        $legame = $pdo->prepare('INSERT INTO hr_allattamento_programma_richieste (id_richiesta, id_programma) VALUES (:richiesta, :programma)');
        foreach ($p['inserire'] as $r) {
            $ins->execute(['codice' => 'HR-' . bin2hex(random_bytes(12)), 'utente' => $c['id_utente'],
                'tipo' => $tipo, 'stato' => $stato, 'responsabile' => $responsabile]);
            $rid = (int)$pdo->lastInsertId();
            $periodo->execute(['richiesta' => $rid, 'inizio' => $r['data_da'], 'fine' => $r['data_a'],
                'ora_da' => $r['ora_da'], 'ora_a' => $r['ora_a'], 'minuti' => $r['minuti']]);
            $legame->execute(['richiesta' => $rid, 'programma' => $id]);
            $storico->execute(['richiesta' => $rid, 'azione' => 'CREAZIONE', 'operatore' => $operatore,
                'dettagli' => 'Richiesta registrata e approvata da HR/admin tramite programma allattamento #' . $id . '.']);
            $ris['inserite']++;
            if ($r['data_da'] === hrAllattOggi()) $ris['trigger_oggi'] = $rid;
        }
    }
    $s = $pdo->prepare('INSERT INTO hr_allattamento_programmi_storico (id_programma, id_operatore, azione, dettagli_json)
        VALUES (:programma, :operatore, :azione, :dettagli)');
    $s->execute(['programma' => $id, 'operatore' => $operatore, 'azione' => $c['azione'],
        'dettagli' => json_encode(['config' => $c, 'risultato' => $ris, 'escluse_chiusure' => $p['chiusure'],
            'gia_presenti' => count($p['duplicati'])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    return $ris;
}
