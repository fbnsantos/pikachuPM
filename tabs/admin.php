<?php
// admin.php - Tab de administração para PikachuPM

// Botão de acesso ao debug (disponível para todos os utilizadores logados)
echo '<div class="mb-3">';
echo '<a href="debug.php" class="btn btn-info btn-sm" target="_blank">';
echo '<i class="bi bi-bug"></i> Abrir Debug';
echo '</a>';
echo '</div>';

// Verificar se o usuário tem permissão de admin (pode ajustar conforme necessário)
if ($_SESSION['username'] !== 'fbsantos' && $_SESSION['user_id'] != 1) {
    echo "<div class='alert alert-danger'><i class='bi bi-exclamation-triangle'></i> Acesso negado. Esta área é restrita a administradores.</div>";
    return;
}

include_once __DIR__ . '/../config.php';

// Função para conectar à base de dados
function conectarDB($dbName = null) {
    global $db_host, $db_name, $db_name_boom, $db_user, $db_pass;
    
    // Se não especificar, usar a base de dados principal
    $database = $dbName ?: $db_name;
    
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$database;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        throw new Exception("Erro de conexão com $database: " . $e->getMessage());
    }
}

// Processar ações
$mensagem = '';
$erro = '';
$dbSelecionada = $_POST['db_selected'] ?? $_GET['db'] ?? $db_name; // Base de dados selecionada

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = conectarDB($dbSelecionada);
        
        // Adicionar novo utilizador ao user_tokens
        if (isset($_POST['add_user_token'])) {
            $user_id = intval($_POST['new_user_id']);
            $username = trim($_POST['new_username']);
            $token = trim($_POST['new_token']);
            
            // Se o token estiver vazio, gerar automaticamente
            if (empty($token)) {
                $token = bin2hex(random_bytes(32));
            }
            
            if (empty($username)) {
                throw new Exception("O nome de utilizador é obrigatório.");
            }
            
            $stmt = $pdo->prepare("INSERT INTO user_tokens (user_id, username, token) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $username, $token]);
            $mensagem = "Utilizador '$username' adicionado com sucesso à tabela user_tokens!";
        }
        
        // Apagar utilizador do user_tokens
        if (isset($_POST['delete_user_token'])) {
            $id = intval($_POST['user_token_id']);
            $stmt = $pdo->prepare("DELETE FROM user_tokens WHERE id = ?");
            $stmt->execute([$id]);
            $mensagem = "Utilizador removido com sucesso da tabela user_tokens!";
        }
        
        // Download da base de dados
        if (isset($_POST['download_db'])) {
            $filename = "backup_" . $dbSelecionada . "_" . date('Y-m-d_H-i-s') . ".sql";
            
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            
            // Gerar backup SQL
            echo "-- Backup da base de dados: $dbSelecionada\n";
            echo "-- Data: " . date('Y-m-d H:i:s') . "\n";
            echo "-- Gerado pelo PikachuPM Admin\n\n";
            echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            
            // Obter todas as tabelas
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                echo "-- Estrutura da tabela `$table`\n";
                echo "DROP TABLE IF EXISTS `$table`;\n";
                
                // Obter CREATE TABLE
                $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                echo $row['Create Table'] . ";\n\n";
                
                // Obter dados da tabela
                echo "-- Dados da tabela `$table`\n";
                $stmt = $pdo->query("SELECT * FROM `$table`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    $columns = array_keys($rows[0]);
                    $columnsList = '`' . implode('`, `', $columns) . '`';
                    
                    foreach ($rows as $row) {
                        $values = array_map(function($value) use ($pdo) {
                            return $value === null ? 'NULL' : $pdo->quote($value);
                        }, array_values($row));
                        
                        echo "INSERT INTO `$table` ($columnsList) VALUES (" . implode(', ', $values) . ");\n";
                    }
                }
                echo "\n";
            }
            
            echo "SET FOREIGN_KEY_CHECKS = 1;\n";
            exit;
        }
        
        // Upload e restore da base de dados
        if (isset($_POST['upload_db']) && isset($_FILES['sql_file'])) {
            if ($_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
                $uploadedFile = $_FILES['sql_file']['tmp_name'];
                $sqlContent = file_get_contents($uploadedFile);
                
                if ($sqlContent !== false) {
                    // Executar o SQL
                    $pdo->exec($sqlContent);
                    $mensagem = "Base de dados '$dbSelecionada' restaurada com sucesso!";
                } else {
                    throw new Exception("Erro ao ler o arquivo SQL.");
                }
            } else {
                throw new Exception("Erro no upload do arquivo.");
            }
        }
        
        // Limpar tabela específica
        if (isset($_POST['clear_table']) && !empty($_POST['table_name'])) {
            $tableName = $_POST['table_name'];
            $stmt = $pdo->prepare("DELETE FROM `$tableName`");
            $stmt->execute();
            $mensagem = "Tabela '$tableName' da base de dados '$dbSelecionada' limpa com sucesso!";
        }
        
        // Apagar tabela completamente
        if (isset($_POST['drop_table']) && !empty($_POST['table_name'])) {
            $tableName = $_POST['table_name'];
            $stmt = $pdo->prepare("DROP TABLE `$tableName`");
            $stmt->execute();
            $mensagem = "Tabela '$tableName' da base de dados '$dbSelecionada' apagada completamente!";
        }
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Obter informações das tabelas
$tabelasInfo = [];
$basesDisponiveis = [$db_name, $db_name_boom];

