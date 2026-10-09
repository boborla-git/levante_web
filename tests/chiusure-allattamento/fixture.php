<?php
declare(strict_types=1);
// Esegue i PHP di produzione con DB/sessione/email sostituiti. Nessun invio reale.
$case = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$root = dirname(__DIR__, 2);
require $root . '/includes/hr_regole_assenze.php';
function utenteAdminGlobale(): bool { return ($_SESSION['username'] ?? '') === 'admin'; }
function richiediPermessoLettura(string $r): void { richiediLogin(); }
function richiediLogin(): void {
    if (!empty($_SESSION['impersonazione_attiva']) && $_SERVER['REQUEST_METHOD'] === 'POST') { http_response_code(403); exit('Sola lettura'); }
}
function haPermessoLettura(string $r): bool { return $r !== 'configurazione_assenze' || hrRegoleOperatoreHr(); }
function haPermessoScrittura(string $r): bool { return true; }
function layoutHeader(...$x): void {}
function layoutFooter(...$x): void {}
function mb_strtolower(string $x): string { return strtolower($x); }
function hrEmailValida(...$x): bool { return false; }
function hrCreaNotificaWeb(...$x): void {}
function hrCreaNotificaEmailPerUtenti(...$x): void {}
function hrRiepilogoAssenzeInviaAggiornamentoSeNecessario(...$x): void {}
function hrDestinatariWebEvento(...$x): array { return []; }
function hrDestinatariPerEvento(...$x): array { return []; }
function hrBadgeAggiorna(...$x): void {}
function badgeStato(...$x): string { return ''; }
class RegolePDO extends PDO {
    public bool $tx = false;
    public array $writes = [], $queries = [], $snapshot = [];
    public bool $committed = false, $rollback = false;
    public function __construct(public array $case) {}
    public function prepare(string $q, array $options = []): PDOStatement|false { return new RegoleStmt($this, $q); }
    public function query(string $q, ?int $mode = null, mixed ...$args): PDOStatement|false { $s = $this->prepare($q); $s->execute(); return $s; }
    public function beginTransaction(): bool { $this->snapshot = $this->writes; $this->tx = true; return true; }
    public function inTransaction(): bool { return $this->tx; }
    public function commit(): bool { $this->tx = false; $this->committed = true; return true; }
    public function rollBack(): bool { $this->writes = $this->snapshot; $this->tx = false; $this->rollback = true; return true; }
    public function lastInsertId(?string $name = null): string|false { return '10'; }
}
class RegoleStmt extends PDOStatement {
    public array $rows = [];
    public function __construct(public RegolePDO $d, public string $q) {}
    public function execute(?array $p = null): bool {
        $p = $p ?? []; $s = preg_replace('/\s+/', ' ', trim($this->q)); $c = $this->d->case;
        $this->d->queries[] = ['sql' => $s, 'params' => $p, 'tx' => $this->d->tx]; $this->rows = [];
        $b = $c['benefit'] ?? ['data_inizio'=>'2099-01-01', 'data_fine'=>'2099-12-31'];
        if (str_starts_with($s, 'SELECT id_risorsa')) { $this->rows = [[1]]; }
        elseif (str_contains($s, 'FROM hr_chiusure_aziendali')) {
            foreach ($c['closures'] ?? [] as $cl) {
                $cl += ['id_chiusura'=>1, 'descrizione'=>'Chiusura di prova', 'attivo'=>1, 'richieste_presenti'=>0];
                if ($cl['attivo'] !== 1 && !str_starts_with($s, 'SELECT c.*')) continue;
                if (isset($p['fine']) && ($cl['data_da'] > $p['fine'] || $cl['data_a'] < $p['inizio'])) continue;
                if (isset($p['id']) && str_contains($s, 'id_chiusura <>') && $cl['id_chiusura'] === $p['id']) continue;
                $this->rows[] = $cl;
            }
        }
        elseif (str_starts_with($s, 'SELECT data_inizio, data_fine FROM hr_benefici')) { $this->rows = $b ? [$b] : []; }
        elseif (str_starts_with($s, 'SELECT 1 FROM hr_benefici')) {
            $valid = (bool)$b;
            if (isset($p['data_rif'])) $valid = $valid && $b['data_inizio'] <= $p['data_rif'] && (!$b['data_fine'] || $b['data_fine'] >= $p['data_rif']);
            elseif (str_contains($s, 'data_fine IS NOT NULL')) $valid = $valid && !empty($b['data_fine']);
            $this->rows = $valid ? [[1]] : [];
        }
        elseif (str_starts_with($s, 'SELECT COALESCE(SUM(CASE WHEN p.tipo_periodo')) {
            $used = 0;
            foreach ($c['records'] ?? [] as $r) {
                if (($r['id_utente'] ?? 1) !== $p['utente'] || ($r['id_richiesta'] ?? 99) === $p['escludi']
                    || !in_array($r['stato'] ?? 'APPROVATA', ['APPROVATA','IN_ATTESA'], true)
                    || ($r['codice'] ?? 'ALLATTAMENTO') !== 'ALLATTAMENTO'
                    || $r['data_da'] > $p['giorno_fine'] || $r['data_a'] < $p['giorno_inizio']) continue;
                $used += ($r['tipo_periodo'] ?? 'ORE') === 'ORE' ? $r['minuti'] : 120;
            }
            $this->rows = [[$used]];
        }
        elseif (str_starts_with($s, 'SELECT r.id_utente_richiedente, te.codice')) { $this->rows = [['id_utente_richiedente'=>1, 'codice'=>$c['existing_code'] ?? 'ALLATTAMENTO']]; }
        elseif (str_starts_with($s, 'SELECT data_da, data_a, tipo_periodo')) { $this->rows = $c['periods'] ?? []; }
        elseif (str_starts_with($s, 'SELECT r.id_tipologia_evento')) { $this->rows = [['id_tipologia_evento'=>9, 'codice'=>'ALTRO', 'descrizione'=>'Altro']]; }
        elseif (str_starts_with($s, 'SELECT id_tipologia_evento, codice, descrizione') && str_contains($s, 'WHERE id_tipologia_evento')) { $this->rows = [['id_tipologia_evento'=>2, 'codice'=>'ALLATTAMENTO', 'descrizione'=>'Allattamento']]; }
        elseif (str_contains($s, 'a.id_richiesta_approvazione') && str_contains($s, 'FOR UPDATE')) {
            $this->rows = [['id_richiesta'=>10,'id_utente_richiedente'=>1,'id_richiesta_approvazione'=>10,'id_approvatore_assegnato'=>1,'tipologia'=>'Allattamento','richiedente_nome'=>'Persona Demo']];
        }
        elseif (str_contains($s, 'FROM hr_tipologie_evento') && str_contains($s, 'ORDER BY ordinamento')) {
            foreach (['FERIE','ALLATTAMENTO','MALATTIA','VISITA_CLIENTE','CONGEDO_STRAORDINARIO_DISABILI','ALTRO'] as $i => $code) {
                $this->rows[] = ['id_tipologia_evento'=>$i+1,'codice'=>$code,'descrizione'=>$code,'richiede_approvazione'=>0,'approvazione_obbligatoria'=>0,'consente_giorni'=>$code === 'ALLATTAMENTO' ? 0 : 1,'consente_ore'=>1];
            }
        }
        elseif (str_starts_with($s, 'SELECT u.id_utente,')) { $this->rows = [['id_utente'=>1,'nome'=>'Persona','cognome'=>'Demo','nominativo'=>'Persona Demo']]; }
        elseif (str_starts_with($s, 'SELECT id_stato_richiesta')) { $this->rows = [[1]]; }
        elseif (str_contains($s, 'SELECT COUNT(*)') && str_contains($s, 'FROM aut_utenti')) { $this->rows = [[1]]; }
        elseif (preg_match('/^(INSERT|UPDATE|DELETE) /', $s)) {
            if (!$this->d->tx) throw new RuntimeException('Write outside transaction: '.$s);
            $this->d->writes[] = ['sql'=>$s,'params'=>$p];
        }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->rows ? array_values($this->rows[0])[$column] : false; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function rowCount(): int { return 1; }
}
$pdo = new RegolePDO($case);
function db(): PDO { global $pdo; return $pdo; }
$_SESSION = ['id_utente'=>1,'utente_id'=>1,'username'=>'Demo','ruoli'=>[], 'hr_regole_csrf'=>'fixture-token'];
if (($case['role'] ?? '') === 'hr') $_SESSION['ruoli'] = ['hr_responsabile_personale'];
if (($case['role'] ?? '') === 'admin') $_SESSION['username'] = 'admin';
if (!empty($case['impersonate'])) $_SESSION['impersonazione_attiva'] = true;
$_SERVER['REQUEST_METHOD'] = $case['method'] ?? 'GET';
$_SERVER['PHP_SELF'] = $case['page'] ?? 'assenze.php';
$_GET = [];
$_POST = ['csrf_token'=>'fixture-token'] + ($case['post'] ?? []);
if (!empty($case['invalid_csrf'])) $_POST['csrf_token'] = 'bad';
$errore = ''; $caught = '';
ob_start();
register_shutdown_function(function() use ($pdo, &$errore, &$caught) {
    $html = ob_get_clean();
    echo json_encode(['error'=>$errore ?: $caught,'fatal'=>error_get_last(), 'status'=>http_response_code(),
        'writes'=>$pdo->writes,'queries'=>$pdo->queries,'committed'=>$pdo->committed,'rollback'=>$pdo->rollback,'html'=>$html], JSON_THROW_ON_ERROR);
});
try {
    if (($case['mode'] ?? '') === 'helper') {
        $pdo->beginTransaction(); hrRegoleBloccaScrittura($pdo);
        hrRegoleVerificaRichiesta($pdo, 1, $case['code'] ?? 'ALLATTAMENTO', $case['periods'] ?? [], $case['exclude'] ?? 0);
        $pdo->commit();
    } else {
        $source = file_get_contents($root . '/' . ($case['page'] ?? 'assenze.php'));
        $source = preg_replace('/^require_once .*;\s*$/m', '', $source);
        $source = str_replace('declare(strict_types=1);', '', $source);
        eval('?>' . $source);
    }
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $caught = $e->getMessage(); }
