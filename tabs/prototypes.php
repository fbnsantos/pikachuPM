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

// Function to get backlog items for a prototype (as issues in the subproject)
function getPrototypeBacklog($prototypeId) {
    global $apiKey, $baseUrl;
    
    // Get issues for this project
    $url = $baseUrl . "issues.json?project_id=" . $prototypeId . "&limit=100";
    
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
    
    return $data['issues'] ?? [];
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

// Function to extract information from prototype description
function extractSectionContent($description, $sectionHeader) {
    $pattern = '/\*\*' . preg_quote($sectionHeader, '/') . '\*\*(.*?)(?=\*\*\d+\.|$)/s';
    if (preg_match($pattern, $description, $matches)) {
        return trim($matches[1]);
    }
    return '';
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
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?= htmlspecialchars($selectedPrototype['name']) ?></h5>
                        <div>
                            <a href="<?= $baseUrl ?>projects/<?= $selectedPrototype['identifier'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                <i class="bi bi-box-arrow-up-right"></i> Ver no Redmine
                            </a>
                            <button type="button" class="btn btn-sm btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#newBacklogItemModal">
                                <i class="bi bi-plus-circle"></i> Adicionar Backlog
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>1. Problem / Challenge</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '1. Problem / Challenge'))) ?></p>
                                    
                                    <h6>2. Target Stakeholders</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '2. Target Stakeholders'))) ?></p>
                                    
                                    <h6>3. Research Goals / Objectives</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '3. Research Goals / Objectives'))) ?></p>
                                    
                                    <h6>4. Solution / Approach</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '4. Solution / Approach'))) ?></p>
                                    
                                    <h6>5. Key Technologies</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '5. Key Technologies'))) ?></p>
                                </div>
                                <div class="col-md-6">
                                    <h6>6. Validation Strategy</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '6. Validation Strategy'))) ?></p>
                                    
                                    <h6>7. Key Partners / Collaborators</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '7. Key Partners / Collaborators'))) ?></p>
                                    
                                    <h6>8. Potential Impact</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '8. Potential Impact'))) ?></p>
                                    
                                    <h6>9. Risks / Uncertainties</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '9. Risks / Uncertainties'))) ?></p>
                                    
                                    <h6>10. Next Steps / Roadmap</h6>
                                    <p><?= nl2br(htmlspecialchars(extractSectionContent($selectedPrototype['description'], '10. Next Steps / Roadmap'))) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="mb-3">Backlog</h5>
                        <?php if (empty($prototypeBacklog)): ?>
                            <p class="text-muted">Nenhum item no backlog deste protótipo.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Tarefa</th>
                                            <th>Status</th>
                                            <th>Prioridade</th>
                                            <th>Atribuído</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($prototypeBacklog as $item): ?>
                                            <tr>
                                                <td><?= $item['id'] ?></td>
                                                <td><?= htmlspecialchars($item['subject']) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= getStatusColor($item['status']['name']) ?>">
                                                        <?= htmlspecialchars($item['status']['name']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= getPriorityColor($item['priority']['name']) ?>">
                                                        <?= htmlspecialchars($item['priority']['name']) ?>
                                                    </span>
                                                </td>
                                                <td><?= isset($item['assigned_to']) ? htmlspecialchars($item['assigned_to']['name']) : '-' ?></td>
                                                <td>
                                                    <a href="<?= $baseUrl ?>issues/<?= $item['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                                        <i class="bi bi-box-arrow-up-right"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <h4 class="text-muted">Selecione um protótipo para ver os detalhes</h4>
                        <p>Ou crie um novo protótipo clicando no botão à esquerda</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; // Fim do if ($mostrar_diagnostico) ?>
</div>

<!-- Modal para Novo Protótipo -->
<div class="modal fade" id="newPrototypeModal" tabindex="-1" aria-labelledby="newPrototypeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newPrototypeModalLabel">Criar Novo Protótipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="?tab=prototypes">
                    <input type="hidden" name="action" value="create_prototype">
                    
                    <div class="mb-3">
                        <label for="subject" class="form-label">Nome do Protótipo</label>
                        <input type="text" class="form-control" id="subject" name="subject" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="problem" class="form-label">1. Problem / Challenge</label>
                                <textarea class="form-control" id="problem" name="problem" rows="3" required></textarea>
                                <small class="form-text text-muted">Que problema social, científico ou industrial está tentando resolver?</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="stakeholders" class="form-label">2. Target Stakeholders</label>
                                <textarea class="form-control" id="stakeholders" name="stakeholders" rows="3" required></textarea>
                                <small class="form-text text-muted">Quem beneficia? (indústrias, academia, políticos, agricultores, etc.)</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="goals" class="form-label">3. Research Goals / Objectives</label>
                                <textarea class="form-control" id="goals" name="goals" rows="3" required></textarea>
                                <small class="form-text text-muted">Quais são os objetivos científicos ou técnicos principais?</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="solution" class="form-label">4. Solution / Approach</label>
                                <textarea class="form-control" id="solution" name="solution" rows="3" required></textarea>
                                <small class="form-text text-muted">Qual é o conceito, sistema ou método proposto?</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="technologies" class="form-label">5. Key Technologies</label>
                                <textarea class="form-control" id="technologies" name="technologies" rows="3" required></textarea>
                                <small class="form-text text-muted">Quais tecnologias estão sendo desenvolvidas ou testadas?</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="validation" class="form-label">6. Validation Strategy</label>
                                <textarea class="form-control" id="validation" name="validation" rows="3" required></textarea>
                                <small class="form-text text-muted">Como testará ou avaliará o protótipo?</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="partners" class="form-label">7. Key Partners / Collaborators</label>
                                <textarea class="form-control" id="partners" name="partners" rows="3" required></textarea>
                                <small class="form-text text-muted">Quem são os parceiros de pesquisa, industriais ou governamentais?</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="impact" class="form-label">8. Potential Impact</label>
                                <textarea class="form-control" id="impact" name="impact" rows="3" required></textarea>
                                <small class="form-text text-muted">Que valor científico, social ou econômico este protótipo pode gerar?</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="risks" class="form-label">9. Risks / Uncertainties</label>
                                <textarea class="form-control" id="risks" name="risks" rows="3" required></textarea>
                                <small class="form-text text-muted">Quais são os principais riscos científicos, técnicos ou logísticos?</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="roadmap" class="form-label">10. Next Steps / Roadmap</label>
                                <textarea class="form-control" id="roadmap" name="roadmap" rows="3" required></textarea>
                                <small class="form-text text-muted">Quais são as ações de curto prazo ou experimentos/projetos de acompanhamento?</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="priority" class="form-label">Prioridade</label>
                        <select class="form-select" id="priority" name="priority">
                            <option value="normal">Normal</option>
                            <option value="low">Baixa</option>
                            <option value="high">Alta</option>
                            <option value="urgent">Urgente</option>
                        </select>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">Criar Protótipo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Novo Item de Backlog -->
<div class="modal fade" id="newBacklogItemModal" tabindex="-1" aria-labelledby="newBacklogItemModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newBacklogItemModalLabel">Adicionar Item ao Backlog</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="?tab=prototypes&prototype_id=<?= $_GET['prototype_id'] ?? '' ?>">
                    <input type="hidden" name="action" value="add_backlog">
                    <input type="hidden" name="prototype_id" value="<?= $_GET['prototype_id'] ?? '' ?>">
                    
                    <div class="mb-3">
                        <label for="subject" class="form-label">Título da Tarefa</label>
                        <input type="text" class="form-control" id="subject" name="subject" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Descrição</label>
                        <textarea class="form-control" id="description" name="description" rows="5" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="priority" class="form-label">Prioridade</label>
                        <select class="form-select" id="priority" name="priority">
                            <option value="normal">Normal</option>
                            <option value="low">Baixa</option>
                            <option value="high">Alta</option>
                            <option value="urgent">Urgente</option>
                        </select>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">Adicionar ao Backlog</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// Helper function to determine status color
function getStatusColor($status) {
    switch (strtolower($status)) {
        case 'new':
            return 'primary';
        case 'in progress':
        case 'em andamento':
            return 'info';
        case 'resolved':
        case 'resolvido':
            return 'success';
        case 'closed':
        case 'fechado':
            return 'dark';
        default:
            return 'secondary';
    }
}

// Helper function to determine priority color
function getPriorityColor($priority) {
    switch (strtolower($priority)) {
        case 'low':
        case 'baixa':
            return 'success';
        case 'normal':
            return 'info';
        case 'high':
        case 'alta':
            return 'warning';
        case 'urgent':
        case 'urgente':
            return 'danger';
        default:
            return 'secondary';
    }
}
?>