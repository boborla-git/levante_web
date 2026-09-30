<?php
declare(strict_types=1);

// Regole condivise da calendario web e feed ICS. Nessun accesso alla sessione:
// il chiamante fornisce sempre l'identita' del proprietario del calendario.

/**
 * Stessa precedenza di autorizzazioni.php: admin globale, regola atomica
 * read/write (anche negata), poi fallback legacy view/edit per le pagine.
 * Valuta esclusivamente dati DB correnti, senza cookie/sessione del browser.
 */
function hrCalendarioValutaPermessi(string $username, array $righe): array
{
    $admin = strtolower(trim($username)) === 'admin';
    $regole = [];
    foreach ($righe as $riga) {
        if (in_array(strtolower(trim((string)($riga['codice_ruolo'] ?? ''))), ['admin', 'admin_portale', 'amministratore'], true)) {
            $admin = true;
        }
        $codice = (string)($riga['codice_risorsa'] ?? '');
        $permesso = strtolower(trim((string)($riga['permesso'] ?? '')));
        if ($codice !== '' && $permesso !== '') {
            $regole[$codice][$permesso][] = (int)($riga['consentito'] ?? 0) === 1;
        }
    }
    $consentito = static function (string $codice, string $permesso) use ($admin, $regole): bool {
        if ($admin) return true;
        if (isset($regole[$codice][$permesso])) {
            return in_array(true, $regole[$codice][$permesso], true);
        }
        if (strpos($codice, 'pagina.') !== 0) return false;
        $legacy = $permesso === 'read'
            ? ['view', 'edit', 'write', 'create', 'delete', 'execute']
            : ['edit', 'write', 'create', 'delete', 'execute'];
        foreach ($legacy as $p) {
            if (in_array(true, $regole[$codice][$p] ?? [], true)) return true;
        }
        return false;
    };
    $configurare = $consentito('pagina.configurazione_assenze', 'write');
    return [
        'leggere' => $consentito('pagina.calendario_assenze', 'read'),
        'configurare' => $configurare,
        'tutte' => $configurare || $consentito('azione.hr.assenze.visualizza_tutte', 'read'),
        'tipologie' => $configurare || $consentito('azione.hr.assenze.visualizza_tipologie', 'read'),
        'pendenti' => $configurare || $consentito('azione.hr.assenze.visualizza_pendenti_globali', 'read'),
    ];
}

