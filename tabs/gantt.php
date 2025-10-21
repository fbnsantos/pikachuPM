<?php
/**
 * gantt.php - Visualização Gantt das Sprints
 * 
 * Este arquivo exibe um diagrama de Gantt das sprints com:
 * - Visualização temporal das sprints
 * - Responsáveis por cada sprint
 * - Estado das sprints (aberta, pausa, fechada)
 * - Link para detalhes da sprint ao clicar
 */

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
$current_user_id = $_SESSION['user_id'] ?? null;

// Buscar sprints
try {
    $query = "
        SELECT s.*, 
               u.username as responsavel_nome,
               DATEDIFF(s.data_fim, CURDATE()) as dias_restantes
        FROM sprints s
        LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id
    ";
    
    if (!$show_closed) {
        $query .= " WHERE s.estado != 'fechada'";
    }
    
    $query .= " ORDER BY s.data_inicio ASC, s.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
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
    $min_date->modify('-1 month');
}
if ($max_date === null) {
    $max_date = clone $today;
    $max_date->modify('+2 months');
}

// Adicionar margem ao período
$min_date->modify('-1 week');
$max_date->modify('+1 week');

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
                    Visualização temporal das sprints
                </p>
            </div>
            
            <div class="gantt-filters">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="showClosedSprints" 
                           <?= $show_closed ? 'checked' : '' ?>
                           onchange="window.location.href='?tab=gantt&show_closed=' + (this.checked ? '1' : '0')">
                    <label class="form-check-label" for="showClosedSprints">
                        Mostrar sprints fechadas
                    </label>
                </div>
                
                <a href="?tab=sprints" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-list-task"></i> Ver Lista de Sprints
                </a>
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
                                         onclick="window.location.href='?tab=sprints&sprint_id=<?= $sprint['id'] ?>'"
                                         data-sprint-id="<?= $sprint['id'] ?>"
                                         data-sprint-nome="<?= htmlspecialchars($sprint['nome']) ?>"
                                         data-sprint-inicio="<?= $sprint['data_inicio'] ?>"
                                         data-sprint-fim="<?= $sprint['data_fim'] ?>"
                                         data-sprint-estado="<?= ucfirst($sprint['estado']) ?>"
                                         data-sprint-responsavel="<?= htmlspecialchars($sprint['responsavel_nome'] ?? 'N/A') ?>"
                                         data-sprint-progresso="<?= $sprint['percentagem'] ?>%">
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

<!-- Tooltip para mostrar informações ao passar o mouse -->
<div id="sprintTooltip" class="sprint-tooltip"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tooltip = document.getElementById('sprintTooltip');
    const ganttBars = document.querySelectorAll('.gantt-bar');
    
    ganttBars.forEach(bar => {
        bar.addEventListener('mouseenter', function(e) {
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
        
        bar.addEventListener('mousemove', updateTooltipPosition);
        
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
});
</script>