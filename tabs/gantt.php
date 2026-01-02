<?php
// tabs/gantt.php - Visualização Gantt das Sprints e Entregáveis/PPS/KPIs
// 
// Este arquivo exibe diagramas de Gantt com:
// - Visualização temporal das sprints
// - Visualização temporal dos entregáveis/PPS/KPIs dos projetos
// - Responsáveis por cada sprint/entregável
// - Estado das sprints/entregáveis
// - Link para detalhes ao clicar
// - Controlo por barra lateral (esquerda)

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

// Função auxiliar para verificar se tabela existe
function tableExists($pdo, $tableName) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE '$tableName'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Verificar quais módulos estão disponíveis
$has_sprints = tableExists($pdo, 'sprints');
$has_projects = tableExists($pdo, 'projects') && tableExists($pdo, 'project_deliverables');
$has_prototypes = tableExists($pdo, 'prototypes') && tableExists($pdo, 'sprint_prototypes');

if (!$has_sprints && !$has_projects) {
    echo '<div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            Nem o módulo de Sprints nem o módulo de Projetos estão disponíveis. 
            Por favor, acesse primeiro o módulo <a href="?tab=sprints">Sprints</a> 
            ou <a href="?tab=projectos">Projetos</a>.
          </div>';
    return;
}

// Obter tipo de visualização (sprints ou deliverables)
$view_type = $_GET['view_type'] ?? 'sprints';
if (!$has_sprints) $view_type = 'deliverables';
if (!$has_projects) $view_type = 'sprints';

// Obter filtros gerais
$show_closed = isset($_GET['show_closed']) && $_GET['show_closed'] === '1';
$order_by = $_GET['order_by'] ?? 'inicio'; // 'inicio' ou 'fim'
$view_range = $_GET['view_range'] ?? 'mes'; // 'semana', 'mes', 'trimestre'
$current_user_id = $_SESSION['user_id'] ?? null;

