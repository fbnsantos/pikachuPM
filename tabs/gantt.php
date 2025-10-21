<?php
// tabs/gantt.php - Visualização Gantt das Sprints
// 
// Este arquivo exibe um diagrama de Gantt das sprints com:
// - Visualização temporal das sprints
// - Responsáveis por cada sprint
// - Estado das sprints (aberta, pausa, fechada)
// - Link para detalhes da sprint ao clicar

if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

include_once __DIR__ . '/../config.php';

// Conectar à base de dados com tratamento de erro
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Erro de conexão à base de dados: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// Verificar se a tabela sprints existe
function sprintTableExists($pdo) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE 'sprints'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

if (!sprintTableExists($pdo)) {
    echo '<div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            A tabela de sprints não existe. Por favor, acesse o módulo 
            <a href="?tab=sprints">Sprints</a> primeiro para criar as tabelas necessárias.
          </div>';
    return;
}

// Obter filtros
$show_closed = isset($_GET['show_closed']) && $_GET['show_closed'] === '1';
$filter_my_sprints = isset($_GET['filter_my_sprints']) && $_GET['filter_my_sprints'] === '1';
$filter_user_id = isset($_GET['filter_user_id']) && !empty($_GET['filter_user_id']) ? $_GET['filter_user_id'] : null;
$order_by = $_GET['order_by'] ?? 'inicio'; // 'inicio' ou 'fim'
$view_range = $_GET['view_range'] ?? 'mes'; // 'semana', 'mes', 'trimestre'
$current_user_id = $_SESSION['user_id'] ?? null;

// Obter lista de usuários para o filtro
try {
    $users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// Buscar sprints
try {
    $query = "
        SELECT s.*, 
               u.username as responsavel_nome,
               DATEDIFF(s.data_fim, CURDATE()) as dias_restantes
        FROM sprints s
        LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!$show_closed) {
        $query .= " AND s.estado != 'fechada'";
    }
    
    // Filtro: minhas sprints (tem prioridade sobre filtro de pessoa específica)
    if ($filter_my_sprints && $current_user_id) {
        $query .= " AND s.responsavel_id = ?";
        $params[] = $current_user_id;
    }
    // Filtro: sprints de pessoa específica
    elseif ($filter_user_id) {
        $query .= " AND s.responsavel_id = ?";
        $params[] = $filter_user_id;
    }
    
    // Ordenação: sprints sem datas aparecem no fim
    if ($order_by === 'fim') {
        $query .= " ORDER BY 
                    CASE WHEN s.data_fim IS NULL THEN 1 ELSE 0 END,
                    s.data_fim ASC,
                    s.created_at DESC";
    } else {
        $query .= " ORDER BY 
                    CASE WHEN s.data_inicio IS NULL THEN 1 ELSE 0 END,
                    s.data_inicio ASC,
                    s.created_at DESC";
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $sprints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estatísticas para cada sprint
    foreach ($sprints as &$sprint) {
        // Verificar se tem tasks associadas
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN t.estado = 'completada' THEN 1 ELSE 0 END) as completadas
                FROM sprint_tasks st
                JOIN todos t ON st.todo_id = t.id
                WHERE st.sprint_id = ?
            ");
            $stmt->execute([$sprint['id']]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $sprint['total_tasks'] = $stats['total'] ?? 0;
            $sprint['tasks_completadas'] = $stats['completadas'] ?? 0;
            $sprint['percentagem'] = $sprint['total_tasks'] > 0 
                ? round(($sprint['tasks_completadas'] / $sprint['total_tasks']) * 100) 
                : 0;
        } catch (PDOException $e) {
            $sprint['total_tasks'] = 0;
            $sprint['tasks_completadas'] = 0;
            $sprint['percentagem'] = 0;
        }
    }
    unset($sprint);
    
} catch (PDOException $e) {
    $sprints = [];
    $error_message = "Erro ao carregar sprints: " . $e->getMessage();
}

// Calcular período de visualização do Gantt
$today = new DateTime();
$min_date = null;
$max_date = null;

foreach ($sprints as $sprint) {
    if ($sprint['data_inicio']) {
        $inicio = new DateTime($sprint['data_inicio']);
        if ($min_date === null || $inicio < $min_date) {
            $min_date = clone $inicio;
        }
    }
    if ($sprint['data_fim']) {
        $fim = new DateTime($sprint['data_fim']);
        if ($max_date === null || $fim > $max_date) {
            $max_date = clone $fim;
        }
    }
}

