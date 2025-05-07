<?php
// tabs/projecto.php
// Inclui o arquivo de configuração
require_once 'config.php';

// Função para fazer requisições à API do Redmine
function redmineAPI($endpoint, $method = 'GET', $data = null) {
    global $API_KEY, $BASE_URL;
    
    $url = $BASE_URL . '/projects' . $endpoint;
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
    
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    } else {
        error_log("Erro API Redmine ($httpCode): $response");
        return ['error' => "Erro ao acessar API ($httpCode)"];
    }
}

// Buscar o projeto pai "tribeprojects"
function getTribeProjects() {
    $response = redmineAPI('/tribeprojects.json?include=children');
    
    if (isset($response['error'])) {
        return null;
    }
    
    return $response['project'] ?? null;
}

// Buscar todos os projetos filhos
function getChildProjects($parentId) {
    $response = redmineAPI(".json?parent_id=$parentId");
    
    if (isset($response['error'])) {
        return [];
    }
    
    return $response['projects'] ?? [];
}

// Buscar as issues de um projeto
function getProjectIssues($projectId) {
    $response = redmineAPI("/$projectId/issues.json?status_id=*&sort=updated_on:desc");
    
    if (isset($response['error'])) {
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
    
    return redmineAPI($endpoint, $method, $issueData);
}

// Buscar trackers disponíveis (tipos de issues)
function getTrackers() {
    $response = redmineAPI("/trackers.json");
    
    if (isset($response['error'])) {
        return [];
    }
    
    return $response['trackers'] ?? [];
}

// Buscar prioridades disponíveis
function getPriorities() {
    $response = redmineAPI("/enumerations/issue_priorities.json");
    
    if (isset($response['error'])) {
        return [];
    }
    
    return $response['issue_priorities'] ?? [];
}

// Buscar usuários para atribuição
function getUsers() {
    $response = redmineAPI("/users.json");
    
    if (isset($response['error'])) {
        return [];
    }
    
    return $response['users'] ?? [];
}

// Processar formulário de issue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'save_issue') {
        $issueData = [
            'project_id' => $_POST['project_id'],
            'subject' => $_POST['subject'],
            'description' => $_POST['description'],
            'tracker_id' => $_POST['tracker_id'],
            'priority_id' => $_POST['priority_id']
        ];
        
        if (!empty($_POST['assigned_to_id'])) {
            $issueData['assigned_to_id'] = $_POST['assigned_to_id'];
        }
        
        $issueId = isset($_POST['issue_id']) ? $_POST['issue_id'] : null;
        $result = saveIssue($issueData, $issueId);
        
        if (!isset($result['error'])) {
            header("Location: ?tab=projecto&project_id=" . $_POST['project_id']);
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

// Verificar se um projeto específico foi selecionado
$selectedProjectId = $_GET['project_id'] ?? null;
$selectedIssueId = $_GET['issue_id'] ?? null;
$action = $_GET['action'] ?? 'list';

// Obter projetos
$tribeProjects = getTribeProjects();
$childProjects = $tribeProjects ? getChildProjects($tribeProjects['id']) : [];

// Obter trackers, prioridades e usuários para formulários
$trackers = getTrackers();
$priorities = getPriorities();
$users = getUsers();

// Buscar issue geral (é a primeira issue do projeto com "geral" no título)
function findGeneralIssue($projectId) {
    $issues = getProjectIssues($projectId);
    
    foreach ($issues as $issue) {
        if (stripos($issue['subject'], 'geral') !== false) {
            return $issue;
        }
    }
    
    return null;
}

// Verificar se estamos visualizando ou editando uma issue específica
$currentIssue = null;
if ($selectedIssueId) {
    $currentIssue = getIssueDetails($selectedIssueId);
}

// Verificar se estamos criando uma issue geral para um projeto que não tem
if ($action === 'create_general' && $selectedProjectId) {
    $generalIssue = findGeneralIssue($selectedProjectId);
    
    if (!$generalIssue) {
        $defaultTrackerId = $trackers[0]['id'] ?? 1;
        $defaultPriorityId = $priorities[0]['id'] ?? 3;
        
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
        }
    } else {
        header("Location: ?tab=projecto&project_id=$selectedProjectId&issue_id=" . $generalIssue['id'] . "&action=edit");
        exit;
    }
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
                                    <textarea class="form-control" id="description" name="description" 
                                              rows="8" required><?= htmlspecialchars($currentIssue['description']) ?></textarea>
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
                                                <?php foreach ($users as $user): ?>
                                                    <option value="<?= $user['id'] ?>"
                                                            <?= isset($currentIssue['assigned_to']) && $currentIssue['assigned_to']['id'] == $user['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($user['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
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
                                    <textarea class="form-control" id="description" name="description" rows="8" required></textarea>
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
                                                <?php foreach ($users as $user): ?>
                                                    <option value="<?= $user['id'] ?>">
                                                        <?= htmlspecialchars($user['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
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
                            <?php if (empty($issues)): ?>
                                <div class="alert alert-info">
                                    Nenhuma issue encontrada para este projeto.
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
                                                        <span class="badge <?= $issue['status']['name'] == 'Closed' ? 'bg-success' : 
                                                                              ($issue['status']['name'] == 'New' ? 'bg-primary' : 'bg-warning') ?>">
                                                            <?= htmlspecialchars($issue['status']['name']) ?>
                                                        </span>
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
});
</script>