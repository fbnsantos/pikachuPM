<?php
// teste_redmine.php - Script simples para testar conexão com Redmine

// Desativar relatório de erros na saída (manterá apenas no log)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Incluir arquivo de configuração
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    include_once $configPath;
    echo "<p>✅ Arquivo de configuração carregado.</p>";
} else {
    echo "<p>❌ Arquivo de configuração não encontrado em: " . $configPath . "</p>";
    exit;
}

// Obter variáveis da configuração
global $BASE_URL, $API_KEY, $user, $pass;
$apiKey = $API_KEY;
$baseUrl = $BASE_URL;

// Verificar se as variáveis estão definidas
echo "<h2>Verificação de Configuração</h2>";
echo "<ul>";
echo "<li>URL Base: " . htmlspecialchars($baseUrl) . (empty($baseUrl) ? " <strong style='color:red'>(VAZIO)</strong>" : "") . "</li>";
echo "<li>API Key: " . (empty($apiKey) ? "<strong style='color:red'>NÃO DEFINIDA</strong>" : "****" . substr($apiKey, -4) . " (" . strlen($apiKey) . " caracteres)") . "</li>";
echo "</ul>";

// Garantir que a URL base termine com barra
if (substr($baseUrl, -1) !== '/') {
    $baseUrl .= '/';
    echo "<p>URL ajustada para terminar com barra: " . htmlspecialchars($baseUrl) . "</p>";
}

// Opções de teste
$endpoints = [
    "projects.json" => "Lista de Projetos",
    "trackers.json" => "Lista de Trackers",
    "issues.json?limit=5" => "5 Issues Recentes",
    "issues.json?project_id=prototypes&tracker_id=prototype&limit=100&status_id=*" => "Protótipos (url original)",
];

$endpointSelecionado = isset($_GET['endpoint']) ? $_GET['endpoint'] : 'projects.json';
if (!array_key_exists($endpointSelecionado, $endpoints)) {
    $endpointSelecionado = 'projects.json';
}

// Interface para seleção do endpoint
echo "<h2>Selecione o Endpoint para Testar</h2>";
echo "<form method='get' action=''>";
echo "<select name='endpoint' onchange='this.form.submit()'>";
foreach ($endpoints as $ep => $desc) {
    $selected = ($ep === $endpointSelecionado) ? 'selected' : '';
    echo "<option value='" . htmlspecialchars($ep) . "' $selected>" . htmlspecialchars($desc) . "</option>";
}
echo "</select>";
echo "</form>";

// Teste da API do Redmine
echo "<h2>Teste da API: " . htmlspecialchars($endpoints[$endpointSelecionado]) . "</h2>";
echo "<p>URL: <code>" . htmlspecialchars($baseUrl . $endpointSelecionado) . "</code></p>";

