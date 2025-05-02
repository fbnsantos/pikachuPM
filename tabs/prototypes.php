<?php
// tabs/prototypes.php - Tab for managing prototypes (using subprojects approach)

// Incluir arquivo de configuração
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    include_once $configPath;
} else {
    die('Erro: Arquivo de configuração não encontrado em: ' . $configPath);
}

// Usar diretamente as variáveis globais que existem no config.php
global $BASE_URL, $API_KEY, $user, $pass;
$apiKey = $API_KEY;
$baseUrl = $BASE_URL;

// Verificar se temos os valores necessários
if (empty($apiKey) || empty($baseUrl)) {
    die('Erro: Configurações de API do Redmine não encontradas. Verifique seu arquivo config.php.');
}

// Garantir que a URL base termine com barra
if (substr($baseUrl, -1) !== '/') {
    $baseUrl .= '/';
}

// Inicializar variáveis usadas em todo o código
$mostrar_diagnostico = isset($_GET['diagnostico']) && $_GET['diagnostico'] === '1';
$resultados_diagnostico = [];
$message = '';
$messageType = '';
$errorMessage = '';
$prototypes = [];
$selectedPrototype = null;
$prototypeBacklog = [];
$backlogByStatus = [
    'backlog' => [],
    'in_progress' => [],
    'suspended' => [],
    'completed' => []
];

// Definições de status para mapeamento
$statusMap = [
    'backlog' => [1], // IDs dos status que são considerados "backlog"
    'in_progress' => [2], // IDs dos status que são considerados "em progresso"
    'suspended' => [3, 6], // IDs dos status que são considerados "suspensos" (on hold, etc)
    'completed' => [5], // IDs dos status que são considerados "completos" (closed, etc)
];

// Function to get prototypes from Redmine (as subprojects)
function getPrototypes() {
    global $apiKey, $baseUrl;
    
    // Log values for debugging
    error_log("Tentando acessar Redmine com URL: " . $baseUrl);
    error_log("Comprimento da chave API: " . strlen($apiKey) . " caracteres");
    
    // Get all subprojects of "tribeprototypes"
    $url = $baseUrl . 'projects.json?limit=100';
    
    // Log the full URL being accessed
    error_log("URL completa: " . $url);
    
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'GET',
            'ignore_errors' => true // This allows us to get the error response
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    // Get response status and full headers
    $status_line = $http_response_header[0] ?? 'Status desconhecido';
    error_log("Resposta da API Redmine: " . $status_line);
    
    if ($response === FALSE) {
        $error_msg = error_get_last()['message'] ?? 'Desconhecido';
        error_log("Falha ao acessar a API do Redmine. Último erro: " . $error_msg);
        return [
            'error' => 'Falha ao obter projetos do Redmine. Erro: ' . $error_msg,
            'raw_response' => null
        ];
    }
    
    // Save the raw response for debugging
    $raw_response = substr($response, 0, 1000); // Limit to first 1000 chars
    
    // Check if we have a valid JSON response
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error_msg = "Resposta não é um JSON válido: " . json_last_error_msg();
        error_log($error_msg);
        error_log("Resposta: " . substr($response, 0, 500));
        return [
            'error' => 'Resposta inválida da API do Redmine: ' . $error_msg,
            'raw_response' => $raw_response
        ];
    }
    
    // Check if we have projects in the response
    if (!isset($data['projects'])) {
        $keys = implode(', ', array_keys($data));
        error_log("A resposta não contém a chave 'projects'. Chaves disponíveis: " . $keys);
        return [
            'error' => "A resposta não contém informações de projetos.",
            'raw_response' => $raw_response
        ];
    }
    
    // Find tribeprototypes project and its subprojects
    $tribeprototypesId = null;
    foreach ($data['projects'] as $project) {
        if ($project['identifier'] === 'tribeprototypes') {
            $tribeprototypesId = $project['id'];
            break;
        }
    }
    
    if (!$tribeprototypesId) {
        return [
            'error' => "O projeto 'tribeprototypes' não foi encontrado no Redmine.",
            'raw_response' => $raw_response
        ];
    }
    
    // Get subprojects of tribeprototypes
    $url = $baseUrl . 'projects.json?parent_id=' . $tribeprototypesId . '&limit=100';
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        $error_msg = error_get_last()['message'] ?? 'Desconhecido';
        return [
            'error' => 'Falha ao obter subprojetos. Erro: ' . $error_msg,
            'raw_response' => null
        ];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'error' => 'Resposta inválida ao buscar subprojetos: ' . json_last_error_msg(),
            'raw_response' => substr($response, 0, 1000)
        ];
    }
    
    // Return the list of prototypes (subprojects)
    return $data['projects'] ?? [];
}