// Se não houver datas, usar período padrão
if ($min_date === null) {
    $min_date = clone $today;
    switch ($view_range) {
        case 'semana':
            $min_date->modify('-1 week');
            break;
        case 'trimestre':
            $min_date->modify('-1 month');
            break;
        default: // mes
            $min_date->modify('-2 weeks');
    }
}
if ($max_date === null) {
    $max_date = clone $today;
    switch ($view_range) {
        case 'semana':
            $max_date->modify('+2 weeks');
            break;
        case 'trimestre':
            $max_date->modify('+3 months');
            break;
        default: // mes
            $max_date->modify('+6 weeks');
    }
}

// Adicionar margem ao período baseado no view_range
switch ($view_range) {
    case 'semana':
        $min_date->modify('-3 days');
        $max_date->modify('+3 days');
        break;
    case 'trimestre':
        $min_date->modify('-1 week');
        $max_date->modify('+1 week');
        break;
    default: // mes
        $min_date->modify('-1 week');
        $max_date->modify('+1 week');
}

// Calcular número de dias
$interval = $min_date->diff($max_date);
$total_days = $interval->days;
?>

<style>
.gantt-container {
    background: white;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.gantt-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e9ecef;
}

.gantt-filters {
    display: flex;
    gap: 15px;
    align-items: center;
}

.gantt-chart {
    width: 100%;
    overflow-x: auto;
    overflow-y: visible;
}

.gantt-timeline {
    display: flex;
    position: relative;
    min-height: 50px;
    border-bottom: 2px solid #dee2e6;
    margin-bottom: 10px;
}

.gantt-timeline-month {
    flex: 1;
    text-align: center;
    font-weight: bold;
    padding: 10px 0;
    border-right: 1px solid #dee2e6;
    background: #f8f9fa;
}

.gantt-timeline-week {
    display: flex;
    width: 100%;
}

.gantt-timeline-day {
    flex: 1;
    text-align: center;
    padding: 5px 0;
    font-size: 0.85rem;
    border-right: 1px solid #e9ecef;
    position: relative;
}

.gantt-timeline-day.today {
    background: #fff3cd;
    font-weight: bold;
}

.gantt-timeline-day.weekend {
    background: #f8f9fa;
}

.gantt-rows {
    position: relative;
}

.gantt-row {
    display: flex;
    align-items: center;
    min-height: 60px;
    border-bottom: 1px solid #e9ecef;
    position: relative;
}

.gantt-row:hover {
    background: #f8f9fa;
}

.gantt-row-label {
    width: 250px;
    padding: 10px 15px;
    font-weight: 500;
    border-right: 2px solid #dee2e6;
    flex-shrink: 0;
}

.gantt-row-label-name {
    font-size: 1rem;
    margin-bottom: 5px;
    color: #212529;
}

.gantt-row-label-info {
    font-size: 0.85rem;
    color: #6c757d;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.gantt-row-timeline {
    flex: 1;
    position: relative;
    height: 60px;
    display: flex;
}

.gantt-bar {
    position: absolute;
    height: 36px;
    top: 12px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    padding: 0 10px;
    color: white;
    font-size: 0.85rem;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.gantt-bar:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    z-index: 10;
}

.gantt-bar.dragging {
    opacity: 0.7;
    cursor: move;
}

.gantt-bar-resize-handle {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 8px;
    cursor: ew-resize;
    z-index: 2;
}

.gantt-bar-resize-handle.left {
    left: 0;
    border-left: 3px solid rgba(255,255,255,0.5);
}

.gantt-bar-resize-handle.right {
    right: 0;
    border-right: 3px solid rgba(255,255,255,0.5);
}

.gantt-bar-resize-handle:hover {
    background: rgba(255,255,255,0.2);
}

