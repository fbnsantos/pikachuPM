<?php
ob_start();
// index.php
ini_set('session.gc_maxlifetime', 86400);
ini_set('session.cookie_lifetime', 86400);
session_start();

const SESSION_TIMEOUT = 86400;

if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

// Forçar logout após 24h de inactividade
if (isset($_SESSION['ultima_atividade']) && (time() - $_SESSION['ultima_atividade']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['ultima_atividade'] = time();

// Definir timezone
date_default_timezone_set('Europe/Lisbon');

// Carregar configurações de tema do banco de dados
include_once __DIR__ . '/config.php';
$tema_cor1 = '#667eea'; // Cor padrão 1
$tema_cor2 = '#764ba2'; // Cor padrão 2

try {
    $pdo_config = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo_config->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Criar tabela se não existir
    $pdo_config->exec("
        CREATE TABLE IF NOT EXISTS system_theme (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gradient_color1 VARCHAR(7) NOT NULL DEFAULT '#667eea',
            gradient_color2 VARCHAR(7) NOT NULL DEFAULT '#764ba2',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Obter configuração atual
    $stmt = $pdo_config->query("SELECT gradient_color1, gradient_color2 FROM system_theme ORDER BY id DESC LIMIT 1");
    $theme = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($theme) {
        $tema_cor1 = $theme['gradient_color1'];
        $tema_cor2 = $theme['gradient_color2'];
    } else {
        // Inserir configuração padrão se não existir
        $pdo_config->exec("INSERT INTO system_theme (gradient_color1, gradient_color2) VALUES ('$tema_cor1', '$tema_cor2')");
    }
} catch (Exception $e) {
    // Em caso de erro, usar cores padrão
    error_log("Erro ao carregar tema: " . $e->getMessage());
}

// Buscar token da API do utilizador atual
$user_api_token = '';
try {
    if (isset($pdo_config)) {
        $stmt_tok = $pdo_config->prepare("SELECT token FROM user_tokens WHERE user_id = ?");
        $stmt_tok->execute([$_SESSION['user_id']]);
        $tok_row = $stmt_tok->fetch(PDO::FETCH_ASSOC);
        if ($tok_row) $user_api_token = $tok_row['token'];
    }
} catch (Exception $e) {
    error_log("Erro ao buscar token API: " . $e->getMessage());
}

// Definição dos horários para reuniões e transições
$HORA_REUNIAO_EQUIPA = "11:26"; // formato HH:MM - Hora para iniciar contagem para reunião
$HORA_TRANSICAO_CALENDARIO = "12:00"; // formato HH:MM - Hora para transição para o calendário

// Tabs disponíveis
$tabs = [
    'dashboard' => 'Painel Principal',
    'research_ideas' => 'Research Ideas',
    'bomlist/bomlist'  => 'Boom list',
    'prototypes/prototypesv2' => 'Prototypes',
    'projectos' => 'Projects',
    'sprints' => 'Sprints',
    'gantt' => 'Gantt',
    'todos' => 'To Do',
    'phd_kanban' => 'PhD plan',
    'leads' => 'Leads',
    'equipa' => 'Daily Meeting',
    'calendar' => 'Calendar',
    'links' => 'Files & Links', 
    'Management' => [
        'label' => 'Management',
        'submenu' => [
            'search' => 'Search Redmine',
            'lab_management' => 'Lab Management', 
            'contactos_comerciais' => 'Contactos Comerciais',
            'financeiro' => 'Financeiro',
            'admin' => 'Administration'
        ]
    ],
    
];

$tabSelecionada = $_GET['tab'] ?? 'dashboard';

// Validar se a tab existe (incluindo submenus)
$tabValida = false;
foreach ($tabs as $key => $value) {
    if ($key === $tabSelecionada) {
        $tabValida = true;
        break;
    }
    if (is_array($value) && isset($value['submenu'])) {
        if (array_key_exists($tabSelecionada, $value['submenu'])) {
            $tabValida = true;
            break;
        }
    }
}

if (!$tabValida) {
    $tabSelecionada = 'dashboard';
}

// Função auxiliar para obter o título da tab
function getTituloTab($tabs, $tabSelecionada) {
    foreach ($tabs as $key => $value) {
        if ($key === $tabSelecionada) {
            return is_array($value) ? $value['label'] : $value;
        }
        if (is_array($value) && isset($value['submenu'])) {
            if (array_key_exists($tabSelecionada, $value['submenu'])) {
                return $value['submenu'][$tabSelecionada];
            }
        }
    }
    return 'Painel Principal';
}

// Verificar se alternância automática está ativada - com configuração por usuário
if ($_SESSION['username'] === 'test') {
    // Para o usuário 'test', ativar por padrão se não estiver definido
    $autoAlternar = isset($_COOKIE['auto_alternar']) ? $_COOKIE['auto_alternar'] === 'true' : true;
    
    // Se não existe cookie, definir como true para o usuário test
    if (!isset($_COOKIE['auto_alternar'])) {
        setcookie('auto_alternar', 'true', time() + (86400 * 30), '/', '', false, true);
    }
} else {
    // Para outros usuários, usar a configuração normal
    $autoAlternar = isset($_COOKIE['auto_alternar']) ? $_COOKIE['auto_alternar'] === 'true' : false;
}

// Determinar se precisamos fazer nova configuração de temporizadores
$reiniciarTemporizadores = isset($_GET['reset_timers']) && $_GET['reset_timers'] === 'true';

// Carregar avisos da tabela notices (SQLite)
$notices = [];
try {
    $dbFile = __DIR__ . '/tabs/database/content.db';
    
    if (file_exists($dbFile)) {
        $db = new SQLite3($dbFile);
        $db->enableExceptions(true);
        
        // Buscar avisos ativos ordenados por prioridade e data
        $result = $db->query('
            SELECT id, text, added_by, added_at, priority 
            FROM notices 
            WHERE active = 1 
            ORDER BY priority DESC, added_at DESC 
            LIMIT 3
        ');
        
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $notices[] = $row;
            }
        }
        
        $db->close();
    }
} catch (Exception $e) {
    // Silenciosamente falhar se a tabela não existir ou houver erro
    error_log("Erro ao carregar notices: " . $e->getMessage());
}

function tempoSessao() {
    if (!isset($_SESSION['inicio'])) {
        $_SESSION['inicio'] = time();
    }
    $duração = time() - $_SESSION['inicio'];
    return gmdate("H:i:s", $duração);
}

// Configurações de tempo (em segundos) personalizadas por usuário
if ($_SESSION['username'] === 'test') {
    $tempoRefreshCalendario = 40; // 40 segundos para o usuário 'test'
} else {
    $tempoRefreshCalendario = 600; // 600 segundos (10 minutos) para outros usuários
}

$tempoAlternanciaAbas = 60;  // 60 segundos para alternância entre abas (igual para todos)
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Área Redmine<?= $tabSelecionada ? ' - ' . getTituloTab($tabs, $tabSelecionada) : '' ?></title>
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
            background: linear-gradient(135deg, <?= $tema_cor1 ?> 0%, <?= $tema_cor2 ?> 100%);
            color: white; 
            padding: 12px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        /* Estilos para área de avisos */
        .notices-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 8px;
            padding: 8px 12px;
            margin-top: 8px;
            border-left: 4px solid #ffc107;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .notices-container .notice-item {
            display: flex;
            align-items: center;
            padding: 4px 0;
            font-size: 0.9em;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .notices-container .notice-item:last-child {
            border-bottom: none;
        }
        
        .notices-container .notice-icon {
            margin-right: 8px;
            font-size: 1.1em;
        }
        
        .notices-container .notice-text {
            flex: 1;
        }
        
        .notices-container .notice-date {
            font-size: 0.85em;
            opacity: 0.8;
            margin-left: 10px;
        }
        
        .no-notices {
            opacity: 0.7;
            font-style: italic;
            font-size: 0.85em;
        }
        nav { 
            background: #f0f0f0; 
            display: flex; 
            flex-wrap: wrap;
            gap: 5px; 
            padding: 6px 15px;
            border-bottom: 1px solid #ddd;
        }
        nav a { 
            text-decoration: none; 
            padding: 6px 10px; 
            background: #ddd; 
            border-radius: 4px;
            color: #333;
            transition: all 0.2s ease;
            font-size: 0.9em;
        }
        nav a:hover {
            background: #ccc;
        }
        nav a.active { 
            background: #0d6efd; 
            color: white; 
        }
        
        /* Estilos para submenu */
        .menu-item {
            position: relative;
            display: inline-block;
        }
        
        .menu-link {
            text-decoration: none; 
            padding: 6px 10px; 
            background: #ddd; 
            border-radius: 4px;
            color: #333;
            transition: all 0.2s ease;
            display: inline-block;
            cursor: pointer;
            font-size: 0.9em;
        }
        
        .menu-link:hover {
            background: #ccc;
        }
        
        .menu-link.active {
            background: #0d6efd; 
            color: white;
        }
        
        .menu-link.has-submenu::after {
            content: ' ▼';
            font-size: 0.7em;
            margin-left: 4px;
            opacity: 0.7;
        }
        
        .submenu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background-color: #2c3e50;
            min-width: 160px;
            box-shadow: 0px 8px 20px rgba(0,0,0,0.3);
            z-index: 1000;
            border-radius: 4px;
            margin-top: 4px;
            overflow: hidden;
        }
        
        .menu-item:hover .submenu {
            display: block;
            animation: slideDown 0.2s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .submenu a {
            display: block;
            color: white !important;
            background-color: transparent !important;
            padding: 8px 12px;
            text-decoration: none;
            transition: background-color 0.2s;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            border-radius: 0;
            font-size: 0.85em;
        }
        
        .submenu a:last-child {
            border-bottom: none;
        }
        
        .submenu a:hover {
            background-color: #34495e !important;
        }
        
        .submenu a.active {
            background-color: #3498db !important;
            font-weight: 600;
        }
        main { 
            flex-grow: 1;
            padding: 20px;
            background-color: #f9f9f9;
        }
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header-left {
            display: flex;
            align-items: center;
            flex: 1;
            min-width: 250px;
        }
        .logo {
            height: 45px;
            margin-right: 15px;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            transition: transform 0.3s ease;
        }
        .logo:hover {
            transform: scale(1.1) rotate(5deg);
        }
        .header-title {
            margin: 0;
            font-size: 1.4em;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
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
            font-family: 'Courier New', monospace;
            font-size: 1em;
            padding: 4px 10px;
            border-radius: 6px;
            background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.1));
            margin-left: 8px;
            border: 1px solid rgba(255,255,255,0.3);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .timer-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .auto-toggle {
            margin-left: 12px;
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, rgba(255,255,255,0.15), rgba(255,255,255,0.05));
            padding: 5px 12px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .auto-toggle:hover {
            background: linear-gradient(135deg, rgba(255,255,255,0.25), rgba(255,255,255,0.15));
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .auto-toggle input {
            margin-right: 6px;
            cursor: pointer;
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
        
        /* Ocultar header e nav quando em reunião */
        body.hide-header-nav header,
        body.hide-header-nav nav {
            display: none !important;
        }
        
        body.hide-header-nav main {
            padding: 0 !important;
        }
        
        footer { 
            background-color: #222; 
            color: #999; 
            padding: 10px 20px; 
            text-align: center; 
            font-size: 0.8em; 
            border-top: 1px solid #444;
            margin-top: auto; /* Isso garante que o rodapé fique na parte inferior */
        }
        
        footer a {
            color: #bbb;
            text-decoration: none;
        }
        
        footer a:hover {
            color: white;
            text-decoration: underline;
        }
        
        /* Responsividade para header */
        @media (max-width: 992px) {
            .header-container {
                flex-direction: column;
            }
            .header-left {
                width: 100%;
            }
            .header-info {
                width: 100%;
                align-items: flex-start;
                margin-top: 10px;
            }
            .notices-container {
                font-size: 0.85em;
            }
        }
        
        @media (max-width: 768px) {
            .logo {
                height: 35px;
            }
            .header-title {
                font-size: 1.1em;
            }
            .notices-container {
                padding: 6px 10px;
            }
            .notice-item {
                flex-wrap: wrap;
            }
            .notice-date {
                width: 100%;
                margin-left: 28px;
                margin-top: 2px;
            }
        }
        
        /* Estilo para a notificação de reunião */
        .meeting-notification {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            padding: 20px 30px;
            background-color: #dc3545;
            color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            z-index: 9999;
            text-align: center;
            font-size: 1.2em;
            font-weight: bold;
            display: none;
        }
        .meeting-notification.show {
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0% {
                transform: translate(-50%, -50%) scale(1);
            }
            50% {
                transform: translate(-50%, -50%) scale(1.05);
            }
            100% {
                transform: translate(-50%, -50%) scale(1);
            }
        }
        
        /* Animação de pulsação para o relógio */
        @keyframes pulse-clock {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pulse {
            animation: pulse-clock 0.7s infinite;
        }
        
        /* Estilo para o relógio grande com contagem regressiva */
        .countdown-clock {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.85);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            color: white;
            display: none;
        }
        .countdown-time {
            font-size: 32rem; /* Aumentado para 4x o tamanho anterior */
            font-weight: bold;
            font-family: 'Digital-7', Arial, sans-serif;
            margin-bottom: 2rem;
            color: #ff5252;
            text-shadow: 0 0 20px rgba(255, 82, 82, 0.7);
            line-height: 1;
        }
        .countdown-message {
            font-size: 4rem; /* Também aumentado para manter a proporção */
            margin-bottom: 3rem;
        }
        .countdown-progress {
            width: 80%; /* Aumentado para ocupar mais espaço horizontal */
            height: 40px; /* Barra de progresso mais alta */
            background-color: #333;
            border-radius: 20px;
            overflow: hidden;
            margin-top: 2rem;
        }
        .countdown-bar {
            height: 100%;
            background-color: #ff5252;
            border-radius: 10px;
            transition: width 1s linear;
        }
 
    </style>
    <!-- Estilos adicionais para garantir centralização perfeita do relógio -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@700&display=swap');
        
        /* Ajuste para que o relógio não bloqueie o resto da interface */
        .countdown-clock {
            position: fixed;
            top: 50px; /* Distância do topo para não cobrir a barra de navegação */
            right: 50px; /* Posicionado à direita */
            width: auto; /* Largura automática baseada no conteúdo */
            height: auto; /* Altura automática baseada no conteúdo */
            z-index: 1000; /* Acima de outros conteúdos, mas não deve bloquear interações */
            background-color: rgba(0, 0, 0, 0.8);
            border-radius: 15px;
            padding: 15px 25px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
            pointer-events: none; /* Permite clicar através do relógio */
            display: none; /* Inicialmente oculto */
        }
        
        .countdown-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .countdown-time {
            font-family: 'Roboto Mono', monospace;
            font-size: 4rem; /* Tamanho reduzido para não ocupar todo o espaço */
            line-height: 1;
            margin: 0;
            padding: 0;
            color: #ff5252;
            text-shadow: 0 0 10px rgba(255, 82, 82, 0.7);
        }
        .countdown-message {
            font-size: 1rem; /* Tamanho reduzido */
            margin-bottom: 5px;
            color: white;
        }
        .countdown-progress {
            width: 100%; /* Ocupar todo o espaço disponível */
            height: 10px; /* Altura reduzida */
            background-color: #333;
            border-radius: 5px;
            overflow: hidden;
            margin-top: 5px;
        }
    </style>
</head>
<body>

<script>
// Verificar se está em reunião e ocultar header/nav
document.addEventListener('DOMContentLoaded', function() {
    // Verificar se existe o indicador de reunião no equipa.php
    const checkReuniaoInterval = setInterval(function() {
        const reuniaoCard = document.querySelector('.reuniao-topo');
        if (reuniaoCard) {
            document.body.classList.add('hide-header-nav');
        } else {
            document.body.classList.remove('hide-header-nav');
        }
    }, 100);
    
    // Parar a verificação após 2 segundos (conteúdo já carregado)
    setTimeout(function() {
        clearInterval(checkReuniaoInterval);
    }, 2000);
});
</script>

<header>
    <div class="header-container">
        <div class="header-left">
            <img src="images/pikachu_logo.png" alt="PikachuPM Logo" class="logo">
            <div>
                <h1 class="header-title">
                    Bem-vindo, <?= htmlspecialchars($_SESSION['username']) ?>
                    <button class="btn-api-token" onclick="pkApiTokenModal(true)" title="Ver token da API (para extensão Chrome)">
                        <i class="bi bi-key"></i> Token API
                    </button>
                    <a class="btn-api-token btn-pwa" href="PWA/index.html" target="_blank" title="Abrir app móvel (PWA)">
                        <i class="bi bi-phone"></i> App móvel
                    </a>
                    <button class="btn-api-token btn-ext" onclick="pkExtModal(true)" title="Instalar extensão Chrome">
                        <i class="bi bi-puzzle"></i> Extensão Chrome
                    </button>
                    <button class="btn-api-token btn-ff" onclick="pkFfModal(true)" title="Instalar extensão Firefox">
                        🦊 Firefox
                    </button>
                </h1>
                
                <?php if (!empty($notices)): ?>
                <div class="notices-container">
                    <?php foreach ($notices as $notice): ?>
                        <div class="notice-item">
                            <span class="notice-icon">
                                <?php 
                                    // Ícone baseado na prioridade
                                    $priority = isset($notice['priority']) ? intval($notice['priority']) : 0;
                                    if ($priority >= 2) echo '🔴'; // Urgente
                                    elseif ($priority == 1) echo '🟡'; // Importante
                                    else echo 'ℹ️'; // Normal
                                ?>
                            </span>
                            <span class="notice-text">
                                <?= htmlspecialchars($notice['text'] ?? '') ?>
                                <?php if (!empty($notice['added_by'])): ?>
                                    <small style="opacity: 0.7;"> - <?= htmlspecialchars($notice['added_by']) ?></small>
                                <?php endif; ?>
                            </span>
                            <?php if (isset($notice['added_at'])): ?>
                                <span class="notice-date"><?= date('d/m H:i', strtotime($notice['added_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="header-info">
            <p>
                ID: <?= $_SESSION['user_id'] ?> 
                <span class="timer-badge" id="session-time"><?= tempoSessao() ?></span>
                <span class="timer-badge" id="current-time"> Hora Actual:<?= date('H:i:s') ?></span>
                
                <label class="auto-toggle">
                    <input type="checkbox" id="auto-toggle-check" <?= $autoAlternar ? 'checked' : '' ?>>
                    Alternar Dashboard/Calendário
                </label>
            </p>
            <p style="margin: 2px 0 0 0; font-size: 0.75em; opacity: 0.8;">
                <span id="logout-countdown" title="Tempo até logout automático">Logout em: 24h</span>
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
    <?php foreach ($tabs as $id => $value): ?>
        <?php if (is_array($value) && isset($value['submenu'])): ?>
            <!-- Item com submenu -->
            <div class="menu-item">
                <span class="menu-link has-submenu"><?= htmlspecialchars($value['label']) ?></span>
                <div class="submenu">
                    <?php foreach ($value['submenu'] as $subId => $subLabel): ?>
                        <a href="?tab=<?= urlencode($subId) ?>" 
                           class="<?= $tabSelecionada === $subId ? 'active' : '' ?>">
                            <?= htmlspecialchars($subLabel) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Item normal -->
            <a href="?tab=<?= urlencode($id) ?>" class="<?= $tabSelecionada === $id ? 'active' : '' ?>">
                <?= htmlspecialchars($value) ?>
            </a>
        <?php endif; ?>
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

<footer>
    <div class="container">
        <p>PikachuPM V1.0 alpha &copy; <?php echo date("Y"); ?> | Produzido por <a href="mailto:fbnsantos@fbnsantos.com">Filipe Neves dos Santos</a></p>
    </div>
</footer>

<div id="refresh-info" class="refresh-info" style="display: none;">
    <div id="refresh-info-content"></div>
</div>

<!-- Elemento para notificação de reunião -->
<div id="meeting-notification" class="meeting-notification">
    <div>HORA DA REUNIÃO DIÁRIA!</div>
    <div>Redirecionando para a página de Reunião...</div>
</div>

<!-- Elemento para o relógio de contagem regressiva não-bloqueante -->
<div id="countdown-clock" class="countdown-clock">
    <div class="countdown-container">
        <div class="countdown-message">PREPARAR PARA REUNIÃO</div>
        <div id="countdown-time" class="countdown-time">02:00</div>
        <div class="countdown-progress">
            <div id="countdown-bar" class="countdown-bar" style="width: 100%;"></div>
        </div>
    </div>
</div>

<!-- Elemento de áudio para alerta sonoro -->
 <audio id="alert-sound" loop>
    <source src="https://criis-projects.inesctec.pt/PK/sound/notification.wav" type="audio/wav">
</audio>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== CONFIGURAÇÃO BÁSICA =====
    const tabAtual = '<?= $tabSelecionada ?>';
    const tempoRefreshCalendario = <?= $tempoRefreshCalendario ?>;
    const tempoAlternanciaAbas = <?= $tempoAlternanciaAbas ?>;
    const reiniciarTemporizadores = <?= $reiniciarTemporizadores ? 'true' : 'false' ?>;
    const usuarioAtual = '<?= $_SESSION['username'] ?>';
    
    // Horários de reunião e transição definidos no PHP
    const HORA_REUNIAO_EQUIPA = '<?= $HORA_REUNIAO_EQUIPA ?>'; // formato "HH:MM"
    const HORA_TRANSICAO_CALENDARIO = '<?= $HORA_TRANSICAO_CALENDARIO ?>'; // formato "HH:MM"
    
    // Extrair horas e minutos para comparações
    const [horaReuniao, minutosReuniao] = HORA_REUNIAO_EQUIPA.split(':').map(Number);
    const [horaCalendario, minutosCalendario] = HORA_TRANSICAO_CALENDARIO.split(':').map(Number);

    // Exibir informações de configuração no console (para debug)
    console.log(`Configurações para ${usuarioAtual}:`);
    console.log(`- Tempo de refresh do calendário: ${tempoRefreshCalendario}s`);
    console.log(`- Tempo de alternância entre abas: ${tempoAlternanciaAbas}s`);
    console.log(`- Alternância automática: ${<?= $autoAlternar ? 'true' : 'false' ?>}`);
    console.log(`- Horário reunião equipa: ${HORA_REUNIAO_EQUIPA}`);
    console.log(`- Horário transição calendário: ${HORA_TRANSICAO_CALENDARIO}`);

    // Elementos DOM importantes
    const autoToggleEl = document.getElementById('auto-toggle-check');
    const refreshInfoEl = document.getElementById('refresh-info');
    const refreshInfoContentEl = document.getElementById('refresh-info-content');
    const sessionTimeEl = document.getElementById('session-time');
    const meetingNotificationEl = document.getElementById('meeting-notification');
    const alertSoundEl = document.getElementById('alert-sound');

    // ===== FUNÇÃO DE CONTROLE DE REUNIÃO DIÁRIA =====
    // Variáveis para controlar o estado da contagem regressiva
    let countdownActive = false;
    let countdownStartTime = 0;
    let countdownDuration = 120; // 120 segundos = 2 minutos
    let countdownInterval;
    const countdownClockEl = document.getElementById('countdown-clock');
    const countdownTimeEl = document.getElementById('countdown-time');
    const countdownBarEl = document.getElementById('countdown-bar');
    
    // Função para iniciar a contagem regressiva de 120 segundos
    function iniciarContagemRegressiva() {
        // Só iniciar se não estiver já ativa
        if (countdownActive) return;
        
        countdownActive = true;
        countdownStartTime = Date.now();
        
        // Exibir o relógio de contagem
        countdownClockEl.style.display = 'block';
        
        // Iniciar som de alerta
        if (alertSoundEl) {
            alertSoundEl.volume = 0.5; // Volume a 50%
            alertSoundEl.loop = true;  // Repetir o som
            alertSoundEl.play()
                .catch(error => console.error('Erro ao tocar alerta sonoro:', error));
        }
        
        // Iniciar intervalo para atualizar a contagem a cada 100ms (para movimento mais suave)
        countdownInterval = setInterval(() => {
            atualizarContagemRegressiva();
        }, 100);
        
        // Registrar no console que a contagem começou
        console.log('Contagem regressiva iniciada às', new Date().toLocaleTimeString());
    }
    
    // Função para atualizar a contagem regressiva
    function atualizarContagemRegressiva() {
        const tempoDecorrido = (Date.now() - countdownStartTime) / 1000; // em segundos
        const tempoRestante = countdownDuration - tempoDecorrido;
        
        if (tempoRestante <= 0) {
            // Tempo acabou
            finalizarContagemRegressiva();
            return;
        }
        
        // Atualizar display do tempo
        const minutos = Math.floor(tempoRestante / 60);
        const segundos = Math.floor(tempoRestante % 60);
        // Garantir sempre 2 dígitos para manter o alinhamento
        countdownTimeEl.textContent = `${minutos.toString().padStart(2, '0')}:${segundos.toString().padStart(2, '0')}`;
        
        // Atualizar barra de progresso
        const porcentagemRestante = (tempoRestante / countdownDuration) * 100;
        countdownBarEl.style.width = `${porcentagemRestante}%`;
        
        // Mudar a cor do relógio para vermelho mais intenso quando faltarem 30 segundos
        if (tempoRestante <= 30) {
            countdownTimeEl.style.color = '#ff0000';
            countdownTimeEl.style.textShadow = '0 0 30px rgba(255, 0, 0, 0.9)';
            
            // Pulsar o relógio nos últimos 30 segundos
            if (!countdownTimeEl.classList.contains('pulse')) {
                countdownTimeEl.classList.add('pulse');
            }
        }
    }
    
    // Função para finalizar a contagem regressiva
    function finalizarContagemRegressiva() {
        clearInterval(countdownInterval);
        countdownActive = false;
        
        // Parar o som
        if (alertSoundEl) {
            alertSoundEl.pause();
            alertSoundEl.currentTime = 0;
        }
        
        // Esconder o relógio
        countdownClockEl.style.display = 'none';
        
        // Redirecionar para a tab equipa
        window.location.href = addParamsToUrl({
            'tab': 'equipa',
            'reset_timers': 'true'
        });
    }
    
    function verificarHorarioReunioes() {
        const agora = new Date();
        const hora = agora.getHours();
        const minutos = agora.getMinutes();
        const segundos = agora.getSeconds();
        
        // Verificar se é hora de iniciar contagem regressiva (usando variáveis globais)
        if (hora === horaReuniao && minutos === minutosReuniao && segundos === 0) {
            iniciarContagemRegressiva();
            return true;
        }
        
        // Verificar se é hora de ir para calendário (usando variáveis globais)
        if (hora === horaCalendario && minutos === minutosCalendario && segundos <= 1) {
            // Redirecionar para a tab calendário
            window.location.href = addParamsToUrl({
                'tab': 'calendar',
                'reset_timers': 'true'
            });
            
            return true;
        }
        
        return countdownActive; // Retorna true se a contagem regressiva estiver ativa
    }

    // ===== UTILITÁRIOS =====
    // Função para adicionar parâmetros à URL atual
    function addParamsToUrl(params) {
        const url = new URL(window.location.href);
        Object.keys(params).forEach(key => {
            if (params[key] !== null) {
                url.searchParams.set(key, params[key]);
            } else {
                url.searchParams.delete(key);
            }
        });
        return url.toString();
    }

    // Funções para manipular localStorage
    function getLocalStorage(key, defaultValue) {
        try {
            const value = localStorage.getItem(key);
            return value !== null ? JSON.parse(value) : defaultValue;
        } catch (e) {
            console.error('Erro ao ler localStorage:', e);
            return defaultValue;
        }
    }

    function setLocalStorage(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            console.error('Erro ao escrever localStorage:', e);
            return false;
        }
    }

    // ===== TEMPORIZADOR DE SESSÃO =====
    // Atualizar o tempo de sessão a cada segundo
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

    // ===== RELÓGIO E COUNTDOWN DE LOGOUT =====
    const currentTimeEl = document.getElementById('current-time');
    const logoutCountdownEl = document.getElementById('logout-countdown');
    const SESSION_TIMEOUT = 86400; // 24 horas em segundos
    
    // Atualizar relógio a cada segundo
    if (currentTimeEl) {
        setInterval(() => {
            const now = new Date();
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            currentTimeEl.textContent = `Hora actual: ${hours}:${minutes}:${seconds}`;
        }, 1000);
    }
    
    // Atualizar countdown de logout
    if (logoutCountdownEl && sessionTimeEl) {
        setInterval(() => {
            // Obter tempo de sessão atual em segundos
            let timeParts = sessionTimeEl.textContent.split(':');
            let sessionSeconds = parseInt(timeParts[0]) * 3600 + parseInt(timeParts[1]) * 60 + parseInt(timeParts[2]);
            
            // Calcular tempo restante até logout
            let remainingSeconds = SESSION_TIMEOUT - sessionSeconds;
            
            if (remainingSeconds <= 0) {
                logoutCountdownEl.textContent = 'Logout em: Sessão expirada';
                logoutCountdownEl.style.color = '#dc3545';
                // Redirecionar para logout após 3 segundos
                setTimeout(() => {
                    window.location.href = 'logout.php';
                }, 3000);
            } else {
                let hours = Math.floor(remainingSeconds / 3600);
                let minutes = Math.floor((remainingSeconds % 3600) / 60);
                
                // Formatar display
                if (hours > 0) {
                    logoutCountdownEl.textContent = `Logout em: ${hours}h ${minutes}m`;
                } else if (minutes > 0) {
                    logoutCountdownEl.textContent = `Logout em: ${minutes}m`;
                } else {
                    logoutCountdownEl.textContent = `Logout em: ${remainingSeconds}s`;
                }
                
                // Mudar cor quando faltar menos de 1 hora
                if (remainingSeconds < 3600) {
                    logoutCountdownEl.style.color = '#ffc107';
                } else {
                    logoutCountdownEl.style.color = 'inherit';
                }
                // Mudar para vermelho quando faltar menos de 10 minutos
                if (remainingSeconds < 600) {
                    logoutCountdownEl.style.color = '#dc3545';
                }
            }
        }, 1000);
    }

    // ===== SISTEMA UNIFICADO DE TEMPORIZADORES =====
    // Esta é a grande mudança - um único sistema central que gerencia todos os temporizadores
    
    // 1. Determinar se devemos alternar automaticamente
    let autoAlternarAtivo = getLocalStorage('auto_alternar', <?= $autoAlternar ? 'true' : 'false' ?>);
    if (autoToggleEl) {
        autoToggleEl.checked = autoAlternarAtivo;
        
        // Salvar estado do checkbox quando alterado
        autoToggleEl.addEventListener('change', function() {
            autoAlternarAtivo = this.checked;
            setLocalStorage('auto_alternar', autoAlternarAtivo);
            document.cookie = `auto_alternar=${autoAlternarAtivo}; max-age=${60*60*24*30}; path=/; SameSite=Strict`;
            
            if (autoAlternarAtivo) {
                // Se a alternância for ativada, definir próxima alternância
                configurarProximaAlternancia();
                iniciarTimerUnificado();
            } else {
                // Se desativar, limpar dados de alternância
                setLocalStorage('proxima_alternancia', null);
                atualizarInterfaceContador();
            }
        });
        
        // Se for usuário 'test' e checkbox estiver marcado, iniciar timers automaticamente
        if (usuarioAtual === 'test' && autoToggleEl.checked) {
            // Acionar ação como se o usuário tivesse clicado
            if (!getLocalStorage('proxima_alternancia', null)) {
                configurarProximaAlternancia();
            }
        }
    }
    
    // 2. Configurar próxima alternância (timestamp absoluto)
    function configurarProximaAlternancia() {
        // Calcular timestamp da próxima alternância (agora + 60 segundos)
        const proximaAlternancia = Date.now() + (tempoAlternanciaAbas * 1000);
        setLocalStorage('proxima_alternancia', proximaAlternancia);
        console.log('Próxima alternância configurada para:', new Date(proximaAlternancia));
    }
    
    // 3. Configurar próximo refresh (timestamp absoluto)
    function configurarProximoRefresh() {
        if (tabAtual === 'calendar') {
            const proximoRefresh = Date.now() + (tempoRefreshCalendario * 1000);
            setLocalStorage('proximo_refresh', proximoRefresh);
            console.log('Próximo refresh configurado para:', new Date(proximoRefresh));
        } else {
            setLocalStorage('proximo_refresh', null);
        }
    }
    
    // 4. Iniciar timer unificado
    let timerUnificadoInterval;
    
    function iniciarTimerUnificado() {
        // Limpar intervalo existente
        if (timerUnificadoInterval) {
            clearInterval(timerUnificadoInterval);
        }
        
        // Criar novo intervalo que verifica ambos os temporizadores
        timerUnificadoInterval = setInterval(() => {
            // Primeiro, verificar se é hora das reuniões (11:20 ou 12:00)
            if (verificarHorarioReunioes()) {
                // Se for hora de reunião, não executar o resto das verificações
                return;
            }
            
            const agora = Date.now();
            
            // Verificar alternância automática
            if (autoAlternarAtivo) {
                const proximaAlternancia = getLocalStorage('proxima_alternancia', null);
                if (proximaAlternancia && agora >= proximaAlternancia) {
                    // Limpar intervalo para evitar ações duplicadas
                    clearInterval(timerUnificadoInterval);
                    
                    // Executar alternância
                    if (tabAtual === 'dashboard') {
                        window.location.href = addParamsToUrl({
                            'tab': 'calendar',
                            'reset_timers': 'true'
                        });
                    } else if (tabAtual === 'calendar') {
                        window.location.href = addParamsToUrl({
                            'tab': 'dashboard',
                            'reset_timers': 'true'
                        });
                    } else {
                        window.location.href = addParamsToUrl({
                            'tab': 'dashboard',
                            'reset_timers': 'true'
                        });
                    }
                    return;
                }
            }
            
            // Verificar refresh do calendário
            if (tabAtual === 'calendar') {
                const proximoRefresh = getLocalStorage('proximo_refresh', null);
                if (proximoRefresh && agora >= proximoRefresh) {
                    // Configurar próximo refresh
                    configurarProximoRefresh();
                    
                    // Recarregar apenas o conteúdo do calendário sem navegar
                    // Isso é melhor que recarregar a página toda
                    fetch(window.location.href)
                        .then(response => response.text())
                        .then(html => {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const newMainContent = doc.querySelector('main').innerHTML;
                            document.querySelector('main').innerHTML = newMainContent;
                            console.log('Conteúdo do calendário atualizado via AJAX');
                        })
                        .catch(error => {
                            console.error('Erro ao atualizar calendário:', error);
                            // Em caso de erro, recorrer ao método tradicional
                            window.location.reload();
                        });
                }
            }
            
            // Atualizar interface
            atualizarInterfaceContador();
        }, 1000);
    }
    
    // 5. Atualizar interface com contadores
    function atualizarInterfaceContador() {
        const agora = Date.now();
        let conteudoInfo = [];
        
        // Verificar alternância automática
        if (autoAlternarAtivo) {
            const proximaAlternancia = getLocalStorage('proxima_alternancia', null);
            if (proximaAlternancia) {
                const segundosRestantes = Math.max(0, Math.ceil((proximaAlternancia - agora) / 1000));
                const textoAlternancia = tabAtual === 'dashboard' ? 'Mudando para Calendário' : 
                    (tabAtual === 'calendar' ? 'Mudando para Dashboard' : '');
                
                if (textoAlternancia) {
                    conteudoInfo.push(`${textoAlternancia} em <span class="countdown">${segundosRestantes}</span>s`);
                }
            }
        }
        
        // Verificar refresh do calendário
        if (tabAtual === 'calendar') {
            const proximoRefresh = getLocalStorage('proximo_refresh', null);
            if (proximoRefresh) {
                const segundosRestantes = Math.max(0, Math.ceil((proximoRefresh - agora) / 1000));
                conteudoInfo.push(`Auto refresh em <span class="countdown">${segundosRestantes}</span>s`);
            }
        }
        
        // Adicionar informação sobre os horários de reunião
        const horaAtual = new Date();
        const hora = horaAtual.getHours();
        const minutos = horaAtual.getMinutes();
        
        // Calcular tempo até próxima reunião (11:20 ou 12:00)
        let segundosAteReuniaoEquipa = 0;
        let segundosAteReuniaoCalendario = 0;
        
        // Calcular segundos até a hora da reunião (usando variáveis globais)
        if (hora < horaReuniao || (hora === horaReuniao && minutos < minutosReuniao)) {
            const horaReuniaoEquipa = new Date();
            horaReuniaoEquipa.setHours(horaReuniao, minutosReuniao, 0, 0);
            segundosAteReuniaoEquipa = Math.floor((horaReuniaoEquipa - horaAtual) / 1000);
            
            if (segundosAteReuniaoEquipa > 0 && segundosAteReuniaoEquipa < 300) { // Mostrar apenas se faltarem menos de 5 minutos
                conteudoInfo.push(`Contagem para reunião em <span class="countdown">${Math.floor(segundosAteReuniaoEquipa / 60)}:${(segundosAteReuniaoEquipa % 60).toString().padStart(2, '0')}</span>`);
            }
        }
        
        // Calcular segundos até a hora de transição para calendário (usando variáveis globais)
        if ((hora === horaReuniao && minutos >= minutosReuniao) || 
            (hora > horaReuniao && hora < horaCalendario) || 
            (hora === horaCalendario && minutos < minutosCalendario)) {
            const horaReuniaoCalendario = new Date();
            horaReuniaoCalendario.setHours(horaCalendario, minutosCalendario, 0, 0);
            segundosAteReuniaoCalendario = Math.floor((horaReuniaoCalendario - horaAtual) / 1000);
            
            if (segundosAteReuniaoCalendario > 0 && segundosAteReuniaoCalendario < 300) { // Mostrar apenas se faltarem menos de 5 minutos
                conteudoInfo.push(`Transição para Calendário em <span class="countdown">${Math.floor(segundosAteReuniaoCalendario / 60)}:${(segundosAteReuniaoCalendario % 60).toString().padStart(2, '0')}</span>`);
            }
        }
        
        // Atualizar elemento de informação
        if (conteudoInfo.length > 0 && refreshInfoEl && refreshInfoContentEl) {
            refreshInfoContentEl.innerHTML = conteudoInfo.join('<br>');
            refreshInfoEl.style.display = 'block';
        } else if (refreshInfoEl) {
            refreshInfoEl.style.display = 'none';
        }
    }
    
    // ===== INICIALIZAÇÃO =====
    // Se é uma nova visita ou reset explícito, configurar temporizadores
    if (reiniciarTemporizadores || tabAtual !== getLocalStorage('ultima_tab', null)) {
        console.log('Configurando novos temporizadores');
        
        // Salvar tab atual
        setLocalStorage('ultima_tab', tabAtual);
        
        // Configurar temporizadores se necessário
        if (autoAlternarAtivo) {
            configurarProximaAlternancia();
        }
        
        if (tabAtual === 'calendar') {
            configurarProximoRefresh();
        }
    }
    
    // Iniciar timer unificado (sempre)
    iniciarTimerUnificado();
    
    // Carregar áudio antecipadamente
    if (alertSoundEl) {
        alertSoundEl.load();
    }
});
</script>

<!-- Modal: Token da API -->
<div id="pk-token-modal" style="display:none" onclick="if(event.target===this)pkApiTokenModal(false)" role="dialog" aria-modal="true" aria-labelledby="pk-token-title">
    <div id="pk-token-box">
        <div id="pk-token-header">
            <span id="pk-token-title"><i class="bi bi-key-fill"></i> Token da API</span>
            <button onclick="pkApiTokenModal(false)" title="Fechar" aria-label="Fechar">&times;</button>
        </div>
        <div id="pk-token-body">
            <p>Usa este token para autenticar na <strong>extensão Chrome do pikachuPM</strong> e na API REST.</p>
            <div id="pk-token-field">
                <code id="pk-token-value"><?= htmlspecialchars($user_api_token ?: '(token não disponível)') ?></code>
                <button id="pk-token-copy" onclick="pkCopyToken()" title="Copiar token">
                    <i class="bi bi-clipboard" id="pk-copy-icon"></i>
                </button>
            </div>
            <p id="pk-token-hint">
                <i class="bi bi-info-circle"></i>
                URL base: <code><?= htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')) ?></code>
            </p>
        </div>
    </div>
</div>

<style>
.btn-api-token {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-left: 10px;
    padding: 3px 10px;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.35);
    border-radius: 20px;
    color: inherit;
    font-size: 0.65em;
    font-weight: 500;
    cursor: pointer;
    vertical-align: middle;
    transition: background 0.2s, border-color 0.2s;
    letter-spacing: 0.3px;
}
.btn-api-token:hover {
    background: rgba(255,255,255,0.28);
    border-color: rgba(255,255,255,0.6);
}
.btn-pwa {
    text-decoration: none;
    background: rgba(52,211,153,0.18);
    border-color: rgba(52,211,153,0.5);
    color: #a7f3d0;
}
.btn-pwa:hover {
    background: rgba(52,211,153,0.32);
    border-color: #34d399;
    color: #fff;
}
.btn-ext {
    background: rgba(99,102,241,0.18);
    border-color: rgba(99,102,241,0.5);
    color: #c7d2fe;
}
.btn-ext:hover {
    background: rgba(99,102,241,0.32);
    border-color: #818cf8;
    color: #fff;
}
.btn-ff {
    background: rgba(251,146,60,0.18);
    border-color: rgba(251,146,60,0.5);
    color: #fed7aa;
}
.btn-ff:hover {
    background: rgba(251,146,60,0.32);
    border-color: #fb923c;
    color: #fff;
}
#pk-token-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(3px);
}
#pk-token-box {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 12px;
    width: 520px;
    max-width: 95vw;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
    animation: pkModalIn 0.18s ease;
}
@keyframes pkModalIn {
    from { opacity: 0; transform: scale(0.95) translateY(10px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
#pk-token-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px 14px;
    border-bottom: 1px solid #334155;
    font-size: 1rem;
    font-weight: 600;
    color: #f1f5f9;
}
#pk-token-header button {
    background: none;
    border: none;
    font-size: 1.4rem;
    color: #64748b;
    cursor: pointer;
    line-height: 1;
    padding: 0 2px;
    transition: color 0.15s;
}
#pk-token-header button:hover { color: #f1f5f9; }
#pk-token-body {
    padding: 18px 20px 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    color: #94a3b8;
    font-size: 0.88rem;
}
#pk-token-body strong { color: #f1f5f9; }
#pk-token-field {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #0f172a;
    border: 1px solid #334155;
    border-radius: 8px;
    padding: 10px 12px;
}
#pk-token-value {
    flex: 1;
    font-family: 'SF Mono', Monaco, 'Courier New', monospace;
    font-size: 0.78rem;
    color: #f59e0b;
    word-break: break-all;
    letter-spacing: 0.5px;
}
#pk-token-copy {
    flex-shrink: 0;
    background: #334155;
    border: none;
    border-radius: 6px;
    color: #94a3b8;
    cursor: pointer;
    padding: 6px 10px;
    font-size: 1rem;
    transition: background 0.15s, color 0.15s;
}
#pk-token-copy:hover { background: #475569; color: #f1f5f9; }
#pk-token-copy.copied { background: #166534; color: #86efac; }
#pk-token-hint {
    font-size: 0.8rem;
    color: #64748b;
    line-height: 1.5;
}
#pk-token-hint code {
    background: rgba(255,255,255,0.07);
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 0.78rem;
    color: #94a3b8;
}
</style>