// Function to create a new prototype (as a subproject)
function createPrototype($data) {
    global $apiKey, $baseUrl;
    
    // First, get the ID of tribeprototypes project
    $url = $baseUrl . 'projects.json?limit=100';
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Falha ao acessar a API do Redmine.'];
    }
    
    $projectsData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Resposta inválida ao buscar projetos.'];
    }
    
    $tribeprototypesId = null;
    foreach ($projectsData['projects'] as $project) {
        if ($project['identifier'] === 'tribeprototypes') {
            $tribeprototypesId = $project['id'];
            break;
        }
    }
    
    if (!$tribeprototypesId) {
        return ['error' => "O projeto 'tribeprototypes' não foi encontrado."];
    }
    
    // Build the description from the template
    $description = <<<EOT
**1. Problem / Challenge**
{$data['problem']}

**2. Target Stakeholders**
{$data['stakeholders']}

**3. Research Goals / Objectives**
{$data['goals']}

**4. Solution / Approach**
{$data['solution']}

**5. Key Technologies**
{$data['technologies']}

**6. Validation Strategy**
{$data['validation']}

**7. Key Partners / Collaborators**
{$data['partners']}

**8. Potential Impact**
{$data['impact']}

**9. Risks / Uncertainties**
{$data['risks']}

**10. Next Steps / Roadmap**
{$data['roadmap']}

**Documentation Link**
{$data['documentation_link']}

**Git Repository**
{$data['git_repository']}
EOT;
    
    // Generate a unique identifier from the subject
    $identifier = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $data['subject'])));
    $identifier = substr($identifier, 0, 90); // Limit to 100 chars to be safe
    
    // Create a new subproject
    $projectData = [
        'project' => [
            'name' => $data['subject'],
            'identifier' => $identifier,
            'description' => $description,
            'parent_id' => $tribeprototypesId,
            'is_public' => true
        ]
    ];
    
    $url = $baseUrl . 'projects.json';
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($projectData),
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Falha ao criar protótipo no Redmine.'];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Resposta inválida ao criar protótipo.'];
    }
    
    if (isset($data['errors'])) {
        return ['error' => 'Erro ao criar protótipo: ' . implode(', ', $data['errors'])];
    }
    
    return $data;
}

// Function to update prototype information
function updatePrototype($projectId, $data) {
    global $apiKey, $baseUrl;
    
    // Get current project info first
    $url = $baseUrl . 'projects/' . $projectId . '.json';
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n",
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Falha ao obter informações do protótipo.'];
    }
    
    $projectData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($projectData['project'])) {
        return ['error' => 'Resposta inválida ao obter informações do protótipo.'];
    }
    
    $currentProject = $projectData['project'];
    
    // Build the updated description from the template
    $description = <<<EOT
**1. Problem / Challenge**
{$data['problem']}

**2. Target Stakeholders**
{$data['stakeholders']}

**3. Research Goals / Objectives**
{$data['goals']}

**4. Solution / Approach**
{$data['solution']}

**5. Key Technologies**
{$data['technologies']}

**6. Validation Strategy**
{$data['validation']}

**7. Key Partners / Collaborators**
{$data['partners']}

**8. Potential Impact**
{$data['impact']}

**9. Risks / Uncertainties**
{$data['risks']}

**10. Next Steps / Roadmap**
{$data['roadmap']}

**Documentation Link**
{$data['documentation_link']}

**Git Repository**
{$data['git_repository']}
EOT;
    
    // Update project data
    $updateData = [
        'project' => [
            'name' => $data['name'],
            'description' => $description
        ]
    ];
    
    $url = $baseUrl . 'projects/' . $projectId . '.json';
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'PUT',
            'content' => json_encode($updateData),
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    // For PUT requests, a 204 No Content is a success
    if ($response === FALSE) {
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';
        
        // Check if status is 204 No Content (successful update)
        if (strpos($statusLine, '204') !== false) {
            return ['success' => true];
        }
        
        return ['error' => 'Falha ao atualizar protótipo.'];
    }
    
    return ['success' => true];
}

