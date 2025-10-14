<?php
/**
 * FICHEIRO DE DEBUG - Verificar qual versão do todos.php está a carregar
 * Coloque este ficheiro na raiz e aceda: http://seu-dominio.com/debug_todos.php
 */

session_start();

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug - Versão do todos.php</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 30px; background: #f5f5f5; }
    .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .success { color: green; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
    .error { color: red; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
    .info { color: blue; background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }
    pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
    h2 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
</style>";
echo "</head><body><div class='container'>";

echo "<h1>🔍 Debug - Verificação do todos.php</h1>";

// 1. Verificar se o ficheiro existe
$todos_path = __DIR__ . '/tabs/todos.php';
echo "<h2>1. Verificação de Ficheiro</h2>";

if (file_exists($todos_path)) {
    echo "<div class='success'>✅ Ficheiro existe em: <code>$todos_path</code></div>";
    
    // Ver tamanho do ficheiro
    $filesize = filesize($todos_path);
    echo "<div class='info'>📦 Tamanho: " . number_format($filesize) . " bytes (" . round($filesize/1024, 2) . " KB)</div>";
    
    // Ver data de modificação
    $modified = date('Y-m-d H:i:s', filemtime($todos_path));
    echo "<div class='info'>📅 Última modificação: $modified</div>";
    
} else {
    echo "<div class='error'>❌ Ficheiro NÃO existe em: <code>$todos_path</code></div>";
    echo "<div class='info'>Caminhos alternativos a verificar:</div>";
    echo "<pre>";
    echo "- " . __DIR__ . "/todos.php\n";
    echo "- " . __DIR__ . "/../todos.php\n";
    echo "- " . $_SERVER['DOCUMENT_ROOT'] . "/tabs/todos.php\n";
    echo "</pre>";
}

// 2. Verificar conteúdo do ficheiro
echo "<h2>2. Análise do Conteúdo</h2>";

if (file_exists($todos_path)) {
    $content = file_get_contents($todos_path);
    $lines = substr_count($content, "\n");
    
    echo "<div class='info'>📝 Número de linhas: $lines</div>";
    
    // Verificar assinaturas da versão nova
    $checks = [
        'Editor Universal' => strpos($content, 'Editor Universal') !== false || strpos($content, 'edit-task-btn') !== false,
        'openTaskEditor' => strpos($content, 'openTaskEditor') !== false,
        'include edit_task.php' => strpos($content, 'edit_task.php') !== false,
        'data-task-id' => strpos($content, 'data-task-id') !== false,
        'Estatísticas (stats-card)' => strpos($content, 'stats-card') !== false,
        'Modal addTaskModal' => strpos($content, 'addTaskModal') !== false,
    ];
    
    echo "<h3>Características da Versão Nova:</h3>";
    echo "<table border='1' cellpadding='10' style='width:100%; border-collapse: collapse;'>";
    echo "<tr><th>Característica</th><th>Status</th></tr>";
    
    foreach ($checks as $feature => $found) {
        $status = $found ? "<span style='color:green'>✅ ENCONTRADO</span>" : "<span style='color:red'>❌ NÃO ENCONTRADO</span>";
        echo "<tr><td>$feature</td><td>$status</td></tr>";
    }
    echo "</table>";
    
    // Contar ocorrências importantes
    echo "<h3>Contagem de Elementos:</h3>";
    echo "<pre>";
    echo "- Botões 'edit-task-btn': " . substr_count($content, 'edit-task-btn') . "\n";
    echo "- Chamadas 'openTaskEditor': " . substr_count($content, 'openTaskEditor') . "\n";
    echo "- Includes 'edit_task.php': " . substr_count($content, 'edit_task.php') . "\n";
    echo "</pre>";
    
    // Mostrar primeiras e últimas linhas
    echo "<h3>Primeiras 20 linhas do ficheiro:</h3>";
    $lines_array = explode("\n", $content);
    echo "<pre>";
    for ($i = 0; $i < min(20, count($lines_array)); $i++) {
        echo htmlspecialchars($lines_array[$i]) . "\n";
    }
    echo "</pre>";
    
    echo "<h3>Últimas 10 linhas do ficheiro:</h3>";
    echo "<pre>";
    $start = max(0, count($lines_array) - 10);
    for ($i = $start; $i < count($lines_array); $i++) {
        echo htmlspecialchars($lines_array[$i]) . "\n";
    }
    echo "</pre>";
}

// 3. Verificar se edit_task.php existe
echo "<h2>3. Verificação do Editor Universal</h2>";

$editor_path = __DIR__ . '/edit_task.php';
if (file_exists($editor_path)) {
    echo "<div class='success'>✅ edit_task.php existe em: <code>$editor_path</code></div>";
    $editor_size = filesize($editor_path);
    echo "<div class='info'>📦 Tamanho: " . number_format($editor_size) . " bytes</div>";
} else {
    echo "<div class='error'>❌ edit_task.php NÃO encontrado em: <code>$editor_path</code></div>";
}

// 4. Verificar API
echo "<h2>4. Verificação da API</h2>";

$api_path = __DIR__ . '/api/get_task_full.php';
if (file_exists($api_path)) {
    echo "<div class='success'>✅ api/get_task_full.php existe</div>";
} else {
    echo "<div class='error'>❌ api/get_task_full.php NÃO encontrado</div>";
}

// 5. Verificar tabelas
echo "<h2>5. Verificação das Tabelas</h2>";

include_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $tables = ['todos', 'task_checklist', 'task_files'];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "<div class='success'>✅ Tabela '$table' existe</div>";
            
            // Contar registos
            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            echo "<div class='info'>📊 $count registos na tabela '$table'</div>";
        } else {
            echo "<div class='error'>❌ Tabela '$table' NÃO existe</div>";
            if ($table !== 'todos') {
                echo "<div class='info'>⚠️ Execute install_task_editor.php para criar esta tabela</div>";
            }
        }
    }
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ Erro ao conectar à BD: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// 6. Verificar permissões
echo "<h2>6. Verificação de Permissões</h2>";

