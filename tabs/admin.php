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

            <?php if ($mensagem): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($mensagem) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($erro): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Seção de Backup e Restore -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0"><i class="bi bi-download"></i> Download da Base de Dados</h5>
                        </div>
                        <div class="card-body">
                            <p class="card-text">Faça o download de toda a base de dados em formato SQL.</p>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="db_selected" value="<?= htmlspecialchars($dbSelecionada) ?>">
                                <button type="submit" name="download_db" class="btn btn-primary" onclick="return confirm('Confirma o download da base de dados <?= htmlspecialchars($dbSelecionada) ?>?')">
                                    <i class="bi bi-download"></i> Download SQL
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="card-title mb-0"><i class="bi bi-upload"></i> Upload da Base de Dados</h5>
                        </div>
                        <div class="card-body">
                            <p class="card-text">Restaure a base de dados a partir de um arquivo SQL.</p>
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="db_selected" value="<?= htmlspecialchars($dbSelecionada) ?>">
                                <div class="mb-3">
                                    <input type="file" class="form-control" name="sql_file" accept=".sql" required>
                                </div>
                                <button type="submit" name="upload_db" class="btn btn-warning" onclick="return confirm('ATENÇÃO: Esta operação irá substituir todos os dados existentes na base <?= htmlspecialchars($dbSelecionada) ?>. Confirma?')">
                                    <i class="bi bi-upload"></i> Restaurar BD
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Informações das Tabelas -->
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0"><i class="bi bi-table"></i> Informações das Tabelas</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($tabelasInfo)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Nome da Tabela</th>
                                        <th>Nº de Linhas</th>
                                        <th>Tamanho</th>
                                        <th>Engine</th>
                                        <th>Collation</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $totalLinhas = 0;
                                    $tamanhoTotal = 0;
                                    foreach ($tabelasInfo as $tabela): 
                                        $totalLinhas += $tabela['linhas'];
                                        $tamanhoTotal += $tabela['tamanho'];
                                    ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($tabela['nome']) ?></strong></td>
                                            <td>
                                                <span class="badge bg-secondary"><?= number_format($tabela['linhas']) ?></span>
                                            </td>
                                            <td><?= formatBytes($tabela['tamanho']) ?></td>
                                            <td><?= htmlspecialchars($tabela['engine']) ?></td>
                                            <td><small><?= htmlspecialchars($tabela['collation']) ?></small></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <form method="post" class="d-inline" onsubmit="return confirm('ATENÇÃO: Esta ação irá apagar TODOS os dados da tabela <?= htmlspecialchars($tabela['nome']) ?> na base <?= htmlspecialchars($dbSelecionada) ?>. A estrutura da tabela será mantida. Confirma?')">
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
                                    <td><strong>ID do Utilizador:</strong></td>
                                    <td><?= htmlspecialchars($_SESSION['user_id']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Data/Hora:</strong></td>
                                    <td><?= date('Y-m-d H:i:s') ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Versão PHP:</strong></td>
                                    <td><?= phpversion() ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Aviso de Segurança -->
            <div class="alert alert-warning mt-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong>Aviso de Segurança:</strong> Esta área contém funcionalidades críticas que podem afetar todo o sistema. 
                Use sempre com precaução e certifique-se de que tem backups atualizados antes de realizar operações de restore ou limpeza de tabelas.
            </div>
        </div>
    </div>
</div>

<style>
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        border: 1px solid rgba(0, 0, 0, 0.125);
    }
    
    .table th {
        font-weight: 600;
    }
    
    .badge {
        font-size: 0.875em;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.8125rem;
    }
    
    .alert {
        border-left: 4px solid;
    }
    
    .alert-success {
        border-left-color: #198754;
    }
    
    .alert-danger {
        border-left-color: #dc3545;
    }
    
    .alert-warning {
        border-left-color: #ffc107;
    }
    
    .alert-info {
        border-left-color: #0dcaf0;
    }
</style>