.gantt-no-dates {
    padding: 10px;
    text-align: center;
    color: #6c757d;
    font-style: italic;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.gantt-no-dates-btn {
    padding: 4px 12px;
    font-size: 0.85rem;
    background: #0d6efd;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s;
}

.gantt-no-dates-btn:hover {
    background: #0b5ed7;
}

.date-picker-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.date-picker-modal.show {
    display: flex;
}

.date-picker-content {
    background: white;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    max-width: 400px;
    width: 90%;
}

.date-picker-header {
    font-size: 1.2rem;
    font-weight: bold;
    margin-bottom: 20px;
    color: #212529;
}

.date-picker-body {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.date-picker-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.date-picker-field label {
    font-weight: 500;
    color: #495057;
}

.date-picker-field input {
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 0.95rem;
}

.date-picker-footer {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    justify-content: flex-end;
}

.date-picker-footer button {
    padding: 8px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.95rem;
    transition: background 0.2s;
}

.date-picker-footer .btn-cancel {
    background: #6c757d;
    color: white;
}

.date-picker-footer .btn-cancel:hover {
    background: #5a6268;
}

.date-picker-footer .btn-save {
    background: #28a745;
    color: white;
}

.date-picker-footer .btn-save:hover {
    background: #218838;
}

.date-picker-footer .btn-save:disabled {
    background: #6c757d;
    cursor: not-allowed;
    opacity: 0.6;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.3);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}

.loading-overlay.show {
    display: flex;
}

.loading-spinner {
    background: white;
    padding: 30px;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.loading-spinner .spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #0d6efd;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto 15px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.gantt-bar.estado-aberta {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
}

.gantt-bar.estado-pausa {
    background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
}

.gantt-bar.estado-fechada {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
}

.gantt-bar-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    gap: 8px;
}

.gantt-bar-progress {
    font-size: 0.75rem;
    background: rgba(255,255,255,0.3);
    padding: 2px 6px;
    border-radius: 3px;
}

.gantt-today-line {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dc3545;
    z-index: 5;
    pointer-events: none;
}

.gantt-today-label {
    position: absolute;
    top: -25px;
    left: -20px;
    background: #dc3545;
    color: white;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 0.75rem;
    font-weight: bold;
    white-space: nowrap;
}

.gantt-legend {
    display: flex;
    gap: 20px;
    margin-top: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 4px;
}

.gantt-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
}

.gantt-legend-color {
    width: 20px;
    height: 20px;
    border-radius: 3px;
}

.gantt-empty {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}

.gantt-grid {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    pointer-events: none;
}

.gantt-grid-line {
    flex: 1;
    border-right: 1px solid #f0f0f0;
}

.sprint-tooltip {
    position: absolute;
    background: #212529;
    color: white;
    padding: 10px;
    border-radius: 4px;
    font-size: 0.85rem;
    z-index: 1000;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.2s;
    max-width: 300px;
}

.sprint-tooltip.show {
    opacity: 1;
}

