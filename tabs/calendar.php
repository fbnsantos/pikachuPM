<?php
// calendar.php - Versão MySQL com férias e aulas
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir configuração do MySQL
include_once __DIR__ . '/../config.php';

// Conectar ao MySQL
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        die("Erro ao conectar à base de dados: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
    // Criar tabela de eventos se não existir
    $create_table = "CREATE TABLE IF NOT EXISTS calendar_eventos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        data DATE NOT NULL,
        tipo VARCHAR(50) NOT NULL,
        descricao TEXT,
        hora TIME DEFAULT NULL,
        criador VARCHAR(100),
        cor VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_data (data),
        INDEX idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$db->query($create_table)) {
        die("Erro ao criar tabela: " . $db->error);
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

// Preservar estado do refresh e alternância
$js_refresh = isset($_GET['js_refresh']) && $_GET['js_refresh'] === 'true';
$auto_alternar = isset($_COOKIE['auto_alternar']) ? $_COOKIE['auto_alternar'] === 'true' : false;

// Tipos de eventos disponíveis e suas cores
$tipos_eventos = [
    'ferias' => ['nome' => 'Férias', 'cor' => 'green'],
    'aulas' => ['nome' => 'Aulas', 'cor' => 'grey'],
    'demo' => ['nome' => 'Demonstração', 'cor' => 'blue'],
    'campo' => ['nome' => 'Saída de campo', 'cor' => 'orange'],
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
    setcookie('filtros_calendario', $filtros_str, time() + (86400 * 30), "/");
}

// Processar formulários
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['data'], $_POST['tipo'], $_POST['descricao'])) {
        $data = $db->real_escape_string($_POST['data']);
        $tipo = $db->real_escape_string($_POST['tipo']);
        $descricao = $db->real_escape_string($_POST['descricao']);
        $hora = isset($_POST['hora']) && !empty($_POST['hora']) ? "'" . $db->real_escape_string($_POST['hora']) . "'" : "NULL";
        $criador = $db->real_escape_string($_SESSION['username'] ?? 'anon');
        $cor = $tipos_eventos[$tipo]['cor'] ?? 'red';
        
        // Verificar se é recorrente (apenas para aulas)
        $semanas_recorrencia = 1; // Por padrão, apenas 1 semana (o evento atual)
        if ($tipo === 'aulas' && isset($_POST['semanas_recorrencia']) && !empty($_POST['semanas_recorrencia'])) {
            $semanas_recorrencia = max(1, min(52, (int)$_POST['semanas_recorrencia']));
        }
        
        // Inserir o evento inicial e os recorrentes
        $data_base = new DateTime($data);
        for ($i = 0; $i < $semanas_recorrencia; $i++) {
            $data_atual = clone $data_base;
            $data_atual->modify("+$i weeks");
            $data_atual_str = $data_atual->format('Y-m-d');
            
            $query = "INSERT INTO calendar_eventos (data, tipo, descricao, hora, criador, cor) 
                      VALUES ('$data_atual_str', '$tipo', '$descricao', $hora, '$criador', '$cor')";
            
            if (!$db->query($query)) {
                error_log("Erro ao inserir evento recorrente (semana $i): " . $db->error);
            }
        }
        
    } elseif (isset($_POST['delete'])) {
        $id = (int)$_POST['delete'];
        $query = "DELETE FROM calendar_eventos WHERE id = $id";
        $db->query($query);
    }
    
    // Preservar estado do refresh com JavaScript
    $queryParams = "tab=calendar&offset=$offset";
    $queryParams .= "&js_refresh=true";
    
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
$eventos = [];
if (!empty($filtros)) {
    $placeholders = implode(',', array_fill(0, count($filtros), '?'));
    $stmt = $db->prepare("SELECT * FROM calendar_eventos WHERE tipo IN ($placeholders)");
    
    // Bind dos parâmetros dinamicamente
    $types = str_repeat('s', count($filtros));
    $stmt->bind_param($types, ...$filtros);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $eventos[] = $row;
    }
    $stmt->close();
}

$eventos_por_dia = [];
foreach ($eventos as $e) {
    $eventos_por_dia[$e['data']][] = $e;
}

