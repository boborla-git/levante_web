<?php
declare(strict_types=1);

function hrAnagraficaConfig(string $tipo): array
{
    $tipi = [
        'reparti' => ['tabella' => 'hr_reparti', 'id' => 'id_reparto', 'titolo' => 'Reparti', 'singolare' => 'reparto', 'pagina' => 'reparti.php'],
        'centri_costo' => ['tabella' => 'hr_centri_costo', 'id' => 'id_centro_costo', 'titolo' => 'Centri di costo', 'singolare' => 'centro di costo', 'pagina' => 'centri_costo.php'],
    ];
    if (!isset($tipi[$tipo])) throw new InvalidArgumentException('Anagrafica non valida.');
    return $tipi[$tipo];
}

function hrAnagraficaLimiti(PDO $pdo, string $tipo): array
{
    $config = hrAnagraficaConfig($tipo);
    $stmt = $pdo->prepare("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = :tabella AND COLUMN_NAME IN ('codice','nome')");
    $stmt->execute(['tabella' => $config['tabella']]);
    $limiti = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $riga) {
        $limiti[(string)$riga['COLUMN_NAME']] = (int)$riga['CHARACTER_MAXIMUM_LENGTH'];
    }
    if (($limiti['codice'] ?? 0) < 1 || ($limiti['nome'] ?? 0) < 1) {
        throw new RuntimeException('Anagrafica non disponibile: verificare la configurazione del portale.');
    }
    return $limiti;
}

function hrAnagraficaValida(array $dati, array $limiti): array
{
    $codice = strtoupper(trim((string)($dati['codice'] ?? '')));
    $nome = trim((string)($dati['nome'] ?? ''));
    if ($codice === '' || $nome === '') throw new RuntimeException('Inserisci sia il codice sia il nome.');
    if (preg_match('/^[A-Z0-9][A-Z0-9_.-]*$/D', $codice) !== 1) {
        throw new RuntimeException('Il codice può contenere lettere, numeri, trattini, punti e underscore; deve iniziare con una lettera o un numero.');
    }
    $lunghezzaNome = preg_match_all('/./us', $nome);
    if ($lunghezzaNome === false) throw new RuntimeException('Il nome contiene caratteri non validi.');
    if (strlen($codice) > $limiti['codice'] || $lunghezzaNome > $limiti['nome']) {
        throw new RuntimeException('Il codice può avere al massimo ' . $limiti['codice'] . ' caratteri e il nome ' . $limiti['nome'] . '.');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $nome) === 1) throw new RuntimeException('Il nome deve essere scritto su una sola riga.');
    return ['codice' => $codice, 'nome' => $nome];
}

function hrAnagraficaCrea(PDO $pdo, string $tipo, array $dati): int
{
    $config = hrAnagraficaConfig($tipo);
    $dati = hrAnagraficaValida($dati, hrAnagraficaLimiti($pdo, $tipo));
    if ($pdo->inTransaction()) throw new RuntimeException('Salvataggio già in corso. Riprova.');
    $tabella = $config['tabella'];
    $id = $config['id'];
    // Serializza la creazione per impedire duplicati anche se la tabella storica
    // non possiede un vincolo univoco. Nessuna modifica allo schema.
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $nomeLock = 'hr-anagrafiche-' . sha1($db . '.' . $tabella);
    $stmtLock = $pdo->prepare('SELECT GET_LOCK(:nome_lock, 5)');
    $stmtLock->execute(['nome_lock' => $nomeLock]);
    if ((int)$stmtLock->fetchColumn() !== 1) throw new RuntimeException('Un altro salvataggio è in corso. Riprova tra qualche secondo.');
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT {$id} FROM {$tabella} WHERE UPPER(TRIM(codice)) = :codice LIMIT 1 FOR UPDATE");
        $stmt->execute(['codice' => $dati['codice']]);
        if ($stmt->fetchColumn() !== false) throw new RuntimeException('Questo codice esiste già, anche se la voce non è attiva. Usa un codice diverso.');
        $ordinamento = (int)$pdo->query("SELECT COALESCE(MAX(ordinamento),0) FROM {$tabella}")->fetchColumn();
        $ordinamento = $ordinamento <= 2147483637 ? $ordinamento + 10 : $ordinamento;
        $stmt = $pdo->prepare("INSERT INTO {$tabella} (codice,nome,ordinamento,attivo) VALUES (:codice,:nome,:ordinamento,1)");
        $stmt->execute(['codice' => $dati['codice'], 'nome' => $dati['nome'], 'ordinamento' => $ordinamento]);
        $nuovoId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $nuovoId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        try {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:nome_lock)');
            $stmt->execute(['nome_lock' => $nomeLock]);
        } catch (Throwable $e) {
            error_log('HR anagrafiche, rilascio lock: ' . $e->getMessage());
        }
    }
}

