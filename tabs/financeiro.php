<?php
// tabs/financeiro.php - Sistema de Gestão Financeira
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

include_once __DIR__ . '/../config.php';

// Conectar à base de dados
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

// Criar tabelas se não existirem
$pdo->exec("
CREATE TABLE IF NOT EXISTS financeiro_categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    tipo ENUM('receita', 'despesa') NOT NULL,
    cor VARCHAR(7) DEFAULT '#6c757d',
    icone VARCHAR(50),
    ativa BOOLEAN DEFAULT TRUE,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_nome_tipo (nome, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS financeiro_transacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('receita', 'despesa') NOT NULL,
    categoria_id INT,
    valor DECIMAL(15,2) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    detalhes TEXT,
    data_transacao DATE NOT NULL,
    data_vencimento DATE,
    estado ENUM('pendente', 'pago', 'cancelado') DEFAULT 'pago',
    metodo_pagamento VARCHAR(50),
    referencia VARCHAR(100),
    fornecedor_cliente VARCHAR(255),
    nif VARCHAR(50),
    projeto_id INT,
    anexo_path VARCHAR(500),
    criado_por INT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES financeiro_categorias(id) ON DELETE SET NULL,
    INDEX idx_tipo (tipo),
    INDEX idx_data (data_transacao),
    INDEX idx_estado (estado),
    INDEX idx_categoria (categoria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS financeiro_orcamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ano INT NOT NULL,
    categoria_id INT NOT NULL,
    valor_mensal DECIMAL(15,2) NOT NULL,
    notas TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES financeiro_categorias(id) ON DELETE CASCADE,
    UNIQUE KEY unique_ano_categoria (ano, categoria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Inserir categorias padrão se não existirem
$defaultCategories = [
    // Despesas
    ['Pessoal/Salários', 'despesa', '#dc3545', 'bi-people-fill'],
    ['Infraestrutura', 'despesa', '#fd7e14', 'bi-building'],
    ['Software/Licenças', 'despesa', '#0dcaf0', 'bi-laptop'],
    ['Hardware/Equipamento', 'despesa', '#6610f2', 'bi-pc-display'],
    ['Marketing', 'despesa', '#d63384', 'bi-megaphone'],
    ['Viagens', 'despesa', '#20c997', 'bi-airplane'],
    ['Formação', 'despesa', '#0d6efd', 'bi-book'],
    ['Materiais', 'despesa', '#ffc107', 'bi-box-seam'],
    ['Serviços Externos', 'despesa', '#6c757d', 'bi-briefcase'],
    ['Outros', 'despesa', '#adb5bd', 'bi-three-dots'],
    
    // Receitas
    ['Vendas', 'receita', '#198754', 'bi-cash-coin'],
    ['Serviços', 'receita', '#20c997', 'bi-tools'],
    ['Consultoria', 'receita', '#0dcaf0', 'bi-lightbulb'],
    ['Financiamento', 'receita', '#0d6efd', 'bi-bank'],
    ['Subsídios', 'receita', '#6610f2', 'bi-gift'],
    ['Outros', 'receita', '#adb5bd', 'bi-three-dots']
];

foreach ($defaultCategories as $cat) {
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO financeiro_categorias (nome, tipo, cor, icone) VALUES (?, ?, ?, ?)");
        $stmt->execute($cat);
    } catch (PDOException $e) {
        // Categoria já existe
    }
}

// Processar ações
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add_transacao':
                $anexo_path = null;
                
                // Upload de anexo
                if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../files/financeiro/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_ext = pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION);
                    $new_name = uniqid() . '_' . time() . '.' . $file_ext;
                    $file_path = $upload_dir . $new_name;
                    
                    if (move_uploaded_file($_FILES['anexo']['tmp_name'], $file_path)) {
                        $anexo_path = 'files/financeiro/' . $new_name;
                    }
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO financeiro_transacoes 
                    (tipo, categoria_id, valor, descricao, detalhes, data_transacao, data_vencimento, 
                     estado, metodo_pagamento, referencia, fornecedor_cliente, nif, projeto_id, anexo_path, criado_por) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['tipo'],
                    $_POST['categoria_id'] ?: null,
                    $_POST['valor'],
                    $_POST['descricao'],
                    $_POST['detalhes'] ?? null,
                    $_POST['data_transacao'],
                    $_POST['data_vencimento'] ?: null,
                    $_POST['estado'] ?? 'pago',
                    $_POST['metodo_pagamento'] ?? null,
                    $_POST['referencia'] ?? null,
                    $_POST['fornecedor_cliente'] ?? null,
                    $_POST['nif'] ?? null,
                    $_POST['projeto_id'] ?: null,
                    $anexo_path,
                    $_SESSION['user_id']
                ]);
                $message = "Transação adicionada com sucesso!";
                $messageType = 'success';
                break;
                
            case 'update_transacao':
                $stmt = $pdo->prepare("
                    UPDATE financeiro_transacoes 
                    SET tipo=?, categoria_id=?, valor=?, descricao=?, detalhes=?, data_transacao=?, 
                        data_vencimento=?, estado=?, metodo_pagamento=?, referencia=?, fornecedor_cliente=?, nif=?, projeto_id=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['tipo'],
                    $_POST['categoria_id'] ?: null,
                    $_POST['valor'],
                    $_POST['descricao'],
                    $_POST['detalhes'] ?? null,
                    $_POST['data_transacao'],
                    $_POST['data_vencimento'] ?: null,
                    $_POST['estado'],
                    $_POST['metodo_pagamento'] ?? null,
                    $_POST['referencia'] ?? null,
                    $_POST['fornecedor_cliente'] ?? null,
                    $_POST['nif'] ?? null,
                    $_POST['projeto_id'] ?: null,
                    $_POST['transacao_id']
                ]);
                $message = "Transação atualizada!";
                $messageType = 'success';
                break;
                
            case 'delete_transacao':
                // Eliminar anexo se existir
                $stmt = $pdo->prepare("SELECT anexo_path FROM financeiro_transacoes WHERE id=?");
                $stmt->execute([$_POST['transacao_id']]);
                $trans = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($trans && $trans['anexo_path'] && file_exists(__DIR__ . '/../' . $trans['anexo_path'])) {
                    unlink(__DIR__ . '/../' . $trans['anexo_path']);
                }
                
                $stmt = $pdo->prepare("DELETE FROM financeiro_transacoes WHERE id=?");
                $stmt->execute([$_POST['transacao_id']]);
                $message = "Transação eliminada!";
                $messageType = 'success';
                break;
                
            case 'add_categoria':
                $stmt = $pdo->prepare("INSERT INTO financeiro_categorias (nome, tipo, cor, icone) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['nome'],
                    $_POST['tipo'],
                    $_POST['cor'] ?? '#6c757d',
                    $_POST['icone'] ?? 'bi-tag'
                ]);
                $message = "Categoria criada!";
                $messageType = 'success';
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Filtros
$anoSelecionado = $_GET['ano'] ?? date('Y');
$mesSelecionado = $_GET['mes'] ?? date('m');
$tipoFiltro = $_GET['tipo'] ?? '';

