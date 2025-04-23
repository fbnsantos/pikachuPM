<?php
// calendar.php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$db_path = __DIR__ . '/../eventos.sqlite';
$nova_base = !file_exists($db_path);

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($nova_base) {
        $db->exec("CREATE TABLE eventos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data TEXT NOT NULL,
            tipo TEXT NOT NULL,
            descricao TEXT,
            criador TEXT,
            cor TEXT NOT NULL
        )");
    }
} catch (Exception $e) {
    die("Erro ao inicializar base de dados: " . $e->getMessage());
}

$hoje = new DateTime();
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$hoje->modify("$offset days");
$inicioSemana = clone $hoje;
$inicioSemana->modify('monday this week');
$datas = [];
$numSemanas = isset($_GET['semanas']) ? max(1, min(40, (int)$_GET['semanas'])) : 30;
for ($i = 0; $i < $numSemanas * 7; $i++) {
    $data = clone $inicioSemana;
    $data->modify("+$i days");
    $datas[] = $data;
}

// Preservar estado da alternância automática
$auto_refresh = isset($_GET['auto_refresh']) && $_GET['auto_refresh'] === 'true';
$auto_alternar = isset($_COOKIE['auto_alternar']) ? $_COOKIE['auto_alternar'] === 'true' : false;
$tempo_alternancia = isset($_COOKIE['tempo_alternancia']) ? intval($_COOKIE['tempo_alternancia']) : 60;

// Tipos de eventos disponíveis e suas cores
$tipos_eventos = [
    'ferias' => ['nome' => 'Férias', 'cor' => 'green'],
    'demo' => ['nome' => 'Demonstração', 'cor' => 'blue'],
    'campo' => ['nome' => 'Saída de campo', 'cor' => 'orange'],
    'aulas' => ['nome' => 'Aulas', 'cor' => 'grey'],
    'tribe' => ['nome' => 'TRIBE MEETING', 'cor' => 'purple'],
    'outro' => ['nome' => 'Outro', 'cor' => 'red']
];

// Gerenciar filtros
$filtros = [];
if (isset($_GET['filtros'])) {
    $filtros = $_GET['filtros'];
} elseif (isset($_COOKIE['filtros_calendario'])) {
    $filtros = explode(',', $_COOKIE['filtros_calendario']);
} else {
    // Por padrão, mostrar todos os tipos
    $filtros = array_keys($tipos_eventos);
}

// Salvar filtros em cookie
if (isset($_GET['aplicar_filtros'])) {
    $filtros_str = implode(',', $filtros);
    setcookie('filtros_calendario', $filtros_str, time() + (86400 * 30), "/"); // Cookie válido por 30 dias
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['data'], $_POST['tipo'], $_POST['descricao'])) {
        $stmt = $db->prepare("INSERT INTO eventos (data, tipo, descricao, criador, cor) VALUES (:data, :tipo, :descricao, :criador, :cor)");
        $cor = $tipos_eventos[$_POST['tipo']]['cor'] ?? 'red';
        $stmt->execute([
            ':data' => $_POST['data'],
            ':tipo' => $_POST['tipo'],
            ':descricao' => $_POST['descricao'],
            ':criador' => $_SESSION['username'] ?? 'anon', // Alterado para username para corresponder ao index.php
            ':cor' => $cor
        ]);
    } elseif (isset($_POST['delete'])) {
        $stmt = $db->prepare("DELETE FROM eventos WHERE id = :id");
        $stmt->execute([':id' => $_POST['delete']]);
    }
    
    // Preservar estado de alternância automática nos redirecionamentos
    $queryParams = "tab=calendar&offset=$offset";
    
    // Preservar parâmetro de auto_refresh se estiver presente
    if ($auto_refresh) {
        $queryParams .= "&auto_refresh=true";
    }
    
    // Preservar filtros
    if (!empty($filtros)) {
        foreach ($filtros as $filtro) {
            $queryParams .= "&filtros[]=" . urlencode($filtro);
        }
    }
    
    header("Location: index.php?$queryParams");
    exit;
}

// Buscar eventos com filtro
$placeholders = implode(',', array_fill(0, count($filtros), '?'));
$stmt = $db->prepare("SELECT * FROM eventos" . (empty($filtros) ? "" : " WHERE tipo IN ($placeholders)"));

if (!empty($filtros)) {
    foreach ($filtros as $index => $tipo) {
        $stmt->bindValue($index + 1, $tipo);
    }
}

$stmt->execute();
$eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$eventos_por_dia = [];
foreach ($eventos as $e) {
    $eventos_por_dia[$e['data']][] = $e;
}