@media (max-width: 768px) {
    .gantt-row-label {
        width: 180px;
    }
    
    .gantt-filters {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}
</style>

<div class="container-fluid">
    <div class="gantt-container">
        <div class="gantt-header">
            <div>
                <h2 class="mb-0">
                    <i class="bi bi-calendar-range"></i> Gantt de Sprints
                </h2>
                <p class="text-muted mb-0 mt-1">
                    Visualização temporal das sprints - Arraste para mover, redimensione pelas bordas
                </p>
            </div>
            
            <div class="gantt-filters">
                <div class="row g-2">
                    <div class="col-auto">
                        <select class="form-select form-select-sm" id="viewRange" onchange="updateFilters()">
                            <option value="semana" <?= $view_range === 'semana' ? 'selected' : '' ?>>📅 Semana</option>
                            <option value="mes" <?= $view_range === 'mes' ? 'selected' : '' ?>>📅 Mês</option>
                            <option value="trimestre" <?= $view_range === 'trimestre' ? 'selected' : '' ?>>📅 Trimestre</option>
                        </select>
                    </div>
                    
                    <div class="col-auto">
                        <select class="form-select form-select-sm" id="orderBy" onchange="updateFilters()">
                            <option value="inicio" <?= $order_by === 'inicio' ? 'selected' : '' ?>>Ordenar por Início</option>
                            <option value="fim" <?= $order_by === 'fim' ? 'selected' : '' ?>>Ordenar por Fim</option>
                        </select>
                    </div>
                    
                    <div class="col-auto">
                        <select class="form-select form-select-sm" id="filterUser" onchange="updateFilters()">
                            <option value="">👥 Todos os responsáveis</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>" <?= $filter_user_id == $user['user_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-auto">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="filterMySprints" 
                                   <?= $filter_my_sprints ? 'checked' : '' ?>
                                   onchange="updateFilters()">
                            <label class="form-check-label" for="filterMySprints">
                                Apenas minhas
                            </label>
                        </div>
                    </div>
                    
                    <div class="col-auto">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="showClosedSprints" 
                                   <?= $show_closed ? 'checked' : '' ?>
                                   onchange="updateFilters()">
                            <label class="form-check-label" for="showClosedSprints">
                                Fechadas
                            </label>
                        </div>
                    </div>
                    
                    <div class="col-auto">
                        <a href="?tab=sprints" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-list-task"></i> Ver Lista
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php elseif (empty($sprints)): ?>
            <div class="gantt-empty">
                <i class="bi bi-calendar-x" style="font-size: 3rem; color: #dee2e6;"></i>
                <h4 class="mt-3">Nenhuma sprint encontrada</h4>
                <p>Crie uma nova sprint no módulo de Sprints para visualizá-la aqui.</p>
                <a href="?tab=sprints" class="btn btn-primary mt-2">
                    <i class="bi bi-plus-circle"></i> Ir para Sprints
                </a>
            </div>
        <?php else: ?>
            <div class="gantt-chart">
                <!-- Timeline Header -->
                <div class="gantt-timeline">
                    <div style="width: 250px; flex-shrink: 0; border-right: 2px solid #dee2e6; background: #f8f9fa;"></div>
                    <div style="flex: 1; display: flex;">
                        <?php
                        $current_date = clone $min_date;
                        $day_width = 100 / $total_days;
                        
                        while ($current_date <= $max_date) {
                            $is_weekend = in_array($current_date->format('N'), [6, 7]);
                            $is_today = $current_date->format('Y-m-d') === $today->format('Y-m-d');
                            $classes = ['gantt-timeline-day'];
                            if ($is_weekend) $classes[] = 'weekend';
                            if ($is_today) $classes[] = 'today';
                            
                            echo '<div class="' . implode(' ', $classes) . '" style="width: ' . $day_width . '%;">';
                            echo '<div style="font-size: 0.75rem;">' . $current_date->format('d') . '</div>';
                            if ($current_date->format('d') == '01' || $current_date == $min_date) {
                                $months_pt = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
                                $month_index = (int)$current_date->format('n') - 1;
                                echo '<div style="font-size: 0.7rem; color: #6c757d;">' . 
                                     $months_pt[$month_index] . '</div>';
                            }
                            echo '</div>';
                            
                            $current_date->modify('+1 day');
                        }
                        ?>
                    </div>
                </div>
                
                <!-- Gantt Rows -->
                <div class="gantt-rows">
                    <?php foreach ($sprints as $sprint): ?>
                        <div class="gantt-row">
                            <div class="gantt-row-label">
                                <div class="gantt-row-label-name">
                                    <?= htmlspecialchars($sprint['nome']) ?>
                                </div>
                                <div class="gantt-row-label-info">
                                    <span>
                                        <i class="bi bi-person"></i>
                                        <?= htmlspecialchars($sprint['responsavel_nome'] ?? 'Sem responsável') ?>
                                    </span>
                                    <?php if ($sprint['total_tasks'] > 0): ?>
                                        <span>
                                            <i class="bi bi-check-circle"></i>
                                            <?= $sprint['tasks_completadas'] ?>/<?= $sprint['total_tasks'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="gantt-row-timeline">
                                <!-- Grid de fundo -->
                                <div class="gantt-grid">
                                    <?php
                                    $current_date = clone $min_date;
                                    while ($current_date <= $max_date) {
                                        echo '<div class="gantt-grid-line" style="width: ' . $day_width . '%;"></div>';
                                        $current_date->modify('+1 day');
                                    }
                                    ?>
                                </div>
                                
                                <?php if ($sprint['data_inicio'] && $sprint['data_fim']): ?>
                                    <?php
                                    $sprint_inicio = new DateTime($sprint['data_inicio']);
                                    $sprint_fim = new DateTime($sprint['data_fim']);
                                    
                                    // Calcular posição e largura da barra
                                    $days_from_start = $min_date->diff($sprint_inicio)->days;
                                    $sprint_duration = $sprint_inicio->diff($sprint_fim)->days + 1;
                                    
                                    $left_percent = ($days_from_start / $total_days) * 100;
                                    $width_percent = ($sprint_duration / $total_days) * 100;
                                    
                                    $estado_class = 'estado-' . strtolower($sprint['estado']);
                                    ?>
                                    
                                    <div class="gantt-bar <?= $estado_class ?>"
                                         style="left: <?= $left_percent ?>%; width: <?= $width_percent ?>%;"
                                         data-sprint-id="<?= $sprint['id'] ?>"
                                         data-sprint-nome="<?= htmlspecialchars($sprint['nome']) ?>"
                                         data-sprint-inicio="<?= $sprint['data_inicio'] ?>"
                                         data-sprint-fim="<?= $sprint['data_fim'] ?>"
                                         data-sprint-estado="<?= ucfirst($sprint['estado']) ?>"
                                         data-sprint-responsavel="<?= htmlspecialchars($sprint['responsavel_nome'] ?? 'N/A') ?>"
                                         data-sprint-progresso="<?= $sprint['percentagem'] ?>%">
                                        
                                        <div class="gantt-bar-resize-handle left" data-direction="left"></div>
                                        
                                        <div class="gantt-bar-content">
                                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= htmlspecialchars($sprint['nome']) ?>
                                            </span>
                                            <?php if ($sprint['total_tasks'] > 0): ?>
                                                <span class="gantt-bar-progress">
                                                    <?= $sprint['percentagem'] ?>%
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="gantt-bar-resize-handle right" data-direction="right"></div>
                                    </div>
                                <?php else: ?>
                                    <!-- Sprint sem datas - mostrar botão para definir -->
                                    <div class="gantt-no-dates">
                                        <i class="bi bi-calendar-x"></i>
                                        <span>Sem datas definidas</span>
                                        <button class="gantt-no-dates-btn" onclick="openDatePicker(<?= $sprint['id'] ?>, '<?= htmlspecialchars($sprint['nome']) ?>')">
                                            <i class="bi bi-plus-circle"></i> Definir Datas
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Linha do dia atual -->
                    <?php
                    $days_from_start_today = $min_date->diff($today)->days;
                    $today_percent = ($days_from_start_today / $total_days) * 100;
                    ?>
                    <div class="gantt-today-line" style="left: calc(250px + <?= $today_percent ?>%);">
                        <div class="gantt-today-label">HOJE</div>
                    </div>
                </div>
            </div>
            
            <!-- Legenda -->
            <div class="gantt-legend">
                <div class="gantt-legend-item">
                    <div class="gantt-legend-color" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);"></div>
                    <span>Aberta</span>
                </div>
                <div class="gantt-legend-item">
                    <div class="gantt-legend-color" style="background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);"></div>
                    <span>Em Pausa</span>
                </div>
                <div class="gantt-legend-item">
                    <div class="gantt-legend-color" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);"></div>
                    <span>Fechada</span>
                </div>
                <div class="gantt-legend-item" style="margin-left: auto;">
                    <i class="bi bi-info-circle"></i>
                    <span>Clique em uma sprint para ver detalhes</span>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal para definir datas -->
