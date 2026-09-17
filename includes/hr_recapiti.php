<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hr_email.php';

function hrRecapitiTipiConsentiti(): array
{
    return ['EMAIL_PERSONALE', 'EMAIL_LAVORO', 'CELLULARE_PERSONALE'];
}

function hrRecapitiValida(string $tipo, string $valore): bool
{
    $valore = trim($valore);
    if ($valore === '') return true;
    if (strpos($tipo, 'EMAIL_') === 0) return filter_var($valore, FILTER_VALIDATE_EMAIL) !== false;
    if (strpos($tipo, 'CELLULARE_') === 0) return preg_match('/^[0-9 +().\-]{6,30}$/', $valore) === 1;
    return false;
}

function hrRecapitiTipoId(PDO $pdo, string $codice): int
{
    $stmt = $pdo->prepare('SELECT id_tipo_recapito FROM hr_tipi_recapito WHERE codice = :codice AND attivo = 1 LIMIT 1');
    $stmt->execute(['codice' => $codice]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function hrRecapitiUtente(PDO $pdo, int $idUtente): array
{
    $out = [];
    $stmt = $pdo->prepare(
        "SELECT ru.id_recapito_utente, tr.codice, tr.descrizione, ru.valore, ru.verificato, ru.attivo
         FROM hr_recapiti_utenti ru
         INNER JOIN hr_tipi_recapito tr ON tr.id_tipo_recapito = ru.id_tipo_recapito
         WHERE ru.id_utente = :id_utente
           AND tr.codice IN ('EMAIL_PERSONALE','EMAIL_LAVORO','CELLULARE_PERSONALE')
         ORDER BY ru.attivo DESC, ru.id_recapito_utente DESC"
    );
    $stmt->execute(['id_utente' => $idUtente]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $codice = (string)$r['codice'];
        if (!isset($out[$codice])) $out[$codice] = $r;
    }
    return $out;
}

function hrRecapitiSalva(PDO $pdo, int $idUtente, string $codiceTipo, string $valore, int $idOperatore, bool $confermaImplicita = false): array
{
    $codiceTipo = strtoupper(trim($codiceTipo));
    $valore = trim($valore);
    if (!in_array($codiceTipo, hrRecapitiTipiConsentiti(), true)) {
        throw new RuntimeException('Tipo recapito non consentito.');
    }
    if (!hrRecapitiValida($codiceTipo, $valore)) {
        throw new RuntimeException(strpos($codiceTipo, 'EMAIL_') === 0 ? 'Indirizzo email non valido.' : 'Numero di cellulare non valido.');
    }

    $idTipo = hrRecapitiTipoId($pdo, $codiceTipo);
    if ($idTipo <= 0) throw new RuntimeException('Tipo recapito non configurato. Eseguire prima lo script SQL di aggiornamento.');

    $stmt = $pdo->prepare(
        'SELECT id_recapito_utente, valore, verificato FROM hr_recapiti_utenti WHERE id_utente = :id_utente AND id_tipo_recapito = :id_tipo ORDER BY attivo DESC, id_recapito_utente DESC LIMIT 1'
    );
    $stmt->execute(['id_utente' => $idUtente, 'id_tipo' => $idTipo]);
    $esistente = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($valore === '') {
        if ($esistente) {
            $upd = $pdo->prepare('UPDATE hr_recapiti_utenti SET attivo = 0, principale = 0, verificato = 0 WHERE id_recapito_utente = :id');
            $upd->execute(['id' => (int)$esistente['id_recapito_utente']]);
        }
        return ['modificato' => (bool)$esistente, 'richiede_verifica' => false, 'id_recapito' => 0];
    }

    $email = strpos($codiceTipo, 'EMAIL_') === 0;
    $stessoValore = $esistente && strcasecmp(trim((string)$esistente['valore']), $valore) === 0;
    if ($email) {
        $verificato = $confermaImplicita ? 1 : (($stessoValore && (int)($esistente['verificato'] ?? 0) === 1) ? 1 : 0);
    } else {
        $verificato = 0;
    }

    $notaOperatore = $confermaImplicita
        ? 'Aggiornato da HR/amministrazione - operatore #' . $idOperatore . ' - conferma implicita'
        : 'Aggiornato dal portale - operatore #' . $idOperatore;

    if ($esistente) {
        $idRecapito = (int)$esistente['id_recapito_utente'];
        $upd = $pdo->prepare(
            'UPDATE hr_recapiti_utenti SET valore = :valore, principale = 1, verificato = :verificato, attivo = 1, note = :note WHERE id_recapito_utente = :id'
        );
        $upd->execute([
            'valore' => $valore,
            'verificato' => $verificato,
            'note' => $notaOperatore,
            'id' => $idRecapito,
        ]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO hr_recapiti_utenti (id_utente,id_tipo_recapito,valore,principale,verificato,attivo,note) VALUES (:id_utente,:id_tipo,:valore,1,:verificato,1,:note)'
        );
        $ins->execute([
            'id_utente' => $idUtente,
            'id_tipo' => $idTipo,
            'valore' => $valore,
            'verificato' => $verificato,
            'note' => $notaOperatore,
        ]);
        $idRecapito = (int)$pdo->lastInsertId();
    }

    return [
        'modificato' => !$stessoValore,
        'richiede_verifica' => $email && !$confermaImplicita && (!$stessoValore || $verificato !== 1),
        'id_recapito' => $idRecapito,
    ];
}

function hrRecapitiCreaTokenVerifica(PDO $pdo, int $idRecapito, int $idUtente): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $pdo->prepare('UPDATE hr_recapiti_verifiche SET utilizzato_il = NOW() WHERE id_recapito_utente = :id AND utilizzato_il IS NULL')
        ->execute(['id' => $idRecapito]);
    $stmt = $pdo->prepare(
        'INSERT INTO hr_recapiti_verifiche (id_recapito_utente,id_utente,token_hash,scade_il,data_creazione) VALUES (:id_recapito,:id_utente,:hash,DATE_ADD(NOW(), INTERVAL 24 HOUR),NOW())'
    );
    $stmt->execute(['id_recapito' => $idRecapito, 'id_utente' => $idUtente, 'hash' => $hash]);
    return $token;
}

function hrRecapitiInviaVerifica(PDO $pdo, int $idUtente, string $email, string $token): array
{
    $config = hrEmailConfig($pdo);
    if (!$config['attiva']) return ['inviata' => false, 'motivo' => 'Invio email HR disattivato da configurazione.'];
    $to = hrEmailValida($email);
    $from = hrEmailValida((string)$config['from_email']);
    if ($to === null || $from === null) return ['inviata' => false, 'motivo' => 'Configurazione email non valida.'];

    $link = hrUrlAssoluto($pdo, '/verifica_recapito.php?token=' . rawurlencode($token));
    $nome = hrEmailNomeUtente($pdo, $idUtente);
    $saluto = $nome !== '' ? 'Buongiorno <strong>' . hrEmailH($nome) . '</strong>,' : 'Buongiorno,';
    $html = '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#0f172a">'
        . '<h2>Portale HR Ravioli S.p.A.</h2><p>' . $saluto . '</p>'
        . '<p>È stato inserito o modificato questo indirizzo email nel portale HR. Per confermarlo, usa il pulsante seguente entro 24 ore.</p>'
        . '<p><a href="' . hrEmailH($link) . '" style="display:inline-block;background:#005bd3;color:#fff;text-decoration:none;padding:10px 16px;border-radius:4px;font-weight:700">Conferma indirizzo email</a></p>'
        . '<p style="color:#64748b;font-size:12px">Se non hai richiesto questa modifica, contatta HR.</p>'
        . '</body></html>';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . hrEmailEncodeHeader((string)$config['from_name']) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: Ravioli Portale HR',
    ];
    $ok = @mail($to, hrEmailEncodeHeader('Verifica indirizzo email - Portale HR Ravioli'), $html, implode("\r\n", $headers), '-f' . $from);
    return ['inviata' => (bool)$ok, 'motivo' => $ok ? null : 'Invio email non riuscito.'];
}
