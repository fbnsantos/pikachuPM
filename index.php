<?php
ob_start();
// index.php
session_start();

// Configurar timeout de sessão para 24 horas
ini_set('session.gc_maxlifetime', 86400);
ini_set('session.cookie_lifetime', 86400);

if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

// Definição dos horários para reuniões e transições
$HORA_REUNIAO_EQUIPA = "11:26"; // formato HH:MM - Hora para iniciar contagem para reunião
$HORA_TRANSICAO_CALENDARIO = "12:00"; // formato HH:MM - Hora para transição para o calendário

// Tabs disponíveis com suporte a submenus
$tabs = [
    'dashboard' => 'Painel Principal',
    'bomlist/bomlist' => 'Boom list',
    'prototypes/prototypesv2' => 'Prototypes',
    'projectos' => 'Projects',
    'sprints' => 'Sprints',
    'gantt' => 'Gantt',
    'todos' => 'To Do',
    'phd_kanban' => 'PhD plan',
    'leads' => 'Leads V2',
    'equipa' => 'Daily Meeting',
    'calendar' => 'Calendar',
    'oportunidades' => 'Leads',
    'search' => 'Search',
    'links' => 'Links',
    'old' => [
        'label' => 'Old',
        'submenu' => [
            'prototypes' => 'Prototypes_v1',
            'projecto' => 'Projects_1',
            'milestone' => 'Milestones'
        ]
    ],
    'admin' => 'Administration',
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

$tituloTab = getTituloTab($tabs, $tabSelecionada);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área Redmine<?= $tabSelecionada ? ' - ' . htmlspecialchars($tituloTab) : '' ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
        }

        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        header h1 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            align-items: center;
            background-color: rgba(0,0,0,0.1);
            padding: 0.5rem;
            border-radius: 8px;
        }

        /* Estilos para o menu */
        .menu-item {
            position: relative;
            display: inline-block;
        }
        
        .submenu {
            display: none;
            position: absolute;
            background-color: #2c3e50;
            min-width: 200px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.3);
            z-index: 1000;
            border-radius: 4px;
            margin-top: 5px;
            animation: fadeIn 0.2s ease-in;
        }

        @keyframes fadeIn {
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
            color: white;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            border-bottom: 1px solid #34495e;
            transition: background-color 0.2s;
        }
        
        .submenu a:last-child {
            border-bottom: none;
            border-radius: 0 0 4px 4px;
        }

        .submenu a:first-child {
            border-radius: 4px 4px 0 0;
        }
        
        .submenu a:hover {
            background-color: #34495e;
        }

        .submenu a.active {
            background-color: #3498db;
            font-weight: bold;
        }
        
        .menu-item:hover .submenu {
            display: block;
        }
        
        .menu-link.has-submenu::after {
            content: ' ▼';
            font-size: 0.7em;
            margin-left: 3px;
        }
        
        .menu-link {
            cursor: pointer;
            display: inline-block;
            padding: 10px 15px;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
            border-radius: 4px;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        .menu-link:hover {
            background-color: rgba(255,255,255,0.2);
        }
        
        .menu-link.active {
            background-color: rgba(255,255,255,0.3);
            font-weight: bold;
        }

        .session-info {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        main {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }

        .logout-link {
            margin-left: auto;
            background-color: rgba(220, 53, 69, 0.8);
        }

        .logout-link:hover {
            background-color: rgba(220, 53, 69, 1);
        }

        /* Responsividade */
        @media (max-width: 768px) {
            header {
                padding: 1rem;
            }

            header h1 {
                font-size: 1.2rem;
            }

            nav {
                flex-direction: column;
                align-items: stretch;
            }

            .menu-link {
                width: 100%;
                text-align: center;
            }

            .menu-item {
                width: 100%;
            }

            .submenu {
                position: static;
                width: 100%;
                margin-top: 0;
            }

            main {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Sistema Redmine - <?= htmlspecialchars($_SESSION['username']) ?></h1>
        <nav>
            <?php foreach ($tabs as $key => $value): ?>
                <?php if (is_array($value) && isset($value['submenu'])): ?>
                    <!-- Item com submenu -->
                    <div class="menu-item">
                        <span class="menu-link has-submenu"><?= htmlspecialchars($value['label']) ?></span>
                        <div class="submenu">
                            <?php foreach ($value['submenu'] as $subKey => $subLabel): ?>
                                <a href="?tab=<?= urlencode($subKey) ?>" 
                                   class="<?= $tabSelecionada === $subKey ? 'active' : '' ?>">
                                    <?= htmlspecialchars($subLabel) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Item normal -->
                    <a href="?tab=<?= urlencode($key) ?>" 
                       class="menu-link <?= $tabSelecionada === $key ? 'active' : '' ?>">
                        <?= htmlspecialchars($value) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a href="logout.php" class="menu-link logout-link">Sair</a>
        </nav>
        <div class="session-info">
            Tempo de sessão: <span id="session-time"><?= tempoSessao() ?></span>
            <?php if ($_SESSION['username'] === 'test'): ?>
                | <label style="cursor: pointer;">
                    <input type="checkbox" id="auto-toggle" <?= $autoAlternar ? 'checked' : '' ?>>
                    Auto-alternar
                </label>
            <?php endif; ?>
        </div>
    </header>

    <main>
        <?php
        // Carregar o conteúdo da tab selecionada
        $arquivo = "tabs/{$tabSelecionada}.php";
        if (file_exists($arquivo)) {
            include $arquivo;
        } else {
            echo "<div class='alert alert-warning'>Conteúdo não encontrado para esta seção: " . htmlspecialchars($tabSelecionada) . "</div>";
        }
        ?>
    </main>

    <script>
        // ===== FUNÇÕES AUXILIARES =====
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

        // ===== AUTO-ALTERNÂNCIA (para usuário test) =====
        const autoToggleEl = document.getElementById('auto-toggle');
        const usuarioAtual = '<?= $_SESSION['username'] ?>';
        
        if (autoToggleEl && usuarioAtual === 'test') {
            let autoAlternarAtivo = getLocalStorage('auto_alternar', <?= $autoAlternar ? 'true' : 'false' ?>);
            
            autoToggleEl.addEventListener('change', function() {
                autoAlternarAtivo = this.checked;
                setLocalStorage('auto_alternar', autoAlternarAtivo);
                document.cookie = `auto_alternar=${autoAlternarAtivo}; max-age=${60*60*24*30}; path=/; SameSite=Strict`;
                
                if (autoAlternarAtivo) {
                    alert('Auto-alternância ativada!');
                } else {
                    alert('Auto-alternância desativada!');
                }
            });
        }

        // ===== ATUALIZAÇÃO PERIÓDICA =====
        // Atualizar a página a cada 5 minutos para manter a sessão ativa
        setInterval(() => {
            console.log('Mantendo sessão ativa...');
            fetch(window.location.href, { method: 'HEAD' }).catch(err => {
                console.error('Erro ao manter sessão:', err);
            });
        }, 300000); // 5 minutos
    </script>
</body>
</html>