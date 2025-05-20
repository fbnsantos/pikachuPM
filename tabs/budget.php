<?php
/**
 * Sistema de Gestão PMO - Alocação de Recursos
 * 
 * Este sistema permite a gestão completa de recursos para projetos:
 * - Recursos Humanos (alocação de pessoal)
 * - Equipamentos (alocação de hardware/software)
 * - Materiais (compras e aquisições)
 * - Orçamentos (controle financeiro)
 * - Tarefas (integração com sistema de tarefas)
 */

// Configuração da aplicação
require_once 'config.php';
session_start();


// Verificar autenticação
if (!isset($_SESSION['user_id']) || !isset($_SESSION['token'])) {
    header('Location: login.php');
    exit;
}

// Obter token e informações do usuário
$token = $_SESSION['token'];
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Funções auxiliares
function format_currency($value) {
    return number_format($value, 2, ',', '.') . ' €';
}

function format_date($date) {
    if (empty($date)) return '';
    $date_obj = new DateTime($date);
    return $date_obj->format('d/m/Y');
}

// Conectar ao banco de dados
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
} catch (Exception $e) {
    die("Erro ao conectar ao banco de dados: " . $e->getMessage());
}

// Obter lista de projetos do usuário
$stmt = $db->prepare('
    SELECT p.* 
    FROM projetos p
    JOIN projeto_membros pm ON p.id = pm.projeto_id
    WHERE pm.user_id = ?
    ORDER BY p.nome
');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$projetos_result = $stmt->get_result();
$projetos = [];
while ($row = $projetos_result->fetch_assoc()) {
    $projetos[] = $row;
}
$stmt->close();

// Definir projeto atual
$projeto_id = isset($_GET['projeto_id']) ? (int)$_GET['projeto_id'] : (isset($projetos[0]) ? $projetos[0]['id'] : 0);
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');

// Obter dados do projeto atual se selecionado
$projeto_atual = null;
if ($projeto_id > 0) {
    $stmt = $db->prepare('SELECT * FROM projetos WHERE id = ?');
    $stmt->bind_param('i', $projeto_id);
    $stmt->execute();
    $projeto_atual = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Determinar se o usuário é gerente do projeto
$is_gerente = false;
if ($projeto_id > 0) {
    $stmt = $db->prepare('
        SELECT * FROM projeto_membros 
        WHERE projeto_id = ? AND user_id = ? AND role = "gerente"
    ');
    $stmt->bind_param('ii', $projeto_id, $user_id);
    $stmt->execute();
    $is_gerente = $stmt->get_result()->num_rows > 0;
    $stmt->close();
}

// Processar ações
$mensagem = '';
$tipo_mensagem = '';

// Adicionar recurso humano
if (isset($_POST['add_recurso_humano']) && $is_gerente) {
    try {
        $pessoa_id = (int)$_POST['pessoa_id'];
        $tarefa_id = isset($_POST['tarefa_id']) ? (int)$_POST['tarefa_id'] : null;
        $data_inicio = $_POST['data_inicio'];
        $data_fim = $_POST['data_fim'];
        $horas_semanais = (float)$_POST['horas_semanais'];
        $custo_hora = (float)$_POST['custo_hora'];
        
        $stmt = $db->prepare('
            INSERT INTO alocacao_recursos_humanos 
            (projeto_id, pessoa_id, tarefa_id, data_inicio, data_fim, horas_semanais, custo_hora) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('iiissdd', $projeto_id, $pessoa_id, $tarefa_id, $data_inicio, $data_fim, $horas_semanais, $custo_hora);
        $stmt->execute();
        $stmt->close();
        
        $mensagem = 'Recurso humano adicionado com sucesso!';
        $tipo_mensagem = 'success';
    } catch (Exception $e) {
        $mensagem = 'Erro ao adicionar recurso humano: ' . $e->getMessage();
        $tipo_mensagem = 'danger';
    }
}

// Adicionar equipamento
if (isset($_POST['add_equipamento']) && $is_gerente) {
    try {
        $equipamento_id = (int)$_POST['equipamento_id'];
        $tarefa_id = isset($_POST['tarefa_id']) ? (int)$_POST['tarefa_id'] : null;
        $data_inicio = $_POST['data_inicio'];
        $data_fim = $_POST['data_fim'];
        $quantidade = (int)$_POST['quantidade'];
        $custo_unitario = (float)$_POST['custo_unitario'];
        
        $stmt = $db->prepare('
            INSERT INTO alocacao_equipamentos 
            (projeto_id, equipamento_id, tarefa_id, data_inicio, data_fim, quantidade, custo_unitario) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('iiissid', $projeto_id, $equipamento_id, $tarefa_id, $data_inicio, $data_fim, $quantidade, $custo_unitario);
        $stmt->execute();
        $stmt->close();
        
        $mensagem = 'Equipamento adicionado com sucesso!';
        $tipo_mensagem = 'success';
    } catch (Exception $e) {
        $mensagem = 'Erro ao adicionar equipamento: ' . $e->getMessage();
        $tipo_mensagem = 'danger';
    }
}

// Adicionar material
if (isset($_POST['add_material']) && $is_gerente) {
    try {
        $descricao = $_POST['descricao'];
        $tarefa_id = isset($_POST['tarefa_id']) ? (int)$_POST['tarefa_id'] : null;
        $data_aquisicao = $_POST['data_aquisicao'];
        $quantidade = (int)$_POST['quantidade'];
        $custo_unitario = (float)$_POST['custo_unitario'];
        $fornecedor = $_POST['fornecedor'];
        
        $stmt = $db->prepare('
            INSERT INTO aquisicao_materiais 
            (projeto_id, descricao, tarefa_id, data_aquisicao, quantidade, custo_unitario, fornecedor) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('isisids', $projeto_id, $descricao, $tarefa_id, $data_aquisicao, $quantidade, $custo_unitario, $fornecedor);
        $stmt->execute();
        $stmt->close();
        
        $mensagem = 'Material adicionado com sucesso!';
        $tipo_mensagem = 'success';
    } catch (Exception $e) {
        $mensagem = 'Erro ao adicionar material: ' . $e->getMessage();
        $tipo_mensagem = 'danger';
    }
}

// Obter membros da equipe do projeto
$membros_equipe = [];
if ($projeto_id > 0) {
    $stmt = $db->prepare('
        SELECT m.*, u.username, u.email, pm.role 
        FROM projeto_membros pm
        JOIN user_tokens u ON pm.user_id = u.user_id
        JOIN membros_equipe m ON u.user_id = m.user_id
        WHERE pm.projeto_id = ?
        ORDER BY u.username
    ');
    $stmt->bind_param('i', $projeto_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $membros_equipe[] = $row;
    }
    $stmt->close();
}

// Obter tarefas do projeto
$tarefas = [];
if ($projeto_id > 0) {
    $stmt = $db->prepare('
        SELECT t.*, u.username as responsavel_nome
        FROM todos t
        LEFT JOIN user_tokens u ON t.responsavel = u.user_id
        WHERE t.projeto_id = ?
        ORDER BY t.data_limite, t.titulo
    ');
    $stmt->bind_param('i', $projeto_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tarefas[] = $row;
    }
    $stmt->close();
}

// Obter recursos humanos alocados
$recursos_humanos = [];
if ($projeto_id > 0) {
    $stmt = $db->prepare('
        SELECT a.*, m.nome as pessoa_nome, t.titulo as tarefa_nome
        FROM alocacao_recursos_humanos a
        JOIN membros_equipe m ON a.pessoa_id = m.id
        LEFT JOIN todos t ON a.tarefa_id = t.id
        WHERE a.projeto_id = ? 
        AND (YEAR(a.data_inicio) = ? OR YEAR(a.data_fim) = ?)
        ORDER BY a.data_inicio
    ');
    $stmt->bind_param('iii', $projeto_id, $ano, $ano);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recursos_humanos[] = $row;
    }
    $stmt->close();
}

// Obter equipamentos alocados
$equipamentos = [];
if ($projeto_id > 0) {
    $stmt = $db->prepare('
        SELECT a.*, e.nome as equipamento_nome, t.titulo as tarefa_nome
        FROM alocacao_equipamentos a
        JOIN equipamentos e ON a.equipamento_id = e.id
        LEFT JOIN todos t ON a.tarefa_id = t.id
        WHERE a.projeto_id = ? 
        AND (YEAR(a.data_inicio) = ? OR YEAR(a.data_fim) = ?)
        ORDER BY a.data_inicio
    ');
    $stmt->bind_param('iii', $projeto_id, $ano, $ano);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $equipamentos[] = $row;
    }
    $stmt->close();
}

// Obter materiais adquiridos
$materiais = [];
if ($projeto_id > 0) {
    $stmt = $db->prepare('
        SELECT a.*, t.titulo as tarefa_nome
        FROM aquisicao_materiais a
        LEFT JOIN todos t ON a.tarefa_id = t.id
        WHERE a.projeto_id = ? 
        AND YEAR(a.data_aquisicao) = ?
        ORDER BY a.data_aquisicao
    ');
    $stmt->bind_param('ii', $projeto_id, $ano);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $materiais[] = $row;
    }
    $stmt->close();
}

// Calcular totais por tipo de recurso
$total_rh = 0;
foreach ($recursos_humanos as $rh) {
    // Calcula número de semanas entre datas
    $inicio = new DateTime($rh['data_inicio']);
    $fim = new DateTime($rh['data_fim']);
    $diff = $inicio->diff($fim);
    $semanas = ceil($diff->days / 7);
    
    // Custo total = horas semanais * semanas * custo hora
    $custo_total = $rh['horas_semanais'] * $semanas * $rh['custo_hora'];
    $total_rh += $custo_total;
}

$total_equipamentos = 0;
foreach ($equipamentos as $eq) {
    $total_equipamentos += $eq['quantidade'] * $eq['custo_unitario'];
}

$total_materiais = 0;
foreach ($materiais as $mat) {
    $total_materiais += $mat['quantidade'] * $mat['custo_unitario'];
}

$total_geral = $total_rh + $total_equipamentos + $total_materiais;

// Obter lista de equipamentos disponíveis
$equipamentos_disponiveis = [];
$stmt = $db->prepare('SELECT * FROM equipamentos ORDER BY nome');
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $equipamentos_disponiveis[] = $row;
}
$stmt->close();

// Fechar conexão com o banco
$db->close();
?>

<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão PMO - Alocação de Recursos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .dashboard-container {
            background-color: #f8f9fa;
            border-radius: 10px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .resource-card {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .resource-card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
            border-bottom: 3px solid #0d6efd;
        }
        
        .summary-card {
            padding: 1.5rem;
            border-radius: 10px;
            color: white;
            margin-bottom: 1rem;
        }
        
        .summary-card.rh {
            background: linear-gradient(45deg, #4158D0, #C850C0);
        }
        
        .summary-card.equip {
            background: linear-gradient(45deg, #0061ff, #60efff);
        }
        
        .summary-card.material {
            background: linear-gradient(45deg, #ff9966, #ff5e62);
        }
        
        .summary-card.total {
            background: linear-gradient(45deg, #134E5E, #71B280);
        }
        
        .gantt-container {
            overflow-x: auto;
            margin-top: 2rem;
        }
        
        .gantt-row {
            height: 40px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .gantt-label {
            width: 200px;
            padding-right: 1rem;
            font-weight: 500;
        }
        
        .gantt-chart {
            display: flex;
            flex-grow: 1;
        }
        
        .gantt-bar {
            height: 20px;
            border-radius: 4px;
            margin-right: 1px;
        }
        
        .gantt-bar.rh {
            background-color: #C850C0;
        }
        
        .gantt-bar.equip {
            background-color: #0061ff;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-kanban"></i> Sistema de Gestão PMO
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="recursos.php">
                            <i class="bi bi-people"></i> Recursos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="tarefas.php">
                            <i class="bi bi-list-check"></i> Tarefas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="relatorios.php">
                            <i class="bi bi-file-earmark-bar-graph"></i> Relatórios
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($username); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="perfil.php">Perfil</a></li>
                            <li><a class="dropdown-item" href="configuracoes.php">Configurações</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Sair</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container my-4">
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-<?php echo $tipo_mensagem; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensagem; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        <?php endif; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">
                <i class="bi bi-people"></i> Gestão de Recursos
            </h1>
            
            <div class="d-flex">
                <div class="me-2">
                    <select class="form-select" id="projeto-select" onchange="window.location.href='recursos.php?projeto_id='+this.value+'&ano=<?php echo $ano; ?>'">
                        <option value="0">Selecione um projeto...</option>
                        <?php foreach ($projetos as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo ($p['id'] == $projeto_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <select class="form-select" id="ano-select" onchange="window.location.href='recursos.php?projeto_id=<?php echo $projeto_id; ?>&ano='+this.value">
                        <?php 
                        $ano_atual = date('Y');
                        for ($i = $ano_atual - 2; $i <= $ano_atual + 3; $i++): 
                        ?>
                            <option value="<?php echo $i; ?>" <?php echo ($i == $ano) ? 'selected' : ''; ?>>
                                <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <?php if ($projeto_id > 0 && $projeto_atual): ?>
            <div class="dashboard-container mb-4">
                <h2><?php echo htmlspecialchars($projeto_atual['nome']); ?></h2>
                <p class="text-muted"><?php echo htmlspecialchars($projeto_atual['descricao']); ?></p>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="summary-card rh">
                            <h4>Recursos Humanos</h4>
                            <h3><?php echo format_currency($total_rh); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card equip">
                            <h4>Equipamentos</h4>
                            <h3><?php echo format_currency($total_equipamentos); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card material">
                            <h4>Materiais</h4>
                            <h3><?php echo format_currency($total_materiais); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card total">
                            <h4>Total Recursos</h4>
                            <h3><?php echo format_currency($total_geral); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <ul class="nav nav-tabs mb-4" id="resourceTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" id="rh-tab" data-bs-toggle="tab" href="#rh" role="tab">
                        <i class="bi bi-person"></i> Recursos Humanos
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="equipamentos-tab" data-bs-toggle="tab" href="#equipamentos" role="tab">
                        <i class="bi bi-pc-display"></i> Equipamentos
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="materiais-tab" data-bs-toggle="tab" href="#materiais" role="tab">
                        <i class="bi bi-box-seam"></i> Materiais
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="gantt-tab" data-bs-toggle="tab" href="#gantt" role="tab">
                        <i class="bi bi-bar-chart"></i> Diagrama de Gantt
                    </a>
                </li>
            </ul>
            
            <div class="tab-content" id="resourceTabsContent">
                <!-- Recursos Humanos -->
                <div class="tab-pane fade show active" id="rh" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3>Recursos Humanos - <?php echo $ano; ?></h3>
                        
                        <?php if ($is_gerente): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRHModal">
                                <i class="bi bi-plus-circle"></i> Adicionar Alocação
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($recursos_humanos)): ?>
                        <div class="alert alert-info">
                            Nenhum recurso humano alocado para este projeto no ano de <?php echo $ano; ?>.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Tarefa</th>
                                        <th>Período</th>
                                        <th>Horas Semanais</th>
                                        <th>Custo/Hora</th>
                                        <th>Custo Total</th>
                                        <?php if ($is_gerente): ?>
                                            <th>Ações</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recursos_humanos as $rh): ?>
                                        <?php
                                            // Calcula número de semanas entre datas
                                            $inicio = new DateTime($rh['data_inicio']);
                                            $fim = new DateTime($rh['data_fim']);
                                            $diff = $inicio->diff($fim);
                                            $semanas = ceil($diff->days / 7);
                                            
                                            // Custo total = horas semanais * semanas * custo hora
                                            $custo_total = $rh['horas_semanais'] * $semanas * $rh['custo_hora'];
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($rh['pessoa_nome']); ?></td>
                                            <td>
                                                <?php echo $rh['tarefa_id'] ? htmlspecialchars($rh['tarefa_nome']) : '<span class="text-muted">Projeto Geral</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo format_date($rh['data_inicio']); ?> - 
                                                <?php echo format_date($rh['data_fim']); ?>
                                            </td>
                                            <td><?php echo $rh['horas_semanais']; ?></td>
                                            <td><?php echo format_currency($rh['custo_hora']); ?></td>
                                            <td><?php echo format_currency($custo_total); ?></td>
                                            <?php if ($is_gerente): ?>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" title="Editar">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger" title="Excluir">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Equipamentos -->
                <div class="tab-pane fade" id="equipamentos" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3>Equipamentos - <?php echo $ano; ?></h3>
                        
                        <?php if ($is_gerente): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEquipamentoModal">
                                <i class="bi bi-plus-circle"></i> Adicionar Equipamento
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($equipamentos)): ?>
                        <div class="alert alert-info">
                            Nenhum equipamento alocado para este projeto no ano de <?php echo $ano; ?>.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Equipamento</th>
                                        <th>Tarefa</th>
                                        <th>Período</th>
                                        <th>Quantidade</th>
                                        <th>Custo Unitário</th>
                                        <th>Custo Total</th>
                                        <?php if ($is_gerente): ?>
                                            <th>Ações</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($equipamentos as $eq): ?>
                                        <?php $custo_total = $eq['quantidade'] * $eq['custo_unitario']; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($eq['equipamento_nome']); ?></td>
                                            <td>
                                                <?php echo $eq['tarefa_id'] ? htmlspecialchars($eq['tarefa_nome']) : '<span class="text-muted">Projeto Geral</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo format_date($eq['data_inicio']); ?> - 
                                                <?php echo format_date($eq['data_fim']); ?>
                                            </td>
                                            <td><?php echo $eq['quantidade']; ?></td>
                                            <td><?php echo format_currency($eq['custo_unitario']); ?></td>
                                            <td><?php echo format_currency($custo_total); ?></td>
                                            <?php if ($is_gerente): ?>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" title="Editar">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger" title="Excluir">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Materiais -->
                <div class="tab-pane fade" id="materiais" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3>Materiais - <?php echo $ano; ?></h3>
                        
                        <?php if ($is_gerente): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMaterialModal">
                                <i class="bi bi-plus-circle"></i> Adicionar Material
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($materiais)): ?>
                        <div class="alert alert-info">
                            Nenhum material registrado para este projeto no ano de <?php echo $ano; ?>.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Descrição</th>
                                        <th>Tarefa</th>
                                        <th>Data Aquisição</th>
                                        <th>Fornecedor</th>
                                        <th>Quantidade</th>
                                        <th>Custo Unitário</th>
                                        <th>Custo Total</th>
                                        <?php if ($is_gerente): ?>
                                            <th>Ações</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($materiais as $mat): ?>
                                        <?php $custo_total = $mat['quantidade'] * $mat['custo_unitario']; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($mat['descricao']); ?></td>
                                            <td>
                                                <?php echo $mat['tarefa_id'] ? htmlspecialchars($mat['tarefa_nome']) : '<span class="text-muted">Projeto Geral</span>'; ?>
                                            </td>
                                            <td><?php echo format_date($mat['data_aquisicao']); ?></td>
                                            <td><?php echo htmlspecialchars($mat['fornecedor']); ?></td>
                                            <td><?php echo $mat['quantidade']; ?></td>
                                            <td><?php echo format_currency($mat['custo_unitario']); ?></td>
                                            <td><?php echo format_currency($custo_total); ?></td>
                                            <?php if ($is_gerente): ?>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" title="Editar">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger" title="Excluir">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Gantt -->
                <div class="tab-pane fade" id="gantt" role="tabpanel">
                    <h3>Diagrama de Gantt - <?php echo $ano; ?></h3>
                    
                    <?php if (empty($recursos_humanos) && empty($equipamentos)): ?>
                        <div class="alert alert-info">
                            Nenhum recurso alocado para este projeto no ano de <?php echo $ano; ?>.
                        </div>
                    <?php else: ?>
                        <div class="gantt-container">
                            <div class="d-flex mb-2">
                                <div style="width: 200px">Recurso</div>
                                <?php 
                                // Criar cabeçalho com meses do ano
                                for ($i = 1; $i <= 12; $i++): 
                                    $monthName = date('M', mktime(0, 0, 0, $i, 1));
                                ?>
                                    <div style="width: 60px; text-align: center;" class="small fw-bold">
                                        <?php echo $monthName; ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            
                            <?php foreach ($recursos_humanos as $rh): ?>
                                <div class="gantt-row">
                                    <div class="gantt-label">
                                        <span class="badge bg-primary">RH</span>
                                        <?php echo htmlspecialchars($rh['pessoa_nome']); ?>
                                    </div>
                                    <div class="gantt-chart">
                                        <?php
                                            $start_date = new DateTime($rh['data_inicio']);
                                            $end_date = new DateTime($rh['data_fim']);
                                            
                                            $start_month = (int)$start_date->format('n');
                                            $end_month = (int)$end_date->format('n');
                                            
                                            // Ajustar para o ano selecionado
                                            if ((int)$start_date->format('Y') < $ano) {
                                                $start_month = 1;
                                            }
                                            
                                            if ((int)$end_date->format('Y') > $ano) {
                                                $end_month = 12;
                                            }
                                            
                                            // Plotar barras do gantt
                                            for ($i = 1; $i <= 12; $i++): 
                                        ?>
                                            <div style="width: 60px; text-align: center;">
                                                <?php if ($i >= $start_month && $i <= $end_month): ?>
                                                    <div class="gantt-bar rh" title="<?php echo htmlspecialchars($rh['pessoa_nome']); ?>"></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($equipamentos as $eq): ?>
                                <div class="gantt-row">
                                    <div class="gantt-label">
                                        <span class="badge bg-info">EQ</span>
                                        <?php echo htmlspecialchars($eq['equipamento_nome']); ?>
                                    </div>
                                    <div class="gantt-chart">
                                        <?php
                                            $start_date = new DateTime($eq['data_inicio']);
                                            $end_date = new DateTime($eq['data_fim']);
                                            
                                            $start_month = (int)$start_date->format('n');
                                            $end_month = (int)$end_date->format('n');
                                            
                                            // Ajustar para o ano selecionado
                                            if ((int)$start_date->format('Y') < $ano) {
                                                $start_month = 1;
                                            }
                                            
                                            if ((int)$end_date->format('Y') > $ano) {
                                                $end_month = 12;
                                            }
                                            
                                            // Plotar barras do gantt
                                            for ($i = 1; $i <= 12; $i++): 
                                        ?>
                                            <div style="width: 60px; text-align: center;">
                                                <?php if ($i >= $start_month && $i <= $end_month): ?>
                                                    <div class="gantt-bar equip" title="<?php echo htmlspecialchars($eq['equipamento_nome']); ?>"></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Modal Adicionar Recurso Humano -->
            <div class="modal fade" id="addRHModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Adicionar Recurso Humano</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="recursos.php?projeto_id=<?php echo $projeto_id; ?>&ano=<?php echo $ano; ?>" method="post">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="pessoa_id" class="form-label">Pessoa</label>
                                    <select class="form-select" id="pessoa_id" name="pessoa_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($membros_equipe as $membro): ?>
                                            <option value="<?php echo $membro['id']; ?>">
                                                <?php echo htmlspecialchars($membro['nome']); ?> (<?php echo $membro['username']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="tarefa_id" class="form-label">Tarefa (opcional)</label>
                                    <select class="form-select" id="tarefa_id" name="tarefa_id">
                                        <option value="">Projeto Geral</option>
                                        <?php foreach ($tarefas as $tarefa): ?>
                                            <option value="<?php echo $tarefa['id']; ?>">
                                                <?php echo htmlspecialchars($tarefa['titulo']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="data_inicio" class="form-label">Data Início</label>
                                            <input type="date" class="form-control date-picker" id="data_inicio" name="data_inicio" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="data_fim" class="form-label">Data Fim</label>
                                            <input type="date" class="form-control date-picker" id="data_fim" name="data_fim" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="horas_semanais" class="form-label">Horas Semanais</label>
                                            <input type="number" class="form-control" id="horas_semanais" name="horas_semanais" min="1" max="40" value="40" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="custo_hora" class="form-label">Custo por Hora (€)</label>
                                            <input type="number" class="form-control" id="custo_hora" name="custo_hora" min="0" step="0.01" value="25.00" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" name="add_recurso_humano" class="btn btn-primary">Adicionar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Modal Adicionar Equipamento -->
            <div class="modal fade" id="addEquipamentoModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Adicionar Equipamento</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="recursos.php?projeto_id=<?php echo $projeto_id; ?>&ano=<?php echo $ano; ?>" method="post">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="equipamento_id" class="form-label">Equipamento</label>
                                    <select class="form-select" id="equipamento_id" name="equipamento_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($equipamentos_disponiveis as $eq): ?>
                                            <option value="<?php echo $eq['id']; ?>">
                                                <?php echo htmlspecialchars($eq['nome']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="tarefa_id" class="form-label">Tarefa (opcional)</label>
                                    <select class="form-select" id="tarefa_id" name="tarefa_id">
                                        <option value="">Projeto Geral</option>
                                        <?php foreach ($tarefas as $tarefa): ?>
                                            <option value="<?php echo $tarefa['id']; ?>">
                                                <?php echo htmlspecialchars($tarefa['titulo']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="data_inicio" class="form-label">Data Início</label>
                                            <input type="date" class="form-control date-picker" id="data_inicio" name="data_inicio" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="data_fim" class="form-label">Data Fim</label>
                                            <input type="date" class="form-control date-picker" id="data_fim" name="data_fim" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="quantidade" class="form-label">Quantidade</label>
                                            <input type="number" class="form-control" id="quantidade" name="quantidade" min="1" value="1" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="custo_unitario" class="form-label">Custo Unitário (€)</label>
                                            <input type="number" class="form-control" id="custo_unitario" name="custo_unitario" min="0" step="0.01" value="0.00" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" name="add_equipamento" class="btn btn-primary">Adicionar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Modal Adicionar Material -->
            <div class="modal fade" id="addMaterialModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Adicionar Material</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="recursos.php?projeto_id=<?php echo $projeto_id; ?>&ano=<?php echo $ano; ?>" method="post">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="descricao" class="form-label">Descrição do Material</label>
                                    <input type="text" class="form-control" id="descricao" name="descricao" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="tarefa_id" class="form-label">Tarefa (opcional)</label>
                                    <select class="form-select" id="tarefa_id" name="tarefa_id">
                                        <option value="">Projeto Geral</option>
                                        <?php foreach ($tarefas as $tarefa): ?>
                                            <option value="<?php echo $tarefa['id']; ?>">
                                                <?php echo htmlspecialchars($tarefa['titulo']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="data_aquisicao" class="form-label">Data de Aquisição</label>
                                            <input type="date" class="form-control date-picker" id="data_aquisicao" name="data_aquisicao" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="fornecedor" class="form-label">Fornecedor</label>
                                            <input type="text" class="form-control" id="fornecedor" name="fornecedor" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="quantidade" class="form-label">Quantidade</label>
                                            <input type="number" class="form-control" id="quantidade" name="quantidade" min="1" value="1" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="custo_unitario" class="form-label">Custo Unitário (€)</label>
                                            <input type="number" class="form-control" id="custo_unitario" name="custo_unitario" min="0" step="0.01" value="0.00" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" name="add_material" class="btn btn-primary">Adicionar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <h4>Bem-vindo ao Sistema de Gestão PMO!</h4>
                <p>Selecione um projeto no menu acima para começar a gerenciar os recursos.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <footer class="bg-light py-3 mt-5">
        <div class="container text-center text-muted">
            <p>&copy; 2025 Sistema de Gestão PMO. Todos os direitos reservados.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar date pickers
            flatpickr(".date-picker", {
                dateFormat: "Y-m-d",
                allowInput: true
            });
        });
    </script>
</body>
</html>