try {
    $pdo = conectarDB($dbSelecionada);
    
    // Obter lista de tabelas
    $stmt = $pdo->query("SHOW TABLES");
    $tabelas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tabelas as $tabela) {
        // Contar linhas
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM `$tabela`");
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Obter informações da estrutura
        $stmt = $pdo->query("SHOW TABLE STATUS LIKE '$tabela'");
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $tabelasInfo[] = [
            'nome' => $tabela,
            'linhas' => $total,
            'tamanho' => $info['Data_length'] + $info['Index_length'],
            'engine' => $info['Engine'],
            'collation' => $info['Collation']
        ];
    }
    
} catch (Exception $e) {
    $erro = "Erro ao obter informações da base de dados '$dbSelecionada': " . $e->getMessage();
}

function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-gear-fill"></i> Administração da Base de Dados</h2>
                <div class="text-muted">
                    <form method="get" class="d-inline">
                        <input type="hidden" name="tab" value="admin">
                        <select name="db" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                            <?php foreach ($basesDisponiveis as $db): ?>
                                <option value="<?= htmlspecialchars($db) ?>" <?= $dbSelecionada === $db ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($db) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <!-- Mensagens de Sucesso/Erro -->
            <?php if (!empty($mensagem)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($mensagem) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($erro)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Ações da Base de Dados -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-tools"></i> Ações da Base de Dados</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Download -->
                        <div class="col-md-6">
                            <form method="post">
                                <input type="hidden" name="db_selected" value="<?= htmlspecialchars($dbSelecionada) ?>">
                                <button type="submit" name="download_db" class="btn btn-success w-100">
                                    <i class="bi bi-download"></i> Download da Base de Dados (SQL)
                                </button>
                            </form>
                        </div>

                        <!-- Upload -->
                        <div class="col-md-6">
                            <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
                                <input type="hidden" name="db_selected" value="<?= htmlspecialchars($dbSelecionada) ?>">
                                <input type="file" name="sql_file" accept=".sql" class="form-control" required>
                                <button type="submit" name="upload_db" class="btn btn-warning text-nowrap" onclick="return confirm('Esta ação irá restaurar a base de dados. Deseja continuar?')">
                                    <i class="bi bi-upload"></i> Restaurar
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lista de Tabelas -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-table"></i> Tabelas da Base de Dados: <?= htmlspecialchars($dbSelecionada) ?></h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($tabelasInfo)): ?>
                        <?php
                        $totalLinhas = array_sum(array_column($tabelasInfo, 'linhas'));
                        $tamanhoTotal = array_sum(array_column($tabelasInfo, 'tamanho'));
                        ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Tabela</th>
                                        <th>Linhas</th>
                                        <th>Tamanho</th>
                                        <th>Engine</th>
                                        <th>Collation</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tabelasInfo as $tabela): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($tabela['nome']) ?></strong></td>
                                            <td><span class="badge bg-secondary"><?= number_format($tabela['linhas']) ?></span></td>
                                            <td><?= formatBytes($tabela['tamanho']) ?></td>
                                            <td><?= htmlspecialchars($tabela['engine']) ?></td>
                                            <td><small><?= htmlspecialchars($tabela['collation']) ?></small></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Tem certeza que deseja limpar todos os dados da tabela <?= htmlspecialchars($tabela['nome']) ?> da base <?= htmlspecialchars($dbSelecionada) ?>? A estrutura da tabela será mantida. Confirma?')">
                                                        <input type="hidden" name="db_selected" value="<?= htmlspecialchars($dbSelecionada) ?>">
                                                        <input type="hidden" name="table_name" value="<?= htmlspecialchars($tabela['nome']) ?>">
                                                        <button type="submit" name="clear_table" class="btn btn-sm btn-outline-warning" title="Limpar dados da tabela">
                                                            <i class="bi bi-trash"></i> Limpar
                                                        </button>
                                                    </form>
                                                    <form method="post" class="d-inline ms-1" onsubmit="return confirm('PERIGO: Esta ação irá APAGAR COMPLETAMENTE a tabela <?= htmlspecialchars($tabela['nome']) ?> da base <?= htmlspecialchars($dbSelecionada) ?> (estrutura e dados). Esta operação NÃO pode ser desfeita! Tem a certeza absoluta?')">
                                                        <input type="hidden" name="db_selected" value="<?= htmlspecialchars($dbSelecionada) ?>">
                                                        <input type="hidden" name="table_name" value="<?= htmlspecialchars($tabela['nome']) ?>">
                                                        <button type="submit" name="drop_table" class="btn btn-sm btn-danger" title="Apagar tabela completamente">
                                                            <i class="bi bi-trash-fill"></i> Apagar
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th>TOTAL</th>
                                        <th><span class="badge bg-primary"><?= number_format($totalLinhas) ?></span></th>
                                        <th><?= formatBytes($tamanhoTotal) ?></th>
                                        <th colspan="3">-</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Nenhuma tabela encontrada ou erro na conexão.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Informações do Sistema -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h6 class="card-title mb-0"><i class="bi bi-info-circle"></i> Informações da Conexão</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Base de Dados Ativa:</strong></td>
                                    <td><?= htmlspecialchars($dbSelecionada) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Host:</strong></td>
                                    <td><?= htmlspecialchars($db_host) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Bases Disponíveis:</strong></td>
                                    <td><?= htmlspecialchars($db_name) ?>, <?= htmlspecialchars($db_name_boom) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Utilizador:</strong></td>
                                    <td><?= htmlspecialchars($db_user) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Nº de Tabelas:</strong></td>
                                    <td><?= count($tabelasInfo) ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h6 class="card-title mb-0"><i class="bi bi-clock"></i> Informações da Sessão</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Utilizador Admin:</strong></td>
                                    <td><?= htmlspecialchars($_SESSION['username']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>User ID:</strong></td>
                                    <td><?= htmlspecialchars($_SESSION['user_id']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Data/Hora Atual:</strong></td>
                                    <td><?= date('Y-m-d H:i:s') ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gestão de User Tokens -->
            <?php
            try {
                $pdo = conectarDB($dbSelecionada);
                
                // Verificar se a tabela user_tokens existe
                $stmt = $pdo->query("SHOW TABLES LIKE 'user_tokens'");
                $tableExists = $stmt->fetch();
                
                if ($tableExists):
                    // Obter todos os utilizadores
                    $stmt = $pdo->query("SELECT * FROM user_tokens ORDER BY user_id ASC");
                    $userTokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
                    <div class="card mt-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="card-title mb-0"><i class="bi bi-people-fill"></i> Gestão de Utilizadores (user_tokens)</h5>
                        </div>
                        <div class="card-body">
                            <!-- Formulário para adicionar novo utilizador -->
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-person-plus"></i> Adicionar Novo Utilizador</h6>
                                </div>
                                <div class="card-body">
                                    <form method="post" class="row g-3">
                                        <input type="hidden" name="db_selected" value="<?= htmlspecialchars($dbSelecionada) ?>">
                                        
                                        <div class="col-md-2">
                                            <label for="new_user_id" class="form-label">User ID</label>
                                            <input type="number" class="form-control" id="new_user_id" name="new_user_id" required>
                                            <small class="text-muted">ID único do utilizador</small>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <label for="new_username" class="form-label">Username *</label>
                                            <input type="text" class="form-control" id="new_username" name="new_username" required>
                                            <small class="text-muted">Nome do utilizador</small>
                                        </div>
                                        
                                        <div class="col-md-5">
                                            <label for="new_token" class="form-label">Token</label>
                                            <input type="text" class="form-control" id="new_token" name="new_token" placeholder="Deixe vazio para gerar automaticamente">
                                            <small class="text-muted">Token de autenticação (gerado automaticamente se vazio)</small>
                                        </div>
                                        
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="submit" name="add_user_token" class="btn btn-success w-100">
                                                <i class="bi bi-plus-circle"></i> Adicionar
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Lista de utilizadores -->
                            <?php if (!empty($userTokens)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>ID</th>
                                                <th>User ID</th>
                                                <th>Username</th>
                                                <th>Token</th>
                                                <th>Criado em</th>
                                                <th>Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($userTokens as $user): ?>
                                                <tr>
                                                    <td><strong><?= htmlspecialchars($user['id']) ?></strong></td>
                                                    <td><span class="badge bg-info"><?= htmlspecialchars($user['user_id']) ?></span></td>
                                                    <td><?= htmlspecialchars($user['username']) ?></td>
                                                    <td>
                                                        <code class="small" style="word-break: break-all;">
                                                            <?= htmlspecialchars(substr($user['token'], 0, 20)) ?>...
                                                        </code>
                                                        <button class="btn btn-sm btn-outline-secondary ms-1" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($user['token']) ?>'); this.innerHTML='<i class=\'bi bi-check\'></i> Copiado!';" title="Copiar token completo">
                                                            <i class="bi bi-clipboard"></i>
                                                        </button>
                                                    </td>
                                                    <td><small><?= htmlspecialchars($user['created_at'] ?? 'N/A') ?></small></td>
                                                    <td>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Tem certeza que deseja apagar o utilizador \'<?= htmlspecialchars($user['username']) ?>\'?')">
                                                            <input type="hidden" name="db_selected" value="<?= htmlspecialchars($dbSelecionada) ?>">
                                                            <input type="hidden" name="user_token_id" value="<?= htmlspecialchars($user['id']) ?>">
                                                            <button type="submit" name="delete_user_token" class="btn btn-sm btn-danger">
                                                                <i class="bi bi-trash"></i> Apagar
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th colspan="6">
                                                    Total de utilizadores: <span class="badge bg-primary"><?= count($userTokens) ?></span>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> Nenhum utilizador encontrado na tabela user_tokens.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
            <?php
                endif;
            } catch (Exception $e) {
                echo '<div class="alert alert-warning mt-4">';
                echo '<i class="bi bi-exclamation-triangle"></i> Não foi possível carregar a tabela user_tokens: ' . htmlspecialchars($e->getMessage());
                echo '</div>';
            }
            ?>

        </div>
    </div>
</div>