function hrCalendarioPermessiUtente(PDO $pdo, int $idUtente): array
{
    $negati = ['leggere' => false, 'configurare' => false, 'tutte' => false, 'tipologie' => false, 'pendenti' => false];
    if ($idUtente <= 0) return $negati;
    $stmt = $pdo->prepare('SELECT username FROM aut_utenti WHERE id_utente = ? AND attivo = 1 LIMIT 1');
    $stmt->execute([$idUtente]);
    $username = $stmt->fetchColumn();
    if ($username === false) return $negati;
    $stmt = $pdo->prepare(
        "SELECT ar.codice_ruolo, ars.codice_risorsa, arp.permesso, arp.consentito
         FROM aut_utenti_ruoli aur
         INNER JOIN aut_ruoli ar ON ar.id_ruolo = aur.id_ruolo AND ar.attivo = 1
         LEFT JOIN aut_ruoli_permessi arp ON arp.id_ruolo = ar.id_ruolo
         LEFT JOIN aut_risorse ars ON ars.id_risorsa = arp.id_risorsa AND ars.attivo = 1
         WHERE aur.id_utente = ? AND aur.attivo = 1
           AND (aur.data_fine IS NULL OR aur.data_fine >= NOW())"
    );
    $stmt->execute([$idUtente]);
    return hrCalendarioValutaPermessi((string)$username, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Query unica per web/ICS. Gli approvatori vedono i pendenti assegnati
 * solamente all'interno del proprio scope. EXISTS evita eventi duplicati
 * in presenza di piu' righe di approvazione della medesima richiesta.
 */
function hrEventiCalendario(PDO $pdo, int $idUtente, array $scopeIds, bool $pendentiGlobali, ?string $dataDa = null, ?string $dataA = null): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $scopeIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) return [];
    if (($dataDa === null) !== ($dataA === null)) throw new InvalidArgumentException('Periodo calendario incompleto.');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $filtroPeriodo = $dataDa !== null ? ' AND p.data_da <= ? AND p.data_a >= ?' : '';
    $sql = "SELECT r.id_richiesta, r.codice_richiesta, r.id_utente_richiedente, r.oggetto,
                   r.data_creazione, r.data_aggiornamento,
                   p.id_richiesta_periodo, p.data_da, p.data_a, p.ora_da, p.ora_a, p.tipo_periodo,
                   te.codice AS codice_tipologia, te.descrizione AS tipologia, te.descrizione_calendario,
                   te.mostra_dettaglio_colleghi, te.mostra_dettaglio_responsabili, te.mostra_dettaglio_hr,
                   sr.codice AS codice_stato_richiesta, sr.descrizione AS stato_richiesta,
                   sp.descrizione_breve AS stato_presenza_breve, sp.descrizione AS stato_presenza,
                   u.nome, u.cognome, u.username
            FROM hr_richieste r
            INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
               AND sr.codice IN ('APPROVATA','IN_ATTESA')
            INNER JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
            INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
               AND te.visibile_calendario = 1 AND te.attivo = 1
            INNER JOIN hr_stati_presenza sp ON sp.id_stato_presenza = te.id_stato_presenza
            INNER JOIN aut_utenti u ON u.id_utente = r.id_utente_richiedente AND u.attivo = 1
            WHERE r.id_utente_richiedente IN ($placeholders)
              $filtroPeriodo
              AND (sr.codice = 'APPROVATA' OR
                   (sr.codice = 'IN_ATTESA' AND
                    (r.id_utente_richiedente = ? OR ? = 1 OR EXISTS (
                        SELECT 1 FROM hr_richieste_approvazioni ra
                        WHERE ra.id_richiesta = r.id_richiesta
                          AND ra.id_approvatore_assegnato = ?
                          AND ra.stato_approvazione = 'IN_ATTESA'
                    ))))
            ORDER BY p.data_da, p.ora_da, u.cognome, u.nome, r.id_richiesta, p.id_richiesta_periodo";
    $params = $ids;
    if ($dataDa !== null) { $params[] = $dataA; $params[] = $dataDa; }
    $params[] = $idUtente;
    $params[] = $pendentiGlobali ? 1 : 0;
    $params[] = $idUtente;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hrNomeUtente(array $row): string
{
    $nome = trim((string)($row['nome'] ?? ''));
    $cognome = trim((string)($row['cognome'] ?? ''));
    $username = trim((string)($row['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);

    return $nominativo !== '' ? $nominativo : ($username !== '' ? $username : ('Utente #' . (int)($row['id_utente'] ?? 0)));
}


function hrIdsDirettiCalendario(PDO $pdo, int $idUtente): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT ro.id_utente
         FROM hr_relazioni_organizzative ro
         INNER JOIN hr_tipi_relazione_organizzativa tro
            ON tro.id_tipo_relazione = ro.id_tipo_relazione
           AND tro.attivo = 1
           AND tro.codice IN ('RESPONSABILE_DIRETTO', 'RESPONSABILE_FUNZIONALE')
         INNER JOIN aut_utenti u
            ON u.id_utente = ro.id_utente
           AND u.attivo = 1
         WHERE ro.id_utente_collegato = :id_utente
           AND ro.attiva = 1
           AND ro.data_inizio <= CURDATE()
           AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())"
    );
    $stmt->execute(['id_utente' => $idUtente]);

    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}


function hrIdsGruppoCalendario(PDO $pdo, int $idUtente): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT gu2.id_utente
         FROM hr_gruppi_utenti gu1
         INNER JOIN hr_gruppi_utenti gu2
            ON gu2.id_gruppo_lavoro = gu1.id_gruppo_lavoro
           AND gu2.attivo = 1
           AND gu2.data_inizio <= CURDATE()
           AND (gu2.data_fine IS NULL OR gu2.data_fine >= CURDATE())
         INNER JOIN aut_utenti u
            ON u.id_utente = gu2.id_utente
           AND u.attivo = 1
         WHERE gu1.id_utente = :id_utente
           AND gu1.attivo = 1
           AND gu1.data_inizio <= CURDATE()
           AND (gu1.data_fine IS NULL OR gu1.data_fine >= CURDATE())"
    );
    $stmt->execute(['id_utente' => $idUtente]);

    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}