// Função para construir URL com parâmetros atuais
function buildUrl($params = []) {
    global $offset, $auto_refresh, $filtros, $numSemanas;
    
    $base = "?tab=calendar";
    
    if (isset($params['offset'])) {
        $base .= "&offset=" . $params['offset'];
    } elseif ($offset != 0) {
        $base .= "&offset=$offset";
    }
    
    if (isset($params['auto_refresh']) ? $params['auto_refresh'] : $auto_refresh) {
        $base .= "&auto_refresh=true";
    }
    
    if (isset($params['semanas'])) {
        $base .= "&semanas=" . $params['semanas'];
    } elseif ($numSemanas != 30) {
        $base .= "&semanas=$numSemanas";
    }
    
    $currentFiltros = isset($params['filtros']) ? $params['filtros'] : $filtros;
    if (!empty($currentFiltros)) {
        foreach ($currentFiltros as $filtro) {
            $base .= "&filtros[]=" . urlencode($filtro);
        }
    }
    
    return $base;
}

?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .calendario { 
        display: grid; 
        grid-template-columns: 1fr 1fr 1fr 1fr 1fr 0.6fr 0.6fr; 
        gap: 5px; 
    }
    .dia { 
        border: 1px solid #ccc; 
        min-height: 150px; 
        padding: 5px; 
        position: relative; 
        background: #f9f9f9;
        box-sizing: border-box; 
    }
    .data { 
        font-weight: bold; 
    }
    .evento { 
        font-size: 0.85em; 
        padding: 2px 4px; 
        margin-top: 2px; 
        border-radius: 4px; 
        color: white; 
        display: block; 
    }
    .fade { 
        opacity: 0; 
        transition: opacity 0.3s ease-in-out; 
    }
    .fade.show { 
        opacity: 1; 
    }
    .hoje { 
        background: #fff4cc !important; 
        border: 2px solid #f5b041; 
    }
    .fimsemana { 
        background: #f0f0f0; 
        opacity: 0.6; 
        font-size: 0.85em; 
    }
    .filter-panel {
        background-color: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 5px;
        padding: 15px;
        margin-bottom: 20px;
    }
    .filter-item {
        display: inline-block;
        margin-right: 15px;
        margin-bottom: 10px;
    }
    .filter-color {
        display: inline-block;
        width: 16px;
        height: 16px;
        border-radius: 3px;
        margin-right: 5px;
        vertical-align: middle;
    }
    .filter-btn {
        margin-left: 10px;
    }
    .filter-toggle {
        cursor: pointer;
        user-select: none;
        display: inline-block;
        margin-bottom: 15px;
    }
</style>