<div id="datePickerModal" class="date-picker-modal">
    <div class="date-picker-content">
        <div class="date-picker-header">
            <i class="bi bi-calendar-plus"></i> Definir Datas da Sprint
        </div>
        <div id="datePickerSprintName" style="color: #6c757d; margin-bottom: 15px; font-size: 0.9rem;"></div>
        <div class="date-picker-body">
            <div class="date-picker-field">
                <label for="sprintStartDate">
                    <i class="bi bi-calendar-event"></i> Data de Início
                </label>
                <input type="date" id="sprintStartDate" required>
            </div>
            <div class="date-picker-field">
                <label for="sprintEndDate">
                    <i class="bi bi-calendar-check"></i> Data de Término
                </label>
                <input type="date" id="sprintEndDate" required>
            </div>
        </div>
        <div class="date-picker-footer">
            <button class="btn-cancel" onclick="closeDatePicker()">
                <i class="bi bi-x-circle"></i> Cancelar
            </button>
            <button class="btn-save" id="saveDatesBtn" onclick="saveDates()">
                <i class="bi bi-check-circle"></i> Salvar
            </button>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner">
        <div class="spinner"></div>
        <div>Salvando datas...</div>
    </div>
</div>

<!-- Tooltip para mostrar informações ao passar o mouse -->
<div id="sprintTooltip" class="sprint-tooltip"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tooltip = document.getElementById('sprintTooltip');
    const ganttBars = document.querySelectorAll('.gantt-bar');
    
    // ===== FUNÇÃO SHOWNOTIFICATION =====
    window.showNotification = function(message, type = 'info') {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.style.position = 'fixed';
        alert.style.top = '20px';
        alert.style.right = '20px';
        alert.style.zIndex = '9999';
        alert.style.minWidth = '300px';
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alert);
        
        setTimeout(() => {
            alert.remove();
        }, 5000);
    };
    
    // ===== TOOLTIP =====
    ganttBars.forEach(bar => {
        bar.addEventListener('mouseenter', function(e) {
            if (this.classList.contains('dragging')) return;
            
            const nome = this.dataset.sprintNome;
            const inicio = this.dataset.sprintInicio;
            const fim = this.dataset.sprintFim;
            const estado = this.dataset.sprintEstado;
            const responsavel = this.dataset.sprintResponsavel;
            const progresso = this.dataset.sprintProgresso;
            
            tooltip.innerHTML = `
                <strong>${nome}</strong><br>
                <small>
                    <i class="bi bi-calendar"></i> ${formatDate(inicio)} - ${formatDate(fim)}<br>
                    <i class="bi bi-flag"></i> Estado: ${estado}<br>
                    <i class="bi bi-person"></i> Responsável: ${responsavel}<br>
                    <i class="bi bi-graph-up"></i> Progresso: ${progresso}
                </small>
            `;
            
            tooltip.classList.add('show');
            updateTooltipPosition(e);
        });
        
        bar.addEventListener('mousemove', function(e) {
            if (!this.classList.contains('dragging')) {
                updateTooltipPosition(e);
            }
        });
        
        bar.addEventListener('mouseleave', function() {
            tooltip.classList.remove('show');
        });
    });
    
    function updateTooltipPosition(e) {
        const x = e.clientX;
        const y = e.clientY;
        
        tooltip.style.left = (x + 15) + 'px';
        tooltip.style.top = (y + 15) + 'px';
    }
    
    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        const date = new Date(dateStr + 'T00:00:00');
        return date.toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });
    }
    
    // ===== DRAG & DROP E RESIZE =====
    const minDate = new Date('<?= $min_date->format('Y-m-d') ?>');
    const totalDays = <?= $total_days ?>;
    
    let isDragging = false;
    let isResizing = false;
    let resizeDirection = null;
    let currentBar = null;
    let startX = 0;
    let startLeft = 0;
    let startWidth = 0;
    let timelineRect = null;
    
    ganttBars.forEach(bar => {
        // Click para abrir detalhes (apenas se não estiver arrastando)
        bar.addEventListener('click', function(e) {
            if (!isDragging && !isResizing && !e.target.classList.contains('gantt-bar-resize-handle')) {
                window.location.href = '?tab=sprints&sprint_id=' + this.dataset.sprintId;
            }
        });
        
        // Drag da barra inteira
        bar.addEventListener('mousedown', function(e) {
            if (e.target.classList.contains('gantt-bar-resize-handle')) return;
            
            isDragging = true;
            currentBar = this;
            startX = e.clientX;
            startLeft = parseFloat(this.style.left);
            
            const timeline = this.closest('.gantt-row-timeline');
            timelineRect = timeline.getBoundingClientRect();
            
            this.classList.add('dragging');
            tooltip.classList.remove('show');
            
            e.preventDefault();
        });
        
        // Resize handles
        const handles = bar.querySelectorAll('.gantt-bar-resize-handle');
        handles.forEach(handle => {
            handle.addEventListener('mousedown', function(e) {
                isResizing = true;
                resizeDirection = this.dataset.direction;
                currentBar = this.closest('.gantt-bar');
                startX = e.clientX;
                startLeft = parseFloat(currentBar.style.left);
                startWidth = parseFloat(currentBar.style.width);
                
                const timeline = currentBar.closest('.gantt-row-timeline');
                timelineRect = timeline.getBoundingClientRect();
                
                currentBar.classList.add('dragging');
                tooltip.classList.remove('show');
                
                e.stopPropagation();
                e.preventDefault();
            });
        });
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging && !isResizing) return;
        
        const deltaX = e.clientX - startX;
        const deltaPercent = (deltaX / timelineRect.width) * 100;
        
        if (isDragging) {
            // Mover a barra
            let newLeft = startLeft + deltaPercent;
            newLeft = Math.max(0, Math.min(100 - parseFloat(currentBar.style.width), newLeft));
            currentBar.style.left = newLeft + '%';
            
        } else if (isResizing) {
            // Redimensionar a barra
            if (resizeDirection === 'left') {
                let newLeft = startLeft + deltaPercent;
                let newWidth = startWidth - deltaPercent;
                
                if (newLeft >= 0 && newWidth >= 2) {
                    currentBar.style.left = newLeft + '%';
                    currentBar.style.width = newWidth + '%';
                }
            } else if (resizeDirection === 'right') {
                let newWidth = startWidth + deltaPercent;
                let maxWidth = 100 - startLeft;
                
                if (newWidth >= 2 && newWidth <= maxWidth) {
                    currentBar.style.width = newWidth + '%';
                }
            }
        }
        
        e.preventDefault();
    });
    
    document.addEventListener('mouseup', function(e) {
        if (isDragging || isResizing) {
            if (currentBar) {
                currentBar.classList.remove('dragging');
                
                // Calcular novas datas
                const left = parseFloat(currentBar.style.left);
                const width = parseFloat(currentBar.style.width);
                
                const startDays = Math.round((left / 100) * totalDays);
                const durationDays = Math.round((width / 100) * totalDays);
                
                const newStartDate = new Date(minDate);
                newStartDate.setDate(newStartDate.getDate() + startDays);
                
                const newEndDate = new Date(newStartDate);
                newEndDate.setDate(newEndDate.getDate() + durationDays - 1);
                
                const sprintId = currentBar.dataset.sprintId;
                const startDateStr = newStartDate.toISOString().split('T')[0];
                const endDateStr = newEndDate.toISOString().split('T')[0];
                
                // Atualizar no servidor
                updateSprintDates(sprintId, startDateStr, endDateStr);
            }
            
            isDragging = false;
            isResizing = false;
            resizeDirection = null;
            currentBar = null;
        }
    });
    
    function updateSprintDates(sprintId, startDate, endDate) {
        const formData = new FormData();
        formData.append('action', 'update_sprint_dates');
        formData.append('sprint_id', sprintId);
        formData.append('data_inicio', startDate);
        formData.append('data_fim', endDate);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // Atualizar os dados da barra
            const bar = document.querySelector(`[data-sprint-id="${sprintId}"]`);
            if (bar) {
                bar.dataset.sprintInicio = startDate;
                bar.dataset.sprintFim = endDate;
            }
            
            // Mostrar notificação de sucesso
            showNotification('Datas atualizadas com sucesso!', 'success');
        })
        .catch(error => {
            console.error('Erro ao atualizar:', error);
            showNotification('Erro ao atualizar as datas', 'danger');
            // Recarregar a página em caso de erro
            setTimeout(() => location.reload(), 1500);
        });
    }
    
    function showNotification(message, type) {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.style.position = 'fixed';
        alert.style.top = '20px';
        alert.style.right = '20px';
        alert.style.zIndex = '9999';
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alert);
        
        setTimeout(() => {
            alert.remove();
        }, 3000);
    }
});

