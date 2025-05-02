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
    "projects.json?limit=100" => "Lista de Projetos (100 max)",
    "trackers.json" => "Lista de Trackers",
    "issues.json?limit=5" => "5 Issues Recentes",
    "issues.json?project_id=tribeprototypes&limit=100&status_id=*" => "Protótipos (sem tracker)",
    "create_project" => "Criar Subprojeto de Protótipo",
];

$endpointSelecionado = isset($_GET['endpoint']) ? $_GET['endpoint'] : 'projects.json?limit=100';
if (!array_key_exists($endpointSelecionado, $endpoints)) {
    $endpointSelecionado = 'projects.json?limit=100';
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

// Adição de paginação para projetos
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// Formulário para criar um novo projeto
$projeto_pai_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : '';
$criacao_projeto = ($endpointSelecionado === 'create_project');

if ($criacao_projeto) {
    echo "<h2>Criar Novo Subprojeto de Protótipo</h2>";
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_project'])) {
        // Processar criação de projeto
        $novo_projeto = [
            'project' => [
                'name' => $_POST['project_name'],
                'identifier' => $_POST['project_identifier'],
                'description' => $_POST['project_description'],
                'parent_id' => $_POST['parent_id'] ?: null,
                'is_public' => isset($_POST['is_public']) ? true : false
            ]
        ];
        
        $api_url = $baseUrl . "projects.json";
        $options = [
            'http' => [
                'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n" .
                            "Content-Type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($novo_projeto),
                'ignore_errors' => true
            ]
        ];
        
        $context = stream_context_create($options);
        $response = @file_get_contents($api_url, false, $context);
        $status = $http_response_header[0] ?? 'Status desconhecido';
        
        echo "<div style='margin-bottom: 20px;'>";
        echo "<h3>Resultado da Criação:</h3>";
        echo "<p><strong>Status:</strong> " . htmlspecialchars($status) . "</p>";
        
        if ($response === FALSE) {
            echo "<p style='color: red;'>Erro ao criar projeto!</p>";
            echo "<p>" . htmlspecialchars(error_get_last()['message'] ?? 'Erro desconhecido') . "</p>";
        } else {
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "<p style='color: red;'>Erro ao analisar resposta!</p>";
                echo "<pre>" . htmlspecialchars($response) . "</pre>";
            } elseif (isset($data['project'])) {
                echo "<p style='color: green;'>Projeto criado com sucesso!</p>";
                echo "<p><strong>ID:</strong> " . htmlspecialchars($data['project']['id']) . "</p>";
                echo "<p><strong>Nome:</strong> " . htmlspecialchars($data['project']['name']) . "</p>";
                echo "<p><a href='" . htmlspecialchars($baseUrl) . "projects/" . 
                     htmlspecialchars($data['project']['identifier']) . 
                     "' target='_blank' class='btn'>Ver Projeto no Redmine</a></p>";
            } elseif (isset($data['errors'])) {
                echo "<p style='color: red;'>Erros ao criar projeto:</p>";
                echo "<ul>";
                foreach ($data['errors'] as $error) {
                    echo "<li>" . htmlspecialchars($error) . "</li>";
                }
                echo "</ul>";
            } else {
                echo "<p style='color: orange;'>Resposta inesperada:</p>";
                echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>";
            }
        }
        echo "</div>";
    }
    
    // Formulário para criar novo projeto
    echo "<form method='post' action='?endpoint=create_project'>";
    echo "<div style='margin-bottom: 15px;'>";
    echo "<label for='project_name'><strong>Nome do Projeto:</strong></label><br>";
    echo "<input type='text' id='project_name' name='project_name' required ";
    echo "style='width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc;' ";
    echo "placeholder='Ex: Protótipo XYZ'>";
    echo "</div>";
    
    echo "<div style='margin-bottom: 15px;'>";
    echo "<label for='project_identifier'><strong>Identificador:</strong></label><br>";
    echo "<input type='text' id='project_identifier' name='project_identifier' required ";
    echo "style='width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc;' ";
    echo "placeholder='Ex: prototipo-xyz (apenas letras, números e hífens)'>";
    echo "</div>";
    
    echo "<div style='margin-bottom: 15px;'>";
    echo "<label for='project_description'><strong>Descrição:</strong></label><br>";
    echo "<textarea id='project_description' name='project_description' ";
    echo "style='width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc; height: 100px;' ";
    echo "placeholder='Descrição do protótipo...'></textarea>";
    echo "</div>";
    
    echo "<div style='margin-bottom: 15px;'>";
    echo "<label for='parent_id'><strong>Projeto Pai (ID):</strong></label><br>";
    echo "<input type='number' id='parent_id' name='parent_id' ";
    echo "value='" . htmlspecialchars($projeto_pai_id) . "' ";
    echo "style='width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc;' ";
    echo "placeholder='Deixe em branco para criar um projeto de nível superior'>";
    echo "</div>";
    
    echo "<div style='margin-bottom: 15px;'>";
    echo "<label>";
    echo "<input type='checkbox' name='is_public' checked> Projeto público";
    echo "</label>";
    echo "</div>";
    
    echo "<div style='margin-bottom: 15px;'>";
    echo "<input type='submit' name='create_project' value='Criar Projeto' class='btn'>";
    echo " <a href='?endpoint=projects.json%3Flimit%3D100' class='btn' style='background-color: #888;'>Cancelar</a>";
    echo "</div>";
    echo "</form>";
    
    // Exibir lista de projetos para selecionar projeto pai
    echo "<hr>";
    echo "<h3>Selecionar Projeto Pai da Lista</h3>";
    echo "<p>Clique em um projeto abaixo para usá-lo como pai para o novo protótipo:</p>";
    
    // Buscar lista de projetos
    $api_url = $baseUrl . "projects.json?limit=100";
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n",
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($api_url, false, $context);
    
    if ($response !== FALSE) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['projects'])) {
            // Campo de busca para filtrar projetos
            echo "<div style='margin-bottom: 15px;'>";
            echo "<input type='text' id='projetoFiltro' onkeyup='filtrarProjetos()' ";
            echo "placeholder='Filtrar projetos...' style='padding: 8px; width: 300px; border-radius: 4px; border: 1px solid #ccc;'>";
            echo "</div>";
            
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse;' id='tabelaProjetos'>";
            echo "<tr><th>ID</th><th>Nome</th><th>Identificador</th><th>Ações</th></tr>";
            
            foreach ($data['projects'] as $project) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($project['id']) . "</td>";
                echo "<td>" . htmlspecialchars($project['name']) . "</td>";
                echo "<td>" . htmlspecialchars($project['identifier']) . "</td>";
                echo "<td><a href='?endpoint=create_project&parent_id=" . $project['id'] . "' class='btn-small'>Selecionar como Pai</a></td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Script de filtro para projetos
            echo "<script>
            function filtrarProjetos() {
                var input, filter, table, tr, td, i, txtValue;
                input = document.getElementById('projetoFiltro');
                filter = input.value.toUpperCase();
                table = document.getElementById('tabelaProjetos');
                tr = table.getElementsByTagName('tr');
                
                for (i = 1; i < tr.length; i++) { // Começar do 1 para pular o cabeçalho
                    td = tr[i].getElementsByTagName('td');
                    var encontrado = false;
                    
                    // Verificar colunas 1 (Nome) e 2 (Identificador)
                    for (var j = 1; j <= 2; j++) {
                        txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            encontrado = true;
                            break;
                        }
                    }
                    
                    if (encontrado) {
                        tr[i].style.display = '';
                    } else {
                        tr[i].style.display = 'none';
                    }
                }
            }
            </script>";
        }
    }
    
    // Não executar o restante do código para API
    exit;
}