// Function to get backlog items for a prototype (as issues in the subproject)
function getPrototypeBacklog($prototypeId) {
    global $apiKey, $baseUrl, $statusMap, $backlogByStatus;
    
    // Get issues for this project
    $url = $baseUrl . "issues.json?project_id=" . $prototypeId . "&limit=100&status_id=*";
    
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Falha ao obter backlog do protótipo.'];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Resposta inválida ao buscar backlog.'];
    }
    
    // Reset backlog by status
    $backlogByStatus = [
        'backlog' => [],
        'in_progress' => [],
        'suspended' => [],
        'completed' => []
    ];
    
    // Group issues by their status
    $issues = $data['issues'] ?? [];
    foreach ($issues as $issue) {
        $statusId = $issue['status']['id'];
        $bucket = 'backlog'; // Default bucket
        
        // Find which bucket this status belongs to
        foreach ($statusMap as $key => $ids) {
            if (in_array($statusId, $ids)) {
                $bucket = $key;
                break;
            }
        }
        
        // Add to the appropriate bucket
        $backlogByStatus[$bucket][] = $issue;
    }
    
    return $issues;
}

// Function to add a backlog item to a prototype (as an issue in the subproject)
function addBacklogItem($prototypeId, $data) {
    global $apiKey, $baseUrl;
    
    $url = $baseUrl . 'issues.json';
    
    // Map priority names to IDs (these IDs are standard in Redmine)
    $priorityIds = [
        'low' => 1,
        'normal' => 2,
        'high' => 3,
        'urgent' => 4
    ];
    
    // Get priority ID from the map or default to normal (2)
    $priorityId = $priorityIds[$data['priority']] ?? 2;
    
    // Prepare the issue data with numeric priority_id
    $issueData = [
        'issue' => [
            'project_id' => $prototypeId,
            'subject' => $data['subject'],
            'description' => $data['description'],
            'priority_id' => $priorityId
        ]
    ];
    
    // If status is provided, add it (optional)
    if (isset($data['status']) && !empty($data['status'])) {
        $issueData['issue']['status_id'] = $data['status'];
    }
    
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($issueData),
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Falha ao adicionar item ao backlog.'];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Resposta inválida ao adicionar item ao backlog.'];
    }
    
    if (isset($data['errors'])) {
        return ['error' => 'Erro ao adicionar item ao backlog: ' . implode(', ', $data['errors'])];
    }
    
    return $data;
}

// Function to update the status of a backlog item
function updateIssueStatus($issueId, $statusId) {
    global $apiKey, $baseUrl;
    
    $url = $baseUrl . 'issues/' . $issueId . '.json';
    
    // Prepare the update data
    $updateData = [
        'issue' => [
            'status_id' => $statusId
        ]
    ];
    
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'PUT',
            'content' => json_encode($updateData),
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    // For PUT requests, a 204 No Content is a success
    if ($response === FALSE) {
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';
        
        // Check if status is 204 No Content (successful update)
        if (strpos($statusLine, '204') !== false) {
            return ['success' => true];
        }
        
        return ['error' => 'Falha ao atualizar status da tarefa.'];
    }
    
    // If we get here with a response, check for errors
    if (!empty($response)) {
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Resposta inválida ao atualizar status.'];
        }
        
        if (isset($data['errors'])) {
            return ['error' => 'Erro ao atualizar status: ' . implode(', ', $data['errors'])];
        }
    }
    
    return ['success' => true];
}

// Function to extract information from prototype description
function extractSectionContent($description, $sectionHeader) {
    $pattern = '/\*\*' . preg_quote($sectionHeader, '/') . '\*\*(.*?)(?=\*\*\d+\.|$)/s';
    if (preg_match($pattern, $description, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

// Handle AJAX requests for status updates
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_status') {
    header('Content-Type: application/json');
    
    $issueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
    $newStatusId = isset($_POST['status_id']) ? (int)$_POST['status_id'] : 0;
    
    if ($issueId <= 0 || $newStatusId <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID de issue ou status inválido']);
        exit;
    }
    
    $result = updateIssueStatus($issueId, $newStatusId);
    echo json_encode($result);
    exit;
}

// Handle Ajax requests for prototype updates
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_prototype') {
    header('Content-Type: application/json');
    
    $projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    
    if ($projectId <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID de projeto inválido']);
        exit;
    }
    
    $result = updatePrototype($projectId, $_POST);
    echo json_encode($result);
    exit;
}

// Executar diagnóstico se solicitado
if ($mostrar_diagnostico) {
    $resultados_diagnostico = diagnosticarConexaoRedmine();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_prototype':
                $result = createPrototype($_POST);
                if (isset($result['error'])) {
                    $message = $result['error'];
                    $messageType = 'danger';
                } else {
                    $message = 'Protótipo criado com sucesso!';
                    $messageType = 'success';
                }
                break;
                
            case 'add_backlog':
                $result = addBacklogItem($_POST['prototype_id'], $_POST);
                if (isset($result['error'])) {
                    $message = $result['error'];
                    $messageType = 'danger';
                } else {
                    $message = 'Item adicionado ao backlog com sucesso!';
                    $messageType = 'success';
                }
                break;
        }
    }
}

