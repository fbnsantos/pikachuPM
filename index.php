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
        /* Reset básico */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
            min-height: 100vh;
        }

        /* Header */
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        header h1 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        /* Navegação */
        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 3px;
            align-items: center;
            background-color: rgba(0,0,0,0.15);
            padding: 0.5rem;
            border-radius: 8px;
        }

        /* Estilos para menu items */
        .menu-item {
            position: relative;
            display: inline-block;
        }
        
        .menu-link {
            display: inline-block;
            padding: 0.6rem 1rem;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            border-radius: 5px;
            font-size: 0.9rem;
            white-space: nowrap;
            cursor: pointer;
            background-color: transparent;
        }
        
        .menu-link:hover {
            background-color: rgba(255,255,255,0.2);
            transform: translateY(-1px);
        }
        
        .menu-link.active {
            background-color: rgba(255,255,255,0.3);
            font-weight: 600;
        }

        .menu-link.has-submenu::after {
            content: ' ▼';
            font-size: 0.65em;
            margin-left: 4px;
            opacity: 0.8;
        }

        /* Submenu */
        .submenu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background-color: #2c3e50;
            min-width: 180px;
            box-shadow: 0px 8px 20px rgba(0,0,0,0.3);
            z-index: 1000;
            border-radius: 6px;
            margin-top: 5px;
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
            color: white;
            padding: 0.75rem 1.2rem;
            text-decoration: none;
            transition: background-color 0.2s;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .submenu a:last-child {
            border-bottom: none;
        }
        
        .submenu a:hover {
            background-color: #34495e;
        }

        .submenu a.active {
            background-color: #3498db;
            font-weight: 600;
        }

        /* Botão de logout */
        .logout-link {
            margin-left: auto;
            background-color: rgba(220, 53, 69, 0.7);
        }

        .logout-link:hover {
            background-color: rgba(220, 53, 69, 0.9);
        }

        /* Session info */
        .session-info {
            margin-top: 0.75rem;
            font-size: 0.9rem;
            opacity: 0.95;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .session-info label {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .session-info input[type="checkbox"] {
            cursor: pointer;
            width: 16px;
            height: 16px;
        }

        /* Main content */
        main {
            padding: 2rem;
            max-width: 100%;
            margin: 0 auto;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }

        .alert-warning {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }

        /* Contador para reunião/calendário */
        #contador-display {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: rgba(0,0,0,0.8);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            z-index: 999;
            font-size: 0.9rem;
            min-width: 200px;
        }

        /* Responsividade */
        @media (max-width: 992px) {
            header h1 {
                font-size: 1.3rem;
            }

            nav {
                gap: 2px;
            }

            .menu-link {
                padding: 0.5rem 0.8rem;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 768px) {
            header {
                padding: 1rem;
            }

            header h1 {
                font-size: 1.1rem;
            }

            nav {
                flex-direction: column;
                align-items: stretch;
                gap: 2px;
            }

            .menu-link,
            .menu-item {
                width: 100%;
                text-align: center;
            }

            .submenu {
                position: static;
                width: 100%;
                margin-top: 0;
                display: none;
            }

            .menu-item.active-mobile .submenu {
                display: block;
            }

            .logout-link {
                margin-left: 0;
            }

            main {
                padding: 1rem;
            }

            #contador-display {
                bottom: 10px;
                right: 10px;
                font-size: 0.8rem;
                padding: 0.75rem 1rem;
                min-width: 150px;
            }
        }

        @media (max-width: 480px) {
            header h1 {
                font-size: 1rem;
            }

            .menu-link {
                font-size: 0.8rem;
            }

            .session-info {
                font-size: 0.8rem;
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
            <span>Tempo de sessão: <strong id="session-time"><?= tempoSessao() ?></strong></span>
            <?php if ($_SESSION['username'] === 'test'): ?>
                <label>
                    <input type="checkbox" id="auto-toggle" <?= $autoAlternar ? 'checked' : '' ?>>
                    <span>Auto-alternar</span>
                </label>
            <?php endif; ?>
        </div>
    </header>

    <!-- Contador de transição (visível apenas quando necessário) -->
    <div id="contador-display" style="display: none;"></div>

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
        // ===== CONFIGURAÇÕES GLOBAIS =====
        const HORA_REUNIAO_EQUIPA = '<?= $HORA_REUNIAO_EQUIPA ?>';
        const HORA_TRANSICAO_CALENDARIO = '<?= $HORA_TRANSICAO_CALENDARIO ?>';
        const TEMPO_REFRESH_CALENDARIO = <?= $tempoRefreshCalendario ?> * 1000; // Converter para ms
        const TEMPO_ALTERNANCIA_ABAS = <?= $tempoAlternanciaAbas ?> * 1000; // Converter para ms
        const USUARIO_ATUAL = '<?= $_SESSION['username'] ?>';
        const REINICIAR_TEMPORIZADORES = <?= $reiniciarTemporizadores ? 'true' : 'false' ?>;

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

        // ===== SISTEMA DE AUTO-ALTERNÂNCIA =====
        const autoToggleEl = document.getElementById('auto-toggle');
        let autoAlternarAtivo = getLocalStorage('auto_alternar', <?= $autoAlternar ? 'true' : 'false' ?>);
        
        if (autoToggleEl) {
            autoToggleEl.checked = autoAlternarAtivo;
            
            autoToggleEl.addEventListener('change', function() {
                autoAlternarAtivo = this.checked;
                setLocalStorage('auto_alternar', autoAlternarAtivo);
                document.cookie = `auto_alternar=${autoAlternarAtivo}; max-age=${60*60*24*30}; path=/; SameSite=Strict`;
                
                if (autoAlternarAtivo) {
                    configurarProximaAlternancia();
                    iniciarTimerUnificado();
                } else {
                    setLocalStorage('proxima_alternancia', null);
                    atualizarInterfaceContador();
                }
            });
            
            if (USUARIO_ATUAL === 'test' && autoToggleEl.checked) {
                if (!getLocalStorage('proxima_alternancia', null)) {
                    configurarProximaAlternancia();
                }
            }
        }

        // ===== FUNÇÕES DE ALTERNÂNCIA =====
        function configurarProximaAlternancia() {
            const agora = new Date();
            const horaAtual = agora.getHours().toString().padStart(2, '0') + ':' + agora.getMinutes().toString().padStart(2, '0');
            
            let proximaAlternancia;
            
            if (horaAtual < HORA_REUNIAO_EQUIPA) {
                proximaAlternancia = {
                    tipo: 'reuniao',
                    hora: HORA_REUNIAO_EQUIPA,
                    tab_destino: 'equipa'
                };
            } else if (horaAtual < HORA_TRANSICAO_CALENDARIO) {
                proximaAlternancia = {
                    tipo: 'calendario',
                    hora: HORA_TRANSICAO_CALENDARIO,
                    tab_destino: 'calendar'
                };
            } else {
                proximaAlternancia = null;
            }
            
            setLocalStorage('proxima_alternancia', proximaAlternancia);
            return proximaAlternancia;
        }

        function iniciarTimerUnificado() {
            const contadorEl = document.getElementById('contador-display');
            
            setInterval(() => {
                if (!autoAlternarAtivo) {
                    if (contadorEl) contadorEl.style.display = 'none';
                    return;
                }
                
                const proximaAlternancia = getLocalStorage('proxima_alternancia', null);
                
                if (!proximaAlternancia) {
                    if (contadorEl) contadorEl.style.display = 'none';
                    return;
                }
                
                const agora = new Date();
                const [horaAlvo, minutoAlvo] = proximaAlternancia.hora.split(':').map(Number);
                const alvo = new Date();
                alvo.setHours(horaAlvo, minutoAlvo, 0, 0);
                
                const diferenca = Math.floor((alvo - agora) / 1000);
                
                if (diferenca <= 0) {
                    window.location.href = `?tab=${proximaAlternancia.tab_destino}`;
                    return;
                }
                
                const minutos = Math.floor(diferenca / 60);
                const segundos = diferenca % 60;
                
                if (contadorEl) {
                    contadorEl.style.display = 'block';
                    contadorEl.innerHTML = `
                        <div><strong>${proximaAlternancia.tipo === 'reuniao' ? '📅 Reunião de Equipa' : '📆 Calendário'}</strong></div>
                        <div>Transição em: ${minutos}:${segundos.toString().padStart(2, '0')}</div>
                    `;
                }
            }, 1000);
        }

        function atualizarInterfaceContador() {
            const contadorEl = document.getElementById('contador-display');
            if (contadorEl) {
                contadorEl.style.display = 'none';
            }
        }

        // ===== MENU MOBILE =====
        if (window.innerWidth <= 768) {
            document.querySelectorAll('.menu-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    if (e.target.classList.contains('has-submenu')) {
                        e.preventDefault();
                        this.classList.toggle('active-mobile');
                    }
                });
            });
        }

        // ===== MANTER SESSÃO ATIVA =====
        setInterval(() => {
            fetch(window.location.href, { method: 'HEAD' }).catch(err => {
                console.error('Erro ao manter sessão:', err);
            });
        }, 300000); // 5 minutos

        // ===== INICIAR SISTEMA =====
        if (autoAlternarAtivo && USUARIO_ATUAL === 'test') {
            iniciarTimerUnificado();
        }
    </script>
</body>
</html>