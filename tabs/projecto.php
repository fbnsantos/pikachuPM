<?php
// tabs/projecto.php
// Inclui o arquivo de configuração
require_once 'config.php';

// ======== DEFINIÇÕES DE FUNÇÕES ========

// Função para fazer requisições à API do Redmine
function redmineAPI($endpoint, $method = 'GET', $data = null) {
    global $API_KEY, $BASE_URL;
    
    // Verificar se endpoint começa com / para evitar problemas de URL
    if ($endpoint[0] !== '/' && !strpos($endpoint, 'http') === 0) {
        $endpoint = '/' . $endpoint;
    }
    
    // Determinar URL base com base no endpoint
    if (strpos($endpoint, '/issues') === 0 || strpos($endpoint, '/users') === 0 || 
        strpos($endpoint, '/trackers') === 0 || strpos($endpoint, '/enumerations') === 0 ||
        strpos($endpoint, '/issue_statuses') === 0) {
        $url = $BASE_URL . $endpoint;
    } else {
        $url = $BASE_URL . '/projects' . $endpoint;
    }
    
    error_log("URL completa: $url");
    
    $ch = curl_init($url);
    
    $headers = [
        'X-Redmine-API-Key: ' . $API_KEY,
        'Content-Type: application/json',
    ];
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST' || $method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    error_log("Resposta da API Redmine: HTTP/1.1 $httpCode " . (curl_getinfo($ch, CURLINFO_HTTP_CODE_STR) ?? ''));
    
    if (curl_errno($ch)) {
        error_log("Erro CURL: " . curl_error($ch));
        curl_close($ch);
        return ['error' => "Erro de conexão: " . curl_error($ch)];
    }
    
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    } else {
        error_log("Erro API Redmine ($httpCode): $response");
        return ['error' => "Erro ao acessar API ($httpCode): " . $response];
    }
}

// Buscar o projeto pai "tribeprojects"
function getTribeProjects() {
    $response = redmineAPI('/tribeprojects.json?include=children');
    
    if (isset($response['error'])) {
        error_log("Erro ao buscar projeto pai: " . print_r($response, true));
        return null;
    }
    
    return $response['project'] ?? null;
}

// Buscar todos os projetos filhos
function getChildProjects($parentId) {
    $response = redmineAPI(".json?parent_id=$parentId");
    
    if (isset($response['error'])) {
        error_log("Erro ao buscar projetos filhos: " . print_r($response, true));
        return [];
    }
    
    return $response['projects'] ?? [];
}

// Função para buscar as issues de um projeto com opção de filtro por atribuição
function getProjectIssues($projectId, $assignedToId = null) {
    $endpoint = "/$projectId/issues.json?status_id=*&sort=updated_on:desc";
    
    // Adicionar filtro por atribuição se necessário
    if ($assignedToId) {
        $endpoint .= "&assigned_to_id=$assignedToId";
    }
    
    $response = redmineAPI($endpoint);
    
    if (isset($response['error'])) {
        error_log("Erro ao buscar issues: " . print_r($response, true));
        return [];
    }
    
    return $response['issues'] ?? [];
}

// Buscar detalhes de uma issue específica
function getIssueDetails($issueId) {
    $response = redmineAPI("/issues/$issueId.json");
    
    if (isset($response['error'])) {
        return null;
    }
    
    return $response['issue'] ?? null;
}

// Criar ou atualizar uma issue
function saveIssue($data, $issueId = null) {
    $endpoint = $issueId ? "/issues/$issueId.json" : "/issues.json";
    $method = $issueId ? 'PUT' : 'POST';
    
    $issueData = [
        'issue' => $data
    ];
    
    // Depuração da chamada à API
    error_log("Chamando API para " . ($issueId ? "atualizar" : "criar") . " issue: " . json_encode($issueData));
    
    $result = redmineAPI($endpoint, $method, $issueData);
    
    // Depuração do resultado
    if (isset($result['error'])) {
        error_log("Erro ao salvar issue: " . print_r($result, true));
    }
    
    return $result;
}

// Buscar trackers disponíveis (tipos de issues)
function getTrackers() {
    $response = redmineAPI("/trackers.json");
    
    if (isset($response['error'])) {
        error_log("Erro ao buscar trackers: " . print_r($response, true));
        return [];
    }
    
    return $response['trackers'] ?? [];
}