<!-- Modal: Extensão Chrome -->
<div id="pk-ext-modal" style="display:none" onclick="if(event.target===this)pkExtModal(false)" role="dialog" aria-modal="true">
    <div id="pk-ext-box">
        <div id="pk-ext-header">
            <span><i class="bi bi-puzzle-fill"></i> Extensão Chrome</span>
            <button onclick="pkExtModal(false)" title="Fechar">&times;</button>
        </div>
        <div id="pk-ext-body">
            <p>Instala a extensão <strong>pikachuPM</strong> no Chrome para teres acesso rápido aos todos, sprints, pomodoro e MQTT no painel lateral.</p>
            <a href="chrome-extension.zip" download class="pk-ext-download">
                <i class="bi bi-download"></i> Descarregar extensão (.zip)
            </a>
            <div class="pk-ext-steps">
                <div class="pk-ext-step"><span class="pk-ext-num">1</span><span>Descomprime o ficheiro <code>.zip</code> numa pasta</span></div>
                <div class="pk-ext-step"><span class="pk-ext-num">2</span><span>Abre <code>chrome://extensions</code> no Chrome</span></div>
                <div class="pk-ext-step"><span class="pk-ext-num">3</span><span>Ativa <strong>Modo de programador</strong> (canto superior direito)</span></div>
                <div class="pk-ext-step"><span class="pk-ext-num">4</span><span>Clica <strong>Carregar sem compactação</strong> e seleciona a pasta</span></div>
                <div class="pk-ext-step"><span class="pk-ext-num">5</span><span>Abre a extensão e cola o teu <strong>Token API</strong> nas definições</span></div>
            </div>
        </div>
    </div>
