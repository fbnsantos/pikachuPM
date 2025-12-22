<?php
// tabs/contactos_comerciais.php - Sistema de Gestão de Contactos Comerciais
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
CREATE TABLE IF NOT EXISTS comercial_contactos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('cliente', 'fornecedor', 'parceiro', 'outro') DEFAULT 'cliente',
    empresa VARCHAR(255) NOT NULL,
    pessoa_contacto VARCHAR(255),
    cargo VARCHAR(100),
    email VARCHAR(255),
    telefone VARCHAR(50),
    telemovel VARCHAR(50),
    website VARCHAR(255),
    morada TEXT,
    cidade VARCHAR(100),
    pais VARCHAR(100) DEFAULT 'Portugal',
    codigo_postal VARCHAR(20),
    nif VARCHAR(50),
    notas TEXT,
    tags VARCHAR(500),
    estado ENUM('ativo', 'inativo', 'potencial') DEFAULT 'ativo',
    criado_por INT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tipo (tipo),
    INDEX idx_empresa (empresa),
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS comercial_interacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contacto_id INT NOT NULL,
    tipo ENUM('reuniao', 'email', 'telefone', 'proposta', 'negociacao', 'outro') DEFAULT 'outro',
    assunto VARCHAR(255) NOT NULL,
    descricao TEXT,
    data_interacao DATETIME NOT NULL,
    proximo_followup DATE,
    user_id INT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contacto_id) REFERENCES comercial_contactos(id) ON DELETE CASCADE,
    INDEX idx_contacto (contacto_id),
    INDEX idx_data (data_interacao),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Processar ações
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'create_contacto':
                $stmt = $pdo->prepare("
                    INSERT INTO comercial_contactos 
                    (tipo, empresa, pessoa_contacto, cargo, email, telefone, telemovel, website, 
                     morada, cidade, pais, codigo_postal, nif, notas, tags, estado, criado_por) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['tipo'],
                    $_POST['empresa'],
                    $_POST['pessoa_contacto'] ?? null,
                    $_POST['cargo'] ?? null,
                    $_POST['email'] ?? null,
                    $_POST['telefone'] ?? null,
                    $_POST['telemovel'] ?? null,
                    $_POST['website'] ?? null,
                    $_POST['morada'] ?? null,
                    $_POST['cidade'] ?? null,
                    $_POST['pais'] ?? 'Portugal',
                    $_POST['codigo_postal'] ?? null,
                    $_POST['nif'] ?? null,
                    $_POST['notas'] ?? null,
                    $_POST['tags'] ?? null,
                    $_POST['estado'] ?? 'ativo',
                    $_SESSION['user_id']
                ]);
                $message = "Contacto criado com sucesso!";
                $messageType = 'success';
                break;
                
            case 'update_contacto':
                $stmt = $pdo->prepare("
                    UPDATE comercial_contactos 
                    SET tipo=?, empresa=?, pessoa_contacto=?, cargo=?, email=?, telefone=?, telemovel=?, 
                        website=?, morada=?, cidade=?, pais=?, codigo_postal=?, nif=?, notas=?, tags=?, estado=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['tipo'],
                    $_POST['empresa'],
                    $_POST['pessoa_contacto'] ?? null,
                    $_POST['cargo'] ?? null,
                    $_POST['email'] ?? null,
                    $_POST['telefone'] ?? null,
                    $_POST['telemovel'] ?? null,
                    $_POST['website'] ?? null,
                    $_POST['morada'] ?? null,
                    $_POST['cidade'] ?? null,
                    $_POST['pais'] ?? 'Portugal',
                    $_POST['codigo_postal'] ?? null,
                    $_POST['nif'] ?? null,
                    $_POST['notas'] ?? null,
                    $_POST['tags'] ?? null,
                    $_POST['estado'],
                    $_POST['contacto_id']
                ]);
                $message = "Contacto atualizado!";
                $messageType = 'success';
                break;
                
            case 'delete_contacto':
                $stmt = $pdo->prepare("DELETE FROM comercial_contactos WHERE id=?");
                $stmt->execute([$_POST['contacto_id']]);
                $message = "Contacto eliminado!";
                $messageType = 'success';
                break;
                
            case 'add_interacao':
                $stmt = $pdo->prepare("
                    INSERT INTO comercial_interacoes 
                    (contacto_id, tipo, assunto, descricao, data_interacao, proximo_followup, user_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['contacto_id'],
                    $_POST['tipo_interacao'],
                    $_POST['assunto'],
                    $_POST['descricao'] ?? null,
                    $_POST['data_interacao'],
                    $_POST['proximo_followup'] ?: null,
                    $_SESSION['user_id']
                ]);
                $message = "Interação registada!";
                $messageType = 'success';
                break;
                
            case 'delete_interacao':
                $stmt = $pdo->prepare("DELETE FROM comercial_interacoes WHERE id=?");
                $stmt->execute([$_POST['interacao_id']]);
                $message = "Interação removida!";
                $messageType = 'success';
                break;
        }
    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Filtros