<div class="container mt-4">
    <h2 class="mb-4">Calendário da equipa</h2>
    <div class="d-flex justify-content-between mb-3">
        <a class="btn btn-secondary" href="<?= buildUrl(['offset' => $offset - 7]) ?>">&laquo; Semana anterior</a>
        <a class="btn btn-outline-primary" href="<?= buildUrl(['offset' => 0]) ?>">Hoje</a>
        <a class="btn btn-secondary" href="<?= buildUrl(['offset' => $offset + 7]) ?>">Semana seguinte &raquo;</a>
    </div>

    <div class="row mb-3">
        <div class="col-md-6">
            <form method="get" class="d-inline-block">
                <input type="hidden" name="tab" value="calendar">
                <input type="hidden" name="offset" value="<?= $offset ?>">
                <?php if ($auto_refresh): ?>
                <input type="hidden" name="auto_refresh" value="true">
                <?php endif; ?>
                <label for="semanas" class="form-label">Número de semanas:</label>
                <select name="semanas" id="semanas" class="form-select form-select-sm w-auto d-inline-block" onchange="this.form.submit()">
                    <?php for ($i = 1; $i <= 40; $i++): ?>
                        <option value="<?= $i ?>" <?= $i == $numSemanas ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
                
                <?php foreach ($filtros as $f): ?>
                    <input type="hidden" name="filtros[]" value="<?= htmlspecialchars($f) ?>">
                <?php endforeach; ?>
            </form>
        </div>
        
        <div class="col-md-6 text-end">
            <span class="filter-toggle" onclick="toggleFilterPanel()">
                <i class="bi bi-funnel"></i> Filtros <i class="bi bi-chevron-down" id="filter-chevron"></i>
            </span>
        </div>
    </div>
    
    <div class="filter-panel mb-3" id="filter-panel" style="display: none;">
        <form method="get" action="">
            <input type="hidden" name="tab" value="calendar">
            <input type="hidden" name="offset" value="<?= $offset ?>">
            <?php if ($auto_refresh): ?>
            <input type="hidden" name="auto_refresh" value="true">
            <?php endif; ?>
            <input type="hidden" name="semanas" value="<?= $numSemanas ?>">
            <input type="hidden" name="aplicar_filtros" value="1">
            
            <div class="row">
                <div class="col-md-9">
                    <?php foreach ($tipos_eventos as $codigo => $info): ?>
                        <div class="filter-item">
                            <label>
                                <input type="checkbox" name="filtros[]" value="<?= $codigo ?>" 
                                       <?= in_array($codigo, $filtros) ? 'checked' : '' ?>>
                                <span class="filter-color" style="background-color: <?= $info['cor'] ?>;"></span>
                                <?= htmlspecialchars($info['nome']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="col-md-3 text-end">
                    <button type="submit" class="btn btn-sm btn-primary">Aplicar Filtros</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selecionarTodos(true)">Todos</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selecionarTodos(false)">Nenhum</button>
                </div>
            </div>
        </form>
    </div>

    <div class="calendario">
        <?php foreach ($datas as $data): 
            $data_str = $data->format('Y-m-d');
            $diaSemana = $data->format('N');
            $isHoje = $data->format('Y-m-d') === (new DateTime())->format('Y-m-d');
            $classeExtra = ($diaSemana == 6 || $diaSemana == 7) ? ' fimsemana' : '';
        ?>
        <div class="dia<?= $isHoje ? ' hoje' : '' ?><?= $classeExtra ?>">
            <div class="data"><?= $data->format('D d/m/Y') ?></div>
            <?php if (isset($eventos_por_dia[$data_str])): ?>
                <?php foreach ($eventos_por_dia[$data_str] as $ev): ?>
                    <form method="post" class="d-flex justify-content-between align-items-center">
                        <span class="evento" style="background: <?= $ev['cor'] ?>;">
                            <?= htmlspecialchars($ev['tipo']) ?>: <?= htmlspecialchars($ev['descricao']) ?>
                        </span>
                        <button type="submit" name="delete" value="<?= $ev['id'] ?>" class="btn btn-sm btn-outline-danger ms-1">x</button>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="toggleForm(this)">+ Adicionar</button>
            <form method="post" class="mt-2 d-none">
                <input type="hidden" name="data" value="<?= $data_str ?>">
                <select name="tipo" class="form-select form-select-sm mb-1">
                    <?php foreach ($tipos_eventos as $codigo => $info): ?>
                        <option value="<?= $codigo ?>"><?= htmlspecialchars($info['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="descricao" placeholder="Descrição" class="form-control form-control-sm mb-1" required>
                <button type="submit" class="btn btn-sm btn-primary">Adicionar</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// Preservar cookie de alternância entre Dashboard/Calendário
const preserveAlternancaTimeout = () => {
    // Se existir um cronômetro de alternância, vamos nos certificar
    // de que qualquer redefinição de página não afete esse cronômetro
    const toggleCountdownEl = document.getElementById('toggle-countdown');
    if (toggleCountdownEl) {
        const autoAlternarAtivo = <?= json_encode($auto_alternar) ?> === true;
        const tempoAlternanciaAtual = <?= $tempo_alternancia ?>;
        
        // Garantir que não sobrescrevemos o cookie durante recarregamentos automáticos
        if (autoAlternarAtivo && window.location.search.includes('auto_refresh=true')) {
            console.log('Preservando tempo de alternância durante refresh automático:', tempoAlternanciaAtual);
        }
    }
};

// Executar ao carregar a página
document.addEventListener('DOMContentLoaded', preserveAlternancaTimeout);

function toggleForm(button) {
    const form = button.nextElementSibling;
    form.classList.toggle('d-none');
    button.classList.toggle('d-none');
    form.classList.add('fade');
    form.classList.add('show');
}

// Fechar formulários ao submeter
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form').forEach(f => {
        f.addEventListener('submit', () => {
            const parent = f.closest('.dia');
            const btn = parent.querySelector('button[type="button"]');
            f.classList.add('d-none');
            if (btn) btn.classList.remove('d-none');
        });
    });
    
    // Verificar se o painel de filtros deve estar aberto com base no localStorage
    const filterPanelOpen = localStorage.getItem('filterPanelOpen') === 'true';
    const filterPanel = document.getElementById('filter-panel');
    const filterChevron = document.getElementById('filter-chevron');
    
    if (filterPanelOpen && filterPanel) {
        filterPanel.style.display = 'block';
        if (filterChevron) filterChevron.classList.replace('bi-chevron-down', 'bi-chevron-up');
    }
});

function toggleFilterPanel() {
    const filterPanel = document.getElementById('filter-panel');
    const filterChevron = document.getElementById('filter-chevron');
    
    if (filterPanel.style.display === 'none') {
        filterPanel.style.display = 'block';
        localStorage.setItem('filterPanelOpen', 'true');
        if (filterChevron) filterChevron.classList.replace('bi-chevron-down', 'bi-chevron-up');
    } else {
        filterPanel.style.display = 'none';
        localStorage.setItem('filterPanelOpen', 'false');
        if (filterChevron) filterChevron.classList.replace('bi-chevron-up', 'bi-chevron-down');
    }
}

function selecionarTodos(selecionar) {
    const checkboxes = document.querySelectorAll('input[name="filtros[]"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = selecionar;
    });
}
</script>