// Get the prototypes to display if not in diagnostic mode
if (!$mostrar_diagnostico) {
    $prototypes = getPrototypes();
    if (isset($prototypes['error'])) {
        $errorMessage = $prototypes['error'];
        $prototypes = [];
    }
}

// Get the selected prototype details if a prototype is selected
if (!$mostrar_diagnostico && isset($_GET['prototype_id']) && !empty($_GET['prototype_id'])) {
    foreach ($prototypes as $proto) {
        if ($proto['id'] == $_GET['prototype_id']) {
            $selectedPrototype = $proto;
            break;
        }
    }
    
    if ($selectedPrototype) {
        $prototypeBacklog = getPrototypeBacklog($selectedPrototype['id']);
        if (isset($prototypeBacklog['error'])) {
            $errorMessage = $prototypeBacklog['error'];
            $prototypeBacklog = [];
        }
    }
}

// Adicionar função de diagnóstico para verificar a conectividade com o Redmine
function diagnosticarConexaoRedmine() {
    global $apiKey, $baseUrl;
    
    $resultados = [];
    
    // Verificar configurações básicas
    $resultados[] = "=== VERIFICAÇÃO DAS CONFIGURAÇÕES ===";
    $resultados[] = "URL Base: " . $baseUrl;
    $resultados[] = "API Key: " . (empty($apiKey) ? "NÃO DEFINIDA" : "****" . substr($apiKey, -4) . " (" . strlen($apiKey) . " caracteres)");
    
    // Testar se conseguimos fazer HTTP request básico
    $resultados[] = "\n=== TESTE DE CONEXÃO HTTP BÁSICA ===";
    $url_teste = "https://httpbin.org/get";
    $resposta = @file_get_contents($url_teste);
    if ($resposta === FALSE) {
        $resultados[] = "❌ Falha ao fazer requisição HTTP básica. Isso pode indicar problemas com a configuração do PHP.";
        $resultados[] = "Erro: " . error_get_last()['message'] ?? 'Desconhecido';
    } else {
        $resultados[] = "✅ Requisição HTTP básica funcionou corretamente.";
    }
    
    // Testar acesso à URL do Redmine (sem autenticação)
    $resultados[] = "\n=== TESTE DE ACESSO À URL DO REDMINE ===";
    // Remover /issues.json da URL se existir
    $url_redmine = preg_replace('/\/issues\.json.*$/', '', $baseUrl);
    $resultados[] = "Testando acesso a: " . $url_redmine;
    
    $context = stream_context_create(['http' => ['ignore_errors' => true]]);
    $resposta = @file_get_contents($url_redmine, false, $context);
    $status = $http_response_header[0] ?? 'Status desconhecido';
    
    $resultados[] = "Status da resposta: " . $status;
    
    if ($resposta === FALSE) {
        $resultados[] = "❌ Falha ao acessar a URL do Redmine.";
        $resultados[] = "Erro: " . error_get_last()['message'] ?? 'Desconhecido';
        $resultados[] = "⚠️ Verifique se a URL está correta e acessível do servidor.";
    } else {
        $resultados[] = "✅ URL do Redmine acessível.";
        
        // Verificar se a página parece ser um Redmine
        if (strpos($resposta, 'Redmine') !== false) {
            $resultados[] = "✅ A resposta parece ser de um sistema Redmine.";
        } else {
            $resultados[] = "⚠️ A resposta não parece ser de um sistema Redmine. Verifique se a URL está correta.";
        }
    }
    
    // Testar API do Redmine
    $resultados[] = "\n=== TESTE DA API DO REDMINE ===";
    $api_url = $baseUrl . "projects.json";
    $resultados[] = "Testando acesso a: " . $api_url;
    
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n",
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];
    $context = stream_context_create($options);
    $resposta = @file_get_contents($api_url, false, $context);
    $status = $http_response_header[0] ?? 'Status desconhecido';
    
    $resultados[] = "Status da resposta: " . $status;
    
    if ($resposta === FALSE) {
        $resultados[] = "❌ Falha ao acessar a API do Redmine.";
        $resultados[] = "Erro: " . error_get_last()['message'] ?? 'Desconhecido';
    } else {
        // Salvar os primeiros 1000 caracteres da resposta
        $response_preview = substr($resposta, 0, 1000);
        $resultados[] = "Amostra da resposta:";
        $resultados[] = "```\n" . $response_preview . "\n```";
        
        $data = json_decode($resposta, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $resultados[] = "❌ A resposta não é um JSON válido.";
            $resultados[] = "Erro JSON: " . json_last_error_msg();
            $resultados[] = "⚠️ Isso pode indicar que a URL não é uma API do Redmine ou há um problema de autenticação.";
        } else {
            if (isset($data['projects'])) {
                $resultados[] = "✅ API do Redmine respondeu corretamente!";
                $resultados[] = "Projetos encontrados: " . count($data['projects']);
                
                // Verificar se o projeto 'tribeprototypes' existe
                $projeto_encontrado = false;
                $resultados[] = "\n=== PROJETOS DISPONÍVEIS ===";
                foreach ($data['projects'] as $projeto) {
                    $resultados[] = "- " . $projeto['name'] . " (id: " . $projeto['id'] . ", identifier: " . $projeto['identifier'] . ")";
                    if ($projeto['identifier'] === 'tribeprototypes') {
                        $projeto_encontrado = true;
                    }
                }
                
                if ($projeto_encontrado) {
                    $resultados[] = "\n✅ Projeto 'tribeprototypes' encontrado!";
                    
                    // Verificar subprojetos
                    $tribeprototypesId = null;
                    foreach ($data['projects'] as $projeto) {
                        if ($projeto['identifier'] === 'tribeprototypes') {
                            $tribeprototypesId = $projeto['id'];
                            break;
                        }
                    }
                    
                    if ($tribeprototypesId) {
                        $api_url = $baseUrl . "projects.json?parent_id=" . $tribeprototypesId;
                        $resposta_sub = @file_get_contents($api_url, false, $context);
                        
                        if ($resposta_sub !== FALSE) {
                            $subprojetos_data = json_decode($resposta_sub, true);
                            if (json_last_error() === JSON_ERROR_NONE && isset($subprojetos_data['projects'])) {
                                $resultados[] = "\n=== SUBPROJETOS DE TRIBEPROTOTYPES ===";
                                $resultados[] = "Total de subprojetos encontrados: " . count($subprojetos_data['projects']);
                                
                                foreach ($subprojetos_data['projects'] as $subprojeto) {
                                    $resultados[] = "- " . $subprojeto['name'] . " (id: " . $subprojeto['id'] . ", identifier: " . $subprojeto['identifier'] . ")";
                                }
                            }
                        }
                    }
                } else {
                    $resultados[] = "\n❌ Projeto 'tribeprototypes' NÃO encontrado!";
                    $resultados[] = "⚠️ Você precisa criar um projeto com o identificador 'tribeprototypes' no Redmine.";
                }
            } elseif (isset($data['errors'])) {
                $resultados[] = "❌ A API do Redmine retornou um erro:";
                $resultados[] = implode(", ", $data['errors']);
                
                // Verificar erros comuns
                if (in_array('Invalid or missing API key', $data['errors'])) {
                    $resultados[] = "\n⚠️ SOLUÇÃO: Verifique se a chave API está correta e ativa no Redmine.";
                }
            } else {
                $resultados[] = "⚠️ Resposta não esperada da API.";
            }
        }
    }
    
    // Recomendações finais
    $resultados[] = "\n=== RECOMENDAÇÕES ===";
    $resultados[] = "1. Verifique se a URL base termina com uma barra ('/').";
    $resultados[] = "2. Confirme se a chave API tem permissões para acessar projetos e issues.";
    $resultados[] = "3. Verifique se o projeto 'tribeprototypes' existe.";
    $resultados[] = "4. Tente acessar o Redmine diretamente em seu navegador para confirmar que está funcionando.";
    
    return $resultados;
}