// Função para atualizar filtros
function updateFilters() {
    const viewRange = document.getElementById('viewRange').value;
    const orderBy = document.getElementById('orderBy').value;
    const filterUser = document.getElementById('filterUser').value;
    const filterMy = document.getElementById('filterMySprints').checked ? '1' : '0';
    const showClosed = document.getElementById('showClosedSprints').checked ? '1' : '0';
    
    let url = `?tab=gantt&view_range=${viewRange}&order_by=${orderBy}&filter_my_sprints=${filterMy}&show_closed=${showClosed}`;
    
    if (filterUser) {
        url += `&filter_user_id=${filterUser}`;
    }
    
    window.location.href = url;
}

// Variável global para armazenar o ID da sprint sendo editada
let currentEditingSprintId = null;

// Função para abrir o modal de definir datas
function openDatePicker(sprintId, sprintName) {
    currentEditingSprintId = sprintId;
    document.getElementById('datePickerSprintName').textContent = sprintName;
    
    // Definir data de início como hoje e término como daqui a 2 semanas
    const today = new Date();
    const twoWeeksLater = new Date(today);
    twoWeeksLater.setDate(twoWeeksLater.getDate() + 14);
    
    document.getElementById('sprintStartDate').value = today.toISOString().split('T')[0];
    document.getElementById('sprintEndDate').value = twoWeeksLater.toISOString().split('T')[0];
    
    document.getElementById('datePickerModal').classList.add('show');
}