// Adicionar paginação ao endpoint se for projects.json
if (strpos($endpointSelecionado, 'projects.json') === 0) {
    // Se já tiver parâmetros, adicione &page=X, caso contrário ?page=X
    if (strpos($endpointSelecionado, '?') !== false) {
        $api_endpoint = $endpointSelecionado . "&page=" . $page;
    } else {
        $api_endpoint = $endpointSelecionado . "?page=" . $page;
    }
} else {
    $api_endpoint = $endpointSelecionado;
}

// Teste da API do Redmine
echo "<h2>Teste da API: " . htmlspecialchars($endpoints[$endpointSelecionado]) . "</h2>";
echo "<p>URL: <code>" . htmlspecialchars($baseUrl . $api_endpoint) . "</code></p>";

$api_url = $baseUrl . $api_endpoint;
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
        
        if ($endpointSelecionado === 'projects.json?limit=100' && isset($data['projects'])) {
            // Informações de paginação, se disponíveis
            $total_count = $data['total_count'] ?? count($data['projects']);
            $offset = $data['offset'] ?? 0;
            $limit = $data['limit'] ?? 25;
            
            echo "<p>Total de projetos: " . htmlspecialchars($total_count) . 
                 " (Exibindo " . count($data['projects']) . " projetos, página $page)</p>";
            
            // Controles de paginação
            echo "<div style='margin-bottom: 15px;'>";
            if ($page > 1) {
                echo "<a href='?endpoint=" . urlencode($endpointSelecionado) . "&page=" . ($page-1) . "' class='btn'>&laquo; Página Anterior</a> ";
            }
            if (count($data['projects']) >= $limit) {
                echo "<a href='?endpoint=" . urlencode($endpointSelecionado) . "&page=" . ($page+1) . "' class='btn'>Próxima Página &raquo;</a>";
            }
            echo "</div>";
            
            // Campo de busca para filtrar projetos na página
            echo "<div style='margin-bottom: 15px;'>";
            echo "<input type='text' id='projetoFiltro' onkeyup='filtrarProjetos()' ";
            echo "placeholder='Filtrar projetos...' style='padding: 8px; width: 300px; border-radius: 4px; border: 1px solid #ccc;'>";
            echo "</div>";
            
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse;' id='tabelaProjetos'>";
            echo "<tr><th>ID</th><th>Nome</th><th>Identificador</th><th>Ações</th></tr>";
            
            $temProtoypes = false;
            foreach ($data['projects'] as $project) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($project['id']) . "</td>";
                echo "<td>" . htmlspecialchars($project['name']) . "</td>";
                echo "<td>" . htmlspecialchars($project['identifier']) . "</td>";
                echo "<td><a href='" . htmlspecialchars($baseUrl) . "projects/" . htmlspecialchars($project['identifier']) . 
                     "' target='_blank' class='btn-small'>Ver no Redmine</a></td>";
                echo "</tr>";
                
                if ($project['identifier'] === 'prototypes') {
                    $temProtoypes = true;
                }
            }
            echo "</table>";
            
            // Script de filtro para projetos
            echo "<script>
            function filtrarProjetos() {
                var input, filter, table, tr, td, i, txtValue;
                input = document.getElementById('projetoFiltro');
                filter = input.value.toUpperCase();
                table = document.getElementById('tabelaProjetos');
                tr = table.getElementsByTagName('tr');
                
                for (i = 1; i < tr.length; i++) { // Começar do 1 para pular o cabeçalho
                    td = tr[i].getElementsByTagName('td');
                    var encontrado = false;
                    
                    // Verificar colunas 1 (Nome) e 2 (Identificador)
                    for (var j = 1; j <= 2; j++) {
                        txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            encontrado = true;
                            break;
                        }
                    }
                    
                    if (encontrado) {
                        tr[i].style.display = '';
                    } else {
                        tr[i].style.display = 'none';
                    }
                }
            }
            </script>";
            
            if ($temProtoypes) {
                echo "<p style='color: green;'>✅ Projeto 'prototypes' encontrado!</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Projeto 'prototypes' NÃO encontrado nesta página!</p>";
                echo "<p>Tente buscar o projeto no campo de filtro acima ou verifique outras páginas.</p>";
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
echo "<h3>Recomendações para o Código do Prototypes:</h3>";
echo "<p>Baseado nos resultados da consulta, aqui estão sugestões para modificar o código de prototypes.php:</p>";

// Verificar se está na endpoint de projetos e se tribeprototypes foi encontrado
if (strpos($endpointSelecionado, 'projects.json') === 0) {
    echo "<div style='background-color: #f9f9f9; padding: 15px; border-radius: 5px; border-left: 4px solid #2c5aa0;'>";
    
    $temTribePrototypes = false;
    if (isset($data['projects'])) {
        foreach ($data['projects'] as $project) {
            if ($project['identifier'] === 'tribeprototypes') {
                $temTribePrototypes = true;
                break;
            }
        }
    }
    
    if (!$temTribePrototypes) {
        echo "<p><strong>Opção 1:</strong> Criar um projeto 'tribeprototypes' no Redmine usando a opção 'Criar Subprojeto de Protótipo'.</p>";
        echo "<p><strong>Opção 2:</strong> Modificar o código para usar um projeto existente:</p>";
        echo "<pre style='background-color: #f0f0f0; padding: 10px;'>";
        echo "// Em prototypes.php, procure por todas as ocorrências de 'tribeprototypes' e substitua pelo ID do projeto\n";
        echo "// Exemplo (substitua 'projeto_existente' pelo identificador do projeto que deseja usar):\n\n";
        echo '$url = $baseUrl . "issues.json?<mark>project_id=projeto_existente</mark>&limit=100&status_id=*";';
        echo "</pre>";
    } else {
        echo "<p>✅ O projeto 'tribeprototypes' foi encontrado! Não é necessário modificar o identificador do projeto no código.</p>";
    }
    
    echo "</div>";
}

// Verificar se está na endpoint de trackers e remover essa análise
if ($endpointSelecionado === 'trackers.json') {
    echo "<div style='background-color: #f9f9f9; padding: 15px; border-radius: 5px; border-left: 4px solid #2c5aa0; margin-top: 15px;'>";
    echo "<p><strong>Modificação do Código:</strong> Remover a dependência do tracker_id</p>";
    
    echo "<pre style='background-color: #f0f0f0; padding: 10px;'>";
    echo "// Em prototypes.php, remova as referências a 'tracker_id=prototype' em todas as URLs\n";
    echo "// De:\n";
    echo '$url = $baseUrl . "issues.json?project_id=tribeprototypes&<mark>tracker_id=prototype&</mark>limit=100&status_id=*";';
    echo "\n// Para:\n";
    echo '$url = $baseUrl . "issues.json?project_id=tribeprototypes&limit=100&status_id=*";';
    echo "</pre>";
    
    echo "</div>";
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
.btn {
    display: inline-block;
    padding: 8px 12px;
    background-color: #2c5aa0;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    margin-right: 10px;
}
.btn:hover {
    background-color: #1d4580;
}
.btn-small {
    display: inline-block;
    padding: 4px 8px;
    background-color: #f0f0f0;
    color: #333;
    text-decoration: none;
    border-radius: 4px;
    font-size: 12px;
}
.btn-small:hover {
    background-color: #e0e0e0;
}
</style>