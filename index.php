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

// Determinar se precisamos fazer nova configuração de temporizadores
$reiniciarTemporizadores = isset($_GET['reset_timers']) && $_GET['reset_timers'] === 'true';

function tempoSessao() {
    if (!isset($_SESSION['inicio'])) {
        $_SESSION['inicio'] = time();
    }
    $duração = time() - $_SESSION['inicio'];
    return gmdate("H:i:s", $duração);
}

// Configurações de tempo (em segundos)
$tempoRefreshCalendario = 40; // 10 segundos para refresh do calendário
$tempoAlternanciaAbas = 60;  // 60 segundos para alternância entre abas
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Área Redmine<?= $tabSelecionada ? ' - ' . $tabs[$tabSelecionada] : '' ?></title>
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

<div id="refresh-info" class="refresh-info" style="display: none;">
    <div id="refresh-info-content"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== CONFIGURAÇÃO BÁSICA =====
    const tabAtual = '<?= $tabSelecionada ?>';
    const tempoRefreshCalendario = <?= $tempoRefreshCalendario ?>;
    const tempoAlternanciaAbas = <?= $tempoAlternanciaAbas ?>;
    const reiniciarTemporizadores = <?= $reiniciarTemporizadores ? 'true' : 'false' ?>;

    // Elementos DOM importantes
    const autoToggleEl = document.getElementById('auto-toggle-check');
    const refreshInfoEl = document.getElementById('refresh-info');
    const refreshInfoContentEl = document.getElementById('refresh-info-content');
    const sessionTimeEl = document.getElementById('session-time');

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

    // ===== SISTEMA UNIFICADO DE TEMPORIZADORES =====
    // Esta é a grande mudança - um único sistema central que gerencia todos os temporizadores
    
    // 1. Determinar se devemos alternar automaticamente
    let autoAlternarAtivo = getLocalStorage('auto_alternar', false);
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
});
</script>

</body>
</html>
<?php
ob_end_flush(); // Libera o buffer
?>