function hrScopeUtentiCalendario(PDO $pdo, int $idUtente, bool $puoVedereTutteAssenze): array
{
    if ($puoVedereTutteAssenze) {
        $stmt = $pdo->query(
            "SELECT id_utente, nome, cognome, username, 0 AS scope_gerarchia, 0 AS scope_gruppo
             FROM aut_utenti
             WHERE attivo = 1
             ORDER BY cognome, nome, username"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $map = [];
    $map[$idUtente] = ['scope_gerarchia' => false, 'scope_gruppo' => false];

    foreach (hrIdsDirettiCalendario($pdo, $idUtente) as $uid) {
        if (!isset($map[$uid])) {
            $map[$uid] = ['scope_gerarchia' => false, 'scope_gruppo' => false];
        }
        $map[$uid]['scope_gerarchia'] = true;
    }

    foreach (hrIdsGruppoCalendario($pdo, $idUtente) as $uid) {
        if ($uid === $idUtente) {
            continue;
        }
        if (!isset($map[$uid])) {
            $map[$uid] = ['scope_gerarchia' => false, 'scope_gruppo' => false];
        }
        $map[$uid]['scope_gruppo'] = true;
    }

    $ids = array_keys($map);
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id_utente, nome, cognome, username
         FROM aut_utenti
         WHERE attivo = 1
           AND id_utente IN ($placeholders)
         ORDER BY cognome, nome, username"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $uid = (int)$row['id_utente'];
        $row['scope_gerarchia'] = !empty($map[$uid]['scope_gerarchia']) ? 1 : 0;
        $row['scope_gruppo'] = !empty($map[$uid]['scope_gruppo']) ? 1 : 0;
    }
    unset($row);

    return $rows;
}


function hrMostraDettaglioCalendario(array $row, array $scopeMap, int $idUtenteCorrente, bool $puoConfigurare, bool $puoVedereTipologieAssenze): bool
{
    $idRichiedente = (int)($row['id_utente_richiedente'] ?? 0);

    if ($idRichiedente === $idUtenteCorrente) {
        return true;
    }

    if ($puoConfigurare || $puoVedereTipologieAssenze) {
        return (int)($row['mostra_dettaglio_hr'] ?? 1) === 1;
    }

    $scope = $scopeMap[$idRichiedente] ?? ['gerarchia' => false, 'gruppo' => false];

    if (!empty($scope['gerarchia'])) {
        return (int)($row['mostra_dettaglio_responsabili'] ?? 1) === 1;
    }

    if (!empty($scope['gruppo'])) {
        return (int)($row['mostra_dettaglio_colleghi'] ?? 0) === 1;
    }

    return false;
}


function hrEtichettaCalendario(array $row, array $scopeMap, int $idUtenteCorrente, bool $puoConfigurare, bool $puoVedereTipologieAssenze): string
{
    $statoPresenza = trim((string)($row['stato_presenza_breve'] ?: $row['stato_presenza'] ?: 'Assente'));
    $dettaglio = trim((string)($row['descrizione_calendario'] ?: $row['tipologia'] ?: 'Assenza'));

    if (hrMostraDettaglioCalendario($row, $scopeMap, $idUtenteCorrente, $puoConfigurare, $puoVedereTipologieAssenze)) {
        return $dettaglio !== '' ? $dettaglio : $statoPresenza;
    }

    return $statoPresenza !== '' ? $statoPresenza : 'Assente';
}