// Obter lista de usuários para o filtro
try {
    $users = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// ============================================================================
// PROCESSAMENTO PARA VISTA DE SPRINTS
// ============================================================================
$sprints = [];
$prototipos = [];

if ($view_type === 'sprints' && $has_sprints) {
    // Filtros específicos de sprints
    $filter_my_sprints = isset($_GET['filter_my_sprints']) && $_GET['filter_my_sprints'] === '1';
    $filter_user_id = isset($_GET['filter_user_id']) && !empty($_GET['filter_user_id']) ? $_GET['filter_user_id'] : null;
    $filter_prototipo = isset($_GET['filter_prototipo']) && !empty($_GET['filter_prototipo']) ? $_GET['filter_prototipo'] : null;
    
    // Obter lista de protótipos únicos associados às sprints
    if ($has_prototypes) {
        try {
            $prototipos = $pdo->query("
                SELECT DISTINCT p.id, p.short_name, p.title
                FROM prototypes p
                INNER JOIN sprint_prototypes sp ON p.id = sp.prototype_id
                ORDER BY p.short_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar protótipos: " . $e->getMessage());
            $prototipos = [];
        }
    }
    
    // Buscar sprints
    try {
        $query = "
            SELECT s.*, 
                   u.username as responsavel_nome,
                   DATEDIFF(s.data_fim, CURDATE()) as dias_restantes
            FROM sprints s
            LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id
        ";
        
        if ($filter_prototipo && $has_prototypes) {
            $query .= "
            INNER JOIN sprint_prototypes sp ON s.id = sp.sprint_id
            ";
        }
        
        $query .= " WHERE 1=1 ";
        
        $params = [];
        
        if (!$show_closed) {
            $query .= " AND s.estado != 'fechada'";
        }
        
        if ($filter_my_sprints && $current_user_id) {
            $query .= " AND s.responsavel_id = ?";
            $params[] = $current_user_id;
        } elseif ($filter_user_id) {
            $query .= " AND s.responsavel_id = ?";
            $params[] = $filter_user_id;
        }
        
        if ($filter_prototipo && $has_prototypes) {
            $query .= " AND sp.prototype_id = ?";
            $params[] = $filter_prototipo;
        }
        
        if ($order_by === 'fim') {
            $query .= " ORDER BY 
                        CASE WHEN s.data_fim IS NULL THEN 1 ELSE 0 END,
                        s.data_fim ASC,
                        s.created_at DESC";
        } elseif ($order_by === 'deadline') {
            // Ordenar por deadline mais próxima (dias restantes)
            $query .= " ORDER BY 
                        CASE 
                            WHEN s.data_fim IS NULL THEN 999999
                            WHEN DATEDIFF(s.data_fim, CURDATE()) < 0 THEN 999998
                            ELSE DATEDIFF(s.data_fim, CURDATE())
                        END ASC,
                        s.data_fim ASC";
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
}

// ============================================================================
// PROCESSAMENTO PARA VISTA DE ENTREGÁVEIS/PPS/KPIs
// ============================================================================
$deliverables = [];
$projects = [];

if ($view_type === 'deliverables' && $has_projects) {
    // Filtros específicos de entregáveis
    $filter_my_deliverables = isset($_GET['filter_my_deliverables']) && $_GET['filter_my_deliverables'] === '1';
    $filter_project = isset($_GET['filter_project']) && !empty($_GET['filter_project']) ? $_GET['filter_project'] : null;
    
    // Obter lista de projetos
    try {
        $projects = $pdo->query("
            SELECT id, short_name, title
            FROM projects
            ORDER BY short_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erro ao buscar projetos: " . $e->getMessage());
        $projects = [];
    }
    
    // Buscar entregáveis
    try {
        $query = "
            SELECT 
                pd.*,
                p.short_name as project_short_name,
                p.title as project_title,
                u.username as owner_name,
                DATEDIFF(pd.due_date, CURDATE()) as dias_restantes
            FROM project_deliverables pd
            INNER JOIN projects p ON pd.project_id = p.id
            LEFT JOIN user_tokens u ON p.owner_id = u.user_id
            WHERE 1=1
        ";
        
        $params = [];
        
        if (!$show_closed) {
            $query .= " AND pd.status != 'completed'";
        }
        
        if ($filter_my_deliverables && $current_user_id) {
            $query .= " AND p.owner_id = ?";
            $params[] = $current_user_id;
        }
        
        if ($filter_project) {
            $query .= " AND pd.project_id = ?";
            $params[] = $filter_project;
        }
        
        if ($order_by === 'fim') {
            $query .= " ORDER BY 
                        CASE WHEN pd.due_date IS NULL THEN 1 ELSE 0 END,
                        pd.due_date ASC,
                        pd.created_at DESC";
        } elseif ($order_by === 'deadline') {
            // Ordenar por deadline mais próxima (dias restantes)
            $query .= " ORDER BY 
                        CASE 
                            WHEN pd.due_date IS NULL THEN 999999
                            WHEN DATEDIFF(pd.due_date, CURDATE()) < 0 THEN 999998
                            ELSE DATEDIFF(pd.due_date, CURDATE())
                        END ASC,
                        pd.due_date ASC";
        } else {
            $query .= " ORDER BY 
                        CASE WHEN pd.created_at IS NULL THEN 1 ELSE 0 END,
                        pd.created_at ASC";
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $deliverables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular estatísticas para cada entregável
        foreach ($deliverables as &$deliv) {
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN t.estado = 'completada' THEN 1 ELSE 0 END) as completadas
                    FROM deliverable_tasks dt
                    JOIN todos t ON dt.todo_id = t.id
                    WHERE dt.deliverable_id = ?
                ");
                $stmt->execute([$deliv['id']]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $deliv['total_tasks'] = $stats['total'] ?? 0;
                $deliv['tasks_completadas'] = $stats['completadas'] ?? 0;
                $deliv['percentagem'] = $deliv['total_tasks'] > 0 
                    ? round(($deliv['tasks_completadas'] / $deliv['total_tasks']) * 100) 
                    : 0;
            } catch (PDOException $e) {
                $deliv['total_tasks'] = 0;
                $deliv['tasks_completadas'] = 0;
                $deliv['percentagem'] = 0;
            }
        }
        unset($deliv);
        
    } catch (PDOException $e) {
        $deliverables = [];
        $error_message = "Erro ao carregar entregáveis: " . $e->getMessage();
    }
}

// ============================================================================
// CALCULAR PERÍODO DE VISUALIZAÇÃO DO GANTT
// ============================================================================
$today = new DateTime();
$min_date = null;
$max_date = null;

// Encontrar datas mínimas e máximas dos dados
if ($view_type === 'sprints') {
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
} else {
    foreach ($deliverables as $deliv) {
        if ($deliv['created_at']) {
            $inicio = new DateTime($deliv['created_at']);
            if ($min_date === null || $inicio < $min_date) {
                $min_date = clone $inicio;
            }
        }
        if ($deliv['due_date']) {
            $fim = new DateTime($deliv['due_date']);
            if ($max_date === null || $fim > $max_date) {
                $max_date = clone $fim;
            }
        }
    }
}

// Se não houver datas nos dados, usar hoje como referência
if ($min_date === null) {
    $min_date = clone $today;
}
if ($max_date === null) {
    $max_date = clone $today;
}

// Ajustar período baseado no view_range
switch ($view_range) {
    case 'semana':
        // Mostrar 3 semanas: 1 antes e 2 depois
        $min_date = clone $today;
        $min_date->modify('monday this week')->modify('-1 week');
        $max_date = clone $min_date;
        $max_date->modify('+3 weeks')->modify('-1 day');
        break;
        
    case 'mes':
        // Mostrar 2 meses: mês atual e próximo
        $min_date = clone $today;
        $min_date->modify('first day of this month');
        $max_date = clone $min_date;
        $max_date->modify('+2 months')->modify('-1 day');
        break;
        
    case 'trimestre':
        // Mostrar 3 meses completos
        $min_date = clone $today;
        $min_date->modify('first day of this month');
        $max_date = clone $min_date;
        $max_date->modify('+3 months')->modify('-1 day');
        break;
}

// Expandir período se os dados ultrapassarem os limites
if ($view_type === 'sprints') {
    foreach ($sprints as $sprint) {
        if ($sprint['data_inicio']) {
            $inicio = new DateTime($sprint['data_inicio']);
            if ($inicio < $min_date) {
                $min_date = clone $inicio;
            }
        }
        if ($sprint['data_fim']) {
            $fim = new DateTime($sprint['data_fim']);
            if ($fim > $max_date) {
                $max_date = clone $fim;
            }
        }
    }
} else {
    foreach ($deliverables as $deliv) {
        if ($deliv['created_at']) {
            $inicio = new DateTime($deliv['created_at']);
            if ($inicio < $min_date) {
                $min_date = clone $inicio;
            }
        }
        if ($deliv['due_date']) {
            $fim = new DateTime($deliv['due_date']);
            if ($fim > $max_date) {
                $max_date = clone $fim;
            }
        }
    }
}

// Adicionar margem de segurança
$min_date->modify('-3 days');
$max_date->modify('+3 days');

// Calcular número total de dias
$total_days = $max_date->diff($min_date)->days + 1;
?>

<style>
/* ===== LAYOUT GERAL ===== */
.gantt-layout {
    display: flex;
    height: calc(100vh - 150px);
    gap: 0;
}

.gantt-sidebar {
    width: 280px;
    background: #f8f9fa;
    border-right: 2px solid #dee2e6;
    padding: 20px;
    overflow-y: auto;
    flex-shrink: 0;
}

.gantt-main {
    flex: 1;
    overflow: auto;
    padding: 20px;
    background: white;
}

/* ===== BARRA LATERAL ===== */
.sidebar-section {
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 1px solid #dee2e6;
}

.sidebar-section:last-child {
    border-bottom: none;
}

.sidebar-title {
    font-size: 13px;
    font-weight: 600;
    color: #495057;
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.view-type-toggle {
    display: flex;
    gap: 8px;
    margin-bottom: 15px;
}

.view-type-btn {
    flex: 1;
    padding: 8px 12px;
    border: 2px solid #dee2e6;
    background: white;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
}

.view-type-btn:hover {
    background: #f8f9fa;
    border-color: #0d6efd;
}

.view-type-btn.active {
    background: #0d6efd;
    color: white;
    border-color: #0d6efd;
}

.filter-group {
    margin-bottom: 15px;
}

.filter-label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: #6c757d;
    margin-bottom: 5px;
}

.form-select, .form-control {
    font-size: 13px;
    padding: 6px 10px;
}

.form-check {
    margin-bottom: 8px;
}

.form-check-label {
    font-size: 13px;
    color: #495057;
}

.btn-apply-filters {
    width: 100%;
    padding: 10px;
    font-size: 13px;
    font-weight: 600;
    background: #0d6efd;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-apply-filters:hover {
    background: #0b5ed7;
}

/* ===== CABEÇALHO DO GANTT ===== */
.gantt-header {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #dee2e6;
}

.gantt-title {
    font-size: 24px;
    font-weight: 700;
    color: #212529;
    margin-bottom: 8px;
}

.gantt-info {
    display: flex;
    gap: 20px;
    font-size: 13px;
    color: #6c757d;
}

.gantt-info-item {
    display: flex;
    align-items: center;
    gap: 5px;
}

/* ===== CONTAINER DO GANTT ===== */
#ganttContainer {
    position: relative;
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow-x: auto;
    overflow-y: auto;
    min-height: 400px;
}

.gantt-chart {
    display: table;
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
}

.gantt-header-row {
    display: table-row;
    background: #f8f9fa;
    position: sticky;
    top: 0;
    z-index: 10;
}

.gantt-row {
    display: table-row;
    border-bottom: 1px solid #e9ecef;
    transition: background-color 0.2s;
}

.gantt-row:hover {
    background: #f8f9fa;
}

.gantt-cell {
    display: table-cell;
    padding: 12px;
    vertical-align: middle;
    border-right: 1px solid #e9ecef;
}

.gantt-cell.labels-column {
    width: 250px;
    min-width: 250px;
    position: sticky;
    left: 0;
    background: white;
    z-index: 5;
    box-shadow: 2px 0 5px rgba(0,0,0,0.05);
}

.gantt-header-row .gantt-cell.labels-column {
    background: #f8f9fa;
    font-weight: 700;
    color: #495057;
}

.gantt-cell.timeline-column {
    min-width: 100%;
    padding: 0;
}

/* ===== LABELS DAS LINHAS ===== */
.gantt-label {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.gantt-label-title {
    font-weight: 600;
    color: #212529;
    font-size: 13px;
    cursor: pointer;
    transition: color 0.2s;
}

.gantt-label-title:hover {
    color: #0d6efd;
    text-decoration: underline;
}

.gantt-label-meta {
    display: flex;
    gap: 10px;
    font-size: 11px;
    color: #6c757d;
    flex-wrap: wrap;
}

.gantt-label-meta-item {
    display: flex;
    align-items: center;
    gap: 3px;
}

.gantt-label-meta-item.urgent {
    color: #dc3545;
    font-weight: 700;
}

.gantt-label-meta-item.warning {
    color: #fd7e14;
    font-weight: 600;
}

.gantt-label-meta-item.soon {
    color: #ffc107;
    font-weight: 600;
}

/* ===== TIMELINE ===== */
.gantt-timeline {
    position: relative;
    height: 60px;
    background: linear-gradient(to right, #f8f9fa 0%, #ffffff 100%);
}

.gantt-months {
    display: flex;
    height: 30px;
    border-bottom: 1px solid #dee2e6;
}

.gantt-month {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 12px;
    color: #495057;
    border-right: 1px solid #dee2e6;
    background: #f8f9fa;
}

.gantt-days {
    display: flex;
    height: 30px;
}

.gantt-day {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: #6c757d;
    border-right: 1px solid #f1f3f5;
}

.gantt-day.today {
    background: #fff3cd;
    font-weight: 700;
    color: #856404;
}

.gantt-day.weekend {
    background: #f8f9fa;
}

/* ===== BARRAS DO GANTT ===== */
.gantt-bars {
    position: relative;
    height: 40px;
    display: flex;
    align-items: center;
}

.gantt-grid {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
}

.gantt-grid-day {
    flex: 1;
    border-right: 1px solid #f1f3f5;
}

.gantt-grid-day.today {
    background: #fff3cd20;
}

.gantt-grid-day.weekend {
    background: #f8f9fa80;
}

.gantt-bar {
    position: absolute;
    height: 28px;
    border-radius: 4px;
    padding: 0 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 11px;
    font-weight: 600;
    color: white;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.gantt-bar:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    z-index: 5;
}

/* Estados das barras - SPRINTS */
.gantt-bar.sprint-aberta {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
}

.gantt-bar.sprint-pausa {
    background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
    color: #212529;
}

.gantt-bar.sprint-fechada {
    background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
}

/* Estados das barras - ENTREGÁVEIS */
.gantt-bar.deliverable-pending {
    background: linear-gradient(135deg, #0dcaf0 0%, #0aa2c0 100%);
}

.gantt-bar.deliverable-in-progress {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
}

.gantt-bar.deliverable-completed {
    background: linear-gradient(135deg, #198754 0%, #146c43 100%);
}

.gantt-bar.deliverable-blocked {
    background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);
}

.gantt-bar-content {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    overflow: hidden;
}

.gantt-bar-text {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gantt-bar-progress {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    opacity: 0.9;
}

/* Barra para items sem datas */
.gantt-placeholder {
    position: absolute;
    height: 28px;
    left: 10px;
    right: 10px;
    border: 2px dashed #dee2e6;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
    font-size: 11px;
    background: #f8f9fa;
    cursor: pointer;
    transition: all 0.2s;
}

.gantt-placeholder:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

/* ===== DENSIDADES ===== */
.density-compact .gantt-row {
    height: 35px;
}

.density-compact .gantt-bars {
    height: 30px;
}

.density-compact .gantt-bar {
    height: 22px;
    font-size: 10px;
}

.density-medium .gantt-row {
    height: 50px;
}

.density-medium .gantt-bars {
    height: 45px;
}

.density-medium .gantt-bar {
    height: 32px;
}

.density-normal .gantt-row {
    height: 65px;
}

.density-normal .gantt-bars {
    height: 60px;
}

.density-normal .gantt-bar {
    height: 40px;
}

/* ===== ESTADOS E BADGES ===== */
.status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.status-aberta,
.status-badge.status-pending {
    background: #cfe2ff;
    color: #084298;
}

.status-badge.status-pausa {
    background: #fff3cd;
    color: #856404;
}

.status-badge.status-fechada,
.status-badge.status-completed {
    background: #d1e7dd;
    color: #0f5132;
}

.status-badge.status-in-progress {
    background: #cfe2ff;
    color: #084298;
}

.status-badge.status-blocked {
    background: #f8d7da;
    color: #842029;
}

/* ===== MODAL ===== */
.gantt-modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
}

.gantt-modal.show {
    display: flex;
}

.gantt-modal-content {
    background: white;
    padding: 30px;
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
}

.gantt-modal-header {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #dee2e6;
}

.gantt-modal-title {
    font-size: 20px;
    font-weight: 700;
    color: #212529;
    margin: 0;
}

.gantt-modal-body {
    margin-bottom: 20px;
}

.gantt-modal-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* ===== LOADING ===== */
.loading-overlay {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(255,255,255,0.9);
    align-items: center;
    justify-content: center;
}

.loading-overlay.show {
    display: flex;
}

.loading-spinner {
    text-align: center;
}

.loading-spinner i {
    font-size: 48px;
    color: #0d6efd;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* ===== NOTIFICAÇÕES ===== */
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 3000;
    min-width: 300px;
    padding: 15px 20px;
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.notification.alert-success {
    background: #d1e7dd;
    color: #0f5132;
    border-left: 4px solid #198754;
}

.notification.alert-danger {
    background: #f8d7da;
    color: #842029;
    border-left: 4px solid #dc3545;
}

/* ===== MENSAGEM VAZIA ===== */
.empty-message {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}

.empty-message i {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.3;
}

.empty-message h4 {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 10px;
}

.empty-message p {
    font-size: 14px;
}

/* ===== RESPONSIVO ===== */
@media (max-width: 768px) {
    .gantt-layout {
        flex-direction: column;
        height: auto;
    }
    
    .gantt-sidebar {
        width: 100%;
        border-right: none;
        border-bottom: 2px solid #dee2e6;
    }
    
    .gantt-cell.labels-column {
        position: relative;
        width: auto;
    }
}
</style>

<div class="gantt-layout">
    <!-- Barra Lateral de Controlo -->
    <div class="gantt-sidebar">
        <!-- Selector de Vista -->
        <div class="sidebar-section">
            <div class="sidebar-title">Tipo de Vista</div>
            <div class="view-type-toggle">
                <?php if ($has_sprints): ?>
                <button class="view-type-btn <?= $view_type === 'sprints' ? 'active' : '' ?>" 
                        onclick="changeViewType('sprints')">
                    <i class="bi bi-lightning"></i> Sprints
                </button>
                <?php endif; ?>
                <?php if ($has_projects): ?>
                <button class="view-type-btn <?= $view_type === 'deliverables' ? 'active' : '' ?>" 
                        onclick="changeViewType('deliverables')">
                    <i class="bi bi-check2-square"></i> Entregáveis
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Filtros de Sprints -->
        <?php if ($view_type === 'sprints' && $has_sprints): ?>
        <div class="sidebar-section">
            <div class="sidebar-title">Filtros de Sprints</div>
            
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="filterMySprints" 
                       <?= $filter_my_sprints ?? false ? 'checked' : '' ?>>
                <label class="form-check-label" for="filterMySprints">
                    Minhas Sprints
                </label>
            </div>
            
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="showClosedSprints" 
                       <?= $show_closed ? 'checked' : '' ?>>
                <label class="form-check-label" for="showClosedSprints">
                    Mostrar Fechadas
                </label>
            </div>
            
            <div class="filter-group">
                <label class="filter-label" for="filterUser">Responsável</label>
                <select id="filterUser" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['user_id'] ?>" 
                                <?= ($filter_user_id ?? '') == $user['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if (!empty($prototipos)): ?>
            <div class="filter-group">
                <label class="filter-label" for="filterPrototipo">Protótipo</label>
                <select id="filterPrototipo" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($prototipos as $proto): ?>
                        <option value="<?= $proto['id'] ?>" 
                                <?= ($filter_prototipo ?? '') == $proto['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($proto['short_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Filtros de Entregáveis -->
        <?php if ($view_type === 'deliverables' && $has_projects): ?>
        <div class="sidebar-section">
            <div class="sidebar-title">Filtros de Entregáveis</div>
            
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="filterMyDeliverables" 
                       <?= $filter_my_deliverables ?? false ? 'checked' : '' ?>>
                <label class="form-check-label" for="filterMyDeliverables">
                    Meus Projetos
                </label>
            </div>
            
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="showClosedDeliverables" 
                       <?= $show_closed ? 'checked' : '' ?>>
                <label class="form-check-label" for="showClosedDeliverables">
                    Mostrar Completados
                </label>
            </div>
            
            <?php if (!empty($projects)): ?>
            <div class="filter-group">
                <label class="filter-label" for="filterProject">Projeto</label>
                <select id="filterProject" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?= $proj['id'] ?>" 
                                <?= ($filter_project ?? '') == $proj['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($proj['short_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Opções de Visualização -->
        <div class="sidebar-section">
            <div class="sidebar-title">Visualização</div>
            
            <div class="filter-group">
                <label class="filter-label" for="viewRange">Período</label>
                <select id="viewRange" class="form-select">
                    <option value="semana" <?= $view_range === 'semana' ? 'selected' : '' ?>>Semana</option>
                    <option value="mes" <?= $view_range === 'mes' ? 'selected' : '' ?>>Mês</option>
                    <option value="trimestre" <?= $view_range === 'trimestre' ? 'selected' : '' ?>>Trimestre</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label" for="orderBy">Ordenar por</label>
                <select id="orderBy" class="form-select">
                    <option value="deadline" <?= $order_by === 'deadline' ? 'selected' : '' ?>>Deadline Mais Próxima</option>
                    <option value="inicio" <?= $order_by === 'inicio' ? 'selected' : '' ?>>Data Início</option>
                    <option value="fim" <?= $order_by === 'fim' ? 'selected' : '' ?>>Data Fim</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label" for="densitySelect">Densidade</label>
                <select id="densitySelect" class="form-select" onchange="changeDensity()">
                    <option value="compact">Compacta</option>
                    <option value="medium" selected>Média</option>
                    <option value="normal">Normal</option>
                </select>
            </div>
        </div>
        
        <!-- Botão Aplicar -->
        <button class="btn-apply-filters" onclick="updateFilters()">
            <i class="bi bi-funnel"></i> Aplicar Filtros
        </button>
    </div>
    
    <!-- Área Principal do Gantt -->
    <div class="gantt-main">
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        
        <!-- Cabeçalho -->
        <div class="gantt-header">
            <div class="gantt-title">
                <i class="bi bi-calendar3"></i>
                <?php if ($view_type === 'sprints'): ?>
                    Gantt de Sprints
                <?php else: ?>
                    Gantt de Entregáveis/PPS/KPIs
                <?php endif; ?>
            </div>
            <div class="gantt-info">
                <div class="gantt-info-item">
                    <i class="bi bi-calendar-range"></i>
                    <?= $min_date->format('d/m/Y') ?> - <?= $max_date->format('d/m/Y') ?>
                </div>
                <div class="gantt-info-item">
                    <i class="bi bi-list-ol"></i>
                    <?php if ($view_type === 'sprints'): ?>
                        <?= count($sprints) ?> sprint(s)
                    <?php else: ?>
                        <?= count($deliverables) ?> entregável(is)
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Container do Gantt -->
        <div id="ganttContainer" class="density-medium">
            <?php
            // Renderizar Gantt de Sprints
            if ($view_type === 'sprints'):
                if (empty($sprints)): ?>
                    <div class="empty-message">
                        <i class="bi bi-calendar-x"></i>
                        <h4>Nenhuma sprint encontrada</h4>
                        <p>Altere os filtros ou crie uma nova sprint no módulo de Sprints</p>
                    </div>
                <?php else: ?>
                    <div class="gantt-chart">
                        <!-- Cabeçalho -->
                        <div class="gantt-header-row">
                            <div class="gantt-cell labels-column">Sprint</div>
                            <div class="gantt-cell timeline-column">
                                <div class="gantt-timeline">
                                    <?php
                                    // Calcular meses para o header
                                    $current_date = clone $min_date;
                                    $months = [];
                                    while ($current_date <= $max_date) {
                                        $month_key = $current_date->format('Y-m');
                                        if (!isset($months[$month_key])) {
                                            $months[$month_key] = [
                                                'name' => $current_date->format('M Y'),
                                                'days' => 0
                                            ];
                                        }
                                        $months[$month_key]['days']++;
                                        $current_date->modify('+1 day');
                                    }
                                    ?>
                                    
                                    <!-- Meses -->
                                    <div class="gantt-months">
                                        <?php foreach ($months as $month): ?>
                                            <div class="gantt-month" style="flex: <?= $month['days'] ?>;">
                                                <?= $month['name'] ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <!-- Dias -->
                                    <div class="gantt-days">
                                        <?php
                                        $current_date = clone $min_date;
                                        while ($current_date <= $max_date):
                                            $is_today = $current_date->format('Y-m-d') === $today->format('Y-m-d');
                                            $is_weekend = in_array($current_date->format('N'), [6, 7]);
                                            $class = [];
                                            if ($is_today) $class[] = 'today';
                                            if ($is_weekend) $class[] = 'weekend';
                                        ?>
                                            <div class="gantt-day <?= implode(' ', $class) ?>">
                                                <?= $current_date->format('d') ?>
                                            </div>
                                        <?php
                                            $current_date->modify('+1 day');
                                        endwhile;
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Linhas de Sprints -->
                        <?php foreach ($sprints as $sprint): ?>
                            <div class="gantt-row">
                                <div class="gantt-cell labels-column">
                                    <div class="gantt-label">
                                        <div class="gantt-label-title" onclick="window.location.href='?tab=sprints&sprint_id=<?= $sprint['id'] ?>'">
                                            <?= htmlspecialchars($sprint['nome']) ?>
                                        </div>
                                        <div class="gantt-label-meta">
                                            <div class="gantt-label-meta-item">
                                                <i class="bi bi-person"></i>
                                                <?= htmlspecialchars($sprint['responsavel_nome'] ?? 'Sem responsável') ?>
                                            </div>
                                            <?php if ($sprint['data_fim']): 
                                                $dias = $sprint['dias_restantes'] ?? 0;
                                                $urgency_class = '';
                                                $urgency_icon = 'bi-calendar-event';
                                                
                                                if ($dias < 0) {
                                                    $urgency_class = 'urgent';
                                                    $urgency_icon = 'bi-exclamation-triangle-fill';
                                                    $urgency_text = abs($dias) . ' dia(s) atrasado';
                                                } elseif ($dias == 0) {
                                                    $urgency_class = 'urgent';
                                                    $urgency_icon = 'bi-exclamation-circle-fill';
                                                    $urgency_text = 'Termina HOJE';
                                                } elseif ($dias <= 3) {
                                                    $urgency_class = 'warning';
                                                    $urgency_icon = 'bi-clock-fill';
                                                    $urgency_text = $dias . ' dia(s)';
                                                } elseif ($dias <= 7) {
                                                    $urgency_class = 'soon';
                                                    $urgency_icon = 'bi-clock';
                                                    $urgency_text = $dias . ' dia(s)';
                                                } else {
                                                    $urgency_text = $dias . ' dia(s)';
                                                }
                                            ?>
                                                <div class="gantt-label-meta-item <?= $urgency_class ?>">
                                                    <i class="<?= $urgency_icon ?>"></i>
                                                    <?= $urgency_text ?>
                                                </div>
                                            <?php endif; ?>
                                            <span class="status-badge status-<?= $sprint['estado'] ?>">
                                                <?= ucfirst($sprint['estado']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="gantt-cell timeline-column">
                                    <div class="gantt-bars">
                                        <!-- Grid de dias -->
                                        <div class="gantt-grid">
                                            <?php
                                            $current_date = clone $min_date;
                                            while ($current_date <= $max_date):
                                                $is_today = $current_date->format('Y-m-d') === $today->format('Y-m-d');
                                                $is_weekend = in_array($current_date->format('N'), [6, 7]);
                                                $class = [];
                                                if ($is_today) $class[] = 'today';
                                                if ($is_weekend) $class[] = 'weekend';
                                            ?>
                                                <div class="gantt-grid-day <?= implode(' ', $class) ?>"></div>
                                            <?php
                                                $current_date->modify('+1 day');
                                            endwhile;
                                            ?>
                                        </div>
                                        
                                        <!-- Barra da sprint -->
                                        <?php if ($sprint['data_inicio'] && $sprint['data_fim']): 
                                            $sprint_start = new DateTime($sprint['data_inicio']);
                                            $sprint_end = new DateTime($sprint['data_fim']);
                                            
                                            // Calcular posição e largura
                                            $days_from_start = $min_date->diff($sprint_start)->days;
                                            $sprint_duration = $sprint_start->diff($sprint_end)->days + 1;
                                            
                                            $left_percent = ($days_from_start / $total_days) * 100;
                                            $width_percent = ($sprint_duration / $total_days) * 100;
                                        ?>
                                            <div class="gantt-bar sprint-<?= $sprint['estado'] ?>"
                                                 style="left: <?= $left_percent ?>%; width: <?= $width_percent ?>%;"
                                                 onclick="window.location.href='?tab=sprints&sprint_id=<?= $sprint['id'] ?>'">
                                                <div class="gantt-bar-content">
                                                    <span class="gantt-bar-text">
                                                        <?= htmlspecialchars($sprint['nome']) ?>
                                                    </span>
                                                    <?php if ($sprint['total_tasks'] > 0): ?>
                                                        <div class="gantt-bar-progress">
                                                            <i class="bi bi-check-circle"></i>
                                                            <?= $sprint['percentagem'] ?>%
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="gantt-placeholder" 
                                                 onclick="openDatePicker(<?= $sprint['id'] ?>, '<?= htmlspecialchars($sprint['nome']) ?>')">
                                                <i class="bi bi-calendar-plus"></i> Definir datas
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            
            <?php
            // Renderizar Gantt de Entregáveis
            else:
                if (empty($deliverables)): ?>
                    <div class="empty-message">
                        <i class="bi bi-inbox"></i>
                        <h4>Nenhum entregável encontrado</h4>
                        <p>Altere os filtros ou crie um novo entregável no módulo de Projetos</p>
                    </div>
                <?php else: ?>
                    <div class="gantt-chart">
                        <!-- Cabeçalho -->
                        <div class="gantt-header-row">
                            <div class="gantt-cell labels-column">Entregável/PPS/KPI</div>
                            <div class="gantt-cell timeline-column">
                                <div class="gantt-timeline">
                                    <?php
                                    // Calcular meses para o header
                                    $current_date = clone $min_date;
                                    $months = [];
                                    while ($current_date <= $max_date) {
                                        $month_key = $current_date->format('Y-m');
                                        if (!isset($months[$month_key])) {
                                            $months[$month_key] = [
                                                'name' => $current_date->format('M Y'),
                                                'days' => 0
                                            ];
                                        }
                                        $months[$month_key]['days']++;
                                        $current_date->modify('+1 day');
                                    }
                                    ?>
                                    
                                    <!-- Meses -->
                                    <div class="gantt-months">
                                        <?php foreach ($months as $month): ?>
                                            <div class="gantt-month" style="flex: <?= $month['days'] ?>;">
                                                <?= $month['name'] ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <!-- Dias -->
                                    <div class="gantt-days">
                                        <?php
                                        $current_date = clone $min_date;
                                        while ($current_date <= $max_date):
                                            $is_today = $current_date->format('Y-m-d') === $today->format('Y-m-d');
                                            $is_weekend = in_array($current_date->format('N'), [6, 7]);
                                            $class = [];
                                            if ($is_today) $class[] = 'today';
                                            if ($is_weekend) $class[] = 'weekend';
                                        ?>
                                            <div class="gantt-day <?= implode(' ', $class) ?>">
                                                <?= $current_date->format('d') ?>
                                            </div>
                                        <?php
                                            $current_date->modify('+1 day');
                                        endwhile;
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Linhas de Entregáveis -->
                        <?php foreach ($deliverables as $deliv): ?>
                            <div class="gantt-row">
                                <div class="gantt-cell labels-column">
                                    <div class="gantt-label">
                                        <div class="gantt-label-title" onclick="window.location.href='?tab=projectos&project_id=<?= $deliv['project_id'] ?>'">
                                            <?= htmlspecialchars($deliv['title']) ?>
                                        </div>
                                        <div class="gantt-label-meta">
                                            <div class="gantt-label-meta-item">
                                                <i class="bi bi-folder"></i>
                                                <?= htmlspecialchars($deliv['project_short_name']) ?>
                                            </div>
                                            <?php if ($deliv['owner_name']): ?>
                                            <div class="gantt-label-meta-item">
                                                <i class="bi bi-person"></i>
                                                <?= htmlspecialchars($deliv['owner_name']) ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($deliv['due_date']): 
                                                $dias = $deliv['dias_restantes'] ?? 0;
                                                $urgency_class = '';
                                                $urgency_icon = 'bi-calendar-event';
                                                
                                                if ($dias < 0) {
                                                    $urgency_class = 'urgent';
                                                    $urgency_icon = 'bi-exclamation-triangle-fill';
                                                    $urgency_text = abs($dias) . ' dia(s) atrasado';
                                                } elseif ($dias == 0) {
                                                    $urgency_class = 'urgent';
                                                    $urgency_icon = 'bi-exclamation-circle-fill';
                                                    $urgency_text = 'Vence HOJE';
                                                } elseif ($dias <= 3) {
                                                    $urgency_class = 'warning';
                                                    $urgency_icon = 'bi-clock-fill';
                                                    $urgency_text = $dias . ' dia(s)';
                                                } elseif ($dias <= 7) {
                                                    $urgency_class = 'soon';
                                                    $urgency_icon = 'bi-clock';
                                                    $urgency_text = $dias . ' dia(s)';
                                                } else {
                                                    $urgency_text = $dias . ' dia(s)';
                                                }
                                            ?>
                                                <div class="gantt-label-meta-item <?= $urgency_class ?>">
                                                    <i class="<?= $urgency_icon ?>"></i>
                                                    <?= $urgency_text ?>
                                                </div>
                                            <?php endif; ?>
                                            <span class="status-badge status-<?= $deliv['status'] ?>">
                                                <?= ucfirst(str_replace('-', ' ', $deliv['status'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="gantt-cell timeline-column">
                                    <div class="gantt-bars">
                                        <!-- Grid de dias -->
                                        <div class="gantt-grid">
                                            <?php
                                            $current_date = clone $min_date;
                                            while ($current_date <= $max_date):
                                                $is_today = $current_date->format('Y-m-d') === $today->format('Y-m-d');
                                                $is_weekend = in_array($current_date->format('N'), [6, 7]);
                                                $class = [];
                                                if ($is_today) $class[] = 'today';
                                                if ($is_weekend) $class[] = 'weekend';
                                            ?>
                                                <div class="gantt-grid-day <?= implode(' ', $class) ?>"></div>
                                            <?php
                                                $current_date->modify('+1 day');
                                            endwhile;
                                            ?>
                                        </div>
                                        
                                        <!-- Barra do entregável -->
                                        <?php if ($deliv['created_at'] && $deliv['due_date']): 
                                            $deliv_start = new DateTime($deliv['created_at']);
                                            $deliv_end = new DateTime($deliv['due_date']);
                                            
                                            // Calcular posição e largura
                                            $days_from_start = $min_date->diff($deliv_start)->days;
                                            $deliv_duration = $deliv_start->diff($deliv_end)->days + 1;
                                            
                                            $left_percent = ($days_from_start / $total_days) * 100;
                                            $width_percent = ($deliv_duration / $total_days) * 100;
                                        ?>
                                            <div class="gantt-bar deliverable-<?= $deliv['status'] ?>"
                                                 style="left: <?= $left_percent ?>%; width: <?= $width_percent ?>%;"
                                                 onclick="window.location.href='?tab=projectos&project_id=<?= $deliv['project_id'] ?>'">
                                                <div class="gantt-bar-content">
                                                    <span class="gantt-bar-text">
                                                        <?= htmlspecialchars($deliv['title']) ?>
                                                    </span>
                                                    <?php if ($deliv['total_tasks'] > 0): ?>
                                                        <div class="gantt-bar-progress">
                                                            <i class="bi bi-check-circle"></i>
                                                            <?= $deliv['percentagem'] ?>%
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php elseif ($deliv['due_date']): 
                                            // Se só tem due_date, mostrar como marco
                                            $deliv_date = new DateTime($deliv['due_date']);
                                            $days_from_start = $min_date->diff($deliv_date)->days;
                                            $left_percent = ($days_from_start / $total_days) * 100;
                                        ?>
                                            <div class="gantt-bar deliverable-<?= $deliv['status'] ?>"
                                                 style="left: <?= $left_percent ?>%; width: 5%;"
                                                 onclick="window.location.href='?tab=projectos&project_id=<?= $deliv['project_id'] ?>'">
                                                <div class="gantt-bar-content">
                                                    <i class="bi bi-flag"></i>
                                                    <span class="gantt-bar-text">
                                                        <?= htmlspecialchars($deliv['title']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="gantt-placeholder">
                                                <i class="bi bi-calendar-x"></i> Sem data definida
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal para definir datas de sprints -->
<div id="datePickerModal" class="gantt-modal">
    <div class="gantt-modal-content">
        <div class="gantt-modal-header">
            <h3 class="gantt-modal-title">Definir Datas da Sprint</h3>
        </div>
        <div class="gantt-modal-body">
            <p>Sprint: <strong id="datePickerSprintName"></strong></p>
            <div class="mb-3">
                <label class="filter-label">Data de Início</label>
                <input type="date" id="sprintStartDate" class="form-control">
            </div>
            <div class="mb-3">
                <label class="filter-label">Data de Término</label>
                <input type="date" id="sprintEndDate" class="form-control">
            </div>
        </div>
        <div class="gantt-modal-footer">
            <button class="btn btn-secondary" onclick="closeDatePicker()">Cancelar</button>
            <button class="btn btn-primary" id="saveDatesBtn" onclick="saveDates()">
                <i class="bi bi-check-circle"></i> Salvar
            </button>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner">
        <i class="bi bi-hourglass-split"></i>
        <p>Carregando...</p>
    </div>
</div>

<script>
// Função para mudar tipo de vista
function changeViewType(type) {
    const url = new URL(window.location.href);
    url.searchParams.set('view_type', type);
    window.location.href = url.toString();
}

// Função para atualizar filtros
function updateFilters() {
    const viewType = '<?= $view_type ?>';
    const viewRange = document.getElementById('viewRange').value;
    const orderBy = document.getElementById('orderBy').value;
    const showClosed = viewType === 'sprints' 
        ? (document.getElementById('showClosedSprints')?.checked ? '1' : '0')
        : (document.getElementById('showClosedDeliverables')?.checked ? '1' : '0');
    
    let url = `?tab=gantt&view_type=${viewType}&view_range=${viewRange}&order_by=${orderBy}&show_closed=${showClosed}`;
    
    if (viewType === 'sprints') {
        const filterMy = document.getElementById('filterMySprints')?.checked ? '1' : '0';
        const filterUser = document.getElementById('filterUser')?.value || '';
        const filterPrototipo = document.getElementById('filterPrototipo')?.value || '';
        
        url += `&filter_my_sprints=${filterMy}`;
        if (filterUser) url += `&filter_user_id=${filterUser}`;
        if (filterPrototipo) url += `&filter_prototipo=${encodeURIComponent(filterPrototipo)}`;
    } else {
        const filterMy = document.getElementById('filterMyDeliverables')?.checked ? '1' : '0';
        const filterProject = document.getElementById('filterProject')?.value || '';
        
        url += `&filter_my_deliverables=${filterMy}`;
        if (filterProject) url += `&filter_project=${filterProject}`;
    }
    
    window.location.href = url;
}

// Função para mudar a densidade
function changeDensity() {
    const density = document.getElementById('densitySelect').value;
    const container = document.getElementById('ganttContainer');
    
    container.classList.remove('density-normal', 'density-medium', 'density-compact');
    container.classList.add('density-' + density);
    
    localStorage.setItem('gantt-density', density);
}

// Carregar densidade salva
document.addEventListener('DOMContentLoaded', function() {
    const savedDensity = localStorage.getItem('gantt-density');
    if (savedDensity) {
        const densitySelect = document.getElementById('densitySelect');
        const container = document.getElementById('ganttContainer');
        
        densitySelect.value = savedDensity;
        container.classList.remove('density-normal', 'density-medium', 'density-compact');
        container.classList.add('density-' + savedDensity);
    }
});

// Modal de datas (apenas para sprints)
let currentEditingSprintId = null;

function openDatePicker(sprintId, sprintName) {
    currentEditingSprintId = sprintId;
    document.getElementById('datePickerSprintName').textContent = sprintName;
    
    const today = new Date();
    const twoWeeksLater = new Date(today);
    twoWeeksLater.setDate(twoWeeksLater.getDate() + 14);
    
    document.getElementById('sprintStartDate').value = today.toISOString().split('T')[0];
    document.getElementById('sprintEndDate').value = twoWeeksLater.toISOString().split('T')[0];
    
    document.getElementById('datePickerModal').classList.add('show');
}

function closeDatePicker() {
    document.getElementById('datePickerModal').classList.remove('show');
}

function saveDates() {
    const startDate = document.getElementById('sprintStartDate').value;
    const endDate = document.getElementById('sprintEndDate').value;
    
    if (!startDate || !endDate) {
        alert('Por favor, preencha ambas as datas.');
        return;
    }
    
    if (new Date(startDate) > new Date(endDate)) {
        alert('A data de início não pode ser posterior à data de término.');
        return;
    }
    
    const saveBtn = document.getElementById('saveDatesBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvando...';
    
    closeDatePicker();
    document.getElementById('loadingOverlay').classList.add('show');
    
    const formData = new FormData();
    formData.append('action', 'update_sprint_dates');
    formData.append('sprint_id', currentEditingSprintId);
    formData.append('data_inicio', startDate);
    formData.append('data_fim', endDate);
    
    fetch('gantt_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            setTimeout(() => {
                window.location.reload();
            }, 300);
        } else {
            document.getElementById('loadingOverlay').classList.remove('show');
            showNotification('Erro ao salvar: ' + (data.message || 'Erro desconhecido'), 'danger');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> Salvar';
        }
    })
    .catch(error => {
        document.getElementById('loadingOverlay').classList.remove('show');
        showNotification('Erro: ' + error.message, 'danger');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> Salvar';
    });
}

// Função para mostrar notificações
function showNotification(message, type) {
    const alert = document.createElement('div');
    alert.className = `notification alert alert-${type}`;
    alert.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
        ${message}
    `;
    document.body.appendChild(alert);
    
    setTimeout(() => {
        alert.remove();
    }, 3000);
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