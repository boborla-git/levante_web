<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

richiediPermessoScrittura('utenti');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: utenti.php');
    exit;
}

$sql = "UPDATE aut_utenti
        SET deve_cambiare_password = 1,
            data_aggiornamento = NOW()
        WHERE id_utente = :id_utente";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id_utente' => $id]);

header('Location: utenti.php');
exit;
