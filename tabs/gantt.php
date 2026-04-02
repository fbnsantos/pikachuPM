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
$order_by = $_GET['order_by'] ?? 'deadline'; // 'inicio', 'fim', 'deadline'
$view_range = $_GET['view_range'] ?? 'mes'; // 'semana', 'mes', 'trimestre', 'semestre'
$time_offset = isset($_GET['time_offset']) ? (int)$_GET['time_offset'] : 0; // Offset em unidades de período
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
// PROCESSAMENTO PARA VISTA DE URGENTES (atrasados + próximas 3 semanas)
// ============================================================================
$urgentes_deliverables = [];
$urgentes_sprints = [];

if ($view_type === 'urgentes') {
    $three_weeks = (new DateTime())->modify('+21 days')->format('Y-m-d');

    // Garantir tabela de notas existe (criada também no ajax, mas pode ser a 1ª visita)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gantt_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ref_type ENUM('deliverable','sprint') NOT NULL,
            ref_id INT NOT NULL,
            nota TEXT NOT NULL,
            autor_id INT DEFAULT NULL,
            autor_name VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ref (ref_type, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {}

    // Mapa de contagens de notas
    $note_counts = ['deliverable' => [], 'sprint' => []];
    try {
        $nc = $pdo->query("SELECT ref_type, ref_id, COUNT(*) n FROM gantt_notes GROUP BY ref_type, ref_id");
        foreach ($nc->fetchAll(PDO::FETCH_ASSOC) as $row)
            $note_counts[$row['ref_type']][$row['ref_id']] = (int)$row['n'];
    } catch (PDOException $e) {}

    if ($has_projects) {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    pd.id, pd.title, pd.due_date, pd.status, pd.project_id,
                    p.short_name as project_short_name, p.title as project_title,
                    u.username as owner_name,
                    DATEDIFF(pd.due_date, CURDATE()) as dias_restantes
                FROM project_deliverables pd
                INNER JOIN projects p ON pd.project_id = p.id
                LEFT JOIN user_tokens u ON p.owner_id = u.user_id
                WHERE pd.status != 'completed'
                  AND pd.due_date IS NOT NULL
                  AND pd.due_date <= ?
                ORDER BY pd.due_date ASC
            ");
            $stmt->execute([$three_weeks]);
            $urgentes_deliverables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $urgentes_deliverables = [];
        }
    }

    if ($has_sprints) {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    s.id, s.nome, s.data_fim, s.estado,
                    u.username as responsavel_nome,
                    DATEDIFF(s.data_fim, CURDATE()) as dias_restantes
                FROM sprints s
                LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id
                WHERE s.estado != 'fechada'
                  AND s.data_fim IS NOT NULL
                  AND s.data_fim <= ?
                ORDER BY s.data_fim ASC
            ");
            $stmt->execute([$three_weeks]);
            $urgentes_sprints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $urgentes_sprints = [];
        }
    }
}

// ============================================================================
// PROCESSAMENTO PARA VISTA DE MÉTRICAS & SAÚDE
// ============================================================================
$metricas = [];