// Get the status options from the Redmine API
function getStatusOptions() {
    global $apiKey, $baseUrl;
    
    $url = $baseUrl . 'issue_statuses.json';
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . $apiKey . "\r\n",
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return [];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['issue_statuses'])) {
        return [];
    }
    
    return $data['issue_statuses'];
}

// Get status options
$statusOptions = getStatusOptions();

// Define status mappings if we got options from API
if (!empty($statusOptions)) {
    // Reset status maps
    $statusMap = [
        'backlog' => [],
        'in_progress' => [],
        'suspended' => [],
        'completed' => []
    ];
    
    // Map statuses to categories
    foreach ($statusOptions as $status) {
        $statusId = $status['id'];
        $statusName = strtolower($status['name']);
        
        if (strpos($statusName, 'new') !== false || strpos($statusName, 'novo') !== false) {
            $statusMap['backlog'][] = $statusId;
        } else if (strpos($statusName, 'progress') !== false || strpos($statusName, 'andamento') !== false) {
            $statusMap['in_progress'][] = $statusId;
        } else if (strpos($statusName, 'rejected') !== false || strpos($statusName, 'rejected') !== false || 
                  strpos($statusName, 'hold') !== false || strpos($statusName, 'suspen') !== false) {
            $statusMap['suspended'][] = $statusId;
        } else if (strpos($statusName, 'closed') !== false || strpos($statusName, 'fechado') !== false || 
                  strpos($statusName, 'resolved') !== false || strpos($statusName, 'resolvido') !== false ||
                  strpos($statusName, 'done') !== false || strpos($statusName, 'complete') !== false) {
            $statusMap['completed'][] = $statusId;
        } else {
            // Default to backlog for unknown statuses
            $statusMap['backlog'][] = $statusId;
        }
    }
}
?>