// Função para fechar o modal
function closeDatePicker() {
    document.getElementById('datePickerModal').classList.remove('show');
    currentEditingSprintId = null;
}

// Função para salvar as datas
function saveDates() {
    const startDate = document.getElementById('sprintStartDate').value;
    const endDate = document.getElementById('sprintEndDate').value;
    const saveBtn = document.getElementById('saveDatesBtn');
    
    if (!startDate || !endDate) {
        alert('Por favor, preencha ambas as datas.');
        return;
    }
    
    if (new Date(startDate) > new Date(endDate)) {
        alert('A data de início não pode ser posterior à data de término.');
        return;
    }
    
    // Desabilitar botão e mostrar loading
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvando...';
    
    // Fechar modal
    closeDatePicker();
    
    // Mostrar overlay de loading
    document.getElementById('loadingOverlay').classList.add('show');
    
    console.log('📤 Enviando requisição para atualizar datas...');
    console.log('Sprint ID:', currentEditingSprintId);
    console.log('Data início:', startDate);
    console.log('Data fim:', endDate);
    
    // Atualizar no servidor
    const formData = new FormData();
    formData.append('action', 'update_sprint_dates');
    formData.append('sprint_id', currentEditingSprintId);
    formData.append('data_inicio', startDate);
    formData.append('data_fim', endDate);
    
    fetch('gantt_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('📥 Resposta recebida. Status:', response.status);
        if (!response.ok) {
            throw new Error('Erro na resposta do servidor: ' + response.status);
        }
        return response.text();
    })
    .then(text => {
        console.log('📄 Conteúdo da resposta (primeiros 500 chars):', text.substring(0, 500));
        
        // Tentar fazer parse do JSON
        try {
            const data = JSON.parse(text);
            console.log('✅ JSON parseado com sucesso:', data);
            
            if (data.success) {
                console.log('🎉 Sucesso! Recarregando página...');
                // Sucesso - recarregar página
                setTimeout(() => {
                    window.location.reload(true);
                }, 300);
            } else {
                // Erro retornado pelo servidor
                console.error('❌ Servidor retornou erro:', data.message);
                document.getElementById('loadingOverlay').classList.remove('show');
                showNotification('Erro ao salvar: ' + (data.message || 'Erro desconhecido'), 'danger');
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> Salvar';
            }
        } catch (e) {
            // Resposta não é JSON válido
            console.error('❌ Erro ao fazer parse do JSON:', e);
            console.error('❌ Resposta completa:', text);
            
            // Verificar se a resposta contém HTML (sinal de que algo deu errado)
            if (text.includes('<!DOCTYPE') || text.includes('<html')) {
                console.error('❌ Servidor retornou HTML em vez de JSON!');
                document.getElementById('loadingOverlay').classList.remove('show');
                showNotification('Erro: Servidor retornou HTML em vez de JSON. Verifique os logs.', 'danger');
            } else {
                throw new Error('Resposta inválida do servidor');
            }
            
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> Salvar';
        }
    })
    .catch(error => {
        console.error('❌ Erro na requisição:', error);
        document.getElementById('loadingOverlay').classList.remove('show');
        
        // Mostrar mensagem e recarregar para verificar se salvou
        showNotification('Erro: ' + error.message + '. Verifique o console para mais detalhes.', 'danger');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> Salvar';
    });
}

// Fechar modal ao clicar fora
document.addEventListener('click', function(e) {
    const modal = document.getElementById('datePickerModal');
    if (e.target === modal) {
        closeDatePicker();
    }
});

// Fechar modal com ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDatePicker();
    }
});
</script>