// Obter dados
$categorias = $pdo->query("SELECT * FROM financeiro_categorias WHERE ativa=1 ORDER BY tipo, nome")->fetchAll(PDO::FETCH_ASSOC);

// Obter projetos
$checkProjects = $pdo->query("SHOW TABLES LIKE 'projects'")->fetch();
$projects = [];
if ($checkProjects) {
    $projects = $pdo->query("SELECT id, short_name, title FROM projects ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Query de transações
$sql = "SELECT t.*, c.nome as categoria_nome, c.cor as categoria_cor, c.icone as categoria_icone, u.username as criador
        FROM financeiro_transacoes t
        LEFT JOIN financeiro_categorias c ON t.categoria_id = c.id
        LEFT JOIN user_tokens u ON t.criado_por = u.user_id
        WHERE YEAR(t.data_transacao) = ? AND MONTH(t.data_transacao) = ?";
$params = [$anoSelecionado, $mesSelecionado];

if ($tipoFiltro) {
    $sql .= " AND t.tipo = ?";
    $params[] = $tipoFiltro;
}

$sql .= " ORDER BY t.data_transacao DESC, t.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular totais
$totalReceitas = 0;
$totalDespesas = 0;
$receitasPorCategoria = [];
$despesasPorCategoria = [];

foreach ($transacoes as $trans) {
    if ($trans['tipo'] === 'receita') {
        $totalReceitas += $trans['valor'];
        if (!isset($receitasPorCategoria[$trans['categoria_nome']])) {
            $receitasPorCategoria[$trans['categoria_nome']] = 0;
        }
        $receitasPorCategoria[$trans['categoria_nome']] += $trans['valor'];
    } else {
        $totalDespesas += $trans['valor'];
        if (!isset($despesasPorCategoria[$trans['categoria_nome']])) {
            $despesasPorCategoria[$trans['categoria_nome']] = 0;
        }
        $despesasPorCategoria[$trans['categoria_nome']] += $trans['valor'];
    }
}

$saldo = $totalReceitas - $totalDespesas;
?>

<style>
.financeiro-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-left: 4px solid #6c757d;
}

.stat-card.receita { border-left-color: #198754; }
.stat-card.despesa { border-left-color: #dc3545; }
.stat-card.saldo { border-left-color: #0d6efd; }

.stat-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 28px;
    font-weight: bold;
    color: #212529;
}

.stat-value.positivo { color: #198754; }
.stat-value.negativo { color: #dc3545; }

.transacao-item {
    background: white;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border-left: 4px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.2s;
}

.transacao-item:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.transacao-item.receita { border-left-color: #198754; }
.transacao-item.despesa { border-left-color: #dc3545; }

.transacao-info {
    flex: 1;
}

.transacao-descricao {
    font-weight: 600;
    color: #212529;
    margin-bottom: 5px;
}

.transacao-meta {
    font-size: 12px;
    color: #6c757d;
}

.transacao-valor {
    font-size: 24px;
    font-weight: bold;
    margin: 0 20px;
}

.transacao-valor.receita { color: #198754; }
.transacao-valor.despesa { color: #dc3545; }

.categoria-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    color: white;
}

.estado-badge {
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
}

.estado-pago { background: #d1e7dd; color: #0f5132; }
.estado-pendente { background: #fff3cd; color: #856404; }
.estado-cancelado { background: #f8d7da; color: #842029; }

.filters-bar {
    background: white;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}

.chart-container {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
</style>

<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="financeiro-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-1">Gestão Financeira</h2>
                <p class="mb-0 opacity-75">
                    <?= date('F Y', strtotime("$anoSelecionado-$mesSelecionado-01")) ?>
                </p>
            </div>
            <div>
                <button class="btn btn-light me-2" data-bs-toggle="modal" data-bs-target="#addTransacaoModal">
                    <i class="bi bi-plus-lg"></i> Nova Transação
                </button>
                <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#addCategoriaModal">
                    <i class="bi bi-tag"></i> Nova Categoria
                </button>
            </div>
        </div>
    </div>

    <!-- Cards de Estatísticas -->
    <div class="stats-cards">
        <div class="stat-card receita">
            <div class="stat-label">Receitas</div>
            <div class="stat-value positivo">€ <?= number_format($totalReceitas, 2, ',', '.') ?></div>
        </div>
        <div class="stat-card despesa">
            <div class="stat-label">Despesas</div>
            <div class="stat-value negativo">€ <?= number_format($totalDespesas, 2, ',', '.') ?></div>
        </div>
        <div class="stat-card saldo">
            <div class="stat-label">Saldo</div>
            <div class="stat-value <?= $saldo >= 0 ? 'positivo' : 'negativo' ?>">
                € <?= number_format($saldo, 2, ',', '.') ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Transações</div>
            <div class="stat-value"><?= count($transacoes) ?></div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filters-bar">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="financeiro">
            <div class="col-md-3">
                <label class="form-label small">Ano</label>
                <select name="ano" class="form-select" onchange="this.form.submit()">
                    <?php for ($y = date('Y') + 1; $y >= 2020; $y--): ?>
                        <option value="<?= $y ?>" <?= $anoSelecionado == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Mês</label>
                <select name="mes" class="form-select" onchange="this.form.submit()">
                    <?php 
                    $meses = ['01'=>'Janeiro', '02'=>'Fevereiro', '03'=>'Março', '04'=>'Abril', '05'=>'Maio', '06'=>'Junho',
                              '07'=>'Julho', '08'=>'Agosto', '09'=>'Setembro', '10'=>'Outubro', '11'=>'Novembro', '12'=>'Dezembro'];
                    foreach ($meses as $num => $nome): 
                    ?>
                        <option value="<?= $num ?>" <?= $mesSelecionado == $num ? 'selected' : '' ?>><?= $nome ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Tipo</label>
                <select name="tipo" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <option value="receita" <?= $tipoFiltro === 'receita' ? 'selected' : '' ?>>Receitas</option>
                    <option value="despesa" <?= $tipoFiltro === 'despesa' ? 'selected' : '' ?>>Despesas</option>
                </select>
            </div>
            <div class="col-md-3">
                <?php if ($tipoFiltro || $anoSelecionado != date('Y') || $mesSelecionado != date('m')): ?>
                    <a href="?tab=financeiro" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-x-circle"></i> Limpar Filtros
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Gráficos de Categorias -->
    <?php if (!empty($receitasPorCategoria) || !empty($despesasPorCategoria)): ?>
    <div class="row mb-4">
        <?php if (!empty($receitasPorCategoria)): ?>
        <div class="col-md-6">
            <div class="chart-container">
                <h5 class="mb-3 text-success"><i class="bi bi-arrow-up-circle"></i> Receitas por Categoria</h5>
                <?php 
                arsort($receitasPorCategoria);
                foreach ($receitasPorCategoria as $cat => $valor): 
                    $percentagem = ($totalReceitas > 0) ? ($valor / $totalReceitas * 100) : 0;
                ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small><?= htmlspecialchars($cat) ?></small>
                            <small class="fw-bold">€ <?= number_format($valor, 2, ',', '.') ?></small>
                        </div>
                        <div class="progress" style="height: 20px;">
                            <div class="progress-bar bg-success" style="width: <?= $percentagem ?>%">
                                <?= number_format($percentagem, 1) ?>%
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($despesasPorCategoria)): ?>
        <div class="col-md-6">
            <div class="chart-container">
                <h5 class="mb-3 text-danger"><i class="bi bi-arrow-down-circle"></i> Despesas por Categoria</h5>
                <?php 
                arsort($despesasPorCategoria);
                foreach ($despesasPorCategoria as $cat => $valor): 
                    $percentagem = ($totalDespesas > 0) ? ($valor / $totalDespesas * 100) : 0;
                ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small><?= htmlspecialchars($cat) ?></small>
                            <small class="fw-bold">€ <?= number_format($valor, 2, ',', '.') ?></small>
                        </div>
                        <div class="progress" style="height: 20px;">
                            <div class="progress-bar bg-danger" style="width: <?= $percentagem ?>%">
                                <?= number_format($percentagem, 1) ?>%
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Lista de Transações -->
    <h5 class="mb-3">Transações</h5>
    <?php if (empty($transacoes)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Nenhuma transação registada neste período.
        </div>
    <?php else: ?>
        <?php foreach ($transacoes as $trans): ?>
            <div class="transacao-item <?= $trans['tipo'] ?>">
                <div class="transacao-info">
                    <div class="transacao-descricao">
                        <?php if ($trans['categoria_icone']): ?>
                            <i class="<?= htmlspecialchars($trans['categoria_icone']) ?>"></i>
                        <?php endif; ?>
                        <?= htmlspecialchars($trans['descricao']) ?>
                    </div>
                    <div class="transacao-meta">
                        <?php if ($trans['categoria_nome']): ?>
                            <span class="categoria-badge" style="background-color: <?= htmlspecialchars($trans['categoria_cor']) ?>">
                                <?= htmlspecialchars($trans['categoria_nome']) ?>
                            </span>
                        <?php endif; ?>
                        
                        <span class="estado-badge estado-<?= $trans['estado'] ?> ms-2">
                            <?= ucfirst($trans['estado']) ?>
                        </span>
                        
                        | <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($trans['data_transacao'])) ?>
                        
                        <?php if ($trans['fornecedor_cliente']): ?>
                            | <i class="bi bi-building"></i> <?= htmlspecialchars($trans['fornecedor_cliente']) ?>
                        <?php endif; ?>
                        
                        <?php if ($trans['metodo_pagamento']): ?>
                            | <i class="bi bi-credit-card"></i> <?= htmlspecialchars($trans['metodo_pagamento']) ?>
                        <?php endif; ?>
                        
                        <?php if ($trans['anexo_path']): ?>
                            | <a href="<?= htmlspecialchars($trans['anexo_path']) ?>" target="_blank">
                                <i class="bi bi-paperclip"></i> Anexo
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="transacao-valor <?= $trans['tipo'] ?>">
                    <?= $trans['tipo'] === 'receita' ? '+' : '-' ?> € <?= number_format($trans['valor'], 2, ',', '.') ?>
                </div>
                
                <div>
                    <button class="btn btn-sm btn-outline-primary" onclick='editTransacao(<?= json_encode($trans) ?>)'>
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Eliminar?')">
                        <input type="hidden" name="action" value="delete_transacao">
                        <input type="hidden" name="transacao_id" value="<?= $trans['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal: Nova Transação -->
<div class="modal fade" id="addTransacaoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nova Transação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_transacao">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Tipo *</label>
                                <select name="tipo" class="form-select" required onchange="updateCategories(this.value, 'add')">
                                    <option value="despesa">Despesa</option>
                                    <option value="receita">Receita</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Categoria *</label>
                                <select name="categoria_id" id="add_categoria_id" class="form-select" required>
                                    <?php foreach ($categorias as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" data-tipo="<?= $cat['tipo'] ?>">
                                            <?= htmlspecialchars($cat['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Valor (€) *</label>
                                <input type="number" name="valor" class="form-control" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Data *</label>
                                <input type="date" name="data_transacao" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição *</label>
                        <input type="text" name="descricao" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Detalhes</label>
                        <textarea name="detalhes" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Estado</label>
                                <select name="estado" class="form-select">
                                    <option value="pago">Pago</option>
                                    <option value="pendente">Pendente</option>
                                    <option value="cancelado">Cancelado</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Data Vencimento</label>
                                <input type="date" name="data_vencimento" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Método de Pagamento</label>
                                <input type="text" name="metodo_pagamento" class="form-control" placeholder="Ex: Transferência, MB Way, etc">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Referência</label>
                                <input type="text" name="referencia" class="form-control" placeholder="Nº Fatura, Ref, etc">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Fornecedor/Cliente</label>
                                <input type="text" name="fornecedor_cliente" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">NIF</label>
                                <input type="text" name="nif" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($projects)): ?>
                    <div class="mb-3">
                        <label class="form-label">Projeto Associado</label>
                        <select name="projeto_id" class="form-select">
                            <option value="">Nenhum</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['id'] ?>">
                                    <?= htmlspecialchars($proj['short_name']) ?> - <?= htmlspecialchars($proj['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Anexo (Fatura, Recibo)</label>
                        <input type="file" name="anexo" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Transação -->
<div class="modal fade" id="editTransacaoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Transação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_transacao">
                    <input type="hidden" name="transacao_id" id="edit_transacao_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Tipo *</label>
                                <select name="tipo" id="edit_tipo" class="form-select" required onchange="updateCategories(this.value, 'edit')">
                                    <option value="despesa">Despesa</option>
                                    <option value="receita">Receita</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Categoria *</label>
                                <select name="categoria_id" id="edit_categoria_id" class="form-select" required>
                                    <?php foreach ($categorias as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" data-tipo="<?= $cat['tipo'] ?>">
                                            <?= htmlspecialchars($cat['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Valor (€) *</label>
                                <input type="number" name="valor" id="edit_valor" class="form-control" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Data *</label>
                                <input type="date" name="data_transacao" id="edit_data_transacao" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição *</label>
                        <input type="text" name="descricao" id="edit_descricao" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Detalhes</label>
                        <textarea name="detalhes" id="edit_detalhes" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Estado</label>
                                <select name="estado" id="edit_estado" class="form-select">
                                    <option value="pago">Pago</option>
                                    <option value="pendente">Pendente</option>
                                    <option value="cancelado">Cancelado</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Data Vencimento</label>
                                <input type="date" name="data_vencimento" id="edit_data_vencimento" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Método de Pagamento</label>
                                <input type="text" name="metodo_pagamento" id="edit_metodo_pagamento" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Referência</label>
                                <input type="text" name="referencia" id="edit_referencia" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Fornecedor/Cliente</label>
                                <input type="text" name="fornecedor_cliente" id="edit_fornecedor_cliente" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">NIF</label>
                                <input type="text" name="nif" id="edit_nif" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($projects)): ?>
                    <div class="mb-3">
                        <label class="form-label">Projeto Associado</label>
                        <select name="projeto_id" id="edit_projeto_id" class="form-select">
                            <option value="">Nenhum</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['id'] ?>">
                                    <?= htmlspecialchars($proj['short_name']) ?> - <?= htmlspecialchars($proj['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Nova Categoria -->
<div class="modal fade" id="addCategoriaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nova Categoria</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_categoria">
                    
                    <div class="mb-3">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tipo *</label>
                        <select name="tipo" class="form-select" required>
                            <option value="despesa">Despesa</option>
                            <option value="receita">Receita</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Cor</label>
                        <input type="color" name="cor" class="form-control" value="#6c757d">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Ícone (Bootstrap Icons)</label>
                        <input type="text" name="icone" class="form-control" placeholder="Ex: bi-tag" value="bi-tag">
                        <small class="text-muted">Visite <a href="https://icons.getbootstrap.com/" target="_blank">Bootstrap Icons</a></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateCategories(tipo, mode) {
    const selectId = mode === 'add' ? 'add_categoria_id' : 'edit_categoria_id';
    const select = document.getElementById(selectId);
    const options = select.querySelectorAll('option');
    
    options.forEach(option => {
        const optionTipo = option.getAttribute('data-tipo');
        if (optionTipo === tipo) {
            option.style.display = '';
        } else {
            option.style.display = 'none';
        }
    });
    
    // Selecionar primeira opção visível
    for (let i = 0; i < options.length; i++) {
        if (options[i].style.display !== 'none') {
            select.value = options[i].value;
            break;
        }
    }
}

function editTransacao(trans) {
    document.getElementById('edit_transacao_id').value = trans.id;
    document.getElementById('edit_tipo').value = trans.tipo;
    document.getElementById('edit_categoria_id').value = trans.categoria_id || '';
    document.getElementById('edit_valor').value = trans.valor;
    document.getElementById('edit_descricao').value = trans.descricao;
    document.getElementById('edit_detalhes').value = trans.detalhes || '';
    document.getElementById('edit_data_transacao').value = trans.data_transacao;
    document.getElementById('edit_data_vencimento').value = trans.data_vencimento || '';
    document.getElementById('edit_estado').value = trans.estado;
    document.getElementById('edit_metodo_pagamento').value = trans.metodo_pagamento || '';
    document.getElementById('edit_referencia').value = trans.referencia || '';
    document.getElementById('edit_fornecedor_cliente').value = trans.fornecedor_cliente || '';
    document.getElementById('edit_nif').value = trans.nif || '';
    
    <?php if (!empty($projects)): ?>
    document.getElementById('edit_projeto_id').value = trans.projeto_id || '';
    <?php endif; ?>
    
    updateCategories(trans.tipo, 'edit');
    
    var modal = new bootstrap.Modal(document.getElementById('editTransacaoModal'));
    modal.show();
}

// Inicializar categorias ao carregar
document.addEventListener('DOMContentLoaded', function() {
    updateCategories('despesa', 'add');
});
</script>