// Buscar todos os eventos para a seção de resumo por categoria
$query_todos = "SELECT * FROM calendar_eventos ORDER BY data ASC";
$result_todos = $db->query($query_todos);
$todos_eventos = [];
while ($row = $result_todos->fetch_assoc()) {
    $todos_eventos[] = $row;
}

// Agrupar eventos por tipo
$eventos_por_tipo = [];
foreach ($todos_eventos as $evento) {
    if (!isset($eventos_por_tipo[$evento['tipo']])) {
        $eventos_por_tipo[$evento['tipo']] = [];
    }
    $eventos_por_tipo[$evento['tipo']][] = $evento;
}

// Função para construir URL com parâmetros atuais
function buildUrl($params = []) {
    global $offset, $js_refresh, $filtros, $numSemanas;
    
    $base = "?tab=calendar";
    
    if (isset($params['offset'])) {
        $base .= "&offset=" . $params['offset'];
    } elseif ($offset != 0) {
        $base .= "&offset=$offset";
    }
    
    $base .= "&js_refresh=true";
    
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

// Função para formatar data
function formatarData($data_str) {
    $data = new DateTime($data_str);
    return $data->format('d/m/Y (D)');
}

?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<style>
    .calendario { 
        display: grid; 
        grid-template-columns: 1fr 1fr 1fr 1fr 1fr 0.6fr 0.6fr; 
        gap: 5px; 
    }
    .dia { 
        border: 1px solid #ccc; 
        min-height: 180px; 
        padding: 5px; 
        position: relative; 
        background: #f9f9f9;
        box-sizing: border-box; 
    }
    .data { 
        font-weight: bold; 
        margin-bottom: 5px;
    }
    .evento { 
        font-size: 0.85em; 
        padding: 2px 4px; 
        margin-top: 2px; 
        border-radius: 4px; 
        color: white; 
        display: block; 
    }
    .evento-com-hora {
        font-weight: bold;
    }
    .fade { 
        opacity: 0; 
        transition: opacity 0.3s ease-in-out; 
    }
    .fade.show { 
        opacity: 1; 
    }
    .hoje { 
        background-color: #fff3cd !important; 
        border: 2px solid #ffc107; 
    }
    .fimsemana { 
        background-color: #f0f0f0; 
    }
    .navegacao {
        margin-bottom: 20px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 5px;
    }
    .botoes-rapidos {
        display: flex;
        gap: 5px;
        margin-top: 5px;
        flex-wrap: wrap;
    }
    .btn-rapido {
        font-size: 0.75em;
        padding: 2px 6px;
        border-radius: 3px;
        border: 1px solid;
    }
    .btn-ferias {
        background-color: #d4edda;
        border-color: #28a745;
        color: #155724;
    }
    .btn-ferias:hover {
        background-color: #28a745;
        color: white;
    }
    .btn-aulas {
        background-color: #e2e3e5;
        border-color: #6c757d;
        color: #383d41;
    }
    .btn-aulas:hover {
        background-color: #6c757d;
        color: white;
    }
    .resumo-eventos {
        margin-top: 40px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 5px;
    }
    .tipo-evento-lista {
        margin-bottom: 15px;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        overflow: hidden;
    }
    .tipo-evento-header {
        padding: 10px 15px;
        background: white;
        cursor: pointer;
        font-weight: bold;
    }
    .tipo-evento-header:hover {
        background: #f8f9fa;
    }
    .tipo-evento-content {
        display: none;
        padding: 10px 15px;
        background: white;
        border-top: 1px solid #dee2e6;
    }
    .tipo-evento-badge {
        display: inline-block;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        margin-right: 10px;
        vertical-align: middle;
    }
    .evento-item {
        padding: 8px 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .evento-item:last-child {
        border-bottom: none;
    }
    .evento-data {
        font-weight: bold;
        color: #495057;
        margin-right: 10px;
    }
    .evento-hora {
        color: #007bff;
        font-weight: bold;
        margin-left: 5px;
    }
    .evento-descricao {
        color: #6c757d;
    }
    .filter-panel {
        padding: 15px;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        margin-bottom: 15px;
    }
    .filter-item {
        display: inline-block;
        margin-right: 15px;
        margin-bottom: 10px;
    }
    .filter-color {
        display: inline-block;
        width: 15px;
        height: 15px;
        border-radius: 50%;
        margin-right: 5px;
        vertical-align: middle;
    }
    .filter-toggle {
        cursor: pointer;
        color: #007bff;
        text-decoration: none;
    }
    .filter-toggle:hover {
        text-decoration: underline;
    }
    .hora-input-group {
        display: none;
        margin-top: 5px;
    }
    .recorrencia-options {
        margin-top: 8px;
        padding: 8px;
        background: #f8f9fa;
        border-radius: 4px;
        border: 1px solid #dee2e6;
    }
    .form-check {
        margin-bottom: 0;
    }
    .form-check-input {
        cursor: pointer;
    }
    .form-check-label {
        cursor: pointer;
    }
    .btn-delete-inline {
        background: none;
        border: none;
        color: #dc3545;
        font-weight: bold;
        font-size: 0.9em;
        padding: 0;
        margin: 0 2px;
        cursor: pointer;
        vertical-align: baseline;
    }
    .btn-delete-inline:hover {
        color: #a02622;
        text-decoration: none;
    }
    .delete-form {
        display: inline;
        margin: 0;
        padding: 0;
    }
    .evento-agrupado {
        display: block;
    }
</style>

<div class="container-fluid">
    <h2>📅 Calendário de Eventos</h2>
    
    <div class="navegacao row">
        <div class="col-md-6">
            <a href="<?= buildUrl(['offset' => $offset - 7]) ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-chevron-left"></i> Semana Anterior
            </a>
            <a href="<?= buildUrl(['offset' => 0]) ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-calendar-today"></i> Hoje
            </a>
            <a href="<?= buildUrl(['offset' => $offset + 7]) ?>" class="btn btn-sm btn-outline-primary">
                Próxima Semana <i class="bi bi-chevron-right"></i>
            </a>
            
            <form method="get" class="d-inline-block ms-3">
                <input type="hidden" name="tab" value="calendar">
                <input type="hidden" name="offset" value="<?= $offset ?>">
                <input type="hidden" name="js_refresh" value="true">
                <label class="me-2">Semanas:</label>
                <select name="semanas" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
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
            <input type="hidden" name="js_refresh" value="true">
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
        <?php 
        $contador_formulario = 0;
        foreach ($datas as $data): 
            $data_str = $data->format('Y-m-d');
            $diaSemana = $data->format('N');
            $isHoje = $data->format('Y-m-d') === (new DateTime())->format('Y-m-d');
            $classeExtra = ($diaSemana == 6 || $diaSemana == 7) ? ' fimsemana' : '';
            $contador_formulario++;
        ?>
        <div class="dia<?= $isHoje ? ' hoje' : '' ?><?= $classeExtra ?>">
            <div class="data"><?= $data->format('D d/m/Y') ?></div>
            
            <?php if (isset($eventos_por_dia[$data_str])): ?>
                <?php 
                // Agrupar eventos por tipo
                $eventos_agrupados = [];
                foreach ($eventos_por_dia[$data_str] as $ev) {
                    $chave = $ev['tipo'];
                    if ($ev['tipo'] === 'aulas' && !empty($ev['hora'])) {
                        $chave = $ev['tipo'] . '_' . $ev['hora'];
                    }
                    if (!isset($eventos_agrupados[$chave])) {
                        $eventos_agrupados[$chave] = [
                            'tipo' => $ev['tipo'],
                            'cor' => $ev['cor'],
                            'hora' => $ev['hora'],
                            'items' => []
                        ];
                    }
                    $eventos_agrupados[$chave]['items'][] = [
                        'descricao' => $ev['descricao'],
                        'id' => $ev['id']
                    ];
                }
                
                foreach ($eventos_agrupados as $grupo): ?>
                    <div class="evento-agrupado mb-1">
                        <span class="evento <?= !empty($grupo['hora']) ? 'evento-com-hora' : '' ?>" style="background: <?= $grupo['cor'] ?>;">
                            <?php if (!empty($grupo['hora'])): ?>
                                <i class="bi bi-clock"></i> <?= date('H:i', strtotime($grupo['hora'])) ?> -
                            <?php endif; ?>
                            <?= htmlspecialchars($grupo['tipo']) ?>: 
                            <?php 
                            $nomes = [];
                            foreach ($grupo['items'] as $item) {
                                $nomes[] = htmlspecialchars($item['descricao']) . ' <form method="post" class="d-inline delete-form"><button type="submit" name="delete" value="' . $item['id'] . '" class="btn-delete-inline">[x]</button></form>';
                            }
                            echo implode(', ', $nomes);
                            ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Botões rápidos para férias e aulas -->
            <div class="botoes-rapidos">
                <button type="button" class="btn btn-rapido btn-ferias" onclick="adicionarRapido(this, '<?= $data_str ?>', 'ferias')">
                    <i class="bi bi-sun"></i> Férias
                </button>
                <button type="button" class="btn btn-rapido btn-aulas" onclick="adicionarRapido(this, '<?= $data_str ?>', 'aulas')">
                    <i class="bi bi-book"></i> Aulas
                </button>
            </div>
            
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2 w-100" onclick="toggleForm(this)">
                <i class="bi bi-plus"></i> Adicionar
            </button>
            
            <form method="post" class="mt-2 d-none">
                <input type="hidden" name="data" value="<?= $data_str ?>">
                <select name="tipo" class="form-select form-select-sm mb-1" onchange="toggleHoraInput(this)">
                    <?php foreach ($tipos_eventos as $codigo => $info): ?>
                        <option value="<?= $codigo ?>"><?= htmlspecialchars($info['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="descricao" placeholder="Descrição" class="form-control form-control-sm mb-1" required>
                <div class="hora-input-group" data-tipo="aulas">
                    <label class="form-label" style="font-size: 0.85em;">Hora:</label>
                    <input type="time" name="hora" class="form-control form-control-sm mb-1">
                    
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="recorrente-<?= $contador_formulario ?>" onchange="toggleRecorrencia(this)">
                        <label class="form-check-label" style="font-size: 0.85em;" for="recorrente-<?= $contador_formulario ?>">
                            Recorrente
                        </label>
                    </div>
                    
                    <div class="recorrencia-options" style="display: none;">
                        <label class="form-label" style="font-size: 0.85em;">Repetir por quantas semanas?</label>
                        <input type="number" name="semanas_recorrencia" class="form-control form-control-sm mb-1" min="1" max="52" value="4" placeholder="Nº de semanas">
                        <small class="text-muted" style="font-size: 0.75em;">Será criada uma aula por semana</small>
                    </div>
                </div>
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-check"></i> Adicionar
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Seção de Resumo de Eventos por Tipo -->
    <div class="resumo-eventos">
        <h3 class="mb-3">Lista de Eventos por Categoria</h3>
        
        <?php foreach ($tipos_eventos as $codigo => $info): 
            if (!isset($eventos_por_tipo[$codigo]) || empty($eventos_por_tipo[$codigo])) {
                continue;
            }
            
            usort($eventos_por_tipo[$codigo], function($a, $b) {
                return strcmp($a['data'], $b['data']);
            });
        ?>
            <div class="tipo-evento-lista">
                <div class="tipo-evento-header d-flex justify-content-between align-items-center" 
                     onclick="toggleEventoLista('<?= $codigo ?>')">
                    <span>
                        <span class="tipo-evento-badge" style="background-color: <?= $info['cor'] ?>;"></span>
                        <?= htmlspecialchars($info['nome']) ?>
                        <span class="badge bg-secondary"><?= count($eventos_por_tipo[$codigo]) ?></span>
                    </span>
                    <i class="bi bi-chevron-down" id="chevron-<?= $codigo ?>"></i>
                </div>
                <div class="tipo-evento-content" id="content-<?= $codigo ?>">
                    <?php 
                    // Agrupar por data
                    $eventos_por_data_tipo = [];
                    foreach ($eventos_por_tipo[$codigo] as $ev) {
                        $chave_data = $ev['data'];
                        if ($codigo === 'aulas' && !empty($ev['hora'])) {
                            $chave_data .= '_' . $ev['hora'];
                        }
                        if (!isset($eventos_por_data_tipo[$chave_data])) {
                            $eventos_por_data_tipo[$chave_data] = [
                                'data' => $ev['data'],
                                'hora' => $ev['hora'],
                                'items' => []
                            ];
                        }
                        $eventos_por_data_tipo[$chave_data]['items'][] = [
                            'descricao' => $ev['descricao'],
                            'id' => $ev['id']
                        ];
                    }
                    
                    foreach ($eventos_por_data_tipo as $grupo): ?>
                        <div class="evento-item">
                            <span class="evento-data"><?= formatarData($grupo['data']) ?></span>
                            <?php if (!empty($grupo['hora'])): ?>
                                <span class="evento-hora">
                                    <i class="bi bi-clock"></i> <?= date('H:i', strtotime($grupo['hora'])) ?>
                                </span>
                            <?php endif; ?>
                            <span class="evento-descricao">
                                <?php 
                                $nomes_resumo = [];
                                foreach ($grupo['items'] as $item) {
                                    $nomes_resumo[] = htmlspecialchars($item['descricao']);
                                }
                                echo implode(', ', $nomes_resumo);
                                ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function toggleForm(button) {
    const form = button.nextElementSibling;
    form.classList.toggle('d-none');
    button.classList.toggle('d-none');
    form.classList.add('fade');
    form.classList.add('show');
}

// Função para mostrar/ocultar opções de recorrência
function toggleRecorrencia(checkbox) {
    const form = checkbox.closest('form');
    const recorrenciaOptions = form.querySelector('.recorrencia-options');
    
    if (checkbox.checked) {
        recorrenciaOptions.style.display = 'block';
    } else {
        recorrenciaOptions.style.display = 'none';
    }
}

// Função para mostrar/ocultar campo de hora baseado no tipo de evento
function toggleHoraInput(selectElement) {
    const form = selectElement.closest('form');
    const horaGroup = form.querySelector('.hora-input-group');
    const tipoSelecionado = selectElement.value;
    
    if (tipoSelecionado === 'aulas') {
        horaGroup.style.display = 'block';
    } else {
        horaGroup.style.display = 'none';
    }
}

// Função para adicionar evento rápido (férias ou aulas)
function adicionarRapido(button, data, tipo) {
    const diaDiv = button.closest('.dia');
    const form = diaDiv.querySelector('form:not(.delete-form)');
    const toggleBtn = diaDiv.querySelector('button[onclick*="toggleForm"]');
    
    // Mostrar o formulário se estiver oculto
    if (form.classList.contains('d-none')) {
        form.classList.remove('d-none');
        if (toggleBtn) {
            toggleBtn.classList.add('d-none');
        }
    }
    
    // Preencher o tipo
    const selectTipo = form.querySelector('select[name="tipo"]');
    if (selectTipo) {
        selectTipo.value = tipo;
        // Trigger do evento change para mostrar campo de hora se necessário
        toggleHoraInput(selectTipo);
    }
    
    // Preencher descrição com username
    const inputDescricao = form.querySelector('input[name="descricao"]');
    if (inputDescricao) {
        if (tipo === 'ferias' || tipo === 'aulas') {
            const username = '<?= $_SESSION['username'] ?? 'user' ?>';
            inputDescricao.value = username;
        }
        // Focar e selecionar o texto
        setTimeout(() => {
            inputDescricao.focus();
            inputDescricao.select();
        }, 50);
    }
}

// Fechar formulários ao submeter
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form').forEach(f => {
        f.addEventListener('submit', () => {
            const parent = f.closest('.dia');
            if (parent) {
                const btn = parent.querySelector('button[type="button"]');
                f.classList.add('d-none');
                if (btn) btn.classList.remove('d-none');
            }
        });
    });
    
    // Verificar se o painel de filtros deve estar aberto
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

function toggleEventoLista(codigo) {
    const content = document.getElementById('content-' + codigo);
    const chevron = document.getElementById('chevron-' + codigo);
    
    if (content.style.display === 'block') {
        content.style.display = 'none';
        chevron.classList.replace('bi-chevron-up', 'bi-chevron-down');
        localStorage.removeItem('eventoLista-' + codigo);
    } else {
        content.style.display = 'block';
        chevron.classList.replace('bi-chevron-down', 'bi-chevron-up');
        localStorage.setItem('eventoLista-' + codigo, 'true');
    }
}
</script>

<?php
// Fechar conexão MySQL
$db->close();
?>