<div class="container-fluid">
    <h1 class="mb-4">Gestão de Protótipos</h1>
    
    <?php if ($mostrar_diagnostico): ?>
    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">Diagnóstico de Conexão com o Redmine</h5>
        </div>
        <div class="card-body">
            <pre class="bg-light p-3" style="max-height: 400px; overflow-y: auto;"><?php echo implode("\n", $resultados_diagnostico); ?></pre>
            <div class="mt-3">
                <a href="?tab=prototypes" class="btn btn-primary">Voltar para Protótipos</a>
            </div>
        </div>
    </div>
    <?php else: ?>
    
    <?php if (!empty($message)): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger" role="alert">
        <p><strong>Erro:</strong> <?= $errorMessage ?></p>
        
        <?php if (isset($prototypes['raw_response'])): ?>
        <div class="mt-2">
            <p><strong>Detalhes da resposta:</strong></p>
            <div class="bg-light p-2 mt-1 rounded">
                <pre class="mb-0" style="white-space: pre-wrap; word-break: break-all;"><?= htmlspecialchars($prototypes['raw_response'] ?? '[Sem resposta]') ?></pre>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="mt-2">
            <a href="?tab=prototypes&diagnostico=1" class="btn btn-sm btn-warning me-2">Executar Diagnóstico</a>
            <button class="btn btn-sm btn-info" type="button" data-bs-toggle="collapse" data-bs-target="#ajudaConexaoCollapse">
                Sugestões de solução
            </button>
        </div>
        
        <div class="collapse mt-3" id="ajudaConexaoCollapse">
            <div class="card card-body">
                <h6>Possíveis soluções:</h6>
                <ol>
                    <li>Verifique se o projeto <strong>'tribeprototypes'</strong> existe no seu Redmine</li>
                    <li>Confirme se a URL do Redmine está correta: <code><?= htmlspecialchars($baseUrl) ?></code></li>
                    <li>Verifique se a chave API tem permissões adequadas</li>
                    <li>Tente acessar diretamente a URL do Redmine em seu navegador</li>
                    <li>Execute o diagnóstico para mais detalhes</li>
                </ol>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Lista de Protótipos -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Protótipos</h5>
                    <div>
                        <a href="?tab=prototypes&diagnostico=1" class="btn btn-sm btn-info me-2" title="Diagnosticar problemas de conexão">
                            <i class="bi bi-bug"></i> Diagnóstico
                        </a>
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newPrototypeModal">
                            <i class="bi bi-plus-circle"></i> Novo Protótipo
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($prototypes)): ?>
                        <p class="text-muted">Nenhum protótipo encontrado.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($prototypes as $prototype): ?>
                                <a href="?tab=prototypes&prototype_id=<?= $prototype['id'] ?>" 
                                   class="list-group-item list-group-item-action <?= (isset($_GET['prototype_id']) && $_GET['prototype_id'] == $prototype['id']) ? 'active' : '' ?>">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h5 class="mb-1"><?= htmlspecialchars($prototype['name']) ?></h5>
                                        <small>#<?= $prototype['id'] ?></small>
                                    </div>
                                    <p class="mb-1"><?= substr(htmlspecialchars($prototype['description']), 0, 100) ?>...</p>
                                    <?php
                                    // Redefining $status for project (not issue)
                                    $status = "Ativo";
                                    // If there's a status in the project check if it's closed
                                    if (isset($prototype['status']) && $prototype['status'] == 5) {
                                        $status = "Fechado";
                                    }
                                    ?>
                                    <small>Status: <?= htmlspecialchars($status) ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Detalhes do Protótipo -->
        <div class="col-md-8">
            <?php if ($selectedPrototype): ?>
                <!-- Canvas Editável do Protótipo -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <span id="prototype-name" class="editable" data-field="name"><?= htmlspecialchars($selectedPrototype['name']) ?></span>
                        </h5>
                        <div>
                            <a href="<?= $baseUrl ?>projects/<?= $selectedPrototype['identifier'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                <i class="bi bi-box-arrow-up-right"></i> Ver no Redmine
                            </a>
                            <button type="button" class="btn btn-sm btn-success edit-mode-toggle">
                                <i class="bi bi-pencil"></i> Editar Canvas
                            </button>
                            <button type="button" class="btn btn-sm btn-primary save-changes" style="display: none;">
                                <i class="bi bi-save"></i> Salvar Alterações
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="canvas-container mb-4">
                            <!-- Seção editável com os 10 campos + links -->
                            <div id="prototype-info" class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h6>1. Problem / Challenge</h6>
                                        <div class="editable" data-field="problem"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '1. Problem / Challenge'))) ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6>2. Target Stakeholders</h6>
                                        <div class="editable" data-field="stakeholders"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '2. Target Stakeholders'))) ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6>3. Research Goals / Objectives</h6>
                                        <div class="editable" data-field="goals"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '3. Research Goals / Objectives'))) ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6>4. Solution / Approach</h6>
                                        <div class="editable" data-field="solution"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '4. Solution / Approach'))) ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6>5. Key Technologies</h6>
                                        <div class="editable" data-field="technologies"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '5. Key Technologies'))) ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h6>6. Validation Strategy</h6>
                                        <div class="editable" data-field="validation"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '6. Validation Strategy'))) ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6>7. Key Partners / Collaborators</h6>
                                        <div class="editable" data-field="partners"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '7. Key Partners / Collaborators'))) ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6>8. Potential Impact</h6>
                                        <div class="editable" data-field="impact"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '8. Potential Impact'))) ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6>9. Risks / Uncertainties</h6>
                                        <div class="editable" data-field="risks"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '9. Risks / Uncertainties'))) ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <h6>10. Next Steps / Roadmap</h6>
                                        <div class="editable" data-field="roadmap"><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '10. Next Steps / Roadmap'))) ?></div>
                                    </div>
                                </div>
                                
                                <!-- Links section -->
                                <div class="col-12 mt-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <h6><i class="bi bi-file-earmark-text"></i> Documentation Link</h6>
                                                <div class="editable" data-field="documentation_link">
                                                    <?php 
                                                    $docLink = extractSectionContent($selectedPrototype['description'], 'Documentation Link');
                                                    if (!empty($docLink)) {
                                                        echo '<a href="' . htmlspecialchars($docLink) . '" target="_blank">' . htmlspecialchars($docLink) . '</a>';
                                                    } else {
                                                        echo '<em>Nenhum link definido</em>';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <h6><i class="bi bi-git"></i> Git Repository</h6>
                                                <div class="editable" data-field="git_repository">
                                                    <?php 
                                                    $gitLink = extractSectionContent($selectedPrototype['description'], 'Git Repository');
                                                    if (!empty($gitLink)) {
                                                        echo '<a href="' . htmlspecialchars($gitLink) . '" target="_blank">' . htmlspecialchars($gitLink) . '</a>';
                                                    } else {
                                                        echo '<em>Nenhum link definido</em>';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Backlog Kanban Board -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Backlog & Tarefas</h5>
                        <div>
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newBacklogItemModal">
                                <i class="bi bi-plus-circle"></i> Adicionar Item
                            </button>
                            <a href="<?= $baseUrl ?>projects/<?= $selectedPrototype['identifier'] ?>/issues" class="btn btn-sm btn-outline-secondary" target="_blank">
                                <i class="bi bi-box-arrow-up-right"></i> Ver no Redmine
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="kanban-board">
                            <div class="row">
                                <!-- Backlog Column -->
                                <div class="col-md-3">
                                    <div class="kanban-column" data-status="backlog" data-status-id="1">
                                        <div class="kanban-column-header bg-light">
                                            <h6 class="mb-0">Backlog <span class="badge bg-secondary"><?= count($backlogByStatus['backlog']) ?></span></h6>
                                        </div>
                                        <div class="kanban-items-container">
                                            <?php foreach ($backlogByStatus['backlog'] as $item): ?>
                                                <div class="kanban-item" draggable="true" data-issue-id="<?= $item['id'] ?>">
                                                    <div class="card mb-2">
                                                        <div class="card-body p-2">
                                                            <h6 class="card-title mb-1"><?= htmlspecialchars($item['subject']) ?></h6>
                                                            <p class="card-text small mb-1"><?= substr(htmlspecialchars($item['description']), 0, 50) ?>...</p>
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <span class="badge bg-<?= getPriorityColor($item['priority']['name']) ?>">
                                                                    <?= htmlspecialchars($item['priority']['name']) ?>
                                                                </span>
                                                                <a href="<?= $baseUrl ?>issues/<?= $item['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (empty($backlogByStatus['backlog'])): ?>
                                                <div class="text-center text-muted py-3">
                                                    <em>Sem itens</em>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- In Progress Column -->
                                <div class="col-md-3">
                                    <div class="kanban-column" data-status="in_progress" data-status-id="2">
                                        <div class="kanban-column-header bg-info text-white">
                                            <h6 class="mb-0">Em Execução <span class="badge bg-light text-dark"><?= count($backlogByStatus['in_progress']) ?></span></h6>
                                        </div>
                                        <div class="kanban-items-container">
                                            <?php foreach ($backlogByStatus['in_progress'] as $item): ?>
                                                <div class="kanban-item" draggable="true" data-issue-id="<?= $item['id'] ?>">
                                                    <div class="card mb-2">
                                                        <div class="card-body p-2">
                                                            <h6 class="card-title mb-1"><?= htmlspecialchars($item['subject']) ?></h6>
                                                            <p class="card-text small mb-1"><?= substr(htmlspecialchars($item['description']), 0, 50) ?>...</p>
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <span class="badge bg-<?= getPriorityColor($item['priority']['name']) ?>">
                                                                    <?= htmlspecialchars($item['priority']['name']) ?>
                                                                </span>
                                                                <a href="<?= $baseUrl ?>issues/<?= $item['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (empty($backlogByStatus['in_progress'])): ?>
                                                <div class="text-center text-muted py-3">
                                                    <em>Sem itens</em>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Suspended Column -->
                                <div class="col-md-3">
                                    <div class="kanban-column" data-status="suspended" data-status-id="3">
                                        <div class="kanban-column-header bg-warning">
                                            <h6 class="mb-0">Suspensa <span class="badge bg-light text-dark"><?= count($backlogByStatus['suspended']) ?></span></h6>
                                        </div>
                                        <div class="kanban-items-container">
                                            <?php foreach ($backlogByStatus['suspended'] as $item): ?>
                                                <div class="kanban-item" draggable="true" data-issue-id="<?= $item['id'] ?>">
                                                    <div class="card mb-2">
                                                        <div class="card-body p-2">
                                                            <h6 class="card-title mb-1"><?= htmlspecialchars($item['subject']) ?></h6>
                                                            <p class="card-text small mb-1"><?= substr(htmlspecialchars($item['description']), 0, 50) ?>...</p>
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <span class="badge bg-<?= getPriorityColor($item['priority']['name']) ?>">
                                                                    <?= htmlspecialchars($item['priority']['name']) ?>
                                                                </span>
                                                                <a href="<?= $baseUrl ?>issues/<?= $item['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (empty($backlogByStatus['suspended'])): ?>
                                                <div class="text-center text-muted py-3">
                                                    <em>Sem itens</em>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Completed Column -->
                                <div class="col-md-3">
                                    <div class="kanban-column" data-status="completed" data-status-id="5">
                                        <div class="kanban-column-header bg-success text-white">
                                            <h6 class="mb-0">Finalizada <span class="badge bg-light text-dark"><?= count($backlogByStatus['completed']) ?></span></h6>
                                        </div>
                                        <div class="kanban-items-container">
                                            <?php foreach ($backlogByStatus['completed'] as $item): ?>
                                                <div class="kanban-item" draggable="true" data-issue-id="<?= $item['id'] ?>">
                                                    <div class="card mb-2">
                                                        <div class="card-body p-2">
                                                            <h6 class="card-title mb-1"><?= htmlspecialchars($item['subject']) ?></h6>
                                                            <p class="card-text small mb-1"><?= substr(htmlspecialchars($item['description']), 0, 50) ?>...</p>
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <span class="badge bg-<?= getPriorityColor($item['priority']['name']) ?>">
                                                                    <?= htmlspecialchars($item['priority']['name']) ?>
                                                                </span>