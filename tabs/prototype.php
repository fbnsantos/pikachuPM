<?php
// tabs/prototypes.php - Tab for managing prototypes

// Include configuration file
include_once __DIR__ . '/../config.php';

// Ensure we have access to Redmine API configuration
if (!defined('API_KEY') || !defined('BASE_URL')) {
    die('Erro: Configurações de API do Redmine não encontradas.');
}

// Function to get prototypes from Redmine
function getPrototypes() {
    // Get all issues from the "prototypes" project with a specific tracker
    $url = BASE_URL . 'issues.json?project_id=prototypes&tracker_id=prototype&limit=100&status_id=*';
    
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . API_KEY . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'GET'
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Falha ao obter protótipos do Redmine.'];
    }
    
    $data = json_decode($response, true);
    return $data['issues'] ?? [];
}

// Function to create a new prototype
function createPrototype($data) {
    $url = BASE_URL . 'issues.json';
    
    // Format the description based on the template
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
    
    // Prepare the issue data
    $issueData = [
        'issue' => [
            'project_id' => 'prototypes',  // Assuming 'prototypes' is the project identifier
            'tracker_id' => 'prototype',   // Assuming 'prototype' is the tracker identifier
            'subject' => $data['subject'],
            'description' => $description,
            'status_id' => 'new',
            'priority_id' => $data['priority'] ?? 'normal'
        ]
    ];
    
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . API_KEY . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($issueData)
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Falha ao criar protótipo no Redmine.'];
    }
    
    $data = json_decode($response, true);
    return $data;
}

// Function to get backlog items for a prototype
function getPrototypeBacklog($prototypeId) {
    // Get all issues from the "prototypes" project with a specific parent_id
    $url = BASE_URL . "issues.json?parent_id={$prototypeId}&limit=100&status_id=*";
    
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . API_KEY . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'GET'
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Falha ao obter backlog do protótipo.'];
    }
    
    $data = json_decode($response, true);
    return $data['issues'] ?? [];
}

// Function to add a backlog item to a prototype
function addBacklogItem($prototypeId, $data) {
    $url = BASE_URL . 'issues.json';
    
    // Prepare the issue data
    $issueData = [
        'issue' => [
            'project_id' => 'prototypes',
            'tracker_id' => 'task',
            'subject' => $data['subject'],
            'description' => $data['description'],
            'status_id' => 'new',
            'priority_id' => $data['priority'] ?? 'normal',
            'parent_issue_id' => $prototypeId
        ]
    ];
    
    $options = [
        'http' => [
            'header' => "X-Redmine-API-Key: " . API_KEY . "\r\n" .
                        "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($issueData)
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Falha ao adicionar item ao backlog.'];
    }
    
    $data = json_decode($response, true);
    return $data;
}

// Handle form submissions
$message = '';
$messageType = '';

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

// Get the prototypes to display
$prototypes = getPrototypes();
$errorMessage = '';
if (isset($prototypes['error'])) {
    $errorMessage = $prototypes['error'];
    $prototypes = [];
}

// Get the selected prototype details if a prototype is selected
$selectedPrototype = null;
$prototypeBacklog = [];
if (isset($_GET['prototype_id']) && !empty($_GET['prototype_id'])) {
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

// Function to extract information from prototype description
function extractSectionContent($description, $sectionHeader) {
    $pattern = '/\*\*' . preg_quote($sectionHeader, '/') . '\*\*(.*?)(?=\*\*\d+\.|$)/s';
    if (preg_match($pattern, $description, $matches)) {
        return trim($matches[1]);
    }
    return '';
}
?>

<div class="container-fluid">
    <h1 class="mb-4">Gestão de Protótipos</h1>
    
    <?php if (!empty($message)): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger" role="alert">
        <?= $errorMessage ?>
    </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Lista de Protótipos -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Protótipos</h5>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newPrototypeModal">
                        <i class="bi bi-plus-circle"></i> Novo Protótipo
                    </button>
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
                                        <h5 class="mb-1"><?= htmlspecialchars($prototype['subject']) ?></h5>
                                        <small>#<?= $prototype['id'] ?></small>
                                    </div>
                                    <p class="mb-1"><?= substr(htmlspecialchars($prototype['description']), 0, 100) ?>...</p>
                                    <small>Status: <?= htmlspecialchars($prototype['status']['name']) ?></small>
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
                        <h5 class="mb-0"><?= htmlspecialchars($selectedPrototype['subject']) ?></h5>
                        <div>
                            <a href="<?= REDMINE_URL ?>issues/<?= $selectedPrototype['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
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
                                                    <a href="<?= BASE_URL ?>issues/<?= $item['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
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