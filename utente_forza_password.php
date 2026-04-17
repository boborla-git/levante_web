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

$sql = "UPDATE utenti
        SET deve_cambiare_password = 1
        WHERE id = :id";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $id]);

header('Location: utenti.php');
exit;