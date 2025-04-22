<?php
// index.php
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

// Tabs disponíveis
$tabs = [
    'dashboard' => 'Painel Principal',
    'oportunidades' => 'Leads',
    'calendar' => 'Calendário',
    'equipa' => 'Reunião Diária',
    'links' => 'Links'
];

$tabSelecionada = $_GET['tab'] ?? 'dashboard';
if (!array_key_exists($tabSelecionada, $tabs)) {
    $tabSelecionada = 'dashboard';
}

function tempoSessao() {
    if (!isset($_SESSION['inicio'])) {
        $_SESSION['inicio'] = time();
    }
    $duração = time() - $_SESSION['inicio'];
    return gmdate("H:i:s", $duração);
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Área Redmine</title>
    <style>
        body { font-family: Arial; margin: 0; padding: 0; }
        header, nav, main { padding: 20px; }
        header { background: #222; color: white; }
        nav { background: #f0f0f0; display: flex; gap: 10px; }
        nav a { text-decoration: none; padding: 8px 12px; background: #ddd; border-radius: 5px; }
        nav a.active { background: #aaa; color: white; }
    </style>
</head>
<body>

<header>
    <h1>Bem-vindo, <?= htmlspecialchars($_SESSION['username']) ?></h1>
    <p>ID: <?= $_SESSION['user_id'] ?> | Sessão: <?= tempoSessao() ?></p>
    <p><a href="logout.php" style="color: #ffcccc;">Sair</a></p>
</header>

<nav>
    <?php foreach ($tabs as $id => $label): ?>
        <a href="?tab=<?= $id ?>" class="<?= $tabSelecionada === $id ? 'active' : '' ?>">
            <?= htmlspecialchars($label) ?>
        </a>
    <?php endforeach; ?>
</nav>

<main>
    <?php
    $ficheiroTab = "tabs/$tabSelecionada.php";
    if (file_exists($ficheiroTab)) {
        include $ficheiroTab;
    } else {
        echo "<p>Conteúdo indisponível.</p>";
    }
    ?>
</main>

</body>
</html>