if ($view_type === 'metricas') {
    $analytics_days = max(7, min(60, (int)($_GET['analytics_days'] ?? 14)));
    $m_start = (new DateTime())->modify("-{$analytics_days} days")->format('Y-m-d');
    $m_today  = date('Y-m-d');

    // Lista de dias do período
    $m_days = [];
    $d = new DateTime($m_start);
    $dt = new DateTime($m_today);
    while ($d <= $dt) { $m_days[] = $d->format('Y-m-d'); $d->modify('+1 day'); }

    // ── Actividade diária (concluídas por dia) ─────────────────────────────
    $m_tasks_day    = array_fill_keys($m_days, 0);
    $m_sprints_day  = array_fill_keys($m_days, 0);
    $m_delivs_day   = array_fill_keys($m_days, 0);

    if (tableExists($pdo, 'todos')) {
        try {
            $st = $pdo->prepare("SELECT DATE(updated_at) d, COUNT(*) n FROM todos
                WHERE estado IN ('concluída','fechada') AND DATE(updated_at) BETWEEN ? AND ?
                GROUP BY DATE(updated_at)");
            $st->execute([$m_start, $m_today]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                if (isset($m_tasks_day[$r['d']])) $m_tasks_day[$r['d']] = (int)$r['n'];
        } catch (PDOException $e) {}
    }

    if ($has_sprints) {
        try {
            $st = $pdo->prepare("SELECT DATE(updated_at) d, COUNT(*) n FROM sprints
                WHERE estado IN ('concluída','fechada','concluida') AND DATE(updated_at) BETWEEN ? AND ?
                GROUP BY DATE(updated_at)");
            $st->execute([$m_start, $m_today]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                if (isset($m_sprints_day[$r['d']])) $m_sprints_day[$r['d']] = (int)$r['n'];
        } catch (PDOException $e) {}
    }

    if ($has_projects) {
        try {
            $st = $pdo->prepare("SELECT DATE(updated_at) d, COUNT(*) n FROM project_deliverables
                WHERE status = 'completed' AND DATE(updated_at) BETWEEN ? AND ?
                GROUP BY DATE(updated_at)");
            $st->execute([$m_start, $m_today]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                if (isset($m_delivs_day[$r['d']])) $m_delivs_day[$r['d']] = (int)$r['n'];
        } catch (PDOException $e) {}
    }

    // ── Compliance de reports por utilizador ──────────────────────────────
    $m_compliance = []; // [username][date] = true
    $m_has_reports = tableExists($pdo, 'daily_reports');
    if ($m_has_reports) {
        $st = $pdo->prepare("SELECT ut.username, dr.report_date
            FROM daily_reports dr JOIN user_tokens ut ON dr.user_id = ut.user_id
            WHERE dr.report_date BETWEEN ? AND ?");
        $st->execute([$m_start, $m_today]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $m_compliance[$r['username']][$r['report_date']] = true;
    }

    // ── Ranking de faltas ao report ───────────────────────────────────────
    $working_days = array_filter($m_days, fn($d) => !in_array(date('N', strtotime($d)), [6,7]));
    $n_working = count($working_days);
    $m_report_rank = [];
    foreach ($users as $u) {
        $submitted = count(array_filter($working_days, fn($d) => isset($m_compliance[$u['username']][$d])));
        $m_report_rank[] = [
            'username' => $u['username'],
            'submitted' => $submitted,
            'missed'    => $n_working - $submitted,
            'pct'       => $n_working > 0 ? round($submitted / $n_working * 100) : 0,
        ];
    }
    usort($m_report_rank, fn($a,$b) => $b['missed'] <=> $a['missed']);

    // ── Reuniões no período (calendar_eventos) ───────────────────────────
    $m_meetings = [];
    if (tableExists($pdo, 'calendar_eventos')) {
        try {
            $st = $pdo->prepare("SELECT * FROM calendar_eventos
                WHERE data BETWEEN ? AND ? ORDER BY data DESC, hora ASC");
            $st->execute([$m_start, $m_today]);
            $m_meetings = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── Tarefas atrasadas por utilizador ─────────────────────────────────
    $m_overdue = [];
    if (tableExists($pdo, 'todos')) {
        try {
            $st = $pdo->query("SELECT ut.username, COUNT(*) n
                FROM todos t
                JOIN user_tokens ut ON (t.responsavel = ut.user_id OR (t.responsavel IS NULL AND t.autor = ut.user_id))
                WHERE t.data_limite < CURDATE() AND t.estado NOT IN ('concluída','fechada')
                GROUP BY ut.username ORDER BY n DESC");
            $m_overdue = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── Tarefas paradas (em execução > 7 dias sem update) ─────────────────
    $m_stalled = [];
    if (tableExists($pdo, 'todos')) {
        try {
            $st = $pdo->prepare("SELECT t.titulo, ut.username,
                    DATEDIFF(CURDATE(), t.updated_at) AS dias,
                    t.data_limite
                FROM todos t
                JOIN user_tokens ut ON (t.responsavel = ut.user_id OR (t.responsavel IS NULL AND t.autor = ut.user_id))
                WHERE t.estado = 'em execução'
                  AND t.updated_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                ORDER BY t.updated_at ASC LIMIT 30");
            $st->execute();
            $m_stalled = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // ── Sprints atrasadas ────────────────────────────────────────────────
    $m_overdue_sprints = [];
    if ($has_sprints) {
        try {
            $st = $pdo->query("SELECT s.nome, u.username AS responsavel,
                    DATEDIFF(CURDATE(), s.data_fim) AS dias_atraso
                FROM sprints s LEFT JOIN user_tokens u ON s.responsavel_id = u.user_id
                WHERE s.data_fim < CURDATE() AND s.estado NOT IN ('concluída','fechada','concluida')
                ORDER BY s.data_fim ASC");
            $m_overdue_sprints = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    $metricas = compact(
        'analytics_days','m_days','working_days','n_working',
        'm_tasks_day','m_sprints_day','m_delivs_day',
        'm_compliance','m_report_rank','m_meetings',
        'm_overdue','m_stalled','m_overdue_sprints'
    );
}

// ============================================================================
// CALCULAR PERÍODO DE VISUALIZAÇÃO DO GANTT
// ============================================================================
$today = new DateTime();
$today->setTime(0, 0, 0); // Normalizar para meia-noite

// Definir período padrão baseado em view_range
// O período SEMPRE começa a partir de hoje (ou próximo início de semana/mês)
// MAIS o offset temporal (para navegação)
$min_date = clone $today;
$max_date = clone $today;

switch ($view_range) {
    case 'semana':
        // Começa na segunda-feira desta semana, mostra 4 semanas
        $min_date->modify('monday this week');
        // Aplicar offset
        if ($time_offset != 0) {
            $min_date->modify(($time_offset * 4) . ' weeks');
        }
        $max_date = clone $min_date;
        $max_date->modify('+4 weeks')->modify('-1 day');
        break;
        
    case 'mes':
        // Começa no primeiro dia deste mês, mostra 2 meses
        $min_date->modify('first day of this month');
        // Aplicar offset
        if ($time_offset != 0) {
            $min_date->modify(($time_offset * 2) . ' months');
        }
        $max_date = clone $min_date;
        $max_date->modify('+2 months')->modify('-1 day');
        break;
        
    case 'trimestre':
        // Começa no primeiro dia deste mês, mostra 3 meses
        $min_date->modify('first day of this month');
        // Aplicar offset
        if ($time_offset != 0) {
            $min_date->modify(($time_offset * 3) . ' months');
        }
        $max_date = clone $min_date;
        $max_date->modify('+3 months')->modify('-1 day');
        break;
        
    case 'semestre':
        // Começa no primeiro dia deste mês, mostra 6 meses
        $min_date->modify('first day of this month');
        // Aplicar offset
        if ($time_offset != 0) {
            $min_date->modify(($time_offset * 6) . ' months');
        }
        $max_date = clone $min_date;
        $max_date->modify('+6 months')->modify('-1 day');
        break;
}

// Guardar os limites padrão para referência
$default_min_date = clone $min_date;
$default_max_date = clone $max_date;

// Expandir período APENAS se houver dados anteriores a min_date ou posteriores a max_date
// Isso permite ver dados passados e futuros que estão fora do período padrão
if ($view_type === 'sprints') {
    foreach ($sprints as $sprint) {
        if ($sprint['data_inicio']) {
            $inicio = new DateTime($sprint['data_inicio']);
            $inicio->setTime(0, 0, 0);
            if ($inicio < $min_date) {
                $min_date = clone $inicio;
            }
        }
        if ($sprint['data_fim']) {
            $fim = new DateTime($sprint['data_fim']);
            $fim->setTime(0, 0, 0);
            if ($fim > $max_date) {
                $max_date = clone $fim;
            }
        }
    }
} else {
    foreach ($deliverables as $deliv) {
        if ($deliv['created_at']) {
            $inicio = new DateTime($deliv['created_at']);
            $inicio->setTime(0, 0, 0);
            if ($inicio < $min_date) {
                $min_date = clone $inicio;
            }
        }
        if ($deliv['due_date']) {
            $fim = new DateTime($deliv['due_date']);
            $fim->setTime(0, 0, 0);
            if ($fim > $max_date) {
                $max_date = clone $fim;
            }
        }
    }
}

// Adicionar pequena margem apenas se expandiu para dados passados/futuros
if ($min_date < $default_min_date) {
    $min_date->modify('-3 days');
}
if ($max_date > $default_max_date) {
    $max_date->modify('+3 days');
}

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

.view-type-btn-urgentes {
    border-color: #dc3545; color: #dc3545;
}
.view-type-btn-urgentes:hover   { background: #fff5f5; border-color: #dc3545; color: #dc3545; }
.view-type-btn-urgentes.active-urgentes { background: #dc3545; color: white; border-color: #dc3545; }

.view-type-btn-metricas {
    border-color: #6f42c1; color: #6f42c1;
}
.view-type-btn-metricas:hover   { background: #f5f0ff; border-color: #6f42c1; color: #6f42c1; }
.view-type-btn-metricas.active-metricas { background: #6f42c1; color: white; border-color: #6f42c1; }

/* ── Métricas & Saúde ── */
.metricas-container { padding: 4px 0; }
.metricas-section   { margin-bottom: 32px; }
.metricas-title     { font-size: 17px; font-weight: 700; margin-bottom: 14px;
                       display: flex; align-items: center; gap: 8px;
                       padding-bottom: 6px; border-bottom: 2px solid #dee2e6; }

/* Summary boxes */
.metricas-summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
.metricas-box     { flex: 1; min-width: 110px; border: 1px solid #dee2e6; border-radius: 8px;
                    padding: 14px 10px; text-align: center; background: #fafafa; }
.metricas-box .mval { font-size: 26px; font-weight: 700; line-height: 1; }
.metricas-box .mlbl { font-size: 11px; color: #6c757d; margin-top: 4px; }
.metricas-box.ok   { border-color: #198754; } .metricas-box.ok   .mval { color: #198754; }
.metricas-box.warn { border-color: #dc3545; } .metricas-box.warn .mval { color: #dc3545; }
.metricas-box.info { border-color: #0d6efd; } .metricas-box.info .mval { color: #0d6efd; }

/* Period quick-select */
.period-btns { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.period-btn  { padding: 4px 10px; border: 1px solid #dee2e6; background: white;
               border-radius: 4px; font-size: 12px; cursor: pointer; }
.period-btn:hover   { background: #e9ecef; }
.period-btn.active  { background: #6f42c1; color: white; border-color: #6f42c1; }

/* Burn chart */
.burn-chart-wrap { overflow-x: auto; }
.burn-svg        { display: block; min-width: 500px; }
.burn-legend     { display: flex; gap: 18px; font-size: 12px; margin-bottom: 8px; flex-wrap: wrap; }
.burn-legend-dot { display: inline-block; width: 12px; height: 12px;
                   border-radius: 50%; margin-right: 4px; vertical-align: middle; }

/* Compliance heatmap */
.compliance-table { width: 100%; border-collapse: collapse; font-size: 11px; }
.compliance-table th { background: #f1f3f5; padding: 5px 6px; text-align: center;
                        font-weight: 600; color: #495057; border-bottom: 2px solid #dee2e6; white-space: nowrap; }
.compliance-table th.name-col { text-align: left; min-width: 110px; }
.compliance-table td { padding: 4px 3px; border-bottom: 1px solid #f0f0f0; text-align: center; }
.compliance-table td.name-col { text-align: left; font-weight: 600; padding-left: 6px; }
.compliance-table tr:hover td { background: #f8f9fa; }
.cell-report-ok   { background: #d1e7dd; color: #0f5132; border-radius: 3px; font-size: 10px; }
.cell-report-miss { background: #f8d7da; color: #842029; border-radius: 3px; font-size: 10px; }
.cell-report-wknd { background: #f1f3f5; color: #adb5bd; border-radius: 3px; font-size: 10px; }
.pct-bar-wrap  { width: 60px; height: 8px; background: #e9ecef; border-radius: 4px; display: inline-block; vertical-align: middle; }
.pct-bar-fill  { height: 100%; border-radius: 4px; background: #198754; }
.pct-bar-fill.low { background: #dc3545; }
.pct-bar-fill.mid { background: #ffc107; }

/* Critical panels */
.critical-grid    { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
.critical-panel   { border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; }
.critical-header  { padding: 10px 14px; font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 8px; }
.critical-body    { padding: 10px 0; }
.critical-row     { display: flex; justify-content: space-between; align-items: center;
                    padding: 6px 14px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.critical-row:last-child { border-bottom: none; }
.critical-row .cname { font-weight: 500; }
.critical-row .cval  { font-size: 12px; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
.cval-danger  { background: #f8d7da; color: #842029; }
.cval-warn    { background: #fff3cd; color: #664d03; }
.cval-ok      { background: #d1e7dd; color: #0f5132; }
.critical-empty { padding: 16px 14px; color: #6c757d; font-style: italic; font-size: 13px; }

/* Meetings list */
.meeting-item { display: flex; align-items: flex-start; gap: 10px;
                padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
.meeting-item:last-child { border-bottom: none; }
.meeting-date-badge { background: #e9ecef; border-radius: 6px; padding: 4px 10px;
                      font-size: 11px; font-weight: 600; white-space: nowrap; min-width: 70px; text-align: center; }
.meeting-tipo { font-size: 11px; padding: 2px 7px; border-radius: 10px; font-weight: 600; }
.tipo-tribe   { background: #cfe2ff; color: #084298; }
.tipo-demo    { background: #d1e7dd; color: #0f5132; }
.tipo-aulas   { background: #fff3cd; color: #664d03; }
.tipo-outro   { background: #e9ecef; color: #495057; }

/* Tabelas de urgentes */
.urgentes-container {
    padding: 10px 0;
}
.urgentes-table-section {
    margin-bottom: 32px;
}
.urgentes-table-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.urgentes-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.urgentes-table th {
    background: #f1f3f5;
    padding: 10px 14px;
    text-align: left;
    font-weight: 600;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
}
.urgentes-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #dee2e6;
    vertical-align: middle;
}
.urgentes-table tr:hover td {
    background: #f8f9fa;
}
.urgentes-table tr.atrasado td {
    background: #fff5f5;
}
.urgentes-table tr.atrasado:hover td {
    background: #ffe9e9;
}
.urgentes-badge-atrasado {
    background: #dc3545; color: white;
    padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;
}
.urgentes-badge-hoje {
    background: #fd7e14; color: white;
    padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;
}
.urgentes-badge-breve {
    background: #ffc107; color: #333;
    padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;
}
.urgentes-empty {
    color: #6c757d; font-style: italic; padding: 20px 0;
}
.urgentes-actions-cell {
    white-space: nowrap;
    min-width: 230px;
}
.urgentes-select {
    font-size: 12px;
    padding: 3px 6px;
    border-radius: 4px;
    border: 1px solid #ced4da;
    background: white;
    cursor: pointer;
    width: 100%;
    margin-bottom: 5px;
}
.urgentes-date-input {
    font-size: 12px;
    padding: 3px 6px;
    border-radius: 4px;
    border: 1px solid #ced4da;
    width: 100%;
}
.urgentes-save-indicator {
    font-size: 11px;
    margin-top: 3px;
    height: 14px;
    transition: opacity 0.5s;
}
.urgentes-save-ok   { color: #198754; }
.urgentes-save-err  { color: #dc3545; }
.urgentes-save-wait { color: #6c757d; }

/* ── Botão de notas na linha ── */
.btn-notas {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; font-size: 12px; border-radius: 12px; cursor: pointer;
    border: 1px solid #6f42c1; color: #6f42c1; background: white;
    transition: all .15s; white-space: nowrap; margin-top: 5px;
}
.btn-notas:hover { background: #f5f0ff; }
.btn-notas.has-notes { background: #6f42c1; color: white; border-color: #6f42c1; }
.btn-notas .note-count {
    background: white; color: #6f42c1; border-radius: 8px;
    padding: 0 5px; font-size: 10px; font-weight: 700;
    display: inline-block; min-width: 16px; text-align: center;
}
.btn-notas.has-notes .note-count { background: rgba(255,255,255,0.25); color: white; }

/* ── Painel lateral de notas ── */
#notesPanel {
    position: fixed; top: 0; right: -440px; width: 420px; height: 100vh;
    background: white; box-shadow: -4px 0 24px rgba(0,0,0,.18);
    z-index: 9999; display: flex; flex-direction: column;
    transition: right .3s cubic-bezier(.4,0,.2,1);
    font-size: 14px;
}
#notesPanel.open { right: 0; }

.notes-panel-header {
    background: #6f42c1; color: white;
    padding: 16px 18px 14px; flex-shrink: 0;
}
.notes-panel-header h4 { margin: 0; font-size: 15px; font-weight: 700; }
.notes-panel-header .notes-subtitle {
    font-size: 11px; opacity: .8; margin-top: 3px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.notes-panel-close {
    position: absolute; top: 12px; right: 14px;
    background: rgba(255,255,255,.2); border: none; color: white;
    border-radius: 50%; width: 28px; height: 28px; cursor: pointer;
    font-size: 16px; line-height: 1; display: flex; align-items: center; justify-content: center;
}
.notes-panel-close:hover { background: rgba(255,255,255,.35); }

.notes-panel-body { flex: 1; overflow-y: auto; padding: 16px 18px; }
.notes-panel-footer { flex-shrink: 0; border-top: 1px solid #dee2e6; padding: 14px 18px; background: #fafafa; }

/* Área de nova nota */
.new-note-area textarea {
    width: 100%; border: 1px solid #dee2e6; border-radius: 6px;
    padding: 8px 10px; font-size: 13px; resize: vertical; min-height: 80px;
    font-family: inherit; outline: none;
}
.new-note-area textarea:focus { border-color: #6f42c1; box-shadow: 0 0 0 2px rgba(111,66,193,.15); }
.new-note-actions { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
.btn-save-note {
    background: #6f42c1; color: white; border: none; border-radius: 6px;
    padding: 6px 16px; font-size: 13px; cursor: pointer; font-weight: 600;
}
.btn-save-note:hover { background: #5a32a3; }
.btn-save-note:disabled { opacity: .6; cursor: not-allowed; }
.btn-create-task-from-note {
    background: white; color: #0d6efd; border: 1px solid #0d6efd;
    border-radius: 6px; padding: 6px 14px; font-size: 13px; cursor: pointer;
}
.btn-create-task-from-note:hover { background: #e8f0fe; }

/* Formulário inline de tarefa */
.task-form-inline {
    background: #f0f4ff; border: 1px solid #c5d5ff; border-radius: 8px;
    padding: 12px; margin-top: 10px; display: none;
}
.task-form-inline.visible { display: block; }
.task-form-inline label { font-size: 11px; font-weight: 600; color: #495057; display: block; margin-bottom: 3px; }
.task-form-inline input, .task-form-inline select, .task-form-inline textarea {
    width: 100%; padding: 5px 8px; border: 1px solid #ced4da; border-radius: 4px;
    font-size: 12px; margin-bottom: 8px; font-family: inherit;
}
.task-form-row { display: flex; gap: 8px; }
.task-form-row > div { flex: 1; }
.btn-submit-task {
    background: #0d6efd; color: white; border: none; border-radius: 5px;
    padding: 6px 16px; font-size: 12px; cursor: pointer; font-weight: 600;
}
.btn-submit-task:hover { background: #0b5ed7; }
.btn-cancel-task {
    background: white; color: #6c757d; border: 1px solid #dee2e6;
    border-radius: 5px; padding: 6px 12px; font-size: 12px; cursor: pointer;
}

/* Lista de notas existentes */
.notes-list { margin-top: 4px; }
.notes-list-title {
    font-size: 11px; font-weight: 700; color: #6c757d; text-transform: uppercase;
    letter-spacing: .5px; margin-bottom: 10px; margin-top: 4px;
}
.note-item {
    background: #fafafa; border: 1px solid #e9ecef; border-radius: 8px;
    padding: 10px 12px; margin-bottom: 10px; position: relative;
}
.note-item-meta {
    font-size: 11px; color: #6c757d; margin-bottom: 6px;
    display: flex; justify-content: space-between; align-items: center;
}
.note-item-author { font-weight: 600; color: #495057; }
.note-item-text { font-size: 13px; white-space: pre-wrap; line-height: 1.5; color: #212529; }
.note-item-actions { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
.btn-note-task {
    font-size: 11px; padding: 3px 10px; border-radius: 10px;
    border: 1px solid #0d6efd; color: #0d6efd; background: white; cursor: pointer;
}
.btn-note-task:hover { background: #e8f0fe; }
.btn-note-delete {
    font-size: 11px; padding: 3px 10px; border-radius: 10px;
    border: 1px solid #dee2e6; color: #dc3545; background: white; cursor: pointer;
}
.btn-note-delete:hover { background: #fff5f5; border-color: #dc3545; }
.notes-empty { color: #6c757d; font-style: italic; font-size: 13px; text-align: center; padding: 20px 0; }
.notes-loading { text-align: center; padding: 20px; color: #6c757d; font-size: 13px; }

/* Overlay escuro */
#notesOverlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.3); z-index: 9998;
}
#notesOverlay.open { display: block; }

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

/* Botões de navegação */
.btn {
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
    border: 1px solid #dee2e6;
    background: white;
    color: #495057;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn:hover {
    background: #f8f9fa;
    border-color: #adb5bd;
}

.btn-primary {
    background: #0d6efd;
    color: white;
    border-color: #0d6efd;
}

.btn-primary:hover {
    background: #0b5ed7;
    border-color: #0a58ca;
}

.btn-outline-secondary {
    background: white;
    color: #6c757d;
    border-color: #6c757d;
}

.btn-outline-secondary:hover {
    background: #6c757d;
    color: white;
}

.btn i {
    font-size: 12px;
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
            <?php if ($has_sprints || $has_projects): ?>
            <button class="view-type-btn view-type-btn-urgentes <?= $view_type === 'urgentes' ? 'active-urgentes' : '' ?>"
                    onclick="changeViewType('urgentes')" style="width:100%; margin-top:8px;">
                <i class="bi bi-exclamation-triangle-fill"></i> Prazos Urgentes
            </button>
            <button class="view-type-btn view-type-btn-metricas <?= $view_type === 'metricas' ? 'active-metricas' : '' ?>"
                    onclick="changeViewType('metricas')" style="width:100%; margin-top:6px;">
                <i class="bi bi-activity"></i> Métricas &amp; Saúde
            </button>
            <?php endif; ?>
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
        
        <!-- Filtros de Métricas -->
        <?php if ($view_type === 'metricas'): ?>
        <div class="sidebar-section">
            <div class="sidebar-title">Período de Análise</div>
            <div class="period-btns">
                <?php foreach ([7,14,30,60] as $pd): ?>
                <button class="period-btn <?= ($metricas['analytics_days'] ?? 14) == $pd ? 'active' : '' ?>"
                        onclick="changeAnalyticsDays(<?= $pd ?>)">
                    <?= $pd ?>d
                </button>
                <?php endforeach; ?>
            </div>
            <div class="filter-label" style="margin-top:8px;">Personalizado</div>
            <div style="display:flex; gap:6px; align-items:center;">
                <input type="number" id="customDaysInput" class="form-control"
                       value="<?= $metricas['analytics_days'] ?? 14 ?>" min="7" max="60"
                       style="width:70px; font-size:12px; padding:4px 6px;">
                <button class="btn-apply-filters" style="padding:6px;" onclick="applyCustomDays()">OK</button>
            </div>
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
                    <option value="semana" <?= $view_range === 'semana' ? 'selected' : '' ?>>4 Semanas</option>
                    <option value="mes" <?= $view_range === 'mes' ? 'selected' : '' ?>>2 Meses</option>
                    <option value="trimestre" <?= $view_range === 'trimestre' ? 'selected' : '' ?>>3 Meses</option>
                    <option value="semestre" <?= $view_range === 'semestre' ? 'selected' : '' ?>>6 Meses</option>
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
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div class="gantt-title">
                        <?php if ($view_type === 'urgentes'): ?>
                            <i class="bi bi-exclamation-triangle-fill text-danger"></i> Prazos Urgentes
                        <?php elseif ($view_type === 'metricas'): ?>
                            <i class="bi bi-activity" style="color:#6f42c1"></i> Métricas &amp; Saúde da Equipa
                        <?php elseif ($view_type === 'sprints'): ?>
                            <i class="bi bi-calendar3"></i> Gantt de Sprints
                        <?php else: ?>
                            <i class="bi bi-calendar3"></i> Gantt de Entregáveis/PPS/KPIs
                        <?php endif; ?>
                    </div>
                    <div class="gantt-info">
                        <?php if ($view_type === 'urgentes'): ?>
                        <div class="gantt-info-item">
                            <i class="bi bi-calendar-event"></i>
                            Atrasados e a fechar nas próximas 3 semanas
                        </div>
                        <div class="gantt-info-item">
                            <i class="bi bi-list-ol"></i>
                            <?= count($urgentes_deliverables) ?> entregável(is) · <?= count($urgentes_sprints) ?> sprint(s)
                        </div>
                        <?php elseif ($view_type === 'metricas'): ?>
                        <div class="gantt-info-item">
                            <i class="bi bi-calendar-range"></i>
                            Últimos <?= $metricas['analytics_days'] ?? 14 ?> dias
                        </div>
                        <div class="gantt-info-item">
                            <i class="bi bi-people"></i>
                            <?= count($users) ?> colaboradores
                        </div>
                        <?php else: ?>
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
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Controles de Navegação Temporal -->
                <?php if ($view_type !== 'urgentes' && $view_type !== 'metricas'): ?>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button class="btn btn-sm btn-outline-secondary" onclick="navigateTime(-1)" title="Período Anterior">
                        <i class="bi bi-chevron-left"></i> Anterior
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="navigateTime(0)" title="Voltar para Hoje">
                        <i class="bi bi-house-fill"></i> Hoje
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="navigateTime(1)" title="Próximo Período">
                        Próximo <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <?php endif; ?>
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
            // Renderizar Vista de Urgentes
            elseif ($view_type === 'urgentes'): ?>
                <div class="urgentes-container">

                    <!-- Tabela de Entregáveis Urgentes -->
                    <div class="urgentes-table-section">
                        <div class="urgentes-table-title text-danger">
                            <i class="bi bi-check2-square"></i> Entregáveis / PPS / KPIs Urgentes
                        </div>
                        <?php if (empty($urgentes_deliverables)): ?>
                            <p class="urgentes-empty"><i class="bi bi-check-circle text-success"></i> Nenhum entregável atrasado ou a fechar nas próximas 3 semanas.</p>
                        <?php else: ?>
                        <table class="urgentes-table">
                            <thead>
                                <tr>
                                    <th>Entregável</th>
                                    <th>Projeto</th>
                                    <th>Responsável</th>
                                    <th>Data de Entrega</th>
                                    <th>Estado</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($urgentes_deliverables as $ud):
                                $dias = (int)$ud['dias_restantes'];
                                $row_class = $dias < 0 ? 'atrasado' : '';
                                if ($dias < 0) {
                                    $badge = '<span class="urgentes-badge-atrasado"><i class="bi bi-exclamation-triangle-fill"></i> ' . abs($dias) . ' dia(s) atrasado</span>';
                                } elseif ($dias === 0) {
                                    $badge = '<span class="urgentes-badge-hoje"><i class="bi bi-alarm-fill"></i> Hoje</span>';
                                } else {
                                    $badge = '<span class="urgentes-badge-breve"><i class="bi bi-hourglass-split"></i> ' . $dias . ' dia(s)</span>';
                                }
                                $uid = 'deliv_' . $ud['id'];
                            ?>
                                <tr class="<?= $row_class ?>">
                                    <td>
                                        <a href="?tab=projectos&project_id=<?= $ud['project_id'] ?>" style="font-weight:600; text-decoration:none; color:inherit;">
                                            <?= htmlspecialchars($ud['title']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($ud['project_short_name']) ?> — <?= htmlspecialchars($ud['project_title']) ?></td>
                                    <td><?= $ud['owner_name'] ? htmlspecialchars($ud['owner_name']) : '<span class="text-muted">—</span>' ?></td>
                                    <td><?= date('d/m/Y', strtotime($ud['due_date'])) ?></td>
                                    <td><?= $badge ?></td>
                                    <td class="urgentes-actions-cell">
                                        <select class="urgentes-select"
                                                onchange="ganttUpdateDeliverableStatus(<?= $ud['id'] ?>, this.value, '<?= $uid ?>')"
                                                title="Alterar estado">
                                            <option value="pending"      <?= $ud['status'] === 'pending'      ? 'selected' : '' ?>>Pendente</option>
                                            <option value="in-progress"  <?= $ud['status'] === 'in-progress'  ? 'selected' : '' ?>>Em progresso</option>
                                            <option value="completed"    <?= $ud['status'] === 'completed'    ? 'selected' : '' ?>>Concluído</option>
                                        </select>
                                        <input type="date" class="urgentes-date-input"
                                               value="<?= htmlspecialchars($ud['due_date']) ?>"
                                               onchange="ganttUpdateDeliverableDueDate(<?= $ud['id'] ?>, this.value, '<?= $uid ?>')"
                                               title="Alterar data de entrega">
                                        <div id="ind_<?= $uid ?>" class="urgentes-save-indicator"></div>
                                        <?php $nc = $note_counts['deliverable'][$ud['id']] ?? 0; ?>
                                        <button class="btn-notas <?= $nc > 0 ? 'has-notes' : '' ?>"
                                                id="notebtn_<?= $uid ?>"
                                                onclick="openNotesPanel('deliverable', <?= $ud['id'] ?>, <?= htmlspecialchars(json_encode($ud['title'])) ?>, <?= $ud['project_id'] ?>)">
                                            <i class="bi bi-sticky"></i> Notas
                                            <span class="note-count" id="nc_<?= $uid ?>"><?= $nc > 0 ? $nc : '' ?></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <!-- Tabela de Sprints Urgentes -->
                    <div class="urgentes-table-section">
                        <div class="urgentes-table-title text-danger">
                            <i class="bi bi-lightning-charge-fill"></i> Sprints Urgentes
                        </div>
                        <?php if (empty($urgentes_sprints)): ?>
                            <p class="urgentes-empty"><i class="bi bi-check-circle text-success"></i> Nenhuma sprint atrasada ou a fechar nas próximas 3 semanas.</p>
                        <?php else: ?>
                        <table class="urgentes-table">
                            <thead>
                                <tr>
                                    <th>Sprint</th>
                                    <th>Responsável</th>
                                    <th>Data de Fecho</th>
                                    <th>Estado</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($urgentes_sprints as $us):
                                $dias = (int)$us['dias_restantes'];
                                $row_class = $dias < 0 ? 'atrasado' : '';
                                if ($dias < 0) {
                                    $badge = '<span class="urgentes-badge-atrasado"><i class="bi bi-exclamation-triangle-fill"></i> ' . abs($dias) . ' dia(s) atrasada</span>';
                                } elseif ($dias === 0) {
                                    $badge = '<span class="urgentes-badge-hoje"><i class="bi bi-alarm-fill"></i> Hoje</span>';
                                } else {
                                    $badge = '<span class="urgentes-badge-breve"><i class="bi bi-hourglass-split"></i> ' . $dias . ' dia(s)</span>';
                                }
                                $sid = 'sprint_' . $us['id'];
                            ?>
                                <tr class="<?= $row_class ?>">
                                    <td>
                                        <a href="?tab=sprints&sprint_id=<?= $us['id'] ?>" style="font-weight:600; text-decoration:none; color:inherit;">
                                            <?= htmlspecialchars($us['nome']) ?>
                                        </a>
                                    </td>
                                    <td><?= $us['responsavel_nome'] ? htmlspecialchars($us['responsavel_nome']) : '<span class="text-muted">—</span>' ?></td>
                                    <td><?= date('d/m/Y', strtotime($us['data_fim'])) ?></td>
                                    <td><?= $badge ?></td>
                                    <td class="urgentes-actions-cell">
                                        <select class="urgentes-select"
                                                onchange="ganttUpdateSprintEstado(<?= $us['id'] ?>, this.value, '<?= $sid ?>')"
                                                title="Alterar estado">
                                            <option value="aberta"      <?= $us['estado'] === 'aberta'      ? 'selected' : '' ?>>Aberta</option>
                                            <option value="em execução" <?= $us['estado'] === 'em execução' ? 'selected' : '' ?>>Em execução</option>
                                            <option value="suspensa"    <?= $us['estado'] === 'suspensa'    ? 'selected' : '' ?>>Suspensa</option>
                                            <option value="concluída"   <?= $us['estado'] === 'concluída'   ? 'selected' : '' ?>>Concluída</option>
                                        </select>
                                        <input type="date" class="urgentes-date-input"
                                               value="<?= htmlspecialchars($us['data_fim']) ?>"
                                               onchange="ganttUpdateSprintDataFim(<?= $us['id'] ?>, this.value, '<?= $sid ?>')"
                                               title="Alterar data de fecho">
                                        <div id="ind_<?= $sid ?>" class="urgentes-save-indicator"></div>
                                        <?php $nc = $note_counts['sprint'][$us['id']] ?? 0; ?>
                                        <button class="btn-notas <?= $nc > 0 ? 'has-notes' : '' ?>"
                                                id="notebtn_<?= $sid ?>"
                                                onclick="openNotesPanel('sprint', <?= $us['id'] ?>, <?= htmlspecialchars(json_encode($us['nome'])) ?>, null)">
                                            <i class="bi bi-sticky"></i> Notas
                                            <span class="note-count" id="nc_<?= $sid ?>"><?= $nc > 0 ? $nc : '' ?></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                </div>

            <?php
            // Renderizar Vista de Métricas & Saúde
            elseif ($view_type === 'metricas'):
                $m = $metricas;
                $m_days      = $m['m_days'];
                $working_days= $m['working_days'];
                $n_working   = $m['n_working'];
                $tasks_day   = $m['m_tasks_day'];
                $sprints_day = $m['m_sprints_day'];
                $delivs_day  = $m['m_delivs_day'];
                $compliance  = $m['m_compliance'];
                $report_rank = $m['m_report_rank'];
                $meetings    = $m['m_meetings'];
                $overdue     = $m['m_overdue'];
                $stalled     = $m['m_stalled'];
                $ov_sprints  = $m['m_overdue_sprints'];
                $adays       = $m['analytics_days'];

                // Totais do período
                $tot_tasks   = array_sum($tasks_day);
                $tot_sprints = array_sum($sprints_day);
                $tot_delivs  = array_sum($delivs_day);
                $tot_reports = array_sum(array_map('count', $compliance));
                $tot_overdue = array_sum(array_column($overdue, 'n'));
                $tot_stalled = count($stalled);
                $tot_ov_sp   = count($ov_sprints);

                // Burn chart: max value for scale
                $chart_max = 1;
                foreach ($m_days as $day)
                    $chart_max = max($chart_max, $tasks_day[$day], $sprints_day[$day], $delivs_day[$day]);

                // SVG dimensions
                $svgW = 820; $svgH = 200;
                $padL = 42; $padR = 12; $padT = 14; $padB = 38;
                $cW = $svgW - $padL - $padR;
                $cH = $svgH - $padT - $padB;
                $n = count($m_days);

                function chartX($i, $n, $padL, $cW) {
                    return $padL + ($n > 1 ? ($i / ($n-1)) * $cW : $cW/2);
                }
                function chartY($v, $max, $padT, $cH) {
                    return $padT + (1 - $v / max(1,$max)) * $cH;
                }
                function svgPolyline($days, $values, $max, $padL, $padT, $cW, $cH, $color, $strokeW=2) {
                    $pts = [];
                    $n = count($days);
                    foreach ($days as $i => $day) {
                        $x = chartX($i, $n, $padL, $cW);
                        $y = chartY($values[$day] ?? 0, $max, $padT, $cH);
                        $pts[] = round($x,1) . ',' . round($y,1);
                    }
                    $p = implode(' ', $pts);
                    return "<polyline points=\"$p\" fill=\"none\" stroke=\"$color\" stroke-width=\"$strokeW\" stroke-linejoin=\"round\" stroke-linecap=\"round\"/>";
                }
                function svgDots($days, $values, $max, $padL, $padT, $cW, $cH, $color, $r=3) {
                    $out = '';
                    $n = count($days);
                    foreach ($days as $i => $day) {
                        $v = $values[$day] ?? 0;
                        if ($v > 0) {
                            $x = round(chartX($i, $n, $padL, $cW), 1);
                            $y = round(chartY($v, $max, $padT, $cH), 1);
                            $out .= "<circle cx=\"$x\" cy=\"$y\" r=\"$r\" fill=\"$color\" stroke=\"white\" stroke-width=\"1\"/>";
                            $out .= "<title>$day: $v</title>";
                        }
                    }
                    return $out;
                }
            ?>
            <div class="metricas-container">

                <!-- ── SUMÁRIO ──────────────────────────────────────── -->
                <div class="metricas-section">
                    <div class="metricas-title">
                        <i class="bi bi-speedometer2" style="color:#6f42c1"></i> Sumário (últimos <?= $adays ?> dias)
                    </div>
                    <div class="metricas-summary">
                        <div class="metricas-box info">
                            <div class="mval"><?= $tot_tasks ?></div>
                            <div class="mlbl">Tarefas concluídas</div>
                        </div>
                        <div class="metricas-box info">
                            <div class="mval"><?= $tot_sprints ?></div>
                            <div class="mlbl">Sprints fechadas</div>
                        </div>
                        <div class="metricas-box info">
                            <div class="mval"><?= $tot_delivs ?></div>
                            <div class="mlbl">Entregáveis concluídos</div>
                        </div>
                        <div class="metricas-box <?= $tot_reports > 0 ? 'ok' : 'warn' ?>">
                            <div class="mval"><?= $tot_reports ?></div>
                            <div class="mlbl">Daily reports</div>
                        </div>
                        <div class="metricas-box <?= $tot_overdue > 0 ? 'warn' : 'ok' ?>">
                            <div class="mval"><?= $tot_overdue ?></div>
                            <div class="mlbl">Tarefas atrasadas</div>
                        </div>
                        <div class="metricas-box <?= $tot_stalled > 0 ? 'warn' : 'ok' ?>">
                            <div class="mval"><?= $tot_stalled ?></div>
                            <div class="mlbl">Tarefas paradas (&gt;7d)</div>
                        </div>
                        <div class="metricas-box <?= $tot_ov_sp > 0 ? 'warn' : 'ok' ?>">
                            <div class="mval"><?= $tot_ov_sp ?></div>
                            <div class="mlbl">Sprints atrasadas</div>
                        </div>
                    </div>
                </div>

                <!-- ── BURN CHART ──────────────────────────────────── -->
                <div class="metricas-section">
                    <div class="metricas-title">
                        <i class="bi bi-graph-up" style="color:#0d6efd"></i> Actividade Diária (concluídas)
                    </div>
                    <div class="burn-legend">
                        <span><span class="burn-legend-dot" style="background:#0d6efd"></span> Tarefas</span>
                        <span><span class="burn-legend-dot" style="background:#fd7e14"></span> Sprints</span>
                        <span><span class="burn-legend-dot" style="background:#198754"></span> Entregáveis</span>
                    </div>
                    <div class="burn-chart-wrap">
                    <svg class="burn-svg" viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" xmlns="http://www.w3.org/2000/svg">
                        <!-- Grid horizontal -->
                        <?php for ($gi = 0; $gi <= 4; $gi++):
                            $yg = round($padT + ($gi/4)*$cH);
                            $vg = round($chart_max * (1 - $gi/4));
                        ?>
                        <line x1="<?= $padL ?>" y1="<?= $yg ?>" x2="<?= $svgW-$padR ?>" y2="<?= $yg ?>"
                              stroke="#e9ecef" stroke-width="1"/>
                        <text x="<?= $padL-4 ?>" y="<?= $yg+4 ?>" text-anchor="end"
                              font-size="10" fill="#adb5bd"><?= $vg ?></text>
                        <?php endfor; ?>

                        <!-- Grid vertical + X labels -->
                        <?php
                        $label_step = max(1, (int)ceil($n / 14));
                        foreach ($m_days as $i => $day):
                            $xg = round(chartX($i, $n, $padL, $cW));
                            $is_weekend = in_array(date('N', strtotime($day)), [6,7]);
                        ?>
                        <?php if ($is_weekend): ?>
                        <rect x="<?= $xg - ($n>1?$cW/($n-1)/2:0) ?>" y="<?= $padT ?>"
                              width="<?= $n>1?$cW/($n-1):$cW ?>" height="<?= $cH ?>"
                              fill="#f8f9fa" opacity="0.6"/>
                        <?php endif; ?>
                        <?php if ($i % $label_step === 0): ?>
                        <line x1="<?= $xg ?>" y1="<?= $padT ?>" x2="<?= $xg ?>" y2="<?= $padT+$cH ?>"
                              stroke="#dee2e6" stroke-width="0.8"/>
                        <text x="<?= $xg ?>" y="<?= $svgH - 22 ?>" text-anchor="middle"
                              font-size="9" fill="#6c757d"><?= date('d/m', strtotime($day)) ?></text>
                        <text x="<?= $xg ?>" y="<?= $svgH - 10 ?>" text-anchor="middle"
                              font-size="8" fill="#adb5bd"><?= date('D', strtotime($day)) ?></text>
                        <?php endif; ?>
                        <?php endforeach; ?>

                        <!-- Linha zero -->
                        <line x1="<?= $padL ?>" y1="<?= $padT+$cH ?>" x2="<?= $svgW-$padR ?>" y2="<?= $padT+$cH ?>"
                              stroke="#495057" stroke-width="1.5"/>
                        <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $padT+$cH ?>"
                              stroke="#495057" stroke-width="1.5"/>

                        <!-- Linhas de dados -->
                        <?= svgPolyline($m_days, $tasks_day,   $chart_max, $padL, $padT, $cW, $cH, '#0d6efd', 2) ?>
                        <?= svgPolyline($m_days, $sprints_day, $chart_max, $padL, $padT, $cW, $cH, '#fd7e14', 2) ?>
                        <?= svgPolyline($m_days, $delivs_day,  $chart_max, $padL, $padT, $cW, $cH, '#198754', 2) ?>

                        <!-- Pontos de dados -->
                        <?= svgDots($m_days, $tasks_day,   $chart_max, $padL, $padT, $cW, $cH, '#0d6efd') ?>
                        <?= svgDots($m_days, $sprints_day, $chart_max, $padL, $padT, $cW, $cH, '#fd7e14') ?>
                        <?= svgDots($m_days, $delivs_day,  $chart_max, $padL, $padT, $cW, $cH, '#198754') ?>
                    </svg>
                    </div>
                    <?php if ($tot_tasks + $tot_sprints + $tot_delivs === 0): ?>
                    <p style="color:#6c757d; font-style:italic; font-size:13px; margin-top:8px;">
                        <i class="bi bi-info-circle"></i> Sem actividade concluída registada no período.
                        Os itens são contados pela data de última actualização para o estado "concluído".
                    </p>
                    <?php endif; ?>
                </div>

                <!-- ── COMPLIANCE DE REPORTS ──────────────────────── -->
                <div class="metricas-section">
                    <div class="metricas-title">
                        <i class="bi bi-journal-check" style="color:#198754"></i>
                        Compliance de Daily Reports
                        <span style="font-size:12px; font-weight:400; color:#6c757d;">
                            (<?= $n_working ?> dia<?= $n_working!=1?'s':'' ?> útil<?= $n_working!=1?'eis':'' ?> no período)
                        </span>
                    </div>
                    <?php if (empty($compliance) && !$m['m_has_reports'] ?? true): ?>
                    <p style="color:#6c757d; font-style:italic;">Módulo de Daily Reports não disponível.</p>
                    <?php else: ?>
                    <div style="overflow-x:auto;">
                    <table class="compliance-table">
                        <thead>
                            <tr>
                                <th class="name-col">Colaborador</th>
                                <?php foreach ($m_days as $day):
                                    $is_wknd = in_array(date('N', strtotime($day)), [6,7]);
                                ?>
                                <th style="<?= $is_wknd ? 'opacity:0.4;' : '' ?> font-size:9px;">
                                    <?= date('d', strtotime($day)) ?><br>
                                    <span style="font-weight:400;"><?= date('D', strtotime($day)) ?></span>
                                </th>
                                <?php endforeach; ?>
                                <th style="min-width:80px;">% / Faltas</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report_rank as $ur): ?>
                        <tr>
                            <td class="name-col"><?= htmlspecialchars($ur['username']) ?></td>
                            <?php foreach ($m_days as $day):
                                $is_wknd = in_array(date('N', strtotime($day)), [6,7]);
                                $has_rep = isset($compliance[$ur['username']][$day]);
                            ?>
                            <td>
                                <?php if ($is_wknd): ?>
                                <span class="cell-report-wknd" title="fim de semana">·</span>
                                <?php elseif ($has_rep): ?>
                                <span class="cell-report-ok" title="report entregue">✔</span>
                                <?php else: ?>
                                <span class="cell-report-miss" title="sem report">✗</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td style="white-space:nowrap; padding-left:8px;">
                                <?php
                                $pct = $ur['pct'];
                                $fill_class = $pct >= 80 ? '' : ($pct >= 50 ? 'mid' : 'low');
                                ?>
                                <span style="font-size:11px; font-weight:600;
                                    color:<?= $pct>=80?'#198754':($pct>=50?'#664d03':'#842029') ?>">
                                    <?= $pct ?>%
                                </span>
                                <div class="pct-bar-wrap" style="margin-left:4px;">
                                    <div class="pct-bar-fill <?= $fill_class ?>" style="width:<?= $pct ?>%;"></div>
                                </div>
                                <?php if ($ur['missed'] > 0): ?>
                                <span style="font-size:10px; color:#dc3545; margin-left:4px;">
                                    −<?= $ur['missed'] ?>
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ── PONTOS CRÍTICOS ────────────────────────────── -->
                <div class="metricas-section">
                    <div class="metricas-title">
                        <i class="bi bi-shield-exclamation" style="color:#dc3545"></i> Pontos Críticos
                    </div>
                    <div class="critical-grid">

                        <!-- Faltas ao report -->
                        <div class="critical-panel">
                            <div class="critical-header" style="background:#f8d7da;">
                                <i class="bi bi-journal-x" style="color:#842029"></i>
                                <span style="color:#842029;">Mais faltas ao Daily Report</span>
                            </div>
                            <div class="critical-body">
                            <?php
                            $shown = array_filter($report_rank, fn($r) => $r['missed'] > 0);
                            if (empty($shown)): ?>
                            <div class="critical-empty"><i class="bi bi-check-circle text-success"></i> Todos entregaram reports</div>
                            <?php else: foreach (array_slice($shown, 0, 8) as $r): ?>
                            <div class="critical-row">
                                <span class="cname"><?= htmlspecialchars($r['username']) ?></span>
                                <span class="cval <?= $r['missed']>=$n_working*0.5?'cval-danger':'cval-warn' ?>">
                                    <?= $r['missed'] ?> falta<?= $r['missed']!=1?'s':'' ?> · <?= $r['pct'] ?>%
                                </span>
                            </div>
                            <?php endforeach; endif; ?>
                            </div>
                        </div>

                        <!-- Tarefas atrasadas por pessoa -->
                        <div class="critical-panel">
                            <div class="critical-header" style="background:#fff3cd;">
                                <i class="bi bi-clock-history" style="color:#664d03"></i>
                                <span style="color:#664d03;">Tarefas atrasadas por pessoa</span>
                            </div>
                            <div class="critical-body">
                            <?php if (empty($overdue)): ?>
                            <div class="critical-empty"><i class="bi bi-check-circle text-success"></i> Sem tarefas atrasadas</div>
                            <?php else: foreach ($overdue as $ou): ?>
                            <div class="critical-row">
                                <span class="cname"><?= htmlspecialchars($ou['username']) ?></span>
                                <span class="cval <?= $ou['n']>=5?'cval-danger':($ou['n']>=2?'cval-warn':'cval-ok') ?>">
                                    <?= $ou['n'] ?> tarefa<?= $ou['n']!=1?'s':'' ?>
                                </span>
                            </div>
                            <?php endforeach; endif; ?>
                            </div>
                        </div>

                        <!-- Sprints atrasadas -->
                        <div class="critical-panel">
                            <div class="critical-header" style="background:#cff4fc;">
                                <i class="bi bi-lightning-charge" style="color:#055160"></i>
                                <span style="color:#055160;">Sprints atrasadas</span>
                            </div>
                            <div class="critical-body">
                            <?php if (empty($ov_sprints)): ?>
                            <div class="critical-empty"><i class="bi bi-check-circle text-success"></i> Sem sprints atrasadas</div>
                            <?php else: foreach ($ov_sprints as $os): ?>
                            <div class="critical-row">
                                <span class="cname" style="font-size:12px;"><?= htmlspecialchars($os['nome']) ?><?php if($os['responsavel']): ?> <span style="color:#6c757d;font-weight:400;">· <?= htmlspecialchars($os['responsavel']) ?></span><?php endif; ?></span>
                                <span class="cval cval-danger">+<?= $os['dias_atraso'] ?>d</span>
                            </div>
                            <?php endforeach; endif; ?>
                            </div>
                        </div>

                        <!-- Tarefas paradas -->
                        <div class="critical-panel">
                            <div class="critical-header" style="background:#e2d9f3;">
                                <i class="bi bi-pause-circle" style="color:#3d0a91"></i>
                                <span style="color:#3d0a91;">Tarefas em execução sem actualização (&gt;7d)</span>
                            </div>
                            <div class="critical-body">
                            <?php if (empty($stalled)): ?>
                            <div class="critical-empty"><i class="bi bi-check-circle text-success"></i> Sem tarefas paradas</div>
                            <?php else: foreach (array_slice($stalled, 0, 10) as $st): ?>
                            <div class="critical-row">
                                <span class="cname" style="font-size:12px;">
                                    <?= htmlspecialchars($st['titulo']) ?>
                                    <span style="color:#6c757d; font-weight:400;">· <?= htmlspecialchars($st['username']) ?></span>
                                </span>
                                <span class="cval <?= $st['dias']>=14?'cval-danger':'cval-warn' ?>">
                                    <?= $st['dias'] ?>d
                                </span>
                            </div>
                            <?php endforeach;
                            if (count($stalled) > 10): ?>
                            <div class="critical-empty">+ <?= count($stalled)-10 ?> mais...</div>
                            <?php endif; endif; ?>
                            </div>
                        </div>

                    </div><!-- /critical-grid -->
                </div>

                <!-- ── REUNIÕES DO PERÍODO ────────────────────────── -->
                <?php if (!empty($meetings)): ?>
                <div class="metricas-section">
                    <div class="metricas-title">
                        <i class="bi bi-calendar-event" style="color:#0dcaf0"></i>
                        Eventos no Período
                        <span style="font-size:12px; font-weight:400; color:#6c757d;">
                            (<?= count($meetings) ?> evento<?= count($meetings)!=1?'s':'' ?> · sem rastreio de presenças)
                        </span>
                    </div>
                    <?php
                    $tipo_labels = [
                        'tribe'=>'TRIBE','aulas'=>'Aulas','demonstração'=>'Demo',
                        'campo'=>'Campo','férias'=>'Férias','outro'=>'Outro'
                    ];
                    $tipo_classes = [
                        'tribe'=>'tipo-tribe','aulas'=>'tipo-aulas','demonstração'=>'tipo-demo',
                        'campo'=>'tipo-outro','férias'=>'tipo-outro','outro'=>'tipo-outro'
                    ];
                    foreach ($meetings as $mv): ?>
                    <div class="meeting-item">
                        <div class="meeting-date-badge">
                            <?= date('d/m', strtotime($mv['data'])) ?><br>
                            <span style="font-weight:400; font-size:9px;"><?= date('D', strtotime($mv['data'])) ?></span>
                            <?php if ($mv['hora']): ?><br><span style="font-weight:400;"><?= substr($mv['hora'],0,5) ?></span><?php endif; ?>
                        </div>
                        <div>
                            <span class="meeting-tipo <?= $tipo_classes[$mv['tipo']] ?? 'tipo-outro' ?>">
                                <?= htmlspecialchars($tipo_labels[$mv['tipo']] ?? ucfirst($mv['tipo'])) ?>
                            </span>
                            <span style="margin-left:8px; font-size:13px;"><?= htmlspecialchars($mv['descricao'] ?? '') ?></span>
                            <?php if ($mv['criador']): ?>
                            <span style="color:#6c757d; font-size:11px; margin-left:6px;">· <?= htmlspecialchars($mv['criador']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div><!-- /metricas-container -->

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

<!-- ══ OVERLAY + PAINEL DE NOTAS ══════════════════════════════════════ -->
<div id="notesOverlay" onclick="closeNotesPanel()"></div>

<div id="notesPanel">
    <div class="notes-panel-header">
        <button class="notes-panel-close" onclick="closeNotesPanel()" title="Fechar">✕</button>
        <h4><i class="bi bi-sticky-fill"></i> <span id="notesPanelType"></span></h4>
        <div class="notes-subtitle" id="notesPanelTitle"></div>
    </div>

    <div class="notes-panel-body">
        <!-- Nova nota -->
        <div class="new-note-area">
            <textarea id="newNoteText" placeholder="Escreve uma nota, observação ou ponto de atenção…"></textarea>
            <div class="new-note-actions">
                <button class="btn-save-note" id="btnSaveNote" onclick="saveNote()">
                    <i class="bi bi-send"></i> Guardar nota
                </button>
                <button class="btn-create-task-from-note" onclick="toggleTaskForm('new')">
                    <i class="bi bi-plus-circle"></i> Criar tarefa
                </button>
            </div>
            <!-- Formulário inline: criar tarefa (a partir de texto livre, sem nota prévia) -->
            <div class="task-form-inline" id="taskFormNew">
                <label>Título da tarefa *</label>
                <textarea id="tfn_titulo" rows="2" placeholder="Título…"></textarea>
                <label>Descrição</label>
                <textarea id="tfn_desc" rows="2" placeholder="Detalhes…"></textarea>
                <div class="task-form-row">
                    <div>
                        <label>Data limite</label>
                        <input type="date" id="tfn_data">
                    </div>
                    <div>
                        <label>Responsável</label>
                        <select id="tfn_resp">
                            <option value="">—</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display:flex; gap:8px;">
                    <button class="btn-submit-task" onclick="submitTask(null)">
                        <i class="bi bi-check-circle"></i> Criar tarefa
                    </button>
                    <button class="btn-cancel-task" onclick="toggleTaskForm('new')">Cancelar</button>
                </div>
            </div>
        </div>

        <!-- Lista de notas -->
        <div class="notes-list">
            <div class="notes-list-title" id="notesListTitle" style="margin-top:16px;">Histórico</div>
            <div id="notesList"><div class="notes-loading"><i class="bi bi-hourglass-split"></i> A carregar…</div></div>
        </div>
    </div>

    <div class="notes-panel-footer" style="font-size:11px; color:#6c757d; text-align:center;">
        As notas são partilhadas com toda a equipa
    </div>
</div>

<!-- Template de formulário inline de tarefa (por nota existente, clonado por JS) -->
<template id="taskFormTemplate">
    <div class="task-form-inline visible" style="margin-top:8px;">
        <label>Título da tarefa *</label>
        <textarea class="tf-titulo" rows="2" placeholder="Título…"></textarea>
        <label>Descrição</label>
        <textarea class="tf-desc" rows="2" placeholder="Detalhes…"></textarea>
        <div class="task-form-row">
            <div>
                <label>Data limite</label>
                <input type="date" class="tf-data">
            </div>
            <div>
                <label>Responsável</label>
                <select class="tf-resp">
                    <option value="">—</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:flex; gap:8px;">
            <button class="btn-submit-task tf-submit"><i class="bi bi-check-circle"></i> Criar tarefa</button>
            <button class="btn-cancel-task tf-cancel">Cancelar</button>
        </div>
    </div>
</template>

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
// Função para navegar no tempo
function navigateTime(direction) {
    const url = new URL(window.location.href);
    
    if (direction === 0) {
        // Voltar para hoje - remove o offset
        url.searchParams.delete('time_offset');
    } else {
        // Avançar ou retroceder
        const currentOffset = parseInt(url.searchParams.get('time_offset') || '0');
        const newOffset = currentOffset + direction;
        
        if (newOffset === 0) {
            url.searchParams.delete('time_offset');
        } else {
            url.searchParams.set('time_offset', newOffset.toString());
        }
    }
    
    window.location.href = url.toString();
}

// ============================================================================
// PAINEL DE NOTAS
// ============================================================================
let _notesRef = { type: null, id: null, title: null, projectId: null };
let _activeNoteBtnId = null;

function openNotesPanel(refType, refId, title, projectId) {
    _notesRef = { type: refType, id: refId, title: title, projectId: projectId };
    _activeNoteBtnId = 'notebtn_' + (refType === 'deliverable' ? 'deliv_' : 'sprint_') + refId;

    document.getElementById('notesPanelType').textContent =
        refType === 'deliverable' ? 'Entregável' : 'Sprint';
    document.getElementById('notesPanelTitle').textContent = title;
    document.getElementById('newNoteText').value = '';
    document.getElementById('tfn_titulo').value = '';
    document.getElementById('tfn_desc').value = '';
    document.getElementById('tfn_data').value = '';
    document.getElementById('tfn_resp').value = '';
    document.getElementById('taskFormNew').classList.remove('visible');

    document.getElementById('notesPanel').classList.add('open');
    document.getElementById('notesOverlay').classList.add('open');
    document.getElementById('newNoteText').focus();

    loadNotes();
}

function closeNotesPanel() {
    document.getElementById('notesPanel').classList.remove('open');
    document.getElementById('notesOverlay').classList.remove('open');
}

function loadNotes() {
    const list = document.getElementById('notesList');
    list.innerHTML = '<div class="notes-loading"><i class="bi bi-hourglass-split"></i> A carregar…</div>';

    const fd = new FormData();
    fd.append('action', 'get_notes');
    fd.append('ref_type', _notesRef.type);
    fd.append('ref_id', _notesRef.id);

    fetch('gantt_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { list.innerHTML = '<div class="notes-empty">Erro ao carregar notas.</div>'; return; }
            renderNotes(res.notes);
        })
        .catch(() => { list.innerHTML = '<div class="notes-empty">Erro de rede.</div>'; });
}

function renderNotes(notes) {
    const list = document.getElementById('notesList');
    const titleEl = document.getElementById('notesListTitle');
    titleEl.textContent = notes.length > 0
        ? `Histórico (${notes.length} nota${notes.length !== 1 ? 's' : ''})`
        : 'Histórico';

    // Atualizar badge no botão da linha
    if (_activeNoteBtnId) {
        const btn  = document.getElementById(_activeNoteBtnId);
        const ncEl = document.getElementById('nc_' + _activeNoteBtnId.replace('notebtn_',''));
        if (btn) { btn.classList.toggle('has-notes', notes.length > 0); }
        if (ncEl) { ncEl.textContent = notes.length > 0 ? notes.length : ''; }
    }

    if (notes.length === 0) {
        list.innerHTML = '<div class="notes-empty"><i class="bi bi-sticky"></i><br>Sem notas ainda.<br>Adiciona a primeira acima.</div>';
        return;
    }

    list.innerHTML = notes.map(n => {
        const dt = new Date(n.created_at);
        const dtStr = dt.toLocaleDateString('pt-PT') + ' ' + dt.toLocaleTimeString('pt-PT', {hour:'2-digit', minute:'2-digit'});
        const noteHtml = n.nota.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        return `
        <div class="note-item" id="note_${n.id}">
            <div class="note-item-meta">
                <span><span class="note-item-author">${n.autor_name || '—'}</span> · ${dtStr}</span>
            </div>
            <div class="note-item-text">${noteHtml}</div>
            <div class="note-item-actions">
                <button class="btn-note-task" onclick="toggleTaskFormFromNote(${n.id}, ${JSON.stringify(n.nota)})">
                    <i class="bi bi-plus-circle"></i> Criar tarefa
                </button>
                <button class="btn-note-delete" onclick="deleteNote(${n.id})">
                    <i class="bi bi-trash"></i> Apagar
                </button>
            </div>
            <div id="taskform_${n.id}"></div>
        </div>`;
    }).join('');
}

function saveNote() {
    const text = document.getElementById('newNoteText').value.trim();
    if (!text) { document.getElementById('newNoteText').focus(); return; }

    const btn = document.getElementById('btnSaveNote');
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> A guardar…';

    const fd = new FormData();
    fd.append('action', 'save_note');
    fd.append('ref_type', _notesRef.type);
    fd.append('ref_id', _notesRef.id);
    fd.append('nota', text);

    fetch('gantt_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-send"></i> Guardar nota';
            if (res.success) {
                document.getElementById('newNoteText').value = '';
                loadNotes();
            } else { alert('Erro: ' + (res.message || 'Desconhecido')); }
        })
        .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send"></i> Guardar nota'; alert('Erro de rede.'); });
}

function deleteNote(noteId) {
    if (!confirm('Apagar esta nota?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_note');
    fd.append('note_id', noteId);
    fetch('gantt_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.success) loadNotes(); else alert('Erro ao apagar.'); })
        .catch(() => alert('Erro de rede.'));
}

function toggleTaskForm(context) {
    const form = document.getElementById('taskFormNew');
    form.classList.toggle('visible');
    if (form.classList.contains('visible')) {
        document.getElementById('tfn_titulo').focus();
    }
}

function toggleTaskFormFromNote(noteId, noteText) {
    const container = document.getElementById('taskform_' + noteId);
    if (container.children.length > 0) { container.innerHTML = ''; return; }

    const tpl = document.getElementById('taskFormTemplate');
    const clone = tpl.content.cloneNode(true);

    // Pré-preencher título com a 1ª linha da nota
    const firstLine = noteText.split('\n')[0].trim().substring(0, 200);
    clone.querySelector('.tf-titulo').value = firstLine;

    clone.querySelector('.tf-submit').addEventListener('click', () => submitTask(noteId));
    clone.querySelector('.tf-cancel').addEventListener('click', () => { container.innerHTML = ''; });

    container.appendChild(clone);
    container.querySelector('.tf-titulo').focus();
}

function submitTask(noteId) {
    let titulo, desc, data, resp;
    if (noteId === null) {
        titulo = document.getElementById('tfn_titulo').value.trim();
        desc   = document.getElementById('tfn_desc').value.trim();
        data   = document.getElementById('tfn_data').value;
        resp   = document.getElementById('tfn_resp').value;
    } else {
        const c = document.getElementById('taskform_' + noteId);
        titulo = c.querySelector('.tf-titulo').value.trim();
        desc   = c.querySelector('.tf-desc').value.trim();
        data   = c.querySelector('.tf-data').value;
        resp   = c.querySelector('.tf-resp').value;
    }

    if (!titulo) { alert('Título obrigatório.'); return; }

    const fd = new FormData();
    fd.append('action', 'create_task_from_note');
    fd.append('titulo', titulo);
    fd.append('descritivo', desc);
    if (data) fd.append('data_limite', data);
    if (resp) fd.append('responsavel', resp);
    if (_notesRef.projectId) fd.append('projeto_id', _notesRef.projectId);

    fetch('gantt_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const msg = `✅ ${res.message}`;
                if (noteId === null) {
                    document.getElementById('taskFormNew').classList.remove('visible');
                    document.getElementById('tfn_titulo').value = '';
                    document.getElementById('tfn_desc').value = '';
                    document.getElementById('tfn_data').value = '';
                    document.getElementById('tfn_resp').value = '';
                    // Mostrar feedback
                    const fb = document.createElement('div');
                    fb.style.cssText = 'color:#198754;font-size:12px;margin-top:6px;';
                    fb.textContent = msg;
                    document.querySelector('.new-note-actions').after(fb);
                    setTimeout(() => fb.remove(), 3000);
                } else {
                    document.getElementById('taskform_' + noteId).innerHTML =
                        `<div style="color:#198754;font-size:12px;padding:6px 0;">${msg}</div>`;
                    setTimeout(() => { const el = document.getElementById('taskform_' + noteId); if(el) el.innerHTML=''; }, 3000);
                }
            } else { alert('Erro: ' + (res.message || 'Desconhecido')); }
        })
        .catch(() => alert('Erro de rede.'));
}

// Fechar painel com Esc
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNotesPanel(); });

// ============================================================================
// PERÍODO DE ANÁLISE — MÉTRICAS
// ============================================================================
function changeAnalyticsDays(days) {
    const url = new URL(window.location.href);
    url.searchParams.set('view_type', 'metricas');
    url.searchParams.set('analytics_days', days);
    window.location.href = url.toString();
}
function applyCustomDays() {
    const v = parseInt(document.getElementById('customDaysInput')?.value);
    if (v >= 7 && v <= 60) changeAnalyticsDays(v);
    else alert('Introduza um valor entre 7 e 60 dias.');
}

// ============================================================================
// AÇÕES INLINE DA VISTA DE URGENTES
// ============================================================================
function ganttAjax(data, indicatorId) {
    const ind = document.getElementById('ind_' + indicatorId);
    if (ind) { ind.className = 'urgentes-save-indicator urgentes-save-wait'; ind.textContent = 'A guardar…'; }

    const formData = new FormData();
    Object.entries(data).forEach(([k, v]) => formData.append(k, v));

    fetch('gantt_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (ind) {
                if (res.success) {
                    ind.className = 'urgentes-save-indicator urgentes-save-ok';
                    ind.textContent = '✓ Guardado';
                } else {
                    ind.className = 'urgentes-save-indicator urgentes-save-err';
                    ind.textContent = '✗ ' + (res.message || 'Erro');
                }
                setTimeout(() => { ind.textContent = ''; }, 3000);
            }
        })
        .catch(() => {
            if (ind) {
                ind.className = 'urgentes-save-indicator urgentes-save-err';
                ind.textContent = '✗ Erro de rede';
                setTimeout(() => { ind.textContent = ''; }, 3000);
            }
        });
}

function ganttUpdateDeliverableStatus(id, status, uid) {
    ganttAjax({ action: 'update_deliverable_status', deliverable_id: id, status: status }, uid);
}
function ganttUpdateDeliverableDueDate(id, due_date, uid) {
    ganttAjax({ action: 'update_deliverable_due_date', deliverable_id: id, due_date: due_date }, uid);
}
function ganttUpdateSprintEstado(id, estado, sid) {
    ganttAjax({ action: 'update_sprint_estado', sprint_id: id, estado: estado }, sid);
}
function ganttUpdateSprintDataFim(id, data_fim, sid) {
    ganttAjax({ action: 'update_sprint_data_fim', sprint_id: id, data_fim: data_fim }, sid);
}

// Função para mudar tipo de vista
function changeViewType(type) {
    const url = new URL(window.location.href);
    url.searchParams.set('view_type', type);
    // Reset offset ao mudar de vista
    url.searchParams.delete('time_offset');
    window.location.href = url.toString();
}

// Função para atualizar filtros
function updateFilters() {
    const viewType = '<?= $view_type ?>';
    if (viewType === 'metricas') { changeAnalyticsDays(document.getElementById('customDaysInput')?.value || 14); return; }
    const viewRange = document.getElementById('viewRange').value;
    const orderBy = document.getElementById('orderBy').value;
    const showClosed = viewType === 'sprints' 
        ? (document.getElementById('showClosedSprints')?.checked ? '1' : '0')
        : (document.getElementById('showClosedDeliverables')?.checked ? '1' : '0');
    
    let url = `?tab=gantt&view_type=${viewType}&view_range=${viewRange}&order_by=${orderBy}&show_closed=${showClosed}`;
    
    // Preservar offset temporal se existir
    const currentOffset = new URLSearchParams(window.location.search).get('time_offset');
    if (currentOffset) {
        url += `&time_offset=${currentOffset}`;
    }
    
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
    
    // Atalhos de navegação temporal
    // Seta esquerda = período anterior
    // Seta direita = próximo período
    // Home = voltar para hoje
    if (!document.activeElement || document.activeElement.tagName !== 'INPUT') {
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            navigateTime(-1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            navigateTime(1);
        } else if (e.key === 'Home') {
            e.preventDefault();
            navigateTime(0);
        }
    }
});
</script>