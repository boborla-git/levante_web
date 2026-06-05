<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('hr_comunicazioni');

$pdo = db();
$idUtenteLoggato = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$puoScrivere = haPermessoScrittura('hr_comunicazioni');
$messaggio = '';
$errore = '';
$comunicazioni = [];
$riepilogo = [
    'totali' => 0,
    'comunicazioni' => 0,
    'moduli' => 0,
    'avvisi' => 0,
];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrComunicazioniTipoLabel(string $tipo): string
{
    $tipo = strtoupper(trim($tipo));
    if ($tipo === 'MODULO') {
        return 'Modulo';
    }
    if ($tipo === 'AVVISO') {
        return 'Avviso';
    }
    return 'Comunicazione';
}

function hrComunicazioniTipoClass(string $tipo): string
{
    $tipo = strtoupper(trim($tipo));
    if ($tipo === 'MODULO') {
        return 'status-wait';
    }
    if ($tipo === 'AVVISO') {
        return 'status-ko';
    }
    return 'status-ok';
}

function hrComunicazioniUrlValido(string $url): bool
{
    if ($url === '') {
        return true;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false || preg_match('/^\/[A-Za-z0-9_\-\/\.]+$/', $url) === 1;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'salva_comunicazione') {
            if (!$puoScrivere) {
                throw new RuntimeException('Non hai i permessi di modifica.');
            }

            $tipo = strtoupper(trim((string)($_POST['tipo'] ?? 'COMUNICAZIONE')));
            if (!in_array($tipo, ['COMUNICAZIONE', 'MODULO', 'AVVISO'], true)) {
                $tipo = 'COMUNICAZIONE';
            }

            $titolo = trim((string)($_POST['titolo'] ?? ''));
            $testo = trim((string)($_POST['testo'] ?? ''));
            $nomeDocumento = trim((string)($_POST['nome_documento'] ?? ''));
            $urlDocumento = trim((string)($_POST['url_documento'] ?? ''));
            $dataPubblicazione = trim((string)($_POST['data_pubblicazione'] ?? ''));
            $dataScadenza = trim((string)($_POST['data_scadenza'] ?? ''));
            $visibile = isset($_POST['visibile']) ? 1 : 0;
            $richiedePresaVisione = isset($_POST['richiede_presa_visione']) ? 1 : 0;

            if ($titolo === '') {
                throw new RuntimeException('Il titolo è obbligatorio.');
            }
            if ($testo === '') {
                throw new RuntimeException('Il testo è obbligatorio.');
            }
            if ($dataPubblicazione === '') {
                $dataPubblicazione = date('Y-m-d');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPubblicazione)) {
                throw new RuntimeException('Data pubblicazione non valida.');
            }
            if ($dataScadenza !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataScadenza)) {
                throw new RuntimeException('Data scadenza non valida.');
            }
            if ($dataScadenza !== '' && $dataScadenza < $dataPubblicazione) {
                throw new RuntimeException('La data scadenza non può precedere la data pubblicazione.');
            }
            if (!hrComunicazioniUrlValido($urlDocumento)) {
                throw new RuntimeException('Il link documento non è valido.');
            }
            if ($urlDocumento !== '' && $nomeDocumento === '') {
                $nomeDocumento = 'Apri documento';
            }

            $stmt = $pdo->prepare(
                'INSERT INTO hr_comunicazioni
                 (tipo, titolo, testo, nome_documento, url_documento, data_pubblicazione, data_scadenza, visibile, richiede_presa_visione, creato_da, data_creazione, data_aggiornamento)
                 VALUES
                 (:tipo, :titolo, :testo, :nome_documento, :url_documento, :data_pubblicazione, :data_scadenza, :visibile, :richiede_presa_visione, :creato_da, NOW(), NOW())'
            );
            $stmt->execute([
                'tipo' => $tipo,
                'titolo' => $titolo,
                'testo' => $testo,
                'nome_documento' => $nomeDocumento !== '' ? $nomeDocumento : null,
                'url_documento' => $urlDocumento !== '' ? $urlDocumento : null,
                'data_pubblicazione' => $dataPubblicazione,
                'data_scadenza' => $dataScadenza !== '' ? $dataScadenza : null,
                'visibile' => $visibile,
                'richiede_presa_visione' => $richiedePresaVisione,
                'creato_da' => $idUtenteLoggato > 0 ? $idUtenteLoggato : null,
            ]);

            header('Location: hr_comunicazioni.php?ok=1');
            exit;
        }

        if ($azione === 'presa_visione') {
            $idComunicazione = (int)($_POST['id_comunicazione'] ?? 0);
            if ($idComunicazione <= 0) {
                throw new RuntimeException('Comunicazione non valida.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO hr_comunicazioni_letture (id_comunicazione, id_utente, data_lettura)
                 VALUES (:id_comunicazione, :id_utente, NOW())
                 ON DUPLICATE KEY UPDATE data_lettura = VALUES(data_lettura)'
            );
            $stmt->execute([
                'id_comunicazione' => $idComunicazione,
                'id_utente' => $idUtenteLoggato,
            ]);

            header('Location: hr_comunicazioni.php?presa_visione=1');
            exit;
        }
    }

    if (isset($_GET['ok'])) {
        $messaggio = 'Comunicazione pubblicata correttamente.';
    } elseif (isset($_GET['presa_visione'])) {
        $messaggio = 'Presa visione registrata correttamente.';
    }

    $where = $puoScrivere
        ? '1 = 1'
        : "c.visibile = 1 AND c.data_pubblicazione <= CURDATE() AND (c.data_scadenza IS NULL OR c.data_scadenza >= CURDATE())";

    $stmt = $pdo->query(
        "SELECT c.*,
                CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,'')) AS autore_nome,
                u.username AS autore_username,
                l.data_lettura
         FROM hr_comunicazioni c
         LEFT JOIN aut_utenti u ON u.id_utente = c.creato_da
         LEFT JOIN hr_comunicazioni_letture l ON l.id_comunicazione = c.id_comunicazione AND l.id_utente = " . (int)$idUtenteLoggato . "
         WHERE {$where}
         ORDER BY c.data_pubblicazione DESC, c.data_creazione DESC, c.id_comunicazione DESC"
    );
    $comunicazioni = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($comunicazioni as $comunicazione) {
        $riepilogo['totali']++;
        $tipo = strtoupper((string)($comunicazione['tipo'] ?? ''));
        if ($tipo === 'MODULO') {
            $riepilogo['moduli']++;
        } elseif ($tipo === 'AVVISO') {
            $riepilogo['avvisi']++;
        } else {
            $riepilogo['comunicazioni']++;
        }
    }
} catch (Throwable $e) {
    $errore = $e->getMessage();
}