$filtroTipo = $_GET['tipo'] ?? '';
$filtroEstado = $_GET['estado'] ?? '';
$filtroBusca = $_GET['busca'] ?? '';

// Obter contactos
$sql = "SELECT c.*, u.username as criador 
        FROM comercial_contactos c 
        LEFT JOIN user_tokens u ON c.criado_por = u.user_id 
        WHERE 1=1";
$params = [];

if ($filtroTipo) {
    $sql .= " AND c.tipo = ?";
    $params[] = $filtroTipo;
}
if ($filtroEstado) {
    $sql .= " AND c.estado = ?";
    $params[] = $filtroEstado;
}
if ($filtroBusca) {
    $sql .= " AND (c.empresa LIKE ? OR c.pessoa_contacto LIKE ? OR c.email LIKE ?)";
    $busca = "%$filtroBusca%";
    $params[] = $busca;
    $params[] = $busca;
    $params[] = $busca;
}

$sql .= " ORDER BY c.empresa ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obter contacto selecionado
$selectedContacto = null;
if (isset($_GET['contacto_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM comercial_contactos WHERE id=?");
    $stmt->execute([$_GET['contacto_id']]);
    $selectedContacto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedContacto) {
        // Obter interações
        $stmt = $pdo->prepare("
            SELECT i.*, u.username 
            FROM comercial_interacoes i 
            LEFT JOIN user_tokens u ON i.user_id = u.user_id 
            WHERE i.contacto_id = ? 
            ORDER BY i.data_interacao DESC
        ");
        $stmt->execute([$selectedContacto['id']]);
        $selectedContacto['interacoes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Estatísticas
$stats = [
    'total' => count($contactos),
    'clientes' => count(array_filter($contactos, fn($c) => $c['tipo'] === 'cliente')),
    'fornecedores' => count(array_filter($contactos, fn($c) => $c['tipo'] === 'fornecedor')),
    'parceiros' => count(array_filter($contactos, fn($c) => $c['tipo'] === 'parceiro')),
    'ativos' => count(array_filter($contactos, fn($c) => $c['estado'] === 'ativo'))
];
?>

<style>
.comercial-container {
    display: flex;
    gap: 20px;
    height: calc(100vh - 180px);
    overflow: hidden;
}

.comercial-sidebar {
    width: 350px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    overflow-y: auto;
    padding: 15px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px;
    border-radius: 8px;
    text-align: center;
}

.stat-number {
    font-size: 24px;
    font-weight: bold;
}

.stat-label {
    font-size: 12px;
    opacity: 0.9;
}

.contacto-item {
    padding: 12px;
    margin-bottom: 8px;
    background: #f8f9fa;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    border-left: 3px solid transparent;
}

.contacto-item:hover {
    background: #e9ecef;
}

.contacto-item.active {
    background: #e7f1ff;
    border-left-color: #0d6efd;
}

.contacto-tipo {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.tipo-cliente { background: #d1e7dd; color: #0f5132; }
.tipo-fornecedor { background: #cfe2ff; color: #084298; }
.tipo-parceiro { background: #fff3cd; color: #856404; }
.tipo-outro { background: #e2e3e5; color: #41464b; }

.contacto-empresa {
    font-weight: 600;
    color: #212529;
    margin-bottom: 4px;
}

.contacto-pessoa {
    font-size: 12px;
    color: #6c757d;
}

.contacto-detail {
    flex: 1;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    overflow-y: auto;
    padding: 25px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.info-item {
    background: #f8f9fa;
    padding: 12px;
    border-radius: 6px;
    border-left: 3px solid #0d6efd;
}

.info-label {
    font-size: 11px;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 14px;
    color: #212529;
}

.interacao-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 10px;
    border-left: 3px solid #0d6efd;
}

.interacao-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 10px;
}

.interacao-tipo {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.tipo-reuniao { background: #d1e7dd; color: #0f5132; }
.tipo-email { background: #cfe2ff; color: #084298; }
.tipo-telefone { background: #fff3cd; color: #856404; }
.tipo-proposta { background: #f8d7da; color: #842029; }

.badge-estado {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.estado-ativo { background: #d1e7dd; color: #0f5132; }
.estado-inativo { background: #e2e3e5; color: #41464b; }
.estado-potencial { background: #fff3cd; color: #856404; }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}

.empty-state-icon {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.3;
}
</style>

<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="comercial-container">
        <!-- Sidebar -->
        <div class="comercial-sidebar">
            <!-- Estatísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['total'] ?></div>
                    <div class="stat-label">Total</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['clientes'] ?></div>
                    <div class="stat-label">Clientes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['fornecedores'] ?></div>
                    <div class="stat-label">Fornecedores</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $stats['parceiros'] ?></div>
                    <div class="stat-label">Parceiros</div>
                </div>
            </div>

            <!-- Filtros -->
            <form method="get" class="mb-3">
                <input type="hidden" name="tab" value="contactos_comerciais">
                <div class="input-group input-group-sm mb-2">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar..." 
                           value="<?= htmlspecialchars($filtroBusca) ?>">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <select name="tipo" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Todos os tipos</option>
                            <option value="cliente" <?= $filtroTipo === 'cliente' ? 'selected' : '' ?>>Clientes</option>
                            <option value="fornecedor" <?= $filtroTipo === 'fornecedor' ? 'selected' : '' ?>>Fornecedores</option>
                            <option value="parceiro" <?= $filtroTipo === 'parceiro' ? 'selected' : '' ?>>Parceiros</option>
                            <option value="outro" <?= $filtroTipo === 'outro' ? 'selected' : '' ?>>Outros</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Todos estados</option>
                            <option value="ativo" <?= $filtroEstado === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                            <option value="inativo" <?= $filtroEstado === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                            <option value="potencial" <?= $filtroEstado === 'potencial' ? 'selected' : '' ?>>Potencial</option>
                        </select>
                    </div>
                </div>
            </form>

            <!-- Botão Novo -->
            <button class="btn btn-primary w-100 mb-3" data-bs-toggle="modal" data-bs-target="#newContactoModal">
                <i class="bi bi-plus-lg"></i> Novo Contacto
            </button>

            <!-- Lista de Contactos -->
            <?php if (empty($contactos)): ?>
                <p class="text-center text-muted mt-4">Nenhum contacto encontrado</p>
            <?php else: ?>
                <?php foreach ($contactos as $cont): ?>
                    <a href="?tab=contactos_comerciais&contacto_id=<?= $cont['id'] ?>&<?= http_build_query(['tipo' => $filtroTipo, 'estado' => $filtroEstado, 'busca' => $filtroBusca]) ?>" 
                       class="text-decoration-none">
                        <div class="contacto-item <?= isset($_GET['contacto_id']) && $_GET['contacto_id'] == $cont['id'] ? 'active' : '' ?>">
                            <span class="contacto-tipo tipo-<?= $cont['tipo'] ?>"><?= ucfirst($cont['tipo']) ?></span>
                            <div class="contacto-empresa"><?= htmlspecialchars($cont['empresa']) ?></div>
                            <?php if ($cont['pessoa_contacto']): ?>
                                <div class="contacto-pessoa">
                                    <i class="bi bi-person"></i> <?= htmlspecialchars($cont['pessoa_contacto']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Detalhes -->
        <div class="contacto-detail">
            <?php if (!$selectedContacto): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">👥</div>
                    <h4>Selecione um contacto</h4>
                    <p>Escolha um contacto da lista ou crie um novo</p>
                </div>
            <?php else: ?>
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h3 class="mb-1"><?= htmlspecialchars($selectedContacto['empresa']) ?></h3>
                        <span class="badge-estado estado-<?= $selectedContacto['estado'] ?>">
                            <?= ucfirst($selectedContacto['estado']) ?>
                        </span>
                        <span class="contacto-tipo tipo-<?= $selectedContacto['tipo'] ?> ms-2">
                            <?= ucfirst($selectedContacto['tipo']) ?>
                        </span>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary" onclick="editContacto(<?= htmlspecialchars(json_encode($selectedContacto)) ?>)">
                            <i class="bi bi-pencil"></i> Editar
                        </button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Eliminar este contacto?')">
                            <input type="hidden" name="action" value="delete_contacto">
                            <input type="hidden" name="contacto_id" value="<?= $selectedContacto['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i> Eliminar
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Informações -->
                <h5 class="mb-3"><i class="bi bi-info-circle"></i> Informações</h5>
                <div class="info-grid">
                    <?php if ($selectedContacto['pessoa_contacto']): ?>
                    <div class="info-item">
                        <div class="info-label">Pessoa de Contacto</div>
                        <div class="info-value"><?= htmlspecialchars($selectedContacto['pessoa_contacto']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($selectedContacto['cargo']): ?>
                    <div class="info-item">
                        <div class="info-label">Cargo</div>
                        <div class="info-value"><?= htmlspecialchars($selectedContacto['cargo']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($selectedContacto['email']): ?>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value">
                            <a href="mailto:<?= htmlspecialchars($selectedContacto['email']) ?>">
                                <?= htmlspecialchars($selectedContacto['email']) ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($selectedContacto['telefone']): ?>
                    <div class="info-item">
                        <div class="info-label">Telefone</div>
                        <div class="info-value"><?= htmlspecialchars($selectedContacto['telefone']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($selectedContacto['telemovel']): ?>
                    <div class="info-item">
                        <div class="info-label">Telemóvel</div>
                        <div class="info-value"><?= htmlspecialchars($selectedContacto['telemovel']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($selectedContacto['website']): ?>
                    <div class="info-item">
                        <div class="info-label">Website</div>
                        <div class="info-value">
                            <a href="<?= htmlspecialchars($selectedContacto['website']) ?>" target="_blank">
                                <?= htmlspecialchars($selectedContacto['website']) ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($selectedContacto['nif']): ?>
                    <div class="info-item">
                        <div class="info-label">NIF</div>
                        <div class="info-value"><?= htmlspecialchars($selectedContacto['nif']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($selectedContacto['morada']): ?>
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <div class="info-label">Morada</div>
                        <div class="info-value">
                            <?= htmlspecialchars($selectedContacto['morada']) ?><br>
                            <?= htmlspecialchars($selectedContacto['codigo_postal']) ?> <?= htmlspecialchars($selectedContacto['cidade']) ?><br>
                            <?= htmlspecialchars($selectedContacto['pais']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($selectedContacto['notas']): ?>
                <div class="alert alert-info mt-3">
                    <strong>Notas:</strong><br>
                    <?= nl2br(htmlspecialchars($selectedContacto['notas'])) ?>
                </div>
                <?php endif; ?>

                <?php if ($selectedContacto['tags']): ?>
                <div class="mt-3">
                    <strong>Tags:</strong>
                    <?php foreach (explode(',', $selectedContacto['tags']) as $tag): ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars(trim($tag)) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Interações -->
                <hr class="my-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5><i class="bi bi-chat-dots"></i> Histórico de Interações</h5>
                    <button class="btn btn-sm btn-primary" onclick="addInteracao(<?= $selectedContacto['id'] ?>)">
                        <i class="bi bi-plus-lg"></i> Nova Interação
                    </button>
                </div>

                <?php if (empty($selectedContacto['interacoes'])): ?>
                    <p class="text-muted">Nenhuma interação registada</p>
                <?php else: ?>
                    <?php foreach ($selectedContacto['interacoes'] as $inter): ?>
                        <div class="interacao-item">
                            <div class="interacao-header">
                                <div>
                                    <span class="interacao-tipo tipo-<?= $inter['tipo'] ?>"><?= ucfirst($inter['tipo']) ?></span>
                                    <strong class="ms-2"><?= htmlspecialchars($inter['assunto']) ?></strong>
                                </div>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Remover?')">
                                    <input type="hidden" name="action" value="delete_interacao">
                                    <input type="hidden" name="interacao_id" value="<?= $inter['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                            <?php if ($inter['descricao']): ?>
                                <p class="mb-2"><?= nl2br(htmlspecialchars($inter['descricao'])) ?></p>
                            <?php endif; ?>
                            <div class="small text-muted">
                                <i class="bi bi-calendar"></i> <?= date('d/m/Y H:i', strtotime($inter['data_interacao'])) ?>
                                | <i class="bi bi-person"></i> <?= htmlspecialchars($inter['username']) ?>
                                <?php if ($inter['proximo_followup']): ?>
                                    | <i class="bi bi-arrow-right-circle"></i> Follow-up: <?= date('d/m/Y', strtotime($inter['proximo_followup'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Novo Contacto -->
<div class="modal fade" id="newContactoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Novo Contacto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_contacto">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Tipo *</label>
                                <select name="tipo" class="form-select" required>
                                    <option value="cliente">Cliente</option>
                                    <option value="fornecedor">Fornecedor</option>
                                    <option value="parceiro">Parceiro</option>
                                    <option value="outro">Outro</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Estado *</label>
                                <select name="estado" class="form-select" required>
                                    <option value="ativo">Ativo</option>
                                    <option value="potencial">Potencial</option>
                                    <option value="inativo">Inativo</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Empresa *</label>
                        <input type="text" name="empresa" class="form-control" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Pessoa de Contacto</label>
                                <input type="text" name="pessoa_contacto" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Cargo</label>
                                <input type="text" name="cargo" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Telefone</label>
                                <input type="text" name="telefone" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Telemóvel</label>
                                <input type="text" name="telemovel" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Website</label>
                                <input type="url" name="website" class="form-control" placeholder="https://">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Morada</label>
                        <textarea name="morada" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Código Postal</label>
                                <input type="text" name="codigo_postal" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Cidade</label>
                                <input type="text" name="cidade" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">País</label>
                                <input type="text" name="pais" class="form-control" value="Portugal">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">NIF</label>
                        <input type="text" name="nif" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tags (separadas por vírgula)</label>
                        <input type="text" name="tags" class="form-control" placeholder="vip, internacional, etc">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notas</label>
                        <textarea name="notas" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Contacto</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Contacto -->
<div class="modal fade" id="editContactoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Contacto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_contacto">
                    <input type="hidden" name="contacto_id" id="edit_contacto_id">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Tipo *</label>
                                <select name="tipo" id="edit_tipo" class="form-select" required>
                                    <option value="cliente">Cliente</option>
                                    <option value="fornecedor">Fornecedor</option>
                                    <option value="parceiro">Parceiro</option>
                                    <option value="outro">Outro</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Estado *</label>
                                <select name="estado" id="edit_estado" class="form-select" required>
                                    <option value="ativo">Ativo</option>
                                    <option value="potencial">Potencial</option>
                                    <option value="inativo">Inativo</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Empresa *</label>
                        <input type="text" name="empresa" id="edit_empresa" class="form-control" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Pessoa de Contacto</label>
                                <input type="text" name="pessoa_contacto" id="edit_pessoa_contacto" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Cargo</label>
                                <input type="text" name="cargo" id="edit_cargo" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="edit_email" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Telefone</label>
                                <input type="text" name="telefone" id="edit_telefone" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Telemóvel</label>
                                <input type="text" name="telemovel" id="edit_telemovel" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Website</label>
                                <input type="url" name="website" id="edit_website" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Morada</label>
                        <textarea name="morada" id="edit_morada" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Código Postal</label>
                                <input type="text" name="codigo_postal" id="edit_codigo_postal" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Cidade</label>
                                <input type="text" name="cidade" id="edit_cidade" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">País</label>
                                <input type="text" name="pais" id="edit_pais" class="form-control">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">NIF</label>
                        <input type="text" name="nif" id="edit_nif" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tags (separadas por vírgula)</label>
                        <input type="text" name="tags" id="edit_tags" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notas</label>
                        <textarea name="notas" id="edit_notas" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Nova Interação -->
<div class="modal fade" id="addInteracaoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nova Interação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_interacao">
                    <input type="hidden" name="contacto_id" id="interacao_contacto_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Tipo *</label>
                        <select name="tipo_interacao" class="form-select" required>
                            <option value="reuniao">Reunião</option>
                            <option value="email">Email</option>
                            <option value="telefone">Telefonema</option>
                            <option value="proposta">Proposta</option>
                            <option value="negociacao">Negociação</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Assunto *</label>
                        <input type="text" name="assunto" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" class="form-control" rows="4"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Data e Hora da Interação *</label>
                        <input type="datetime-local" name="data_interacao" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Próximo Follow-up</label>
                        <input type="date" name="proximo_followup" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editContacto(contacto) {
    document.getElementById('edit_contacto_id').value = contacto.id;
    document.getElementById('edit_tipo').value = contacto.tipo;
    document.getElementById('edit_estado').value = contacto.estado;
    document.getElementById('edit_empresa').value = contacto.empresa;
    document.getElementById('edit_pessoa_contacto').value = contacto.pessoa_contacto || '';
    document.getElementById('edit_cargo').value = contacto.cargo || '';
    document.getElementById('edit_email').value = contacto.email || '';
    document.getElementById('edit_telefone').value = contacto.telefone || '';
    document.getElementById('edit_telemovel').value = contacto.telemovel || '';
    document.getElementById('edit_website').value = contacto.website || '';
    document.getElementById('edit_morada').value = contacto.morada || '';
    document.getElementById('edit_codigo_postal').value = contacto.codigo_postal || '';
    document.getElementById('edit_cidade').value = contacto.cidade || '';
    document.getElementById('edit_pais').value = contacto.pais || '';
    document.getElementById('edit_nif').value = contacto.nif || '';
    document.getElementById('edit_tags').value = contacto.tags || '';
    document.getElementById('edit_notas').value = contacto.notas || '';
    
    var modal = new bootstrap.Modal(document.getElementById('editContactoModal'));
    modal.show();
}

function addInteracao(contactoId) {
    document.getElementById('interacao_contacto_id').value = contactoId;
    
    // Definir data/hora atual
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    
    document.querySelector('input[name="data_interacao"]').value = `${year}-${month}-${day}T${hours}:${minutes}`;
    
    var modal = new bootstrap.Modal(document.getElementById('addInteracaoModal'));
    modal.show();
}
</script>