if (file_exists($todos_path)) {
    $perms = fileperms($todos_path);
    $perms_string = substr(sprintf('%o', $perms), -4);
    echo "<div class='info'>🔒 Permissões do ficheiro: $perms_string</div>";
    
    if (is_readable($todos_path)) {
        echo "<div class='success'>✅ Ficheiro é legível</div>";
    } else {
        echo "<div class='error'>❌ Ficheiro NÃO é legível</div>";
    }
    
    if (is_writable($todos_path)) {
        echo "<div class='success'>✅ Ficheiro tem permissões de escrita</div>";
    } else {
        echo "<div class='error'>❌ Ficheiro NÃO tem permissões de escrita</div>";
    }
}

// 7. Verificar cache
echo "<h2>7. Cache e Browser</h2>";
echo "<div class='info'>🔄 Para garantir que vê a versão mais recente:</div>";
echo "<pre>";
echo "1. Limpe o cache do browser (Ctrl+Shift+Delete)\n";
echo "2. Faça um hard refresh (Ctrl+F5 ou Ctrl+Shift+R)\n";
echo "3. Tente em modo anónimo/privado\n";
echo "4. Verifique se há cache no servidor (OPcache, etc)\n";
echo "</pre>";

// Verificar OPcache
if (function_exists('opcache_get_status')) {
    $opcache = opcache_get_status();
    if ($opcache && $opcache['opcache_enabled']) {
        echo "<div class='error'>⚠️ OPcache está ATIVO - isto pode causar cache de ficheiros PHP</div>";
        echo "<div class='info'>💡 Solução: Execute <code>opcache_reset()</code> ou reinicie o servidor web</div>";
        
        // Tentar limpar cache
        if (opcache_reset()) {
            echo "<div class='success'>✅ Cache OPcache limpo com sucesso!</div>";
        }
    } else {
        echo "<div class='success'>✅ OPcache está desativado ou não configurado</div>";
    }
}

// 8. Ações recomendadas
echo "<h2>8. 🛠️ Ações Recomendadas</h2>";

$all_ok = true;
foreach ($checks as $feature => $found) {
    if (!$found) $all_ok = false;
}

if ($all_ok) {
    echo "<div class='success'>";
    echo "<h3>✅ TUDO PARECE CORRETO!</h3>";
    echo "<p>O ficheiro todos.php parece estar com a versão nova.</p>";
    echo "<p><strong>Se ainda vê a versão antiga no browser:</strong></p>";
    echo "<ol>";
    echo "<li>Limpe o cache do browser completamente</li>";
    echo "<li>Feche e reabra o browser</li>";
    echo "<li>Tente em modo anónimo</li>";
    echo "</ol>";
    echo "</div>";
} else {
    echo "<div class='error'>";
    echo "<h3>⚠️ VERSÃO ANTIGA DETECTADA</h3>";
    echo "<p><strong>Passos para corrigir:</strong></p>";
    echo "<ol>";
    echo "<li>Faça backup do todos.php atual</li>";
    echo "<li>Copie novamente o código da versão nova</li>";
    echo "<li>Guarde o ficheiro</li>";
    echo "<li>Verifique as permissões (chmod 644)</li>";
    echo "<li>Limpe o cache do servidor se necessário</li>";
    echo "<li>Execute este script novamente</li>";
    echo "</ol>";
    echo "</div>";
}

// Botão para limpar cache
echo "<h2>9. Limpar Cache do Servidor</h2>";
echo "<form method='POST'>";
echo "<button type='submit' name='clear_cache' style='padding:10px 20px; background:#667eea; color:white; border:none; border-radius:5px; cursor:pointer;'>";
echo "🔄 Limpar Cache do Servidor";
echo "</button>";
echo "</form>";

if (isset($_POST['clear_cache'])) {
    // Limpar OPcache
    if (function_exists('opcache_reset')) {
        opcache_reset();
        echo "<div class='success'>✅ OPcache limpo!</div>";
    }
    
    // Limpar possíveis caches de sessão
    if (function_exists('session_start')) {
        session_start();
        session_destroy();
        echo "<div class='success'>✅ Sessões limpas!</div>";
    }
    
    echo "<div class='info'>🔄 Recarregue a página do todos.php agora!</div>";
}

echo "</div></body></html>";
?>