</div>

<style>
#pk-ext-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(3px);
}
#pk-ext-box {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 12px;
    width: 480px;
    max-width: 95vw;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
    animation: pkModalIn 0.18s ease;
}
#pk-ext-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px 14px;
    border-bottom: 1px solid #334155;
    font-size: 1rem;
    font-weight: 600;
    color: #f1f5f9;
}
#pk-ext-header button {
    background: none;
    border: none;
    font-size: 1.4rem;
    color: #64748b;
    cursor: pointer;
    line-height: 1;
    padding: 0 2px;
    transition: color 0.15s;
}
#pk-ext-header button:hover { color: #f1f5f9; }
#pk-ext-body {
    padding: 18px 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    color: #94a3b8;
    font-size: 0.88rem;
}
#pk-ext-body strong { color: #f1f5f9; }
#pk-ext-body p { line-height: 1.6; }
.pk-ext-download {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #6366f1;
    color: #fff;
    text-decoration: none;
    padding: 9px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.88rem;
    transition: background 0.15s;
    align-self: flex-start;
}
.pk-ext-download:hover { background: #4f46e5; color: #fff; }
.pk-ext-steps {
    display: flex;
    flex-direction: column;
    gap: 9px;
}
.pk-ext-step {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    line-height: 1.5;
}
.pk-ext-num {
    background: rgba(99,102,241,0.25);
    color: #a5b4fc;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
    flex-shrink: 0;
    margin-top: 1px;
}
#pk-ext-body code {
    background: rgba(255,255,255,0.07);
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 0.78rem;
    color: #94a3b8;
}
</style>

