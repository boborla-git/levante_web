<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/hr_email.php';
require_once __DIR__ . '/includes/hr_calendario.php';

richiediLogin();
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$pdo = db();
$idUtente = (int)($_SESSION['utente_id'] ?? 0);
$link = '';
$avviso = '';

try {
    // L'identita' viene esclusivamente dalla sessione. Nessun parametro
    // GET/POST puo' selezionare o cambiare il calendario di un'altra persona.
    $permessiCalendario = hrCalendarioPermessiUtente($pdo, $idUtente);
    if (!$permessiCalendario['leggere']) {
        throw new RuntimeException('Calendario non abilitato.');
    }
    $stmt = $pdo->prepare(
        "SELECT u.username, t.token, c.valore AS token_hash
         FROM aut_utenti u
         INNER JOIN hr_ics_token_utenti t ON t.id_utente = u.id_utente
         INNER JOIN hr_configurazioni c
            ON c.codice = CONCAT('HR_ICS_TOKEN_SHA256_USER_', u.id_utente)
           AND c.attivo = 1
         WHERE u.id_utente = :id_utente AND u.attivo = 1
         LIMIT 1"
    );
    $stmt->execute(['id_utente' => $idUtente]);
    $riga = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$riga || !hash_equals(strtolower(trim((string)$riga['token_hash'])), hash('sha256', (string)$riga['token']))) {
        $avviso = 'Il collegamento al calendario non è disponibile. Contatta l’amministratore del portale.';
    } else {
        $baseUrl = rtrim((string)hrEmailConfig($pdo)['base_url'], '/');
        $partiUrl = parse_url($baseUrl);
        // Usa l'indirizzo configurato del portale, mai l'Host della richiesta.
        if (!$partiUrl || strtolower((string)($partiUrl['scheme'] ?? '')) !== 'https'
            || empty($partiUrl['host']) || isset($partiUrl['query']) || isset($partiUrl['fragment'])
            || isset($partiUrl['user']) || isset($partiUrl['pass'])) {
            $avviso = 'Il collegamento al calendario non è ancora configurato. Contatta l’amministratore del portale.';
        } else {
            $link = $baseUrl . '/calendario_personale_ics.php?' . http_build_query(
                ['utente' => (string)$riga['username'], 'token' => (string)$riga['token']],
                '', '&', PHP_QUERY_RFC3986
            );
        }
    }
} catch (Throwable $e) {
    // Non mostrare errori SQL o informazioni riservate nella pagina.
    $avviso = 'Il collegamento al calendario non è disponibile. Contatta l’amministratore del portale.';
}

layoutHeader('Il mio calendario');
?>
<style>
.ics-link { width:100%; max-width:100%; box-sizing:border-box; font-size:16px; line-height:1.5; overflow-wrap:anywhere; word-break:break-word; resize:vertical; user-select:text; -webkit-user-select:text; }
.ics-actions { display:flex; flex-wrap:wrap; gap:12px; margin-top:12px; }
.ics-actions button { min-height:44px; padding:10px 16px; }
.ics-help { margin-top:16px; line-height:1.6; }
@media(max-width:600px) { .ics-actions button { width:100%; } }
</style>
<div class="card card-form">
    <div class="section-head"><div>
        <h1>Il mio calendario</h1>
        <div class="meta">Il calendario Levante anche nell’app che usi ogni giorno.</div>
    </div></div>
    <p>Questo collegamento ti permette di aggiungere il tuo calendario Levante a Outlook, Google Calendar o al calendario del telefono. Include i tuoi eventi e quelli delle persone che puoi vedere in Calendario assenze: riporti diretti, membri dei tuoi gruppi ed eventuali altre persone consentite dai tuoi permessi. Mostra gli stessi dettagli e le stesse richieste in attesa autorizzati nel portale.</p>
    <?php if ($link !== ''): ?>
        <div class="form-group">
            <label for="ics-link">Collegamento personale al calendario</label>
            <textarea class="ics-link" id="ics-link" rows="4" readonly spellcheck="false" autocapitalize="off" autocomplete="off" aria-describedby="ics-select-help"><?= htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            <div class="meta" id="ics-select-help">Puoi selezionare il testo con il mouse o tenendo premuto sul telefono, oppure usare il pulsante Copia collegamento.</div>
        </div>
        <div class="ics-actions">
            <button type="button" class="btn btn-primary" id="ics-copy"><i class="la la-copy" aria-hidden="true"></i> Copia collegamento</button>
            <button type="button" class="btn btn-light" id="ics-select">Seleziona collegamento</button>
        </div>
        <p id="ics-copy-status" role="status" aria-live="polite"></p>
    <?php else: ?>
        <div class="info-box" role="status"><?= htmlspecialchars($avviso, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <div class="ics-help">
        <p>Nel tuo calendario scegli <strong>Aggiungi calendario da Internet</strong>, <strong>Da URL</strong> o <strong>Abbonamento</strong> e incolla il collegamento. Gli aggiornamenti saranno automatici, con i tempi previsti dall’app che utilizzi. Importare un file ICS una sola volta, invece, non mantiene il calendario aggiornato.</p>
        <p>Le modifiche alle richieste si fanno in Levante. <strong>Il collegamento rimane lo stesso anche se cambi password.</strong></p>
        <p>Il collegamento è personale: chi lo possiede può vedere gli eventi e le persone inclusi nel tuo calendario Levante. Conservalo e non condividerlo con altre persone.</p>
    </div>
</div>
<script>
(function () {
    'use strict';
    var campo = document.getElementById('ics-link');
    var copia = document.getElementById('ics-copy');
    var seleziona = document.getElementById('ics-select');
    var stato = document.getElementById('ics-copy-status');
    if (!campo || !copia || !seleziona || !stato) return;
    function selezionaTesto() {
        campo.focus();
        campo.select();
        campo.setSelectionRange(0, campo.value.length);
    }
    seleziona.addEventListener('click', function () {
        selezionaTesto();
        stato.textContent = 'Collegamento selezionato. Ora puoi copiarlo.';
    });
    function copiaAlternativa() {
        selezionaTesto();
        var riuscita = false;
        try { riuscita = document.execCommand('copy'); } catch (e) {}
        stato.textContent = riuscita ? 'Collegamento copiato.'
            : 'Usa Copia dal menu del testo selezionato.';
    }
    copia.addEventListener('click', function () {
        if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(campo.value).then(function () {
                stato.textContent = 'Collegamento copiato.';
            }).catch(copiaAlternativa);
        } else {
            copiaAlternativa();
        }
    });
})();
</script>
<?php layoutFooter(); ?>