// Buscar prioridades disponíveis
function getPriorities() {
    $response = redmineAPI("/enumerations/issue_priorities.json");
    
    if (isset($response['error'])) {
        error_log("Erro ao buscar prioridades: " . print_r($response, true));
        return [];
    }
    
    return $response['issue_priorities'] ?? [];
}

// Buscar usuários para atribuição
function getUsers() {
    // No Redmine, precisamos usar o endpoint correto para buscar usuários
    $response = redmineAPI("/users.json?limit=100");
    
    if (isset($response['error'])) {
        error_log("Erro ao buscar usuários: " . print_r($response, true));
        return [];
    }
    
    return $response['users'] ?? [];
}

// Adicionar função para buscar status disponíveis no Redmine
function getStatuses() {
    $response = redmineAPI("/issue_statuses.json");
    
    if (isset($response['error'])) {
        error_log("Erro ao buscar status: " . print_r($response, true));
        return [];
    }
    
    return $response['issue_statuses'] ?? [];
}

// Atualizar apenas o status de uma issue
function updateIssueStatus($issueId, $statusId) {
    $issueData = [
        'issue' => [
            'status_id' => (int)$statusId
        ]
    ];
    
    error_log("Atualizando status da issue $issueId para $statusId");
    
    $result = redmineAPI("/issues/$issueId.json", 'PUT', $issueData);
    
    if (isset($result['error'])) {
        error_log("Erro ao atualizar status: " . print_r($result, true));
    }
    
    return !isset($result['error']);
}

// Buscar issue geral (é a primeira issue do projeto com "geral" no título)
function findGeneralIssue($projectId) {
    $issues = getProjectIssues($projectId);
    error_log("Procurando issue geral para o projeto $projectId. Total de issues: " . count($issues));
    
    foreach ($issues as $issue) {
        if (stripos($issue['subject'], 'geral') !== false || 
            stripos($issue['subject'], 'Informações Gerais') !== false) {
            error_log("Issue geral encontrada: ID=" . $issue['id'] . ", Subject=" . $issue['subject']);
            return $issue;
        }
    }
    
    error_log("Nenhuma issue geral encontrada para o projeto $projectId");
    return null;
}

// ======== INÍCIO DO CÓDIGO PRINCIPAL ========

// Verificar se um projeto específico foi selecionado
$selectedProjectId = $_GET['project_id'] ?? null;
$selectedIssueId = $_GET['issue_id'] ?? null;
$action = $_GET['action'] ?? 'list';

// Verificar filtro de atribuição
$filterAssigned = isset($_GET['assigned']) ? $_GET['assigned'] : 'all';
$currentUserId = $_SESSION['user_id'] ?? null;

// Processar formulário de issue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'save_issue') {
        // Depuração dos dados do POST
        error_log("Dados do POST para save_issue: " . print_r($_POST, true));
        
        $issueData = [
            'project_id' => (int)$_POST['project_id'],
            'subject' => $_POST['subject'],
            'description' => $_POST['description'],
            'tracker_id' => (int)$_POST['tracker_id'],
            'priority_id' => (int)$_POST['priority_id']
        ];
        
        if (!empty($_POST['assigned_to_id'])) {
            $issueData['assigned_to_id'] = (int)$_POST['assigned_to_id'];
        }
        
        $issueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : null;
        $result = saveIssue($issueData, $issueId);
        
        if (!isset($result['error'])) {
            header("Location: ?tab=projecto&project_id=" . $_POST['project_id']);
            exit;
        } else {
            $error = $result['error'];
            // Adicionar exibição de erro para depuração
            echo '<div class="alert alert-danger mb-4">' . 
                 '<strong>Erro ao salvar issue:</strong> ' . htmlspecialchars($error) .
                 '</div>';
        }
    }
    
    if ($action === 'update_status') {
        if (isset($_POST['issue_id']) && isset($_POST['status_id'])) {
            $issueId = (int)$_POST['issue_id'];
            $statusId = (int)$_POST['status_id'];
            $projectId = $_POST['project_id'] ?? null;
            
            $success = updateIssueStatus($issueId, $statusId);
            
            if ($success && $projectId) {
                // Redirecionar para a mesma página com uma mensagem de sucesso
                header("Location: ?tab=projecto&project_id=$projectId&status_updated=1");
                exit;
            } else {
                $error = "Falha ao atualizar o status da tarefa.";
            }
        }
    }
}