<!-- Modal: Extensão Firefox -->
<div id="pk-ff-modal" style="display:none" onclick="if(event.target===this)pkFfModal(false)" role="dialog" aria-modal="true">
    <div id="pk-ff-box">
        <div id="pk-ext-header" style="border-color:#fb923c33">
            <span>🦊 Extensão Firefox</span>
            <button onclick="pkFfModal(false)" title="Fechar">&times;</button>
        </div>
        <div id="pk-ext-body">
            <p>Instala a extensão <strong>pikachuPM</strong> no Firefox para teres o painel lateral com todos, sprints, pomodoro e MQTT.</p>
            <a href="firefox-extension.zip" download class="pk-ext-download" style="background:#fb923c">
                <i class="bi bi-download"></i> Descarregar extensão (.zip)
            </a>
            <div class="pk-ext-steps">
                <div class="pk-ext-step"><span class="pk-ext-num" style="background:rgba(251,146,60,0.2);color:#fed7aa">1</span><span>Descomprime o ficheiro <code>.zip</code> numa pasta</span></div>
                <div class="pk-ext-step"><span class="pk-ext-num" style="background:rgba(251,146,60,0.2);color:#fed7aa">2</span><span>Abre <code>about:debugging</code> no Firefox</span></div>
                <div class="pk-ext-step"><span class="pk-ext-num" style="background:rgba(251,146,60,0.2);color:#fed7aa">3</span><span>Clica <strong>Este Firefox</strong> → <strong>Carregar add-on temporário</strong></span></div>
                <div class="pk-ext-step"><span class="pk-ext-num" style="background:rgba(251,146,60,0.2);color:#fed7aa">4</span><span>Seleciona o ficheiro <code>manifest.json</code> dentro da pasta</span></div>
                <div class="pk-ext-step"><span class="pk-ext-num" style="background:rgba(251,146,60,0.2);color:#fed7aa">5</span><span>Abre a extensão → definições → cola o teu <strong>Token API</strong></span></div>
            </div>
            <p style="font-size:0.78rem;color:#64748b;border-top:1px solid #334155;padding-top:10px;margin-top:4px">
                <i class="bi bi-info-circle"></i>
                Para instalação permanente (sem expirar), submete no <a href="https://addons.mozilla.org/" target="_blank" style="color:#fb923c">addons.mozilla.org</a> ou usa uma política de empresa.
            </p>
        </div>
    </div>
