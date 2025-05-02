<?php
// config_check.php - Script para verificar a configuração MySQL

// Ativar exibição de erros
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<html><head><title>Verificação de Configuração MySQL</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
    .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; margin: 10px 0; border-radius: 4px; }
    .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 10px; margin: 10px 0; border-radius: 4px; }
    .warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 10px; margin: 10px 0; border-radius: 4px; }
    .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 10px; margin: 10px 0; border-radius: 4px; }
    pre { margin: 10px 0; padding: 10px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; overflow: auto; }
    table { border-collapse: collapse; width: 100%; margin: 15px 0; }
    table, th, td { border: 1px solid #ddd; }
    th, td { padding: 10px; text-align: left; }
    th { background-color: #f2f2f2; }
</style></head><body>";

echo "<h1>Verificação de Configuração MySQL</h1>";

// Verificar se PHP tem as extensões necessárias
echo "<h2>1. Verificando extensões PHP</h2>";
if (extension_loaded('mysqli')) {
    echo "<div class='success'>✅ Extensão MySQLi está carregada.</div>";
} else {
    echo "<div class='error'>❌ Extensão MySQLi NÃO está carregada. Esta extensão é obrigatória para conectar ao MySQL.</div>";
}

// Verificar arquivo de configuração
echo "<h2>2. Verificando arquivo de configuração</h2>";
$config_file = '../config.php';
if (file_exists($config_file)) {
    echo "<div class='success'>✅ Arquivo de configuração encontrado: $config_file</div>";
    
    // Incluir arquivo e verificar variáveis
    try {
        include($config_file);
        
        echo "<h3>Variáveis de configuração:</h3>";
        echo "<table>";
        echo "<tr><th>Variável</th><th>Status</th><th>Valor</th></tr>";
        
        // Verificar db_host
        echo "<tr>";
        echo "<td><code>\$db_host</code></td>";
        if (isset($db_host)) {
            echo "<td class='success'>Definido</td>";
            echo "<td>" . htmlspecialchars($db_host) . "</td>";
        } else {
            echo "<td class='error'>Não definido</td>";
            echo "<td>-</td>";
        }
        echo "</tr>";
        
        // Verificar db_name
        echo "<tr>";
        echo "<td><code>\$db_name</code></td>";
        if (isset($db_name)) {
            echo "<td class='success'>Definido</td>";
            echo "<td>" . htmlspecialchars($db_name) . "</td>";
        } else {
            echo "<td class='error'>Não definido</td>";
            echo "<td>-</td>";
        }
        echo "</tr>";
        
        // Verificar db_user
        echo "<tr>";
        echo "<td><code>\$db_user</code></td>";
        if (isset($db_user)) {
            echo "<td class='success'>Definido</td>";
            echo "<td>" . htmlspecialchars($db_user) . "</td>";
        } else {
            echo "<td class='error'>Não definido</td>";
            echo "<td>-</td>";
        }
        echo "</tr>";
        
        // Verificar db_pass
        echo "<tr>";
        echo "<td><code>\$db_pass</code></td>";
        if (isset($db_pass)) {
            echo "<td class='success'>Definido</td>";
            echo "<td>[OCULTO]</td>";
        } else {
            echo "<td class='error'>Não definido</td>";
            echo "<td>-</td>";
        }
        echo "</tr>";
        
        echo "</table>";
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Erro ao incluir ou processar o arquivo de configuração: " . $e->getMessage() . "</div>";
    }
    
} else {
    echo "<div class='error'>❌ Arquivo de configuração NÃO encontrado: $config_file</div>";
    echo "<div class='info'>Crie o arquivo config.php na pasta pai com o seguinte conteúdo:</div>";
    echo "<pre>&lt;?php
// Configurações do banco de dados
\$db_host = 'localhost';     // Host do banco de dados
\$db_name = 'pikachu_pm';    // Nome do banco de dados 
\$db_user = 'root';          // Usuário do MySQL
\$db_pass = 'sua_senha';     // Senha do MySQL
?&gt;</pre>";
}

// Testar conexão com o MySQL
echo "<h2>3. Testando conexão com o MySQL</h2>";

if (isset($db_host) && isset($db_user) && isset($db_pass) && isset($db_name)) {
    try {
        $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
        
        if ($mysqli->connect_error) {
            echo "<div class='error'>❌ Falha na conexão: " . htmlspecialchars($mysqli->connect_error) . "</div>";
            
            // Verificar se o banco existe
            try {
                $mysqli_check = new mysqli($db_host, $db_user, $db_pass);
                
                if (!$mysqli_check->connect_error) {
                    $result = $mysqli_check->query("SHOW DATABASES LIKE '" . $mysqli_check->real_escape_string($db_name) . "'");
                    if ($result->num_rows == 0) {
                        echo "<div class='warning'>⚠️ O banco de dados '$db_name' não existe. Tentando criar...</div>";
                        
                        if ($mysqli_check->query("CREATE DATABASE IF NOT EXISTS `" . $mysqli_check->real_escape_string($db_name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                            echo "<div class='success'>✅ Banco de dados '$db_name' criado com sucesso!</div>";
                        } else {
                            echo "<div class='error'>❌ Erro ao criar o banco de dados: " . $mysqli_check->error . "</div>";
                        }
                    }
                    $mysqli_check->close();
                }
            } catch (Exception $e) {
                echo "<div class='error'>❌ Erro ao verificar banco de dados: " . $e->getMessage() . "</div>";
            }
        } else {
            echo "<div class='success'>✅ Conexão estabelecida com sucesso!</div>";
            
            // Testar criação de tabela
            echo "<h3>Testando permissões para criar tabelas:</h3>";
            
            if ($mysqli->query("CREATE TABLE IF NOT EXISTS _config_test (id INT)")) {
                echo "<div class='success'>✅ Permissão para criar tabelas confirmada</div>";
                $mysqli->query("DROP TABLE IF EXISTS _config_test");
            } else {
                echo "<div class='error'>❌ Sem permissão para criar tabelas: " . $mysqli->error . "</div>";
            }
            
            // Verificar se as tabelas existem
            echo "<h3>Verificando tabelas do sistema:</h3>";
            echo "<table>";
            echo "<tr><th>Tabela</th><th>Status</th></tr>";
            
            $tables = ['user_tokens', 'todos'];
            foreach ($tables as $table) {
                $result = $mysqli->query("SHOW TABLES LIKE '$table'");
                echo "<tr>";
                echo "<td><code>$table</code></td>";
                if ($result->num_rows > 0) {
                    echo "<td class='success'>Existe</td>";
                } else {
                    echo "<td class='warning'>Não existe</td>";
                }
                echo "</tr>";
            }
            
            echo "</table>";
            
            $mysqli->close();
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ Exceção ao conectar ao MySQL: " . $e->getMessage() . "</div>";
    }
} else {
    echo "<div class='error'>❌ Informações de conexão incompletas. Verifique o arquivo de configuração.</div>";
}

echo "<h2>4. Próximos passos</h2>";
echo "<ol>";
echo "<li>Certifique-se de que todas as verificações acima estão passando</li>";
echo "<li>Se o banco de dados ou tabelas não existirem, você pode executar o script <code>todos.php</code> para criá-los automaticamente</li>";
echo "<li>Se os erros persistirem, verifique os logs de erro do MySQL (geralmente em /var/log/mysql/error.log no Linux)</li>";
echo "<li>Confirme se o usuário MySQL tem permissões suficientes para criar e modificar tabelas</li>";
echo "</ol>";

echo "</body></html>";
?>