// IMPORTANTE: Carregar todos os dados DEPOIS de definir todas as funções para evitar erros
try {
    // Obter projetos
    $tribeProjects = getTribeProjects();
    $childProjects = $tribeProjects ? getChildProjects($tribeProjects['id']) : [];

    // Obter trackers, prioridades e usuários para formulários
    $trackers = getTrackers();
    $priorities = getPriorities();
    $users = getUsers();
    
    // Obter status disponíveis para issues
    $statuses = getStatuses();

    // Verificar se estamos visualizando ou editando uma issue específica
    $currentIssue = null;
    if ($selectedIssueId) {
        $currentIssue = getIssueDetails($selectedIssueId);
    }

    // Verificar se estamos criando uma issue geral para um projeto que não tem
    if ($action === 'create_general' && $selectedProjectId) {
        $generalIssue = findGeneralIssue($selectedProjectId);
        
        if (!$generalIssue) {
            // Obter trackers e prioridades novamente para garantir que temos dados
            if (empty($trackers)) {
                $trackers = getTrackers();
            }
            if (empty($priorities)) {
                $priorities = getPriorities();
            }
            
            // Encontrar IDs para o tracker e prioridade (ou usar valores padrão se não encontrados)
            $defaultTrackerId = 1; // Valor padrão para tracker (geralmente "Bug")
            $defaultPriorityId = 2; // Valor padrão para prioridade (geralmente "Normal")
            
            if (!empty($trackers)) {
                $defaultTrackerId = $trackers[0]['id'];
            }
            
            if (!empty($priorities)) {
                foreach ($priorities as $priority) {
                    if ($priority['name'] === 'Normal') {
                        $defaultPriorityId = $priority['id'];
                        break;
                    }
                }
            }
            
            $issueData = [
                'project_id' => $selectedProjectId,
                'subject' => 'Informações Gerais do Projeto',
                'description' => "# Links e recursos do projeto\n\n* [Documentação]\n* [Repositório]\n* [Ambiente de teste]\n\n## Notas gerais\n\nAdicione aqui informações gerais do projeto.",
                'tracker_id' => $defaultTrackerId,
                'priority_id' => $defaultPriorityId
            ];
            
            $result = saveIssue($issueData);
            
            if (!isset($result['error'])) {
                header("Location: ?tab=projecto&project_id=$selectedProjectId");
                exit;
            } else {
                $error = $result['error'];
                // Depuração
                error_log("Erro ao criar issue geral: " . print_r($result, true));
            }
        } else {
            header("Location: ?tab=projecto&project_id=$selectedProjectId&issue_id=" . $generalIssue['id'] . "&action=edit");
            exit;
        }
    }
} catch (Exception $e) {
    error_log("Erro ao carregar dados: " . $e->getMessage());
    $error = "Ocorreu um erro ao carregar os dados. Verifique o log para mais detalhes.";
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-3">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Projetos</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php if ($tribeProjects): ?>
                            <a href="?tab=projecto" class="list-group-item list-group-item-action fw-bold">
                                <i class="bi bi-folder2-open me-2"></i> <?= htmlspecialchars($tribeProjects['name']) ?>
                            </a>
                            <?php foreach ($childProjects as $project): ?>
                                <a href="?tab=projecto&project_id=<?= $project['id'] ?>" 
                                   class="list-group-item list-group-item-action <?= $selectedProjectId == $project['id'] ? 'active' : '' ?>">
                                    <i class="bi bi-folder me-2"></i> <?= htmlspecialchars($project['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="list-group-item text-danger">
                                Erro ao carregar projetos. Verifique a conexão com o Redmine.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-9">
            <?php if ($selectedProjectId): ?>
                <?php
                $projectDetails = null;
                foreach ($childProjects as $project) {
                    if ($project['id'] == $selectedProjectId) {
                        $projectDetails = $project;
                        break;
                    }
                }
                
                $generalIssue = findGeneralIssue($selectedProjectId);
                $issues = getProjectIssues($selectedProjectId);
                ?>
                
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-folder-check me-2"></i> <?= htmlspecialchars($projectDetails['name'] ?? 'Projeto') ?>
                        </h5>
                        <div>
                            <!-- Filtro de visualização por atribuição -->
                            <div class="btn-group me-2">
                                <a href="?tab=projecto&project_id=<?= $selectedProjectId ?>&assigned=all" 
                                   class="btn btn-<?= $filterAssigned === 'all' ? 'light' : 'outline-light' ?> btn-sm">
                                    <i class="bi bi-people-fill me-1"></i> Todas as tarefas
                                </a>
                                <a href="?tab=projecto&project_id=<?= $selectedProjectId ?>&assigned=me" 
                                   class="btn btn-<?= $filterAssigned === 'me' ? 'light' : 'outline-light' ?> btn-sm">
                                    <i class="bi bi-person-fill me-1"></i> Minhas tarefas
                                </a>
                            </div>
                            
                            <?php if ($generalIssue): ?>
                                <a href="?tab=projecto&project_id=<?= $selectedProjectId ?>&issue_id=<?= $generalIssue['id'] ?>&action=edit" 
                                   class="btn btn-light btn-sm">
                                    <i class="bi bi-info-circle me-1"></i> Editar Informações Gerais
                                </a>
                            <?php else: ?>
                                <a href="?tab=projecto&project_id=<?= $selectedProjectId ?>&action=create_general" 
                                   class="btn btn-light btn-sm">
                                    <i class="bi bi-info-circle me-1"></i> Criar Informações Gerais
                                </a>
                            <?php endif; ?>
                            <a href="?tab=projecto&project_id=<?= $selectedProjectId ?>&action=new_issue" 
                               class="btn btn-success btn-sm ms-2">
                                <i class="bi bi-plus-circle me-1"></i> Nova Issue
                            </a>
                        </div>
                    </div>
                    
                    <?php if ($generalIssue && $action !== 'edit' && $action !== 'new_issue'): ?>
                        <div class="card-body bg-light border-bottom">
                            <h5><?= htmlspecialchars($generalIssue['subject']) ?></h5>
                            <div class="issue-description">
                                <?= nl2br(htmlspecialchars($generalIssue['description'])) ?>
                            </div>
                            <div class="mt-2 text-end">
                                <a href="?tab=projecto&project_id=<?= $selectedProjectId ?>&issue_id=<?= $generalIssue['id'] ?>&action=edit" 
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-pencil me-1"></i> Editar
                                </a>
                                <a href="<?= $BASE_URL ?>/issues/<?= $generalIssue['id'] ?>" 
                                   class="btn btn-outline-secondary btn-sm ms-2" target="_blank">
                                    <i class="bi bi-box-arrow-up-right me-1"></i> Ver no Redmine
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($action === 'edit' && $currentIssue): ?>
                        <div class="card-body">
                            <h5>Editar Issue</h5>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="save_issue">
                                <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
                                <input type="hidden" name="issue_id" value="<?= $currentIssue['id'] ?>">
                                
                                <div class="mb-3">
                                    <label for="subject" class="form-label">Assunto</label>
                                    <input type="text" class="form-control" id="subject" name="subject" 
                                           value="<?= htmlspecialchars($currentIssue['subject']) ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label">Descrição</label>
                                    <div class="mb-2 markdown-toolbar">
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="**texto**" title="Negrito">
                                            <i class="bi bi-type-bold"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="*texto*" title="Itálico">
                                            <i class="bi bi-type-italic"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="# " title="Título">
                                            <i class="bi bi-type-h1"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="## " title="Subtítulo">
                                            <i class="bi bi-type-h2"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="* " title="Lista com marcadores">
                                            <i class="bi bi-list-ul"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="1. " title="Lista numerada">
                                            <i class="bi bi-list-ol"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="[texto](url)" title="Link">
                                            <i class="bi bi-link-45deg"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="---" title="Linha horizontal">
                                            <i class="bi bi-hr"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="> texto" title="Citação">
                                            <i class="bi bi-blockquote-left"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-markdown="\`código\`" title="Código">
                                            <i class="bi bi-code"></i>
                                        </button>
                                    </div>
                                    <textarea class="form-control" id="description" name="description" 
                                              rows="8" required><?= htmlspecialchars($currentIssue['description']) ?></textarea>
                                    <div class="form-text">
                                        <a href="#" id="toggle-preview" class="link-primary">
                                            <i class="bi bi-eye"></i> Pré-visualizar
                                        </a>
                                    </div>
                                    <div id="markdown-preview" class="card mt-2 p-3 border d-none"></div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="tracker_id" class="form-label">Tipo</label>
                                            <select class="form-select" id="tracker_id" name="tracker_id" required>
                                                <?php foreach ($trackers as $tracker): ?>
                                                    <option value="<?= $tracker['id'] ?>" 
                                                            <?= $currentIssue['tracker']['id'] == $tracker['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($tracker['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="priority_id" class="form-label">Prioridade</label>
                                            <select class="form-select" id="priority_id" name="priority_id" required>
                                                <?php foreach ($priorities as $priority): ?>
                                                    <option value="<?= $priority['id'] ?>"
                                                            <?= $currentIssue['priority']['id'] == $priority['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($priority['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="assigned_to_id" class="form-label">Atribuir para</label>
                                            <select class="form-select" id="assigned_to_id" name="assigned_to_id">
                                                <option value="">Ninguém</option>
                                                <?php 
                                                if (!empty($users)):
                                                    foreach ($users as $user): 
                                                ?>
                                                    <option value="<?= $user['id'] ?>"
                                                            <?= isset($currentIssue['assigned_to']) && $currentIssue['assigned_to']['id'] == $user['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($user['firstname'] . ' ' . $user['lastname']) ?>
                                                    </option>
                                                <?php 
                                                    endforeach;
                                                endif;
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-end">
                                    <a href="?tab=projecto&project_id=<?= $selectedProjectId ?>" class="btn btn-secondary me-2">Cancelar</a>
                                    <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                                </div>
                            </form>
                        </div>
                        
                    <?php elseif ($action === 'new_issue'): ?>
                        <div class="card-body">
                            <h5>Nova Issue</h5>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="save_issue">
                                <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
                                
                                <div class="mb-3">
                                    <label for="subject" class="form-label">Assunto</label>
                                    <input type="text" class="form-control" id="subject" name="subject" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label">Descrição</label>
                                    <div class="mb-2 markdown-toolbar">
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="**texto**" title="Negrito">
                                            <i class="bi bi-type-bold"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="*texto*" title="Itálico">
                                            <i class="bi bi-type-italic"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="# " title="Título">
                                            <i class="bi bi-type-h1"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="## " title="Subtítulo">
                                            <i class="bi bi-type-h2"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="* " title="Lista com marcadores">
                                            <i class="bi bi-list-ul"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="1. " title="Lista numerada">
                                            <i class="bi bi-list-ol"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="[texto](url)" title="Link">
                                            <i class="bi bi-link-45deg"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="---" title="Linha horizontal">
                                            <i class="bi bi-hr"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-markdown="> texto" title="Citação">
                                            <i class="bi bi-blockquote-left"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-markdown="\`código\`" title="Código">
                                            <i class="bi bi-code"></i>
                                        </button>
                                    </div>
                                    <textarea class="form-control" id="description" name="description" 
                                              rows="8" required></textarea>
                                    <div class="form-text">
                                        <a href="#" id="toggle-preview" class="link-primary">
                                            <i class="bi bi-eye"></i> Pré-visualizar
                                        </a>
                                    </div>
                                    <div id="markdown-preview" class="card mt-2 p-3 border d-none"></div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="tracker_id" class="form-label">Tipo</label>
                                            <select class="form-select" id="tracker_id" name="tracker_id" required>
                                                <?php foreach ($trackers as $tracker): ?>
                                                    <option value="<?= $tracker['id'] ?>">
                                                        <?= htmlspecialchars($tracker['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="priority_id" class="form-label">Prioridade</label>
                                            <select class="form-select" id="priority_id" name="priority_id" required>
                                                <?php foreach ($priorities as $priority): ?>
                                                    <option value="<?= $priority['id'] ?>">
                                                        <?= htmlspecialchars($priority['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="assigned_to_id" class="form-label">Atribuir para</label>
                                            <select class="form-select" id="assigned_to_id" name="assigned_to_id">
                                                <option value="">Ninguém</option>
                                                <?php 
                                                if (!empty($users)):
                                                    foreach ($users as $user): 
                                                ?>
                                                    <option value="<?= $user['id'] ?>">
                                                        <?= htmlspecialchars($user['firstname'] . ' ' . $user['lastname']) ?>
                                                    </option>
                                                <?php 
                                                    endforeach;
                                                endif;
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-end">
                                    <a href="?tab=projecto&project_id=<?= $selectedProjectId ?>" class="btn btn-secondary me-2">Cancelar</a>
                                    <button type="submit" class="btn btn-primary">Criar Issue</button>
                                </div>
                            </form>
                        </div>
                        
                    <?php else: ?>
                        <div class="card-body">
                            <?php if (isset($_GET['status_updated']) && $_GET['status_updated'] == 1): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="bi bi-check-circle-fill me-2"></i> Status da tarefa atualizado com sucesso!
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (empty($issues)): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i> Nenhuma issue encontrada para este projeto.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th width="10%">ID</th>
                                                <th width="40%">Assunto</th>
                                                <th width="15%">Status</th>
                                                <th width="15%">Prioridade</th>
                                                <th width="20%">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            // Colocar issue geral no topo se existir
                                            $normalIssues = [];
                                            foreach ($issues as $issue) {
                                                if ($generalIssue && $issue['id'] == $generalIssue['id']) {
                                                    continue; // Pular a issue geral, já que ela está mostrada no topo
                                                }
                                                
                                                // Filtrar por atribuição se necessário
                                                if ($filterAssigned === 'me' && 
                                                    (!isset($issue['assigned_to']) || $issue['assigned_to']['id'] != $currentUserId)) {
                                                    continue; // Pular issues não atribuídas ao usuário atual
                                                }
                                                
                                                $normalIssues[] = $issue;
                                            }
                                            
                                            // Exibir as issues
                                            foreach ($normalIssues as $issue):
                                            ?>
                                                <tr>
                                                    <td>#<?= $issue['id'] ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <i class="bi bi-<?= $issue['tracker']['name'] == 'Bug' ? 'bug' : 'card-text' ?> me-2"></i>
                                                            <span><?= htmlspecialchars($issue['subject']) ?></span>
                                                        </div>
                                                        <?php if (isset($issue['assigned_to'])): ?>
                                                            <small class="text-muted">
                                                                <i class="bi bi-person me-1"></i> 
                                                                <?= htmlspecialchars($issue['assigned_to']['name']) ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <!-- Dropdown para alterar status -->
                                                        <form method="post" action="" class="status-form">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="issue_id" value="<?= $issue['id'] ?>">
                                                            <input type="hidden" name="project_id" value="<?= $selectedProjectId ?>">
                                                            <select name="status_id" class="form-select form-select-sm status-select" 
                                                                    aria-label="Alterar status">
                                                                <?php foreach ($statuses as $status): ?>
                                                                    <option value="<?= $status['id'] ?>" 
                                                                            <?= $issue['status']['id'] == $status['id'] ? 'selected' : '' ?>
                                                                            class="status-option-<?= strtolower(str_replace(' ', '-', $status['name'])) ?>">
                                                                        <?= htmlspecialchars($status['name']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </form>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $priorityClass = 'bg-secondary';
                                                        if ($issue['priority']['name'] == 'Alta') {
                                                            $priorityClass = 'bg-danger';
                                                        } elseif ($issue['priority']['name'] == 'Normal') {
                                                            $priorityClass = 'bg-info';
                                                        } elseif ($issue['priority']['name'] == 'Baixa') {
                                                            $priorityClass = 'bg-success';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $priorityClass ?>">
                                                            <?= htmlspecialchars($issue['priority']['name']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="?tab=projecto&project_id=<?= $selectedProjectId ?>&issue_id=<?= $issue['id'] ?>&action=edit" 
                                                           class="btn btn-sm btn-outline-primary me-1">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <a href="<?= $BASE_URL ?>/issues/<?= $issue['id'] ?>" 
                                                           class="btn btn-sm btn-outline-secondary" target="_blank">
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
                    <?php endif; ?>
                </div>
                
            <?php else: ?>
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Gestão de Projetos</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i> Selecione um projeto no menu lateral para visualizar e gerenciar suas issues.
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <i class="bi bi-folder-plus display-4 text-primary"></i>
                                        <h5 class="mt-3">Projetos</h5>
                                        <p class="text-muted">Visualize e gerencie projetos na estrutura do Redmine.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <i class="bi bi-card-checklist display-4 text-success"></i>
                                        <h5 class="mt-3">Issues</h5>
                                        <p class="text-muted">Crie, edite e acompanhe issues de cada projeto.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <i class="bi bi-info-circle display-4 text-info"></i>
                                        <h5 class="mt-3">Informações Gerais</h5>
                                        <p class="text-muted">Acesse facilmente informações importantes de cada projeto.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Converter markdown para HTML nas descrições das issues
    const issueDescription = document.querySelector('.issue-description');
    if (issueDescription) {
        // Função simples para converter links em elementos <a>
        const text = issueDescription.textContent;
        const linkPattern = /\[(.*?)\]\((.*?)\)/g;
        let html = text.replace(linkPattern, '<a href="$2" target="_blank">$1</a>');
        
        // Processar listas
        html = html.replace(/^\* (.*?)$/gm, '<li>$1</li>');
        html = html.replace(/<li>.*?<\/li>/gs, match => `<ul>${match}</ul>`);
        
        // Processar títulos
        html = html.replace(/^# (.*?)$/gm, '<h3>$1</h3>');
        html = html.replace(/^## (.*?)$/gm, '<h5>$1</h5>');
        
        // Aplicar a transformação
        issueDescription.innerHTML = html;
    }
    
    // Botões de formatação Markdown
    const markdownButtons = document.querySelectorAll('[data-markdown]');
    const descriptionTextarea = document.getElementById('description');
    
    if (markdownButtons.length > 0 && descriptionTextarea) {
        markdownButtons.forEach(button => {
            button.addEventListener('click', function() {
                const markdownTemplate = this.getAttribute('data-markdown');
                const selectionStart = descriptionTextarea.selectionStart;
                const selectionEnd = descriptionTextarea.selectionEnd;
                const currentValue = descriptionTextarea.value;
                
                // Se texto selecionado, aplicar markdown ao redor
                if (selectionStart !== selectionEnd) {
                    const selectedText = currentValue.substring(selectionStart, selectionEnd);
                    let newText;
                    
                    if (markdownTemplate.includes('texto')) {
                        // Para tags como **texto** ou [texto](url)
                        if (markdownTemplate === '[texto](url)') {
                            newText = markdownTemplate.replace('texto', selectedText).replace('url', 'https://');
                        } else {
                            newText = markdownTemplate.replace('texto', selectedText);
                        }
                    } else if (markdownTemplate.trim().endsWith(' ')) {
                        // Para prefixos como "# " ou "* "
                        // Adicionar no início de cada linha
                        const lines = selectedText.split('\n');
                        newText = lines.map(line => markdownTemplate + line).join('\n');
                    } else {
                        // Para outros casos (como ---)
                        newText = markdownTemplate;
                    }
                    
                    // Substituir a seleção pelo texto formatado
                    descriptionTextarea.value = currentValue.substring(0, selectionStart) + 
                                             newText + 
                                             currentValue.substring(selectionEnd);
                    
                    // Reposicionar o cursor após a formatação
                    descriptionTextarea.focus();
                    descriptionTextarea.selectionStart = selectionStart + newText.length;
                    descriptionTextarea.selectionEnd = selectionStart + newText.length;
                } else {
                    // Se não houver seleção, inserir o template na posição do cursor
                    let newText;
                    if (markdownTemplate === '[texto](url)') {
                        newText = '[texto](https://)';
                    } else if (markdownTemplate.includes('texto')) {
                        newText = markdownTemplate;
                    } else {
                        newText = markdownTemplate;
                    }
                    
                    descriptionTextarea.value = currentValue.substring(0, selectionStart) + 
                                             newText + 
                                             currentValue.substring(selectionStart);
                    
                    // Colocar o cursor na posição adequada
                    if (markdownTemplate.includes('texto')) {
                        const cursorPos = selectionStart + markdownTemplate.indexOf('texto');
                        descriptionTextarea.focus();
                        descriptionTextarea.selectionStart = cursorPos;
                        descriptionTextarea.selectionEnd = cursorPos + 5; // "texto" tem 5 caracteres
                    } else {
                        descriptionTextarea.focus();
                        descriptionTextarea.selectionStart = selectionStart + newText.length;
                        descriptionTextarea.selectionEnd = selectionStart + newText.length;
                    }
                }
                
                // Atualizar a pré-visualização se estiver visível
                updateMarkdownPreview();
            });
        });
    }
    
    // Pré-visualização de Markdown
    const togglePreviewButton = document.getElementById('toggle-preview');
    const markdownPreview = document.getElementById('markdown-preview');
    
    function updateMarkdownPreview() {
        if (!markdownPreview || markdownPreview.classList.contains('d-none')) {
            return;
        }
        
        if (descriptionTextarea) {
            const markdown = descriptionTextarea.value;
            
            // Conversão simples de markdown para HTML
            let html = markdown;
            
            // Converter títulos
            html = html.replace(/^# (.*?)$/gm, '<h3>$1</h3>');
            html = html.replace(/^## (.*?)$/gm, '<h4>$1</h4>');
            html = html.replace(/^### (.*?)$/gm, '<h5>$1</h5>');
            
            // Converter negrito e itálico
            html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
            
            // Converter links
            html = html.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank">$1</a>');
            
            // Converter listas não ordenadas
            html = html.replace(/^\* (.*?)$/gm, '<li>$1</li>');
            html = html.replace(/(<li>.*?<\/li>\n*)+/gs, match => `<ul>${match}</ul>`);
            
            // Converter listas ordenadas
            html = html.replace(/^(\d+)\. (.*?)$/gm, '<li>$2</li>');
            html = html.replace(/(<li>.*?<\/li>\n*)+/gs, match => {
                // Verificar se é realmente uma lista ordenada (começa com número)
                if (/^\d+\./.test(match)) {
                    return `<ol>${match}</ol>`;
                }
                return match;
            });
            
            // Converter citações
            html = html.replace(/^> (.*?)$/gm, '<blockquote>$1</blockquote>');
            
            // Converter código inline
            html = html.replace(/`(.*?)`/g, '<code>$1</code>');
            
            // Converter linha horizontal
            html = html.replace(/^---+$/gm, '<hr>');
            
            // Aplicar quebras de linha
            html = html.replace(/\n/g, '<br>');
            
            // Exibir HTML na pré-visualização
            markdownPreview.innerHTML = html;
        }
    }
    
    if (togglePreviewButton && markdownPreview && descriptionTextarea) {
        togglePreviewButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (markdownPreview.classList.contains('d-none')) {
                // Mostrar pré-visualização
                markdownPreview.classList.remove('d-none');
                togglePreviewButton.innerHTML = '<i class="bi bi-eye-slash"></i> Ocultar pré-visualização';
                updateMarkdownPreview();
            } else {
                // Ocultar pré-visualização
                markdownPreview.classList.add('d-none');
                togglePreviewButton.innerHTML = '<i class="bi bi-eye"></i> Pré-visualizar';
            }
        });
        
        // Atualizar pré-visualização quando o conteúdo muda
        descriptionTextarea.addEventListener('input', updateMarkdownPreview);
    }
    
    // Alteração automática de status quando o usuário muda a seleção
    const statusSelects = document.querySelectorAll('.status-select');
    if (statusSelects.length > 0) {
        statusSelects.forEach(select => {
            select.addEventListener('change', function() {
                // Encontrar o formulário pai e submetê-lo
                const form = this.closest('form');
                if (form) {
                    form.submit();
                }
            });
        });
    }
    
    // Estilos adicionais para o editor Markdown e status
    const style = document.createElement('style');
    style.textContent = `
        .markdown-toolbar {
            background-color: #f8f9fa;
            border: 1px solid #ced4da;
            border-bottom: none;
            border-radius: 0.25rem 0.25rem 0 0;
            padding: 0.5rem;
        }
        
        #description {
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }
        
        #markdown-preview {
            max-height: 500px;
            overflow-y: auto;
            background-color: #f8f9fa;
        }
        
        #markdown-preview h3 {
            font-size: 1.5rem;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        
        #markdown-preview h4 {
            font-size: 1.25rem;
            margin-top: 1rem;
        }
        
        #markdown-preview ul, #markdown-preview ol {
            padding-left: 2rem;
        }
        
        #markdown-preview blockquote {
            border-left: 3px solid #dee2e6;
            padding-left: 1rem;
            color: #6c757d;
        }
        
        #markdown-preview code {
            background-color: #e9ecef;
            padding: 0.2rem 0.4rem;
            border-radius: 0.2rem;
        }
        
        /* Estilos para os status no select */
        .status-select {
            font-weight: 500;
        }
        
        .status-option-new {
            background-color: #cfe2ff;
            color: #084298;
        }
        
        .status-option-in-progress {
            background-color: #fff3cd;
            color: #664d03;
        }
        
        .status-option-closed {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        
        .status-option-feedback {
            background-color: #f8d7da;
            color: #842029;
        }
    `;
    document.head.appendChild(style);
});
</script>