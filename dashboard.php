<?php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}
?>

<h1>Olá, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
<p>Este é o painel depois do login via Redmine.</p>
<a href="logout.php">Sair</a>