layoutHeader('HR Comunicazioni');
?>

<link rel="stylesheet" href="/assets/hr.css">

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>HR Comunicazioni</h1>
            <div class="meta">Comunicazioni aziendali, modulistica HR e avvisi pubblicati dall'ufficio del personale.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="cambio_iban.php"><i class="la la-university" aria-hidden="true"></i> Cambio IBAN</a>
            <a class="btn btn-light" href="assenze.php"><i class="la la-calendar" aria-hidden="true"></i> Assenze</a>
        </div>
    </div>
</div>

<section class="hr-profile-summary">
    <span><strong><?= (int)$riepilogo['totali'] ?></strong> elementi</span>
    <span><strong><?= (int)$riepilogo['comunicazioni'] ?></strong> comunicazioni</span>
    <span><strong><?= (int)$riepilogo['moduli'] ?></strong> moduli</span>
    <span><strong><?= (int)$riepilogo['avvisi'] ?></strong> avvisi</span>
</section>

<?php if ($messaggio !== ''): ?><div class="alert alert-success"><?= h($messaggio) ?></div><?php endif; ?>
<?php if ($errore !== ''): ?><div class="alert alert-error"><?= h($errore) ?></div><?php endif; ?>

<?php if ($puoScrivere): ?>
    <details class="card card-compact" open>
        <summary><strong>Nuova comunicazione / modulo</strong></summary>
        <form method="post" class="hr-profile-form" style="margin-top:14px;">
            <input type="hidden" name="azione" value="salva_comunicazione">
            <div class="hr-profile-form-grid">
                <div class="form-group">
                    <label for="tipo">Tipo</label>
                    <select id="tipo" name="tipo">
                        <option value="COMUNICAZIONE">Comunicazione</option>
                        <option value="MODULO">Modulo</option>
                        <option value="AVVISO">Avviso</option>
                    </select>
                </div>
                <div class="form-group hr-profile-form-wide">
                    <label for="titolo">Titolo</label>
                    <input type="text" id="titolo" name="titolo" maxlength="180" required>
                </div>
                <div class="form-group">
                    <label for="data_pubblicazione">Pubblica dal</label>
                    <input type="date" id="data_pubblicazione" name="data_pubblicazione" value="<?= h(date('Y-m-d')) ?>">
                </div>
                <div class="form-group">
                    <label for="data_scadenza">Scadenza</label>
                    <input type="date" id="data_scadenza" name="data_scadenza">
                </div>
                <div class="form-group">
                    <label for="nome_documento">Nome documento</label>
                    <input type="text" id="nome_documento" name="nome_documento" maxlength="180" placeholder="es. Modulo cambio IBAN">
                </div>
                <div class="form-group hr-profile-form-wide">
                    <label for="url_documento">Link documento</label>
                    <input type="text" id="url_documento" name="url_documento" maxlength="255" placeholder="https://... oppure /percorso/file.pdf">
                </div>
            </div>
            <div class="form-group">
                <label for="testo">Testo</label>
                <textarea id="testo" name="testo" rows="5" required></textarea>
            </div>
            <div class="hr-profile-form-actions">
                <label class="meta"><input type="checkbox" name="visibile" value="1" checked> visibile</label>
                <label class="meta"><input type="checkbox" name="richiede_presa_visione" value="1"> richiede presa visione</label>
                <button type="submit" class="btn btn-primary"><i class="la la-save" aria-hidden="true"></i> Pubblica</button>
            </div>
        </form>
    </details>