</div>
<style>
#pk-ff-box {
    background: #1e293b;
    border: 1px solid #fb923c44;
    border-radius: 12px;
    width: 480px;
    max-width: 95vw;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
    animation: pkModalIn 0.18s ease;
    position: relative;
    z-index: 10000;
}
#pk-ff-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(3px);
}
</style>

<script>
function pkApiTokenModal(open) {
    document.getElementById('pk-token-modal').style.display = open ? 'flex' : 'none';
    if (open) {
        // Reset copy button state
        const btn = document.getElementById('pk-token-copy');
        btn.classList.remove('copied');
        document.getElementById('pk-copy-icon').className = 'bi bi-clipboard';
    }
}

function pkExtModal(open) {
    document.getElementById('pk-ext-modal').style.display = open ? 'flex' : 'none';
}

function pkFfModal(open) {
    document.getElementById('pk-ff-modal').style.display = open ? 'flex' : 'none';
}

function pkCopyToken() {
    const token = document.getElementById('pk-token-value').textContent;
    navigator.clipboard.writeText(token).then(() => {
        const btn = document.getElementById('pk-token-copy');
        btn.classList.add('copied');
        document.getElementById('pk-copy-icon').className = 'bi bi-clipboard-check';
        setTimeout(() => {
            btn.classList.remove('copied');
            document.getElementById('pk-copy-icon').className = 'bi bi-clipboard';
        }, 2500);
    });
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') pkApiTokenModal(false);
});
</script>

</body>
</html>
<?php
ob_end_flush(); // Libera o buffer
?>