$api_url = $baseUrl . $endpointSelecionado;
$options = [
    'http' => [
        'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n" .
                    "Content-Type: application/json\r\n",
        'method' => 'GET',
        'ignore_errors' => true  // Isso permite obter a resposta mesmo em caso de erro
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($api_url, false, $context);

// Verificar status HTTP
$status = $http_response_header[0] ?? 'Status desconhecido';
echo "<p><strong>Status da resposta:</strong> " . htmlspecialchars($status) . "</p>";

// Exibir cabeçalhos da resposta
echo "<h3>Cabeçalhos da Resposta:</h3>";
echo "<pre style='background-color: #f5f5f5; padding: 10px; border-radius: 5px; max-height: 200px; overflow: auto;'>";
foreach ($http_response_header as $header) {
    echo htmlspecialchars($header) . "\n";
}
echo "</pre>";

// Verificar se temos uma resposta
if ($response === FALSE) {
    $error = error_get_last();
    echo "<h3 style='color: red;'>Erro ao acessar a API</h3>";
    echo "<p>" . htmlspecialchars($error['message'] ?? 'Erro desconhecido') . "</p>";
} else {
    // Exibir resposta bruta
    echo "<h3>Resposta Bruta:</h3>";
    echo "<pre style='background-color: #f5f5f5; padding: 10px; border-radius: 5px; max-height: 200px; overflow: auto;'>";
    echo htmlspecialchars(substr($response, 0, 2000)); // Limitar a 2000 caracteres
    if (strlen($response) > 2000) {
        echo "\n...(truncado)...";
    }
    echo "</pre>";
    
    // Tentar analisar como JSON
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<h3 style='color: red;'>Erro ao analisar JSON</h3>";
        echo "<p>" . htmlspecialchars(json_last_error_msg()) . "</p>";
    } else {
        echo "<h3>Resposta JSON Formatada:</h3>";
        echo "<pre style='background-color: #f5f5f5; padding: 10px; border-radius: 5px; max-height: 400px; overflow: auto;'>";
        echo htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "</pre>";
        
        // Análise específica baseada no endpoint
        echo "<h3>Análise da Resposta:</h3>";
        
        if ($endpointSelecionado === 'projects.json' && isset($data['projects'])) {
            echo "<p>Total de projetos: " . count($data['projects']) . "</p>";
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
            echo "<tr><th>ID</th><th>Nome</th><th>Identificador</th></tr>";
            
            $temProtoypes = false;
            foreach ($data['projects'] as $project) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($project['id']) . "</td>";
                echo "<td>" . htmlspecialchars($project['name']) . "</td>";
                echo "<td>" . htmlspecialchars($project['identifier']) . "</td>";
                echo "</tr>";
                
                if ($project['identifier'] === 'prototypes') {
                    $temProtoypes = true;
                }
            }
            echo "</table>";
            
            if ($temProtoypes) {
                echo "<p style='color: green;'>✅ Projeto 'prototypes' encontrado!</p>";
            } else {
                echo "<p style='color: red;'>❌ Projeto 'prototypes' NÃO encontrado! É necessário criar este projeto no Redmine.</p>";
            }
        }
        
        if ($endpointSelecionado === 'trackers.json' && isset($data['trackers'])) {
            echo "<p>Total de trackers: " . count($data['trackers']) . "</p>";
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
            echo "<tr><th>ID</th><th>Nome</th></tr>";
            
            $temPrototype = false;
            foreach ($data['trackers'] as $tracker) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($tracker['id']) . "</td>";
                echo "<td>" . htmlspecialchars($tracker['name']) . "</td>";
                echo "</tr>";
                
                if (strtolower($tracker['name']) === 'prototype') {
                    $temPrototype = true;
                }
            }
            echo "</table>";
            
            if ($temPrototype) {
                echo "<p style='color: green;'>✅ Tracker 'prototype' encontrado!</p>";
            } else {
                echo "<p style='color: red;'>❌ Tracker 'prototype' NÃO encontrado! É necessário criar este tracker no Redmine.</p>";
            }
        }
        
        if (strpos($endpointSelecionado, 'issues.json') === 0) {
            if (isset($data['issues'])) {
                echo "<p>Total de issues: " . count($data['issues']) . "</p>";
                
                if (count($data['issues']) === 0) {
                    echo "<p>Nenhuma issue encontrada para os critérios especificados.</p>";
                    
                    if (strpos($endpointSelecionado, 'project_id=prototypes') !== false) {
                        echo "<p>Isso pode significar que:</p>";
                        echo "<ol>";
                        echo "<li>O projeto 'prototypes' existe mas não tem issues, ou</li>";
                        echo "<li>O projeto 'prototypes' não existe, ou</li>";
                        echo "<li>O tracker 'prototype' não existe ou não está associado ao projeto.</li>";
                        echo "</ol>";
                    }
                } else {
                    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
                    echo "<tr><th>ID</th><th>Assunto</th><th>Status</th><th>Projeto</th><th>Tracker</th></tr>";
                    
                    foreach ($data['issues'] as $issue) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($issue['id']) . "</td>";
                        echo "<td>" . htmlspecialchars($issue['subject']) . "</td>";
                        echo "<td>" . htmlspecialchars($issue['status']['name']) . "</td>";
                        echo "<td>" . htmlspecialchars($issue['project']['name']) . "</td>";
                        echo "<td>" . htmlspecialchars($issue['tracker']['name']) . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                }
            } else {
                echo "<p style='color: red;'>A resposta não contém o campo 'issues'.</p>";
                echo "<p>Estrutura da resposta:</p>";
                echo "<ul>";
                foreach (array_keys($data) as $key) {
                    echo "<li>" . htmlspecialchars($key) . "</li>";
                }
                echo "</ul>";
            }
        }
    }
}

echo "<hr>";
echo "<h3>Próximos Passos:</h3>";
echo "<ul>";

if (strpos($status, '200') !== false) {
    echo "<li>A API está respondendo com sucesso (200 OK).</li>";
    
    if (isset($data['issues']) && empty($data['issues']) && strpos($endpointSelecionado, 'project_id=prototypes') !== false) {
        echo "<li style='color: blue;'>Verifique se o projeto 'prototypes' existe (teste o endpoint 'projects.json').</li>";
        echo "<li style='color: blue;'>Verifique se o tracker 'prototype' existe (teste o endpoint 'trackers.json').</li>";
        echo "<li style='color: blue;'>Você pode precisar criar o projeto 'prototypes' e/ou o tracker 'prototype' no Redmine.</li>";
        echo "<li style='color: blue;'>Outra opção é modificar o código para usar um projeto e tracker existentes.</li>";
    }
} else {
    echo "<li style='color: red;'>A API retornou um erro: " . htmlspecialchars($status) . "</li>";
    
    if (strpos($status, '401') !== false) {
        echo "<li style='color: red;'>Erro de autenticação. Verifique se a chave API está correta e não expirou.</li>";
    } elseif (strpos($status, '403') !== false) {
        echo "<li style='color: red;'>Acesso proibido. Verifique se a chave API tem permissões suficientes.</li>";
    } elseif (strpos($status, '404') !== false) {
        echo "<li style='color: red;'>Recurso não encontrado. Verifique se a URL está correta.</li>";
    }
}

echo "</ul>";
?>

<style>
body {
    font-family: Arial, sans-serif;
    line-height: 1.6;
    margin: 20px;
    padding: 0;
    color: #333;
}
h2 {
    color: #2c5aa0;
    margin-top: 30px;
    border-bottom: 1px solid #ccc;
    padding-bottom: 5px;
}
h3 {
    color: #2c5aa0;
    margin-top: 20px;
}
pre {
    white-space: pre-wrap;
    word-wrap: break-word;
}
code {
    background-color: #f5f5f5;
    padding: 2px 4px;
    border-radius: 3px;
}
select {
    padding: 8px;
    border-radius: 4px;
    border: 1px solid #ccc;
    font-size: 14px;
    width: 300px;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    margin-bottom: 20px;
}
th {
    background-color: #f2f2f2;
    text-align: left;
}
tr:nth-child(even) {
    background-color: #f9f9f9;
}
</style>