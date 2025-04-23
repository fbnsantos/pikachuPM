<?php
ob_start();
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

// Verificar se alternância automática está ativada
$autoAlternar = isset($_COOKIE['auto_alternar']) ? $_COOKIE['auto_alternar'] === 'true' : false;

function tempoSessao() {
    if (!isset($_SESSION['inicio'])) {
        $_SESSION['inicio'] = time();
    }
    $duração = time() - $_SESSION['inicio'];
    return gmdate("H:i:s", $duração);
}

// Determinar o tempo de refresh com base na aba
$refreshTime = 0; // 0 = sem refresh
if ($tabSelecionada === 'calendar') {
    $refreshTime = 10; // 60 segundos para calendário
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Área Redmine<?= $tabSelecionada ? ' - ' . $tabs[$tabSelecionada] : '' ?></title>
    <?php if ($refreshTime > 0): ?>
    <meta http-equiv="refresh" content="<?= $refreshTime ?>">
    <?php endif; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 0; 
            padding: 0; 
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        header, nav, main { padding: 20px; }
        header { 
            background: #222; 
            color: white; 
            padding: 15px 20px;
        }
        nav { 
            background: #f0f0f0; 
            display: flex; 
            flex-wrap: wrap;
            gap: 10px; 
            padding: 10px 20px;
            border-bottom: 1px solid #ddd;
        }
        nav a { 
            text-decoration: none; 
            padding: 8px 12px; 
            background: #ddd; 
            border-radius: 5px;
            color: #333;
            transition: all 0.2s ease;
        }
        nav a:hover {
            background: #ccc;
        }
        nav a.active { 
            background: #0d6efd; 
            color: white; 
        }
        main { 
            flex-grow: 1;
            padding: 20px;
            background-color: #f9f9f9;
        }
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-title {
            margin: 0;
        }
        .header-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        .header-info p {
            margin: 0;
        }
        .timer-badge {
            font-family: monospace;
            font-size: 1.1em;
            padding: 3px 10px;
            border-radius: 10px;
            background-color: rgba(255,255,255,0.2);
            margin-left: 10px;
        }
        .auto-toggle {
            margin-left: 15px;
            display: flex;
            align-items: center;
            background-color: rgba(255,255,255,0.1);
            padding: 5px 10px;
            border-radius: 20px;
            cursor: pointer;
        }
        .auto-toggle:hover {
            background-color: rgba(255,255,255,0.2);
        }
        .auto-toggle input {
            margin-right: 5px;
        }
        .refresh-info {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: rgba(0,0,0,0.7);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            z-index: 1000;
        }
        .countdown {
            font-family: monospace;
            font-weight: bold;
            margin-left: 5px;
        }
        .logout-btn {
            color: #ffcccc;
            text-decoration: none;
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #ffcccc;
            transition: all 0.2s ease;
        }
        .logout-btn:hover {
            background-color: #ffcccc;
            color: #222;
        }
    </style>
</head>
<body>

<header>
    <div class="header-container">
        <h1 class="header-title">Bem-vindo, <?= htmlspecialchars($_SESSION['username']) ?></h1>
        <div class="header-info">
            <p>
                ID: <?= $_SESSION['user_id'] ?> 
                <span class="timer-badge" id="session-time"><?= tempoSessao() ?></span>
                
                <label class="auto-toggle">
                    <input type="checkbox" id="auto-toggle-check" <?= $autoAlternar ? 'checked' : '' ?>>
                    Alternar Dashboard/Calendário
                </label>
            </p>
            <p class="mt-2">
                <a href="logout.php" class="logout-btn">
                    <i class="bi bi-box-arrow-right"></i> Sair
                </a>
            </p>
        </div>
    </div>
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

<?php if ($refreshTime > 0 || $autoAlternar): ?>
<div class="refresh-info">
    <?php if ($refreshTime > 0): ?>
    <span>Auto refresh em <span id="refresh-countdown" class="countdown"><?= $refreshTime ?></span>s</span>
    <?php endif; ?>
    
    <?php if ($autoAlternar): ?>
    <span><?= $tabSelecionada === 'dashboard' ? 'Mudando para Calendário' : ($tabSelecionada === 'calendar' ? 'Mudando para Dashboard' : '') ?> em <span id="toggle-countdown" class="countdown">60</span>s</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Atualizar o tempo de sessão a cada segundo
    const sessionTimeEl = document.getElementById('session-time');
    if (sessionTimeEl) {
        let timeParts = sessionTimeEl.textContent.split(':');
        let seconds = parseInt(timeParts[0]) * 3600 + parseInt(timeParts[1]) * 60 + parseInt(timeParts[2]);
        
        setInterval(() => {
            seconds++;
            let hours = Math.floor(seconds / 3600).toString().padStart(2, '0');
            let minutes = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
            let secs = (seconds % 60).toString().padStart(2, '0');
            sessionTimeEl.textContent = `${hours}:${minutes}:${secs}`;
        }, 1000);
    }
    
    // Contador de refresh
    const refreshCountdownEl = document.getElementById('refresh-countdown');
    if (refreshCountdownEl) {
        let refreshTime = parseInt(refreshCountdownEl.textContent);
        setInterval(() => {
            refreshTime--;
            if (refreshTime <= 0) {
                refreshTime = <?= $refreshTime ?>;
            }
            refreshCountdownEl.textContent = refreshTime;
        }, 1000);
    }
    
    // Configurar alternância automática
    const autoToggleEl = document.getElementById('auto-toggle-check');
    if (autoToggleEl) {
        // Salvar estado do checkbox quando alterado
        autoToggleEl.addEventListener('change', function() {
            // Salvar preferência em cookie (válido por 30 dias)
            document.cookie = `auto_alternar=${this.checked}; max-age=${60*60*24*30}; path=/; SameSite=Strict`;
            
            if (this.checked) {
                startToggleTimer();
            } else {
                // Se desativar, remover a contagem regressiva
                const toggleCountdown = document.getElementById('toggle-countdown');
                if (toggleCountdown) {
                    toggleCountdown.parentElement.style.display = 'none';
                }
            }
        });
        
        // Iniciar timer de alternância se estiver marcado
        if (autoToggleEl.checked) {
            startToggleTimer();
        }
    }
    
    // Função para iniciar o timer de alternância
    function startToggleTimer() {
        const toggleCountdownEl = document.getElementById('toggle-countdown');
        if (toggleCountdownEl) {
            let toggleTime = parseInt(toggleCountdownEl.textContent);
            toggleCountdownEl.parentElement.style.display = 'inline';
            
            // Limpar intervalos existentes para evitar múltiplos
            if (window.toggleInterval) {
                clearInterval(window.toggleInterval);
            }
            
            // Configurar novo intervalo
            window.toggleInterval = setInterval(() => {
                toggleTime--;
                toggleCountdownEl.textContent = toggleTime;
                
                if (toggleTime <= 0) {
                    // Alternar entre dashboard e calendar
                    const tabAtual = '<?= $tabSelecionada ?>';
                    if (tabAtual === 'dashboard') {
                        window.location.href = '?tab=calendar';
                    } else if (tabAtual === 'calendar') {
                        window.location.href = '?tab=dashboard';
                    } else {
                        // Se estiver em outra aba, ir para dashboard
                        window.location.href = '?tab=dashboard';
                    }
                }
            }, 1000);
        }
    }
});
</script>

</body>
</html>
<?php
ob_end_flush(); // Libera o buffer
?>