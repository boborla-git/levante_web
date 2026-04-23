<?php
declare(strict_types=1);

function hrScopeNomeUtente(array $row): string
{
    $nome = trim((string)($row['nome'] ?? ''));
    $cognome = trim((string)($row['cognome'] ?? ''));
    $username = trim((string)($row['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);
    if ($nominativo !== '') {
        return $nominativo;
    }
    if ($username !== '') {
        return $username;
    }
    return 'Utente #' . (int)($row['id_utente'] ?? 0);
}

function hrScopeUtenteAttivo(PDO $pdo, int $idUtente): ?array
{
    if ($idUtente <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id_utente, username, nome, cognome
         FROM aut_utenti
         WHERE id_utente = :id_utente
           AND attivo = 1
         LIMIT 1"
    );
    $stmt->execute(['id_utente' => $idUtente]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function hrScopeCodiciGerarchici(): array
{
    return ['RESPONSABILE_DIRETTO', 'RESPONSABILE_FUNZIONALE'];
}

function hrScopeDirectReportIds(PDO $pdo, int $idUtente): array
{
    if ($idUtente <= 0) {
        return [];
    }

    $quoted = [];
    foreach (hrScopeCodiciGerarchici() as $codice) {
        $quoted[] = $pdo->quote($codice);
    }
    $lista = implode(',', $quoted);

    $stmt = $pdo->prepare(
        "SELECT DISTINCT ro.id_utente
         FROM hr_relazioni_organizzative ro
         INNER JOIN hr_tipi_relazione_organizzativa tro
            ON tro.id_tipo_relazione = ro.id_tipo_relazione
           AND tro.attivo = 1
           AND tro.codice IN ($lista)
         INNER JOIN aut_utenti u
            ON u.id_utente = ro.id_utente
           AND u.attivo = 1
         WHERE ro.id_utente_collegato = :id_utente
           AND ro.attiva = 1
           AND ro.data_inizio <= CURDATE()
           AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())"
    );
    $stmt->execute(['id_utente' => $idUtente]);

    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function hrScopeCollaboratorIds(PDO $pdo, int $idUtente): array
{
    if ($idUtente <= 0) {
        return [];
    }

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

    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function hrScopeUtentiByIds(PDO $pdo, array $ids): array
{
    $clean = [];
    foreach ($ids as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $clean[$id] = $id;
        }
    }
    $ids = array_values($clean);

    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id_utente, username, nome, cognome
         FROM aut_utenti
         WHERE attivo = 1
           AND id_utente IN ($placeholders)
         ORDER BY nome, cognome, username"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    usort($rows, function (array $a, array $b): int {
        return strcmp(hrScopeNomeUtente($a), hrScopeNomeUtente($b));
    });

    return $rows;
}

function hrScopeUtentiGestionali(PDO $pdo, int $idUtente, bool $puoConfigurare, bool $includeSelf = true): array
{
    if ($puoConfigurare) {
        $stmt = $pdo->query(
            "SELECT id_utente, username, nome, cognome
             FROM aut_utenti
             WHERE attivo = 1
             ORDER BY nome, cognome, username"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        usort($rows, function (array $a, array $b): int {
            return strcmp(hrScopeNomeUtente($a), hrScopeNomeUtente($b));
        });
        return $rows;
    }

    $ids = hrScopeDirectReportIds($pdo, $idUtente);
    if ($includeSelf) {
        array_unshift($ids, $idUtente);
    }

    return hrScopeUtentiByIds($pdo, $ids);
}

function hrScopeUtentiInformativi(PDO $pdo, int $idUtente, bool $puoConfigurare, bool $includeSelf = true): array
{
    $rows = hrScopeUtentiInformativiDettaglio($pdo, $idUtente, $puoConfigurare, $includeSelf);
    $plain = [];
    foreach ($rows as $row) {
        $copy = $row;
        unset($copy['scope_sources'], $copy['scope_badges']);
        $plain[] = $copy;
    }
    return $plain;
}

function hrScopeUtentiInformativiDettaglio(PDO $pdo, int $idUtente, bool $puoConfigurare, bool $includeSelf = true): array
{
    if ($puoConfigurare) {
        $rows = hrScopeUtentiGestionali($pdo, $idUtente, true, true);
        foreach ($rows as &$row) {
            $row['scope_sources'] = ['all'];
            $row['scope_badges'] = [['type' => 'all', 'label' => 'tutto']];
        }
        unset($row);
        return $rows;
    }

    $sources = [];

    if ($includeSelf && $idUtente > 0) {
        $sources[$idUtente]['self'] = true;
    }

    foreach (hrScopeDirectReportIds($pdo, $idUtente) as $directId) {
        $sources[$directId]['hierarchy'] = true;
    }

    foreach (hrScopeCollaboratorIds($pdo, $idUtente) as $groupId) {
        $sources[$groupId]['group'] = true;
    }

    $rows = hrScopeUtentiByIds($pdo, array_keys($sources));
    foreach ($rows as &$row) {
        $uid = (int)$row['id_utente'];
        $flags = $sources[$uid] ?? [];
        $row['scope_sources'] = array_keys($flags);
        $badges = [];
        if (isset($flags['self'])) {
            $badges[] = ['type' => 'self', 'label' => 'tu'];
        }
        if (isset($flags['hierarchy'])) {
            $badges[] = ['type' => 'hierarchy', 'label' => 'gerarchia'];
        }
        if (isset($flags['group'])) {
            $badges[] = ['type' => 'group', 'label' => 'gruppo'];
        }
        $row['scope_badges'] = $badges;
    }
    unset($row);

    return $rows;
}