<?php endif; ?>

<div class="card card-wide">
    <div class="section-head">
        <div>
            <h2>Comunicazioni e modulistica</h2>
            <div class="meta">Elenco degli elementi HR disponibili in base alla pubblicazione e ai permessi.</div>
        </div>
    </div>

    <?php if (!$comunicazioni): ?>
        <div class="info-box">Non sono presenti comunicazioni HR disponibili.</div>
    <?php else: ?>
        <div class="modules">
            <?php foreach ($comunicazioni as $comunicazione): ?>
                <?php
                $tipo = (string)($comunicazione['tipo'] ?? 'COMUNICAZIONE');
                $autore = trim((string)($comunicazione['autore_nome'] ?? ''));
                if ($autore === '') {
                    $autore = trim((string)($comunicazione['autore_username'] ?? ''));
                }
                $richiedePresaVisione = (int)($comunicazione['richiede_presa_visione'] ?? 0) === 1;
                $presaVisione = trim((string)($comunicazione['data_lettura'] ?? '')) !== '';
                ?>
                <article class="module-box" style="cursor:default;">
                    <div class="module-kicker">
                        <span class="status-badge <?= h(hrComunicazioniTipoClass($tipo)) ?>"><?= h(hrComunicazioniTipoLabel($tipo)) ?></span>
                        <?php if ((int)$comunicazione['visibile'] !== 1): ?>
                            <span class="status-badge status-neutral">Bozza/non visibile</span>
                        <?php endif; ?>
                    </div>
                    <h3><?= h((string)$comunicazione['titolo']) ?></h3>
                    <p><?= nl2br(h((string)$comunicazione['testo'])) ?></p>
                    <div class="meta">
                        Pubblicata dal <?= h((string)$comunicazione['data_pubblicazione']) ?>
                        <?php if ((string)($comunicazione['data_scadenza'] ?? '') !== ''): ?> · scade il <?= h((string)$comunicazione['data_scadenza']) ?><?php endif; ?>
                        <?php if ($autore !== ''): ?> · <?= h($autore) ?><?php endif; ?>
                    </div>
                    <?php if ((string)($comunicazione['url_documento'] ?? '') !== ''): ?>
                        <p><a class="btn btn-light" href="<?= h((string)$comunicazione['url_documento']) ?>" target="_blank" rel="noopener"><i class="la la-file-download" aria-hidden="true"></i> <?= h((string)($comunicazione['nome_documento'] ?: 'Apri documento')) ?></a></p>
                    <?php endif; ?>
                    <?php if ($richiedePresaVisione): ?>
                        <?php if ($presaVisione): ?>
                            <div class="alert alert-success">Presa visione registrata.</div>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="azione" value="presa_visione">
                                <input type="hidden" name="id_comunicazione" value="<?= (int)$comunicazione['id_comunicazione'] ?>">
                                <button type="submit" class="btn btn-primary"><i class="la la-check" aria-hidden="true"></i> Conferma presa visione</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php layoutFooter(); ?>
