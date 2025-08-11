<?php
/**
 * Arquivo de gerenciamento de milestones
 * 
 * Este arquivo gerencia as milestones no Redmine através da API
 * As milestones são issues do projeto tribemilestone
 */

// Incluir configurações
require_once 'config.php';
include __DIR__ . '/tabs/milestone_functions.php'

// Verificar sessão
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}


// Extrair projetos e protótipos associados a uma milestone da descrição
function extractAssociatedProjectsFromDescription($description) {
    $associated = [
        'prototypes' => [], // Vai conter arrays com 'id' e 'name'
        'projects' => []    // Vai conter arrays com 'id' e 'name'
    ];
    
    if (empty($description)) {
        return $associated;
    }
    
    error_log("Extraindo projetos/protótipos da descrição: " . substr($description, 0, 500));
    
    // Encontrar protótipos
    if (preg_match('/Protótipos:(.*?)(?:Projetos:|$)/s', $description, $matches)) {
        $prototypesSection = trim($matches[1]);
        
        // Novo formato: "* #123 Nome do Protótipo"
        preg_match_all('/\*\s*\#(\d+)\s+(.+?)(?:\n|$)/m', $prototypesSection, $prototypeMatches, PREG_SET_ORDER);
        if (!empty($prototypeMatches)) {
            foreach ($prototypeMatches as $match) {
                $associated['prototypes'][] = [
                    'id' => (int)$match[1],
                    'name' => trim($match[2])
                ];
            }
        } else {
            // Formato legado (compatibilidade): "* Nome do Protótipo"
            preg_match_all('/\*\s*(?!\#)([^*\n]+)(?:\n|$)/m', $prototypesSection, $legacyMatches);
            if (!empty($legacyMatches[1])) {
                foreach ($legacyMatches[1] as $name) {
                    $associated['prototypes'][] = [
                        'id' => null, // ID desconhecido no formato legado
                        'name' => trim($name)
                    ];
                }
            }
        }
    }
    
    // Encontrar projetos
    if (preg_match('/Projetos:(.*?)(?:Backlog:|Em Execução:|Pausa:|Fechado:|$)/s', $description, $matches)) {
        $projectsSection = trim($matches[1]);
        
        // Novo formato: "* #123 Nome do Projeto"
        preg_match_all('/\*\s*\#(\d+)\s+(.+?)(?:\n|$)/m', $projectsSection, $projectMatches, PREG_SET_ORDER);
        if (!empty($projectMatches)) {
            foreach ($projectMatches as $match) {
                if (strpos($match[2], '#') === false) { // Garantir que não é uma tarefa
                    $associated['projects'][] = [
                        'id' => (int)$match[1],
                        'name' => trim($match[2])
                    ];
                }
            }
        }
        
        // Formato legado (compatibilidade): "* Nome do Projeto" (sem # no início)
        preg_match_all('/\*\s*(?!\#)([^*\n]+)(?:\n|$)/m', $projectsSection, $legacyMatches);
        if (!empty($legacyMatches[1])) {
            foreach ($legacyMatches[1] as $name) {
                $associated['projects'][] = [
                    'id' => null, // ID desconhecido no formato legado
                    'name' => trim($name)
                ];
            }
        }
        
        // Capturar tarefas com o formato antigo que estavam na seção de projetos
        preg_match_all('/\*\s*\#(\d+)\s*-\s*(.*?)(?:\n|$)/m', $projectsSection, $taskMatches, PREG_SET_ORDER);
        if (!empty($taskMatches)) {
            error_log("Atenção: Tarefas encontradas na seção de projetos (formato antigo)");
        }
    }
    
    error_log("Projetos extraídos: " . json_encode($associated['projects']));
    error_log("Protótipos extraídos: " . json_encode($associated['prototypes']));
    
    return $associated;
}

// Extrair features e tarefas associadas a uma milestone da descrição
// Extrair features e tarefas associadas a uma milestone da descrição
function extractTasksFromDescription($description) {
    $tasks = [
        'backlog' => [],
        'in_progress' => [],
        'paused' => [],
        'closed' => []
    ];
    
    if (empty($description)) {
        return $tasks;
    }
    
    // Log para debug
    error_log("Extraindo tarefas da descrição: " . substr($description, 0, 500) . (strlen($description) > 500 ? "..." : ""));
    
    // Primeiro, vamos extrair tarefas que possam estar na seção de Projetos
    if (preg_match('/Projetos:(.*?)(?:Backlog:|Em Execução:|Pausa:|Fechado:|$)/s', $description, $matches)) {
        $projectsSection = trim($matches[1]);
        // Capturar linhas que começam com #, que são tarefas
        preg_match_all('/\*\s*\#(\d+)\s*-\s*(.*?)(?:\n|$)/m', $projectsSection, $taskMatches, PREG_SET_ORDER);
        
        if (!empty($taskMatches)) {
            foreach ($taskMatches as $match) {
                // Adicionar ao backlog, a menos que esteja em outra seção
                $taskId = (int)$match[1];
                $taskTitle = trim($match[2]);
                
                // Verificar se esta tarefa já está em alguma outra seção específica
                $foundInSection = false;
                
                // Vamos verificar mais tarde se está em alguma seção específica
                $tasks['_temp'][] = [
                    'id' => $taskId,
                    'title' => $taskTitle,
                    'project' => null,  // Será preenchido depois
                    'assignee' => null  // Será preenchido depois
                ];
            }
        }
    }
    
    // Para cada seção, extrair tarefas
    $sections = [
        'Backlog:' => 'backlog',
        'Em Execução:' => 'in_progress',
        'Pausa:' => 'paused',
        'Fechado:' => 'closed'
    ];
    
    // Armazenar os IDs das tarefas que encontramos nas seções específicas
    $specificTaskIds = [];
    
    foreach ($sections as $sectionHeader => $taskType) {
        if (preg_match('/' . preg_quote($sectionHeader, '/') . '(.*?)(?:' . implode('|', array_map(function($s) { return preg_quote($s, '/'); }, array_keys($sections))) . '|$)/s', $description, $matches)) {
            $sectionContent = trim($matches[1]);
            
            // Padrão flexível para capturar tarefas
            preg_match_all('/\*\s*\#(\d+)(?:\s*-\s*|\s+)(.*?)(?:\n|$)/m', $sectionContent, $taskMatches, PREG_SET_ORDER);
            
            if (!empty($taskMatches)) {
                foreach ($taskMatches as $match) {
                    $taskId = (int)$match[1];
                    $specificTaskIds[] = $taskId; // Marcar que encontramos esta tarefa numa seção específica
                    
                    $tasks[$taskType][] = [
                        'id' => $taskId,
                        'title' => trim($match[2]),
                        'project' => null,  // Será preenchido depois
                        'assignee' => null  // Será preenchido depois
                    ];
                }
            }
            
            // Log das tarefas encontradas
            error_log("Tarefas encontradas na seção '$sectionHeader': " . json_encode($tasks[$taskType]));
        }
    }
    
    // Agora, adicionar as tarefas da seção de Projetos que não estão em seções específicas ao backlog
    if (isset($tasks['_temp'])) {
        foreach ($tasks['_temp'] as $task) {
            if (!in_array($task['id'], $specificTaskIds)) {
                $tasks['backlog'][] = $task;
            }
        }
        unset($tasks['_temp']);
    }
    
    // Log final das tarefas por seção
    foreach ($sections as $sectionHeader => $taskType) {
        error_log("Tarefas na seção '$taskType' (final): " . json_encode($tasks[$taskType]));
    }
    
    return $tasks;
}

// Obter detalhes adicionais das tarefas (projeto e assignee)
function getTaskDetails($taskIds) {
    if (empty($taskIds)) {
        return [];
    }
    
    $taskDetails = [];
    
    // Agrupar as tarefas em lotes para evitar consultas muito grandes
    $batches = array_chunk($taskIds, 50);
    
    foreach ($batches as $batch) {
        // Converter array de IDs para string separada por vírgula
        $idsString = implode(',', $batch);
        
        // Consultar detalhes das tarefas
        $issues = callRedmineAPI('/issues.json?issue_id=' . $idsString . '&include=project,assigned_to&limit=100');
        
        if (isset($issues['issues'])) {
            foreach ($issues['issues'] as $issue) {
                $taskDetail = [
                    'id' => $issue['id'],
                    'project' => isset($issue['project']) ? [
                        'id' => $issue['project']['id'],
                        'name' => $issue['project']['name']
                    ] : null,
                    'assignee' => isset($issue['assigned_to']) ? [
                        'id' => $issue['assigned_to']['id'],
                        'name' => isset($issue['assigned_to']['name']) ? $issue['assigned_to']['name'] : 
                               (isset($issue['assigned_to']['firstname']) && isset($issue['assigned_to']['lastname']) ? 
                                $issue['assigned_to']['firstname'] . ' ' . $issue['assigned_to']['lastname'] : 
                                'Usuário #' . $issue['assigned_to']['id'])
                    ] : null
                ];
                
                $taskDetails[$issue['id']] = $taskDetail;
            }
        }
    }
    
    return $taskDetails;
}

// Criar nova milestone
function createMilestone($title, $description, $assignedTo, $dueDate) {
    $mainProjects = getMainProjectIds();
    
    if (!$mainProjects['milestone_id']) {
        return ['error' => 'Projeto de milestones não encontrado'];
    }
    
    $data = [
        'issue' => [
            'project_id' => $mainProjects['milestone_id'],
            'subject' => $title,
            'description' => $description,
            'tracker_id' => 2, // Assumindo 2 como o tracker de milestones
            'status_id' => 1,  // Novo
        ]
    ];
    
    if (!empty($assignedTo)) {
        $data['issue']['assigned_to_id'] = (int)$assignedTo;
    }
    
    if (!empty($dueDate)) {
        $data['issue']['due_date'] = $dueDate;
    }
    
    return callRedmineAPI('/issues.json', 'POST', $data);
}

// Atualizar uma milestone existente
function updateMilestone($issueId, $updates) {
    $data = [
        'issue' => $updates
    ];

    // Log da requisição para diagnóstico
    error_log("Atualizando milestone #$issueId: " . json_encode($updates, JSON_UNESCAPED_UNICODE));
    
    // Limpar buffers de saída antes da chamada da API para evitar mistura de conteúdo
    while (ob_get_level()) ob_end_clean();
    
    $result = callRedmineAPI('/issues/' . $issueId . '.json', 'PUT', $data);
    
    // Log da resposta para diagnóstico
    if (isset($result['error'])) {
        error_log("Erro na atualização da milestone #$issueId: " . json_encode($result, JSON_UNESCAPED_UNICODE));
    }
    
    return $result;
}

// Atualizar status de uma tarefa
function updateTaskStatus($taskId, $statusId) {
    $data = [
        'issue' => [
            'status_id' => (int)$statusId
        ]
    ];
    
    return callRedmineAPI('/issues/' . $taskId . '.json', 'PUT', $data);
}

// Formatar a descrição da milestone com projetos, protótipos e tarefas
function formatMilestoneDescription($prototypes, $projects, $tasks) {
    // Log dos dados recebidos
    error_log("Formatando descrição com: " . 
              "protótipos=" . json_encode($prototypes) . 
              ", projetos=" . json_encode($projects) . 
              ", tarefas=" . json_encode(array_keys($tasks)));
    
    $description = "Protótipos:\n";
    if (!empty($prototypes)) {
        foreach ($prototypes as $prototype) {
            // Verificar se é formato novo (array com id e name) ou legado (string)
            if (is_array($prototype) && isset($prototype['id']) && isset($prototype['name'])) {
                $description .= "* #" . $prototype['id'] . " " . $prototype['name'] . "\n";
            } elseif (is_string($prototype)) {
                // Formato legado - manter como estava
                $description .= "* $prototype\n";
            }
        }
    } else {
        $description .= "\n";
    }
    
    $description .= "\nProjetos:\n";
    if (!empty($projects)) {
        foreach ($projects as $project) {
            // Verificar se é formato novo (array com id e name) ou legado (string)
            if (is_array($project) && isset($project['id']) && isset($project['name'])) {
                $description .= "* #" . $project['id'] . " " . $project['name'] . "\n";
            } elseif (is_string($project)) {
                // Formato legado - manter como estava
                $description .= "* $project\n";
            }
        }
    } else {
        $description .= "\n";
    }
    
    // Adicionar tarefas organizadas por status
    $statusSections = [
        'backlog' => 'Backlog',
        'in_progress' => 'Em Execução',
        'paused' => 'Pausa',
        'closed' => 'Fechado'
    ];
    
    foreach ($statusSections as $status => $label) {
        $description .= "\n$label:\n";
        if (!empty($tasks[$status])) {
            foreach ($tasks[$status] as $task) {
                // Garantir que temos os campos necessários
                if (isset($task['id']) && isset($task['title'])) {
                    $description .= "* #" . $task['id'] . " - " . $task['title'] . "\n";
                }
            }
        }
    }
    
    // Log da descrição formatada para debug
    error_log("Descrição formatada: " . substr($description, 0, 500) . (strlen($description) > 500 ? "..." : ""));
    
    return $description;
}

// Manipulador de ações
$action = $_GET['action'] ?? 'list';
$message = '';
$messageType = '';

// Processar formulários AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
)) {
    // Set content type to JSON
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'move_task':
            $taskId = $_POST['task_id'] ?? null;
            $newStatus = $_POST['new_status'] ?? null;
            $milestoneId = $_POST['milestone_id'] ?? null;
            
            if ($taskId && $newStatus && $milestoneId) {
                try {
                    // Mapear o status "lógico" para o ID do status do Redmine
                    $statusMapping = [
                        'backlog' => 1,      // Novo/Backlog
                        'in_progress' => 2,  // Em progresso
                        'paused' => 3,       // Resolvido/Pausa (exemplo)
                        'closed' => 5        // Fechado
                    ];
                    
                    $statusId = $statusMapping[$newStatus] ?? 1;
                    
                    // Atualizar o status da tarefa no Redmine
                    $result = updateTaskStatus($taskId, $statusId);
                    
                    if (isset($result['error'])) {
                        $errorMessage = 'Erro ao atualizar status da tarefa: ' . $result['error'];
                        error_log($errorMessage);
                        echo json_encode(['success' => false, 'message' => $errorMessage]);
                        exit;
                    }
                    
                    // Obter milestone atual
                    $milestone = getMilestoneDetails($milestoneId);
                    
                    if (isset($milestone['error'])) {
                        $errorMessage = 'Erro ao obter detalhes da milestone: ' . $milestone['error'];
                        error_log($errorMessage);
                        echo json_encode(['success' => false, 'message' => $errorMessage]);
                        exit;
                    }
                    
                    // Extrair informações atuais
                    $associated = extractAssociatedProjectsFromDescription($milestone['description']);
                    $tasks = extractTasksFromDescription($milestone['description']);
                    
                    // Encontrar a tarefa para mover
                    $taskToMove = null;
                    $currentStatus = null;
                    
                    foreach ($tasks as $status => $statusTasks) {
                        foreach ($statusTasks as $index => $task) {
                            if ($task['id'] == $taskId) {
                                $taskToMove = $task;
                                $currentStatus = $status;
                                // Remover da lista atual
                                unset($tasks[$status][$index]);
                                $tasks[$status] = array_values($tasks[$status]); // Reordenar índices
                                break 2;
                            }
                        }
                    }
                    
                    // Se encontrou a tarefa, adicionar ao novo status
                    if ($taskToMove) {
                        $tasks[$newStatus][] = $taskToMove;
                        
                        // Atualizar a descrição da milestone
                        $newDescription = formatMilestoneDescription($associated['prototypes'], $associated['projects'], $tasks);
                        
                        $updates = [
                            'description' => $newDescription
                        ];
                        
                        $updateResult = updateMilestone($milestoneId, $updates);
                        
                        if (isset($updateResult['error'])) {
                            $errorMessage = 'Erro ao atualizar descrição da milestone: ' . $updateResult['error'];
                            error_log($errorMessage);
                            echo json_encode(['success' => false, 'message' => $errorMessage]);
                        } else {
                            echo json_encode(['success' => true]);
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Tarefa não encontrada na milestone']);
                    }
                } catch (Exception $e) {
                    error_log('Exceção ao mover tarefa: ' . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
                }
                
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
                exit;
            }
            break;
            
        case 'add_task':
            $milestoneId = $_POST['milestone_id'] ?? null;
            $taskId = $_POST['task_id'] ?? null;
            
            if ($milestoneId && $taskId) {
                try {
                    // Obter detalhes da tarefa com informações adicionais
                    $task = callRedmineAPI('/issues/' . $taskId . '.json?include=project,assigned_to');
                    
                    if (isset($task['error']) || !isset($task['issue'])) {
                        $errorMessage = isset($task['error']) ? $task['error'] : 'Erro ao obter detalhes da tarefa';
                        error_log('Erro ao obter tarefa #' . $taskId . ': ' . $errorMessage);
                        echo json_encode(['success' => false, 'message' => $errorMessage]);
                        exit;
                    }
                    
                    $taskDetails = $task['issue'];
                    
                    // Obter milestone atual
                    $milestone = getMilestoneDetails($milestoneId);
                    
                    if (isset($milestone['error'])) {
                        $errorMessage = 'Erro ao obter detalhes da milestone: ' . $milestone['error'];
                        error_log($errorMessage);
                        echo json_encode(['success' => false, 'message' => $errorMessage]);
                        exit;
                    }
                    
                    // Extrair informações atuais
                    $associated = extractAssociatedProjectsFromDescription($milestone['description']);
                    $tasks = extractTasksFromDescription($milestone['description']);
                    
                    // Verificar se a tarefa já existe em algum status
                    $taskExists = false;
                    foreach ($tasks as $status => $statusTasks) {
                        foreach ($statusTasks as $task) {
                            if ($task['id'] == $taskId) {
                                $taskExists = true;
                                break 2;
                            }
                        }
                    }
                    
                    if (!$taskExists) {
                        // Adicionar tarefa ao backlog
                        $taskData = [
                            'id' => (int)$taskId,
                            'title' => $taskDetails['subject']
                        ];
                        
                        // Adicionar informações do projeto se disponíveis
                        if (isset($taskDetails['project'])) {
                            $taskData['project'] = [
                                'id' => $taskDetails['project']['id'],
                                'name' => $taskDetails['project']['name']
                            ];
                        }
                        
                        // Adicionar informações do responsável se disponíveis
                        if (isset($taskDetails['assigned_to'])) {
                            $displayName = isset($taskDetails['assigned_to']['name']) ? 
                                $taskDetails['assigned_to']['name'] : 
                                (isset($taskDetails['assigned_to']['firstname']) && isset($taskDetails['assigned_to']['lastname']) ? 
                                    $taskDetails['assigned_to']['firstname'] . ' ' . $taskDetails['assigned_to']['lastname'] : 
                                    'Usuário #' . $taskDetails['assigned_to']['id']);
                            
                            $taskData['assignee'] = [
                                'id' => $taskDetails['assigned_to']['id'],
                                'name' => $displayName
                            ];
                        }
                        
                        $tasks['backlog'][] = $taskData;
                        
                        // Atualizar a descrição da milestone
                        $newDescription = formatMilestoneDescription($associated['prototypes'], $associated['projects'], $tasks);
                        
                        $updates = [
                            'description' => $newDescription
                        ];
                        
                        $updateResult = updateMilestone($milestoneId, $updates);
                        
                        if (isset($updateResult['error'])) {
                            $errorMessage = 'Erro ao atualizar descrição da milestone: ' . $updateResult['error'];
                            error_log($errorMessage);
                            echo json_encode(['success' => false, 'message' => $errorMessage]);
                        } else {
                            echo json_encode([
                                'success' => true, 
                                'task' => $taskData
                            ]);
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Tarefa já existe na milestone']);
                    }
                } catch (Exception $e) {
                    error_log('Exceção ao adicionar tarefa: ' . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
                }
                
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
                exit;
            }
            break;
        case 'remove_task':
            $milestoneId = $_POST['milestone_id'] ?? null;
            $taskId = $_POST['task_id'] ?? null;
            
            if ($milestoneId && $taskId) {
                try {
                    // Obter milestone atual
                    $milestone = getMilestoneDetails($milestoneId);
                    
                    if (isset($milestone['error'])) {
                        $errorMessage = 'Erro ao obter detalhes da milestone: ' . $milestone['error'];
                        error_log($errorMessage);
                        echo json_encode(['success' => false, 'message' => $errorMessage]);
                        exit;
                    }
                    
                    // Extrair informações atuais
                    $associated = extractAssociatedProjectsFromDescription($milestone['description']);
                    $tasks = extractTasksFromDescription($milestone['description']);
                    
                    // Remover a tarefa de todos os status
                    $taskRemoved = false;
                    foreach ($tasks as $status => $statusTasks) {
                        foreach ($statusTasks as $index => $task) {
                            if ($task['id'] == $taskId) {
                                unset($tasks[$status][$index]);
                                $tasks[$status] = array_values($tasks[$status]); // Reordenar índices
                                $taskRemoved = true;
                                break;
                            }
                        }
                    }
                    
                    if ($taskRemoved) {
                        // Atualizar a descrição da milestone
                        $newDescription = formatMilestoneDescription($associated['prototypes'], $associated['projects'], $tasks);
                        
                        $updates = [
                            'description' => $newDescription
                        ];
                        
                        $updateResult = updateMilestone($milestoneId, $updates);
                        
                        if (isset($updateResult['error'])) {
                            $errorMessage = 'Erro ao atualizar descrição da milestone: ' . $updateResult['error'];
                            error_log($errorMessage);
                            echo json_encode(['success' => false, 'message' => $errorMessage]);
                        } else {
                            echo json_encode(['success' => true]);
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Tarefa não encontrada na milestone']);
                    }
                } catch (Exception $e) {
                    error_log('Exceção ao remover tarefa: ' . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
                }
                
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
                exit;
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Ação desconhecida']);
            exit;
    }
    
    exit; // Garante que nenhum HTML seja enviado após a resposta JSON
}

// Processar formulários normais (não-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_milestone':
                $title = $_POST['title'] ?? '';
                $description = $_POST['description'] ?? '';
                $assignedTo = $_POST['assigned_to'] ?? null;
                $dueDate = $_POST['due_date'] ?? null;
                
                if (!empty($title)) {
                    $result = createMilestone($title, $description, $assignedTo, $dueDate);
                    
                    if (isset($result['error'])) {
                        $message = 'Erro ao criar milestone: ' . $result['error'];
                        $messageType = 'danger';
                    } else {
                        $message = 'Milestone criada com sucesso!';
                        $messageType = 'success';
                        // Redirecionar para evitar reenvio do formulário
                        header('Location: ?tab=milestone&action=list&message=' . urlencode($message) . '&messageType=' . $messageType);
                        exit;
                    }
                } else {
                    $message = 'Título é obrigatório.';
                    $messageType = 'warning';
                }
                break;
                
            case 'update_milestone':
                $issueId = $_POST['issue_id'] ?? null;
                $title = $_POST['title'] ?? '';
                $assignedTo = $_POST['assigned_to'] ?? null;
                $dueDate = $_POST['due_date'] ?? null;
                
                // Obter protótipos e projetos selecionados em formato JSON
                $selectedPrototypes = isset($_POST['prototypes']) && is_array($_POST['prototypes']) ? $_POST['prototypes'] : [];
                $selectedProjects = isset($_POST['projects']) && is_array($_POST['projects']) ? $_POST['projects'] : [];
                
                // Decodificar JSON para arrays com id e name
                $decodedPrototypes = [];
                foreach ($selectedPrototypes as $prototypeJson) {
                    $prototype = json_decode($prototypeJson, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($prototype['id']) && isset($prototype['name'])) {
                        $decodedPrototypes[] = $prototype;
                    } else {
                        // Formato legado ou erro - usar como string
                        $decodedPrototypes[] = $prototypeJson;
                    }
                }
                
                $decodedProjects = [];
                foreach ($selectedProjects as $projectJson) {
                    $project = json_decode($projectJson, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($project['id']) && isset($project['name'])) {
                        $decodedProjects[] = $project;
                    } else {
                        // Formato legado ou erro - usar como string
                        $decodedProjects[] = $projectJson;
                    }
                }
                
                // Obter as tarefas existentes por status
                $existingDescription = $_POST['existing_description'] ?? '';
                $existingTasks = extractTasksFromDescription($existingDescription);
                
                // Formatar nova descrição com os dados decodificados
                $newDescription = formatMilestoneDescription($decodedPrototypes, $decodedProjects, $existingTasks);
                
                $updates = [
                    'subject' => $title,
                    'description' => $newDescription
                ];
                
                if (!empty($assignedTo)) {
                    $updates['assigned_to_id'] = (int)$assignedTo;
                }
                
                if (!empty($dueDate)) {
                    $updates['due_date'] = $dueDate;
                }
                
                if ($issueId && !empty($title)) {
                    // Limpar qualquer saída anterior
                    while (ob_get_level()) ob_end_clean();
                    ob_start();
                    
                    $result = updateMilestone($issueId, $updates);
                    
                    // Limpar o buffer novamente para garantir que nada comprometeu a resposta
                    ob_end_clean();
                    
                    if (isset($result['error'])) {
                        error_log("Erro na atualização da milestone via formulário: " . json_encode($result));
                        $message = 'Erro ao atualizar milestone: ' . $result['error'];
                        $messageType = 'danger';
                    } else {
                        $message = 'Milestone atualizada com sucesso!';
                        $messageType = 'success';
                        
                        // Redirecionar para evitar reenvio do formulário
                        header('Location: ?tab=milestone&action=view&id=' . $issueId . '&message=' . urlencode($message) . '&messageType=' . $messageType);
                        exit;
                    }
                } else {
                    $message = 'ID da milestone e título são obrigatórios.';
                    $messageType = 'warning';
                }
                break;
                
            case 'move_task':
                $taskId = $_POST['task_id'] ?? null;
                $newStatus = $_POST['new_status'] ?? null;
                $milestoneId = $_POST['milestone_id'] ?? null;
                
                if ($taskId && $newStatus && $milestoneId) {
                    try {
                        // Mapear o status "lógico" para o ID do status do Redmine
                        $statusMapping = [
                            'backlog' => 1,      // Novo/Backlog
                            'in_progress' => 2,  // Em progresso
                            'paused' => 3,       // Resolvido/Pausa (exemplo)
                            'closed' => 5        // Fechado
                        ];
                        
                        $statusId = $statusMapping[$newStatus] ?? 1;
                        
                        // Atualizar o status da tarefa no Redmine
                        $result = updateTaskStatus($taskId, $statusId);
                        
                        if (isset($result['error'])) {
                            $errorMessage = 'Erro ao atualizar status da tarefa: ' . $result['error'];
                            error_log($errorMessage);
                            echo json_encode(['success' => false, 'message' => $errorMessage]);
                            exit;
                        }
                        
                        // Obter milestone atual
                        $milestone = getMilestoneDetails($milestoneId);
                        
                        if (isset($milestone['error'])) {
                            $errorMessage = 'Erro ao obter detalhes da milestone: ' . $milestone['error'];
                            error_log($errorMessage);
                            echo json_encode(['success' => false, 'message' => $errorMessage]);
                            exit;
                        }
                        
                        // Extrair informações atuais
                        $associated = extractAssociatedProjectsFromDescription($milestone['description']);
                        $tasks = extractTasksFromDescription($milestone['description']);
                        
                        // Encontrar a tarefa para mover
                        $taskToMove = null;
                        $currentStatus = null;
                        
                        foreach ($tasks as $status => $statusTasks) {
                            foreach ($statusTasks as $index => $task) {
                                if ($task['id'] == $taskId) {
                                    $taskToMove = $task;
                                    $currentStatus = $status;
                                    // Remover da lista atual
                                    unset($tasks[$status][$index]);
                                    $tasks[$status] = array_values($tasks[$status]); // Reordenar índices
                                    break 2;
                                }
                            }
                        }
                        
                        // Se encontrou a tarefa, adicionar ao novo status
                        if ($taskToMove) {
                            $tasks[$newStatus][] = $taskToMove;
                            
                            // Atualizar a descrição da milestone
                            $newDescription = formatMilestoneDescription($associated['prototypes'], $associated['projects'], $tasks);
                            
                            $updates = [
                                'description' => $newDescription
                            ];
                            
                            $updateResult = updateMilestone($milestoneId, $updates);
                            
                            if (isset($updateResult['error'])) {
                                $errorMessage = 'Erro ao atualizar descrição da milestone: ' . $updateResult['error'];
                                error_log($errorMessage);
                                echo json_encode(['success' => false, 'message' => $errorMessage]);
                            } else {
                                echo json_encode(['success' => true]);
                            }
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Tarefa não encontrada na milestone']);
                        }
                    } catch (Exception $e) {
                        error_log('Exceção ao mover tarefa: ' . $e->getMessage());
                        echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
                    }
                    
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
                    exit;
                }
                break;
                
            case 'add_task':
                $milestoneId = $_POST['milestone_id'] ?? null;
                $taskId = $_POST['task_id'] ?? null;
                
                if ($milestoneId && $taskId) {
                    try {
                        // Obter detalhes da tarefa
                        $task = callRedmineAPI('/issues/' . $taskId . '.json');
                        
                        if (isset($task['error']) || !isset($task['issue'])) {
                            $errorMessage = isset($task['error']) ? $task['error'] : 'Erro ao obter detalhes da tarefa';
                            error_log('Erro ao obter tarefa #' . $taskId . ': ' . $errorMessage);
                            echo json_encode(['success' => false, 'message' => $errorMessage]);
                            exit;
                        }
                        
                        $taskDetails = $task['issue'];
                        
                        // Obter milestone atual
                        $milestone = getMilestoneDetails($milestoneId);
                        
                        if (isset($milestone['error'])) {
                            $errorMessage = 'Erro ao obter detalhes da milestone: ' . $milestone['error'];
                            error_log($errorMessage);
                            echo json_encode(['success' => false, 'message' => $errorMessage]);
                            exit;
                        }
                        
                        // Extrair informações atuais
                        $associated = extractAssociatedProjectsFromDescription($milestone['description']);
                        $tasks = extractTasksFromDescription($milestone['description']);
                        
                        // Verificar se a tarefa já existe em algum status
                        $taskExists = false;
                        foreach ($tasks as $status => $statusTasks) {
                            foreach ($statusTasks as $task) {
                                if ($task['id'] == $taskId) {
                                    $taskExists = true;
                                    break 2;
                                }
                            }
                        }
                        
                        if (!$taskExists) {
                            // Adicionar tarefa ao backlog
                            $tasks['backlog'][] = [
                                'id' => (int)$taskId,
                                'title' => $taskDetails['subject']
                            ];
                            
                            // Atualizar a descrição da milestone
                            $newDescription = formatMilestoneDescription($associated['prototypes'], $associated['projects'], $tasks);
                            
                            $updates = [
                                'description' => $newDescription
                            ];
                            
                            $updateResult = updateMilestone($milestoneId, $updates);
                            
                            if (isset($updateResult['error'])) {
                                $errorMessage = 'Erro ao atualizar descrição da milestone: ' . $updateResult['error'];
                                error_log($errorMessage);
                                echo json_encode(['success' => false, 'message' => $errorMessage]);
                            } else {
                                echo json_encode([
                                    'success' => true, 
                                    'task' => [
                                        'id' => (int)$taskId,
                                        'title' => $taskDetails['subject']
                                    ]
                                ]);
                            }
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Tarefa já existe na milestone']);
                        }
                    } catch (Exception $e) {
                        error_log('Exceção ao adicionar tarefa: ' . $e->getMessage());
                        echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
                    }
                    
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
                    exit;
                }
                break;
                
            case 'remove_task':
                $milestoneId = $_POST['milestone_id'] ?? null;
                $taskId = $_POST['task_id'] ?? null;
                
                if ($milestoneId && $taskId) {
                    try {
                        // Obter milestone atual
                        $milestone = getMilestoneDetails($milestoneId);
                        
                        if (isset($milestone['error'])) {
                            $errorMessage = 'Erro ao obter detalhes da milestone: ' . $milestone['error'];
                            error_log($errorMessage);
                            echo json_encode(['success' => false, 'message' => $errorMessage]);
                            exit;
                        }
                        
                        // Extrair informações atuais
                        $associated = extractAssociatedProjectsFromDescription($milestone['description']);
                        $tasks = extractTasksFromDescription($milestone['description']);
                        
                        // Remover a tarefa de todos os status
                        $taskRemoved = false;
                        foreach ($tasks as $status => $statusTasks) {
                            foreach ($statusTasks as $index => $task) {
                                if ($task['id'] == $taskId) {
                                    unset($tasks[$status][$index]);
                                    $tasks[$status] = array_values($tasks[$status]); // Reordenar índices
                                    $taskRemoved = true;
                                    break;
                                }
                            }
                        }
                        
                        if ($taskRemoved) {
                            // Atualizar a descrição da milestone
                            $newDescription = formatMilestoneDescription($associated['prototypes'], $associated['projects'], $tasks);
                            
                            $updates = [
                                'description' => $newDescription
                            ];
                            
                            $updateResult = updateMilestone($milestoneId, $updates);
                            
                            if (isset($updateResult['error'])) {
                                $errorMessage = 'Erro ao atualizar descrição da milestone: ' . $updateResult['error'];
                                error_log($errorMessage);
                                echo json_encode(['success' => false, 'message' => $errorMessage]);
                            } else {
                                echo json_encode(['success' => true]);
                            }
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Tarefa não encontrada na milestone']);
                        }
                    } catch (Exception $e) {
                        error_log('Exceção ao remover tarefa: ' . $e->getMessage());
                        echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
                    }
                    
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
                    exit;
                }
                break;
        }
    }
}

// Verificar mensagem via GET (após redirect)
if (isset($_GET['message']) && isset($_GET['messageType'])) {
    $message = $_GET['message'];
    $messageType = $_GET['messageType'];
}

// Carregar dados necessários com base na ação
switch ($action) {
    case 'list':
        $milestones = getMilestones();
        break;
        
    case 'new':
        $users = getUsers();
        break;
        
    case 'edit':
    case 'view':
        $id = $_GET['id'] ?? null;
        if ($id) {
            $milestone = getMilestoneDetails($id);
            $users = getUsers();
            $prototypes = getPrototypes();
            $projects = getProjects();
            
            // Extrair protótipos e projetos associados da descrição
            $associated = extractAssociatedProjectsFromDescription($milestone['description'] ?? '');
            $tasks = extractTasksFromDescription($milestone['description'] ?? '');
            
            // Obter tarefas dos projetos selecionados
            $selectedProjects = [];
            $selectedPrototypes = [];
            
            // Identificar projetos e protótipos associados
            foreach ($associated['prototypes'] as $assocPrototype) {
                $prototypeId = null;
                
                // Formato novo - objeto com ID e nome
                if (is_array($assocPrototype) && isset($assocPrototype['id'])) {
                    $prototypeId = $assocPrototype['id'];
                } 
                // Formato legado - apenas o nome
                else if (is_string($assocPrototype)) {
                    // Procurar o protótipo pelo nome para obter o ID
                    foreach ($prototypes as $p) {
                        if ($p['name'] == $assocPrototype) {
                            $prototypeId = $p['id'];
                            break;
                        }
                    }
                }
                
                if ($prototypeId) {
                    $selectedPrototypes[] = $prototypeId;
                    error_log("Adicionado protótipo associado ID: $prototypeId");
                }
            }
            
            foreach ($associated['projects'] as $assocProject) {
                $projectId = null;
                
                // Formato novo - objeto com ID e nome
                if (is_array($assocProject) && isset($assocProject['id'])) {
                    $projectId = $assocProject['id'];
                } 
                // Formato legado - apenas o nome
                else if (is_string($assocProject)) {
                    // Procurar o projeto pelo nome para obter o ID
                    foreach ($projects as $p) {
                        if ($p['name'] == $assocProject) {
                            $projectId = $p['id'];
                            break;
                        }
                    }
                }
                
                if ($projectId) {
                    $selectedProjects[] = $projectId;
                    error_log("Adicionado projeto associado ID: $projectId");
                }
            }
            
            error_log("Projetos selecionados: " . implode(", ", $selectedProjects));
            error_log("Protótipos selecionados: " . implode(", ", $selectedPrototypes));
            
            $projectIssues = [];
            
            // Obter tarefas de todos os projetos e protótipos associados
            foreach (array_merge($selectedProjects, $selectedPrototypes) as $projectId) {
                $issues = getProjectIssues($projectId);
                if (!empty($issues)) {
                    $projectIssues[$projectId] = $issues;
                    error_log("Obtidas " . count($issues) . " tarefas para o projeto/protótipo ID: $projectId");
                } else {
                    error_log("Nenhuma tarefa encontrada para o projeto/protótipo ID: $projectId");
                }
            }
            
            // Obter statuses disponíveis
            $statuses = getStatuses();
        }
        break;
}
?>

<div class="container-fluid">
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><?= $action === 'list' ? 'Milestones' : ($action === 'new' ? 'Nova Milestone' : ($action === 'edit' ? 'Editar Milestone' : 'Detalhes da Milestone')) ?></h1>
        <?php if ($action === 'list'): ?>
            <a href="?tab=milestone&action=new" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Nova Milestone
            </a>
        <?php endif; ?>
    </div>

    <?php if ($action === 'list'): ?>
        <?php if (isset($milestones['error'])): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($milestones['error']) ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Título</th>
                            <th>Responsável</th>
                            <th>Data Limite</th>
                            <th>Status</th>
                            <th>Progresso</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($milestones as $milestone): ?>
                            <tr>
                                <td>#<?= $milestone['id'] ?></td>
                                <td><?= htmlspecialchars($milestone['subject']) ?></td>
                                <td>
                                    <?php if (isset($milestone['assigned_to'])): 
                                        // Determinar o nome a exibir - considerando vários formatos possíveis
                                        $assignedUser = $milestone['assigned_to'];
                                        
                                        if (isset($assignedUser['firstname']) && isset($assignedUser['lastname'])) {
                                            $displayName = $assignedUser['firstname'] . ' ' . $assignedUser['lastname'];
                                        } elseif (isset($assignedUser['name'])) {
                                            $displayName = $assignedUser['name'];
                                        } else {
                                            $displayName = 'Usuário #' . $assignedUser['id'];
                                        }
                                    ?>
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-person-fill text-primary me-2"></i>
                                            <?= htmlspecialchars($displayName) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">
                                            <i class="bi bi-person-x me-1"></i>
                                            Não atribuído
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($milestone['due_date'])): ?>
                                        <div><?= htmlspecialchars($milestone['due_date']) ?></div>
                                        <small class="badge bg-<?= $milestone['deadline_color'] ?>">
                                            <?= htmlspecialchars($milestone['deadline_text']) ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">Não definida</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $milestone['status']['id'] == 1 ? 'primary' : ($milestone['status']['id'] == 2 ? 'warning' : ($milestone['status']['id'] == 5 ? 'success' : 'secondary')) ?>">
                                        <?= htmlspecialchars($milestone['status']['name']) ?>
                                    </span>
                                </td>
                                <td style="width: 250px;">
                                    <?php if ($milestone['task_stats']['total'] > 0): ?>
                                        <div class="progress mb-2" style="height: 10px;" title="Total de Tarefas: <?= $milestone['task_stats']['total'] ?>">
                                            <div class="progress-bar bg-primary" role="progressbar" 
                                                style="width: <?= $milestone['task_stats']['backlog']['percent'] ?>%;" 
                                                title="Backlog: <?= $milestone['task_stats']['backlog']['count'] ?> (<?= $milestone['task_stats']['backlog']['percent'] ?>%)">
                                            </div>
                                            <div class="progress-bar bg-warning" role="progressbar" 
                                                style="width: <?= $milestone['task_stats']['in_progress']['percent'] ?>%;" 
                                                title="Em Execução: <?= $milestone['task_stats']['in_progress']['count'] ?> (<?= $milestone['task_stats']['in_progress']['percent'] ?>%)">
                                            </div>
                                            <div class="progress-bar bg-secondary" role="progressbar" 
                                                style="width: <?= $milestone['task_stats']['paused']['percent'] ?>%;" 
                                                title="Pausa: <?= $milestone['task_stats']['paused']['count'] ?> (<?= $milestone['task_stats']['paused']['percent'] ?>%)">
                                            </div>
                                            <div class="progress-bar bg-success" role="progressbar" 
                                                style="width: <?= $milestone['task_stats']['closed']['percent'] ?>%;" 
                                                title="Fechado: <?= $milestone['task_stats']['closed']['count'] ?> (<?= $milestone['task_stats']['closed']['percent'] ?>%)">
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between small">
                                            <span>Concluído: <?= $milestone['task_stats']['completion'] ?>%</span>
                                            <span class="text-muted"><?= $milestone['task_stats']['closed']['count'] ?>/<?= $milestone['task_stats']['total'] ?> tarefas</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">Sem tarefas</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="?tab=milestone&action=view&id=<?= $milestone['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> Ver
                                        </a>
                                        <a href="?tab=milestone&action=edit&id=<?= $milestone['id'] ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-pencil"></i> Editar
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($milestones)): ?>
                            <tr>
                                <td colspan="6" class="text-center">Nenhuma milestone encontrada.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    
    <?php elseif ($action === 'new'): ?>
        <?php if (isset($users['error'])): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($users['error']) ?>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <form method="post" action="?tab=milestone&action=new">
                        <input type="hidden" name="action" value="create_milestone">
                        
                        <div class="mb-3">
                            <label for="title" class="form-label">Título</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Descrição</label>
                            <textarea class="form-control" id="description" name="description" rows="5"></textarea>
                            <small class="text-muted">A descrição será reformatada quando projetos e protótipos forem adicionados.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="assigned_to" class="form-label">Responsável</label>
                            <select class="form-select" id="assigned_to" name="assigned_to">
                                <option value="">Selecione um responsável</option>
                                <?php foreach ($users as $user): 
                                    // Determinar o nome a exibir - considerando vários formatos possíveis
                                    if (isset($user['firstname']) && isset($user['lastname'])) {
                                        $displayName = $user['firstname'] . ' ' . $user['lastname'];
                                    } elseif (isset($user['name'])) {
                                        $displayName = $user['name'];
                                    } else {
                                        $displayName = 'Usuário #' . $user['id'];
                                    }
                                ?>
                                    <option value="<?= $user['id'] ?>">
                                        <?= htmlspecialchars($displayName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($users)): ?>
                                <div class="text-danger small mt-1">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    Não foi possível carregar a lista de usuários. Verifique a conexão com o Redmine.
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="due_date" class="form-label">Data Limite</label>
                            <input type="date" class="form-control" id="due_date" name="due_date">
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="?tab=milestone&action=list" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Criar Milestone</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    
    <?php elseif ($action === 'edit' || $action === 'view'): ?>
        <?php if (!isset($id) || !$id): ?>
            <div class="alert alert-danger">
                ID da milestone não especificado.
            </div>
        <?php elseif (isset($milestone['error'])): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($milestone['error']) ?>
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Informações da Milestone</h5>
                            <?php if ($action === 'view'): ?>
                                <a href="?tab=milestone&action=edit&id=<?= $id ?>" class="btn btn-sm btn-outline-warning">
                                    <i class="bi bi-pencil"></i> Editar
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-body">
                            <?php if ($action === 'edit'): ?>
                                <form method="post" action="?tab=milestone&action=edit&id=<?= $id ?>">
                                    <input type="hidden" name="action" value="update_milestone">
                                    <input type="hidden" name="issue_id" value="<?= $id ?>">
                                    <input type="hidden" name="existing_description" value="<?= htmlspecialchars($milestone['description'] ?? '') ?>">
                                    
                                    <div class="mb-3">
                                        <label for="title" class="form-label">Título</label>
                                        <input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($milestone['subject']) ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="assigned_to" class="form-label">Responsável</label>
                                        <select class="form-select" id="assigned_to" name="assigned_to">
                                            <option value="">Selecione um responsável</option>
                                            <?php 
                                            // Debug para ver os usuários disponíveis
                                            error_log("Usuários disponíveis para seleção: " . json_encode($users));
                                            
                                            $selectedUserId = isset($milestone['assigned_to']) && isset($milestone['assigned_to']['id']) 
                                                            ? $milestone['assigned_to']['id'] 
                                                            : null;
                                                            
                                            error_log("Usuário selecionado: " . ($selectedUserId ? $selectedUserId : 'nenhum'));
                                            
                                            if (!empty($users)): 
                                                foreach ($users as $user): 
                                                    // Determinar o nome a exibir - considerando vários formatos possíveis
                                                    if (isset($user['firstname']) && isset($user['lastname'])) {
                                                        $displayName = $user['firstname'] . ' ' . $user['lastname'];
                                                    } elseif (isset($user['name'])) {
                                                        $displayName = $user['name'];
                                                    } else {
                                                        $displayName = 'Usuário #' . $user['id'];
                                                    }
                                                    
                                                    $isSelected = $selectedUserId && isset($user['id']) && $selectedUserId == $user['id'];
                                                    
                                                    // Debug para verificar a seleção
                                                    if ($selectedUserId && isset($user['id'])) {
                                                        error_log("Comparando usuário: ID={$user['id']}, Nome={$displayName} => " . 
                                                                ($isSelected ? 'SELECIONADO' : 'não selecionado'));
                                                    }
                                            ?>
                                                <option value="<?= $user['id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($displayName) ?>
                                                </option>
                                            <?php 
                                                endforeach; 
                                            else: 
                                            ?>
                                                <option value="" disabled>Nenhum usuário disponível</option>
                                            <?php endif; ?>
                                        </select>
                                        <?php if (empty($users)): ?>
                                            <div class="text-danger small mt-1">
                                                <i class="bi bi-exclamation-triangle-fill"></i>
                                                Não foi possível carregar a lista de usuários. Verifique a conexão com o Redmine.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="due_date" class="form-label">Data Limite</label>
                                        <input type="date" class="form-control" id="due_date" name="due_date" value="<?= $milestone['due_date'] ?? '' ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="prototypes" class="form-label">Protótipos Associados</label>
                                        <select multiple class="form-select" id="prototypes" name="prototypes[]" size="5">
                                            <?php foreach ($prototypes as $prototype): 
                                                // Verificar se este protótipo está na lista de associados
                                                $isSelected = false;
                                                foreach ($associated['prototypes'] as $associatedPrototype) {
                                                    if (
                                                        // Verificar pelo ID (formato novo)
                                                        (isset($associatedPrototype['id']) && $associatedPrototype['id'] == $prototype['id']) ||
                                                        // Verificar pelo nome (formato legado)
                                                        (is_string($associatedPrototype) && $associatedPrototype == $prototype['name']) ||
                                                        (isset($associatedPrototype['name']) && $associatedPrototype['name'] == $prototype['name'])
                                                    ) {
                                                        $isSelected = true;
                                                        break;
                                                    }
                                                }
                                                
                                                // O valor salvo será um JSON contendo id e name
                                                $prototypeData = json_encode([
                                                    'id' => $prototype['id'],
                                                    'name' => $prototype['name']
                                                ]);
                                            ?>
                                                <option value='<?= htmlspecialchars($prototypeData) ?>' <?= $isSelected ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($prototype['name']) ?> (#<?= $prototype['id'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Segure Ctrl para selecionar múltiplos.</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="projects" class="form-label">Projetos Associados</label>
                                        <select multiple class="form-select" id="projects" name="projects[]" size="5">
                                            <?php foreach ($projects as $project): 
                                                // Verificar se este projeto está na lista de associados
                                                $isSelected = false;
                                                foreach ($associated['projects'] as $associatedProject) {
                                                    if (
                                                        // Verificar pelo ID (formato novo)
                                                        (isset($associatedProject['id']) && $associatedProject['id'] == $project['id']) ||
                                                        // Verificar pelo nome (formato legado)
                                                        (is_string($associatedProject) && $associatedProject == $project['name']) ||
                                                        (isset($associatedProject['name']) && $associatedProject['name'] == $project['name'])
                                                    ) {
                                                        $isSelected = true;
                                                        break;
                                                    }
                                                }
                                                
                                                // O valor salvo será um JSON contendo id e name
                                                $projectData = json_encode([
                                                    'id' => $project['id'],
                                                    'name' => $project['name']
                                                ]);
                                            ?>
                                                <option value='<?= htmlspecialchars($projectData) ?>' <?= $isSelected ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($project['name']) ?> (#<?= $project['id'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Segure Ctrl para selecionar múltiplos.</small>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between">
                                        <a href="?tab=milestone&action=view&id=<?= $id ?>" class="btn btn-secondary">Cancelar</a>
                                        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="mb-3">
                                    <h6 class="fw-bold">ID</h6>
                                    <p>#<?= $milestone['id'] ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <h6 class="fw-bold">Título</h6>
                                    <p><?= htmlspecialchars($milestone['subject']) ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <h6 class="fw-bold">Responsável</h6>
                                    <p>
                                        <?php if (isset($milestone['assigned_to'])): 
                                            // Determinar o nome a exibir - considerando vários formatos possíveis
                                            $assignedUser = $milestone['assigned_to'];
                                            
                                            if (isset($assignedUser['firstname']) && isset($assignedUser['lastname'])) {
                                                $displayName = $assignedUser['firstname'] . ' ' . $assignedUser['lastname'];
                                            } elseif (isset($assignedUser['name'])) {
                                                $displayName = $assignedUser['name'];
                                            } else {
                                                $displayName = 'Usuário #' . $assignedUser['id'];
                                            }
                                        ?>
                                            <span class="d-flex align-items-center">
                                                <i class="bi bi-person-fill text-primary me-2"></i>
                                                <?= htmlspecialchars($displayName) ?>
                                                <span class="badge bg-secondary ms-2">ID: <?= $assignedUser['id'] ?></span>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">
                                                <i class="bi bi-person-x text-muted me-2"></i>
                                                Não atribuído
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="mb-3">
                                    <h6 class="fw-bold">Data Limite</h6>
                                    <?php if (isset($milestone['due_date'])): 
                                        // Calcular dias restantes
                                        $due_date = new DateTime($milestone['due_date']);
                                        $today = new DateTime();
                                        $interval = $today->diff($due_date);
                                        $days_diff = $interval->days;
                                        $is_past = $interval->invert; // 1 se já passou, 0 se é no futuro
                                        
                                        if ($days_diff == 0 && $is_past == 0) {
                                            $deadline_text = 'Hoje';
                                            $deadline_color = 'warning';
                                        } elseif ($is_past) {
                                            $deadline_text = 'Atrasado ' . $days_diff . ' dia' . ($days_diff > 1 ? 's' : '');
                                            $deadline_color = 'danger';
                                        } else {
                                            $deadline_text = $days_diff . ' dia' . ($days_diff > 1 ? 's' : '') . ' restantes';
                                            $deadline_color = 'success';
                                        }
                                    ?>
                                        <p><?= htmlspecialchars($milestone['due_date']) ?></p>
                                        <div class="badge bg-<?= $deadline_color ?> mb-2"><?= $deadline_text ?></div>
                                    <?php else: ?>
                                        <p><span class="text-muted">Não definida</span></p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-3">
                                    <h6 class="fw-bold">Status</h6>
                                    <p>
                                        <span class="badge bg-<?= $milestone['status']['id'] == 1 ? 'primary' : ($milestone['status']['id'] == 2 ? 'warning' : ($milestone['status']['id'] == 5 ? 'success' : 'secondary')) ?>">
                                            <?= htmlspecialchars($milestone['status']['name']) ?>
                                        </span>
                                    </p>
                                </div>
                                
                                <div class="mb-4">
                                    <h6 class="fw-bold">Progresso</h6>
                                    <?php 
                                    // Calcular contagens de tarefas
                                    $tasks = extractTasksFromDescription($milestone['description'] ?? '');
                                    $backlog_count = count($tasks['backlog']);
                                    $in_progress_count = count($tasks['in_progress']);
                                    $paused_count = count($tasks['paused']);
                                    $closed_count = count($tasks['closed']);
                                    $total_count = $backlog_count + $in_progress_count + $paused_count + $closed_count;
                                    
                                    // Calcular percentuais
                                    $backlog_percent = $total_count > 0 ? round(($backlog_count / $total_count) * 100) : 0;
                                    $in_progress_percent = $total_count > 0 ? round(($in_progress_count / $total_count) * 100) : 0;
                                    $paused_percent = $total_count > 0 ? round(($paused_count / $total_count) * 100) : 0;
                                    $closed_percent = $total_count > 0 ? round(($closed_count / $total_count) * 100) : 0;
                                    $completion = $total_count > 0 ? round(($closed_count / $total_count) * 100) : 0;
                                    
                                    if ($total_count > 0):
                                    ?>
                                    <div class="progress mb-2" style="height: 15px;">
                                        <div class="progress-bar bg-primary" role="progressbar" 
                                            style="width: <?= $backlog_percent ?>%;" 
                                            title="Backlog: <?= $backlog_count ?> (<?= $backlog_percent ?>%)">
                                            <?= $backlog_percent > 10 ? $backlog_percent . '%' : '' ?>
                                        </div>
                                        <div class="progress-bar bg-warning" role="progressbar" 
                                            style="width: <?= $in_progress_percent ?>%;" 
                                            title="Em Execução: <?= $in_progress_count ?> (<?= $in_progress_percent ?>%)">
                                            <?= $in_progress_percent > 10 ? $in_progress_percent . '%' : '' ?>
                                        </div>
                                        <div class="progress-bar bg-secondary" role="progressbar" 
                                            style="width: <?= $paused_percent ?>%;" 
                                            title="Pausa: <?= $paused_count ?> (<?= $paused_percent ?>%)">
                                            <?= $paused_percent > 10 ? $paused_percent . '%' : '' ?>
                                        </div>
                                        <div class="progress-bar bg-success" role="progressbar" 
                                            style="width: <?= $closed_percent ?>%;" 
                                            title="Fechado: <?= $closed_count ?> (<?= $closed_percent ?>%)">
                                            <?= $closed_percent > 10 ? $closed_percent . '%' : '' ?>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-3">
                                        <div class="small" title="Taxa de conclusão">
                                            <i class="bi bi-check-circle-fill text-success"></i> 
                                            <span class="fw-bold"><?= $completion ?>% concluído</span>
                                        </div>
                                        <div class="small text-muted">
                                            <?= $closed_count ?> de <?= $total_count ?> tarefas completadas
                                        </div>
                                    </div>
                                    
                                    <div class="row small text-center">
                                        <div class="col">
                                            <div class="card border-0 bg-light">
                                                <div class="card-body py-2">
                                                    <div class="h4 mb-0"><?= $backlog_count ?></div>
                                                    <div class="text-primary">Backlog</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card border-0 bg-light">
                                                <div class="card-body py-2">
                                                    <div class="h4 mb-0"><?= $in_progress_count ?></div>
                                                    <div class="text-warning">Em Execução</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card border-0 bg-light">
                                                <div class="card-body py-2">
                                                    <div class="h4 mb-0"><?= $paused_count ?></div>
                                                    <div class="text-secondary">Pausa</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card border-0 bg-light">
                                                <div class="card-body py-2">
                                                    <div class="h4 mb-0"><?= $closed_count ?></div>
                                                    <div class="text-success">Fechado</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                        <p class="text-muted">Esta milestone não possui tarefas.</p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-3">
                                    <h6 class="fw-bold">Protótipos Associados</h6>
                                    <?php if (!empty($associated['prototypes'])): ?>
                                        <ul class="list-group">
                                            <?php foreach ($associated['prototypes'] as $prototype): 
                                                // Obter nome e ID do protótipo (formatos novo e legado)
                                                if (is_array($prototype) && isset($prototype['name'])) {
                                                    $prototypeName = $prototype['name'];
                                                    $prototypeId = isset($prototype['id']) ? $prototype['id'] : 'N/A';
                                                } else {
                                                    $prototypeName = $prototype;
                                                    $prototypeId = 'N/A';
                                                }
                                                
                                                // Procurar o objeto protótipo completo para obter o identificador
                                                $prototypeIdentifier = null;
                                                foreach ($prototypes as $p) {
                                                    if (
                                                        ($prototypeId !== 'N/A' && $p['id'] == $prototypeId) || 
                                                        $p['name'] == $prototypeName
                                                    ) {
                                                        $prototypeIdentifier = $p['identifier'] ?? null;
                                                        break;
                                                    }
                                                }
                                            ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <?= htmlspecialchars($prototypeName) ?>
                                                    <span class="badge bg-secondary">
                                                        ID: <?= $prototypeId ?>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="text-muted">Nenhum protótipo associado.</p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-3">
                                    <h6 class="fw-bold">Projetos Associados</h6>
                                    <?php if (!empty($associated['projects'])): ?>
                                        <ul class="list-group">
                                            <?php foreach ($associated['projects'] as $project): 
                                                // Obter nome e ID do projeto (formatos novo e legado)
                                                if (is_array($project) && isset($project['name'])) {
                                                    $projectName = $project['name'];
                                                    $projectId = isset($project['id']) ? $project['id'] : 'N/A';
                                                } else {
                                                    $projectName = $project;
                                                    $projectId = 'N/A';
                                                }
                                                
                                                // Procurar o objeto projeto completo para obter o identificador
                                                $projectIdentifier = null;
                                                foreach ($projects as $p) {
                                                    if (
                                                        ($projectId !== 'N/A' && $p['id'] == $projectId) || 
                                                        $p['name'] == $projectName
                                                    ) {
                                                        $projectIdentifier = $p['identifier'] ?? null;
                                                        break;
                                                    }
                                                }
                                            ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <?= htmlspecialchars($projectName) ?>
                                                    <span class="badge bg-secondary">
                                                        ID: <?= $projectId ?>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="text-muted">Nenhum projeto associado.</p>
                                    <?php endif; ?>
                                </div>
                                
                                <a href="?tab=milestone&action=list" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Voltar para Lista
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-8">
                    <!-- Seção de Tarefas (exibida tanto em modo de edição quanto de visualização) -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Tarefas da Milestone</h5>
                                <span class="badge bg-info text-white" data-bs-toggle="tooltip" data-bs-placement="left" title="Arraste e solte tarefas entre colunas para mudar seu status">
                                    <i class="bi bi-question-circle"></i> Dica: Drag & Drop
                                </span>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="project-selector" class="form-label">Selecione um Projeto/Protótipo</label>
                                    <select class="form-select" id="project-selector">
                                        <option value="">Selecione...</option>
                                        <?php 
                                        // Depuração para verificar os dados disponíveis
                                        error_log("Projetos disponíveis: " . json_encode(array_column($projects, 'name')));
                                        error_log("Protótipos disponíveis: " . json_encode(array_column($prototypes, 'name')));
                                        error_log("Projetos associados: " . json_encode($associated['projects']));
                                        error_log("Protótipos associados: " . json_encode($associated['prototypes']));
                                        
                                        // Inicializar array para projetos associados
                                        $allProjects = [];
                                        
                                        // 1. Adicionar protótipos associados ao dropdown
                                        foreach ($prototypes as $prototype) {
                                            $isAssociated = false;
                                            
                                            // Verificar se este protótipo está associado
                                            foreach ($associated['prototypes'] as $assocPrototype) {
                                                // Verificar por ID (formato novo) ou nome (formato legado)
                                                if (
                                                    (is_array($assocPrototype) && isset($assocPrototype['id']) && $assocPrototype['id'] == $prototype['id']) ||
                                                    (is_array($assocPrototype) && isset($assocPrototype['name']) && $assocPrototype['name'] == $prototype['name']) ||
                                                    (is_string($assocPrototype) && $assocPrototype == $prototype['name'])
                                                ) {
                                                    $isAssociated = true;
                                                    break;
                                                }
                                            }
                                            
                                            if ($isAssociated) {
                                                $allProjects[$prototype['id']] = $prototype['name'] . ' (Protótipo)';
                                                error_log("Adicionando protótipo: " . $prototype['name'] . " (ID: " . $prototype['id'] . ")");
                                            }
                                        }
                                        
                                        // 2. Adicionar projetos associados ao dropdown
                                        foreach ($projects as $project) {
                                            $isAssociated = false;
                                            
                                            // Verificar se este projeto está associado
                                            foreach ($associated['projects'] as $assocProject) {
                                                // Verificar por ID (formato novo) ou nome (formato legado)
                                                if (
                                                    (is_array($assocProject) && isset($assocProject['id']) && $assocProject['id'] == $project['id']) ||
                                                    (is_array($assocProject) && isset($assocProject['name']) && $assocProject['name'] == $project['name']) ||
                                                    (is_string($assocProject) && $assocProject == $project['name'])
                                                ) {
                                                    $isAssociated = true;
                                                    break;
                                                }
                                            }
                                            
                                            if ($isAssociated) {
                                                $allProjects[$project['id']] = $project['name'] . ' (Projeto)';
                                                error_log("Adicionando projeto: " . $project['name'] . " (ID: " . $project['id'] . ")");
                                            }
                                        }
                                        
                                        // Exibir o número de projetos/protótipos encontrados
                                        error_log("Total de projetos/protótipos associados encontrados: " . count($allProjects));
                                        
                                        // 3. Renderizar as opções
                                        foreach ($allProjects as $projectId => $projectName):
                                        ?>
                                            <option value="<?= $projectId ?>">
                                                <?= htmlspecialchars($projectName) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <?php if (empty($allProjects)): ?>
                                    <div class="alert alert-warning mt-2 small">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                        Nenhum projeto ou protótipo associado encontrado. Adicione projetos/protótipos à milestone na seção de edição.
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="task-selector" class="form-label">Selecione uma Tarefa</label>
                                    <select class="form-select" id="task-selector" disabled>
                                        <option value="">Selecione um projeto primeiro</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="d-grid mb-4">
                                <button id="add-task-btn" class="btn btn-primary" disabled>
                                    <i class="bi bi-plus-circle"></i> Adicionar Tarefa
                                </button>
                            </div>
                            
                            <script>
                                // Configurar objeto global para armazenar as tarefas por projeto
                                window.projectTasks = <?= json_encode($projectIssues) ?>;
                                console.log("Tarefas carregadas por projeto:", window.projectTasks);
                            </script>
                            
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <div class="task-column" data-status="backlog">
                                        <div class="card bg-light">
                                            <div class="card-header bg-primary text-white">
                                                <h6 class="mb-0">Backlog</h6>
                                            </div>
                                            <div class="card-body p-2">
                                                <div class="task-list" id="backlog-tasks">
                                                    <?php 
                                                    error_log("Tarefas no backlog para exibição: " . json_encode($tasks['backlog']));
                                                    
                                                    // Remove duplicates based on ID
                                                    $uniqueTasks = [];
                                                    $uniqueTaskIds = [];
                                                    
                                                    if (!empty($tasks['backlog'])) {
                                                        foreach ($tasks['backlog'] as $task) {
                                                            if (!in_array($task['id'], $uniqueTaskIds)) {
                                                                $uniqueTasks[] = $task;
                                                                $uniqueTaskIds[] = $task['id'];
                                                            }
                                                        }
                                                    }
                                                    
                                                    if (!empty($uniqueTasks)): 
                                                        foreach ($uniqueTasks as $task): 
                                                    ?>
                                                        <div class="card mb-2 task-card" data-task-id="<?= $task['id'] ?>">
                                                            <div class="card-body p-2">
                                                                <p class="mb-1">
                                                                    <small class="text-muted">#<?= $task['id'] ?></small>
                                                                    <?php if (isset($task['project']) && $task['project']): ?>
                                                                        <span class="badge bg-info"><?= htmlspecialchars($task['project']['name']) ?></span>
                                                                    <?php endif; ?>
                                                                </p>
                                                                <h6 class="card-title mb-0 task-title"><?= htmlspecialchars($task['title']) ?></h6>
                                                                <?php if (isset($task['assignee']) && $task['assignee']): ?>
                                                                    <div class="mt-1 small">
                                                                        <i class="bi bi-person-fill"></i> 
                                                                        <?= htmlspecialchars($task['assignee']['name']) ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <div class="mt-2 text-end">
                                                                    <button class="btn btn-sm btn-outline-danger remove-task-btn" title="Remover da milestone">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                    <a href="<?= $BASE_URL ?>/redmine/issues/<?= $task['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-1" title="Ver no Redmine">
                                                                        <i class="bi bi-box-arrow-up-right"></i>
                                                                    </a>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php 
                                                        endforeach; 
                                                    else:
                                                    ?>
                                                        <div class="text-muted text-center p-3">
                                                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                                                            Nenhuma tarefa no backlog
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-3 mb-3">
                                    <div class="task-column" data-status="in_progress">
                                        <div class="card bg-light">
                                            <div class="card-header bg-warning text-dark">
                                                <h6 class="mb-0">Em Execução</h6>
                                            </div>
                                            <div class="card-body p-2">
                                                <div class="task-list" id="in-progress-tasks">
                                                    <?php 
                                                    // Remove duplicates based on ID
                                                    $uniqueTasks = [];
                                                    $uniqueTaskIds = [];
                                                    
                                                    if (!empty($tasks['in_progress'])) {
                                                        foreach ($tasks['in_progress'] as $task) {
                                                            if (!in_array($task['id'], $uniqueTaskIds)) {
                                                                $uniqueTasks[] = $task;
                                                                $uniqueTaskIds[] = $task['id'];
                                                            }
                                                        }
                                                    }
                                                    
                                                    if (!empty($uniqueTasks)): 
                                                        foreach ($uniqueTasks as $task): 
                                                    ?>
                                                        <div class="card mb-2 task-card" data-task-id="<?= $task['id'] ?>">
                                                            <div class="card-body p-2">
                                                                <p class="mb-1">
                                                                    <small class="text-muted">#<?= $task['id'] ?></small>
                                                                </p>
                                                                <h6 class="card-title mb-0 task-title"><?= htmlspecialchars($task['title']) ?></h6>
                                                                <div class="mt-2 text-end">
                                                                    <button class="btn btn-sm btn-outline-danger remove-task-btn" title="Remover da milestone">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                    <a href="<?= $BASE_URL ?>/redmine/issues/<?= $task['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-1" title="Ver no Redmine">
                                                                        <i class="bi bi-box-arrow-up-right"></i>
                                                                    </a>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php 
                                                        endforeach; 
                                                    else:
                                                    ?>
                                                        <div class="text-muted text-center p-3">
                                                            <i class="bi bi-calendar-check fs-4 d-block mb-2"></i>
                                                            Nenhuma tarefa em execução
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-3 mb-3">
                                    <div class="task-column" data-status="paused">
                                        <div class="card bg-light">
                                            <div class="card-header bg-secondary text-white">
                                                <h6 class="mb-0">Pausa</h6>
                                            </div>
                                            <div class="card-body p-2">
                                                <div class="task-list" id="paused-tasks">
                                                    <?php 
                                                    // Remove duplicates based on ID
                                                    $uniqueTasks = [];
                                                    $uniqueTaskIds = [];
                                                    
                                                    if (!empty($tasks['paused'])) {
                                                        foreach ($tasks['paused'] as $task) {
                                                            if (!in_array($task['id'], $uniqueTaskIds)) {
                                                                $uniqueTasks[] = $task;
                                                                $uniqueTaskIds[] = $task['id'];
                                                            }
                                                        }
                                                    }
                                                    
                                                    if (!empty($uniqueTasks)): 
                                                        foreach ($uniqueTasks as $task): 
                                                    ?>
                                                        <div class="card mb-2 task-card" data-task-id="<?= $task['id'] ?>">
                                                            <div class="card-body p-2">
                                                                <p class="mb-1">
                                                                    <small class="text-muted">#<?= $task['id'] ?></small>
                                                                </p>
                                                                <h6 class="card-title mb-0 task-title"><?= htmlspecialchars($task['title']) ?></h6>
                                                                <div class="mt-2 text-end">
                                                                    <button class="btn btn-sm btn-outline-danger remove-task-btn" title="Remover da milestone">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                    <a href="<?= $BASE_URL ?>/redmine/issues/<?= $task['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-1" title="Ver no Redmine">
                                                                        <i class="bi bi-box-arrow-up-right"></i>
                                                                    </a>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php 
                                                        endforeach; 
                                                    else:
                                                    ?>
                                                        <div class="text-muted text-center p-3">
                                                            <i class="bi bi-pause-circle fs-4 d-block mb-2"></i>
                                                            Nenhuma tarefa em pausa
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-3 mb-3">
                                    <div class="task-column" data-status="closed">
                                        <div class="card bg-light">
                                            <div class="card-header bg-success text-white">
                                                <h6 class="mb-0">Fechado</h6>
                                            </div>
                                            <div class="card-body p-2">
                                                <div class="task-list" id="closed-tasks">
                                                    <?php 
                                                    // Remove duplicates based on ID
                                                    $uniqueTasks = [];
                                                    $uniqueTaskIds = [];
                                                    
                                                    if (!empty($tasks['closed'])) {
                                                        foreach ($tasks['closed'] as $task) {
                                                            if (!in_array($task['id'], $uniqueTaskIds)) {
                                                                $uniqueTasks[] = $task;
                                                                $uniqueTaskIds[] = $task['id'];
                                                            }
                                                        }
                                                    }
                                                    
                                                    if (!empty($uniqueTasks)): 
                                                        foreach ($uniqueTasks as $task): 
                                                    ?>
                                                        <div class="card mb-2 task-card" data-task-id="<?= $task['id'] ?>">
                                                            <div class="card-body p-2">
                                                                <p class="mb-1">
                                                                    <small class="text-muted">#<?= $task['id'] ?></small>
                                                                </p>
                                                                <h6 class="card-title mb-0 task-title"><?= htmlspecialchars($task['title']) ?></h6>
                                                                <div class="mt-2 text-end">
                                                                    <button class="btn btn-sm btn-outline-danger remove-task-btn" title="Remover da milestone">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                    <a href="<?= $BASE_URL ?>/redmine/issues/<?= $task['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-1" title="Ver no Redmine">
                                                                        <i class="bi bi-box-arrow-up-right"></i>
                                                                    </a>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php 
                                                        endforeach; 
                                                    else:
                                                    ?>
                                                        <div class="text-muted text-center p-3">
                                                            <i class="bi bi-check-circle fs-4 d-block mb-2"></i>
                                                            Nenhuma tarefa fechada
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Links para Redmine (somente em modo de visualização) -->
                    <?php if ($action === 'view'): ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Links do Redmine</h5>
                            </div>
                            
                            <div class="card-body">
                                <div class="d-grid">
                                    <a href="<?= $BASE_URL ?>/redmine/issues/<?= $milestone['id'] ?>" target="_blank" class="btn btn-outline-primary mb-2">
                                        <i class="bi bi-box-arrow-up-right"></i> Abrir Milestone no Redmine
                                    </a>
                                    
                                    <?php if (!empty($associated['prototypes'])): ?>
                                        <h6 class="mt-3">Protótipos:</h6>
                                        <div class="list-group">
                                            <?php foreach ($associated['prototypes'] as $prototype): 
                                                // Obter nome e ID do protótipo (formatos novo e legado)
                                                if (is_array($prototype) && isset($prototype['name'])) {
                                                    $prototypeName = $prototype['name'];
                                                    $prototypeId = isset($prototype['id']) ? $prototype['id'] : null;
                                                } else {
                                                    $prototypeName = $prototype;
                                                    $prototypeId = null;
                                                }
                                                
                                                // Procurar o objeto protótipo completo para obter o identificador
                                                $foundPrototype = null;
                                                foreach ($prototypes as $p) {
                                                    if (
                                                        ($prototypeId !== null && $p['id'] == $prototypeId) || 
                                                        $p['name'] == $prototypeName
                                                    ) {
                                                        $foundPrototype = $p;
                                                        break;
                                                    }
                                                }
                                                
                                                if ($foundPrototype):
                                            ?>
                                                <a href="<?= $BASE_URL ?>/redmine/projects/<?= $foundPrototype['identifier'] ?>" 
                                                   target="_blank" 
                                                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                                    <?= htmlspecialchars($prototypeName) ?>
                                                    <span class="badge bg-secondary rounded-pill">
                                                        ID: <?= $foundPrototype['id'] ?>
                                                    </span>
                                                </a>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($associated['projects'])): ?>
                                        <h6 class="mt-3">Projetos:</h6>
                                        <div class="list-group">
                                            <?php foreach ($associated['projects'] as $project): 
                                                // Obter nome e ID do projeto (formatos novo e legado)
                                                if (is_array($project) && isset($project['name'])) {
                                                    $projectName = $project['name'];
                                                    $projectId = isset($project['id']) ? $project['id'] : null;
                                                } else {
                                                    $projectName = $project;
                                                    $projectId = null;
                                                }
                                                
                                                // Procurar o objeto projeto completo para obter o identificador
                                                $foundProject = null;
                                                foreach ($projects as $p) {
                                                    if (
                                                        ($projectId !== null && $p['id'] == $projectId) || 
                                                        $p['name'] == $projectName
                                                    ) {
                                                        $foundProject = $p;
                                                        break;
                                                    }
                                                }
                                                
                                                if ($foundProject):
                                            ?>
                                                <a href="<?= $BASE_URL ?>/redmine/projects/<?= $foundProject['identifier'] ?>" 
                                                   target="_blank" 
                                                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                                    <?= htmlspecialchars($projectName) ?>
                                                    <span class="badge bg-secondary rounded-pill">
                                                        ID: <?= $foundProject['id'] ?>
                                                    </span>
                                                </a>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Adicionar dependências para drag and drop -->
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.css" />
            <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
            
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Inicializar tooltips do Bootstrap
                    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                    tooltipTriggerList.map(function (tooltipTriggerEl) {
                        return new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                    
                    const milestoneId = '<?= $id ?? "" ?>';
                    const projectSelector = document.getElementById('project-selector');
                    const taskSelector = document.getElementById('task-selector');
                    const addTaskBtn = document.getElementById('add-task-btn');
                    
                    // Armazenar as tarefas de todos os projetos/protótipos
                    const allProjectIssues = <?= !empty($projectIssues) ? json_encode($projectIssues) : '{}' ?>;
                    
                    // Logar informações para diagnóstico
                    console.log("Projetos/protótipos disponíveis:", Object.keys(allProjectIssues).length);
                    Object.keys(allProjectIssues).forEach(projectId => {
                        console.log(`Projeto ID ${projectId}: ${allProjectIssues[projectId].length} tarefas`);
                    });
                    
                    // Configurar drag and drop para cada coluna
                    const taskColumns = document.querySelectorAll('.task-list');
                    taskColumns.forEach(column => {
                        Sortable.create(column, {
                            group: 'tasks',
                            animation: 150,
                            onEnd: function(evt) {
                                const taskId = evt.item.dataset.taskId;
                                const newStatus = evt.to.closest('.task-column').dataset.status;
                                
                                // Atualizar status da tarefa via AJAX
                                updateTaskStatus(taskId, newStatus);
                            }
                        });
                    });
                    
                    // Atualizar o combobox de tarefas quando um projeto é selecionado
                    projectSelector.addEventListener('change', function() {
                        const projectId = this.value;
                        taskSelector.innerHTML = '';
                        
                        if (projectId) {
                            console.log(`Projeto selecionado: ${projectId}`);
                            
                            // Habilitar o seletor de tarefas
                            taskSelector.disabled = false;
                            
                            // Preencher com as tarefas do projeto/protótipo selecionado
                            if (allProjectIssues[projectId] && allProjectIssues[projectId].length > 0) {
                                console.log(`Carregando ${allProjectIssues[projectId].length} tarefas para o projeto ${projectId}`);
                                taskSelector.appendChild(new Option('Selecione uma tarefa...', ''));
                                
                                allProjectIssues[projectId].forEach(issue => {
                                    // Verificar se a tarefa já está na milestone antes de adicionar
                                    const isAlreadyAdded = isMilestoneTask(issue.id);
                                    
                                    if (!isAlreadyAdded) {
                                        const option = new Option(`#${issue.id} - ${issue.subject}`, issue.id);
                                        taskSelector.appendChild(option);
                                    }
                                });
                                
                                if (taskSelector.options.length <= 1) {
                                    taskSelector.innerHTML = '<option value="">Todas as tarefas já estão adicionadas</option>';
                                }
                            } else {
                                console.log(`Nenhuma tarefa disponível para o projeto ${projectId}`);
                                taskSelector.innerHTML = '<option value="">Nenhuma tarefa disponível</option>';
                            }
                        } else {
                            taskSelector.innerHTML = '<option value="">Selecione um projeto primeiro</option>';
                            taskSelector.disabled = true;
                        }
                        
                        // Habilitar/desabilitar botão de adicionar
                        updateAddTaskButton();
                    });
                    
                    // Atualizar estado do botão de adicionar quando uma tarefa é selecionada
                    taskSelector.addEventListener('change', function() {
                        updateAddTaskButton();
                    });
                    
                    // Ação do botão de adicionar tarefa
                    addTaskBtn.addEventListener('click', function() {
                        const taskId = taskSelector.value;
                        
                        if (!taskId) return;
                        
                        // Mostrar indicador de carregamento
                        addTaskBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Adicionando...';
                        addTaskBtn.disabled = true;
                        
                        // Adicionar tarefa à milestone via AJAX
                        fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new URLSearchParams({
                                'action': 'add_task',
                                'milestone_id': milestoneId,
                                'task_id': taskId
                            })
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Erro na resposta do servidor: ' + response.status);
                            }
                            
                            // Check if content type is JSON
                            const contentType = response.headers.get('content-type');
                            if (!contentType || !contentType.includes('application/json')) {
                                throw new Error('Resposta do servidor não é JSON válido. Tipo de conteúdo: ' + (contentType || 'desconhecido'));
                            }
                            
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) {
                                // Encontrar os detalhes completos da tarefa (incluindo projeto e assignee)
                                const selectedProjectId = projectSelector.value;
                                let taskDetails = {
                                    id: data.task.id,
                                    title: data.task.title,
                                    project: null,
                                    assignee: null
                                };
                                
                                if (allProjectIssues[selectedProjectId]) {
                                    const fullTaskInfo = allProjectIssues[selectedProjectId].find(t => t.id == data.task.id);
                                    if (fullTaskInfo) {
                                        // Adicionar informações do projeto
                                        taskDetails.project = {
                                            id: fullTaskInfo.project ? fullTaskInfo.project.id : null,
                                            name: fullTaskInfo.project ? fullTaskInfo.project.name : null
                                        };
                                        
                                        // Adicionar informações do responsável
                                        if (fullTaskInfo.assigned_to) {
                                            taskDetails.assignee = {
                                                id: fullTaskInfo.assigned_to.id,
                                                name: fullTaskInfo.assigned_to.name || `Usuário #${fullTaskInfo.assigned_to.id}`
                                            };
                                        }
                                    }
                                }
                                
                                // Adicionar visualmente a tarefa ao backlog
                                addTaskToColumn(taskDetails, 'backlog-tasks');
                                
                                // Remover a opção do combobox
                                const selectedOption = taskSelector.options[taskSelector.selectedIndex];
                                taskSelector.removeChild(selectedOption);
                                
                                if (taskSelector.options.length <= 0) {
                                    taskSelector.innerHTML = '<option value="">Todas as tarefas já estão adicionadas</option>';
                                }
                                
                                // Resetar o combobox
                                taskSelector.value = '';
                                updateAddTaskButton();
                            } else {
                                alert('Erro ao adicionar tarefa: ' + (data.message || 'Erro desconhecido'));
                            }
                        })
                        .catch(error => {
                            console.error('Erro:', error);
                            alert('Erro de conexão ao adicionar tarefa: ' + error.message);
                        })
                        .finally(() => {
                            // Restaurar o botão
                            addTaskBtn.innerHTML = '<i class="bi bi-plus-circle"></i> Adicionar Tarefa';
                            addTaskBtn.disabled = false;
                        });
                    });
                    
                    // Delegar evento de remoção de tarefa
                    document.addEventListener('click', function(e) {
                        if (e.target.closest('.remove-task-btn')) {
                            const btn = e.target.closest('.remove-task-btn');
                            const taskCard = btn.closest('.task-card');
                            const taskId = taskCard.dataset.taskId;
                            
                            if (confirm('Tem certeza que deseja remover esta tarefa da milestone?')) {
                                // Mudar aspecto do botão para indicar processamento
                                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                                btn.disabled = true;
                                
                                // Remover tarefa da milestone via AJAX
                                fetch(window.location.href, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                        'X-Requested-With': 'XMLHttpRequest'
                                    },
                                    body: new URLSearchParams({
                                        'action': 'remove_task',
                                        'milestone_id': milestoneId,
                                        'task_id': taskId
                                    })
                                })
                                .then(response => {
                                    if (!response.ok) {
                                        throw new Error('Erro na resposta do servidor: ' + response.status);
                                    }
                                    
                                    // Check if content type is JSON
                                    const contentType = response.headers.get('content-type');
                                    if (!contentType || !contentType.includes('application/json')) {
                                        throw new Error('Resposta do servidor não é JSON válido. Tipo de conteúdo: ' + (contentType || 'desconhecido'));
                                    }
                                    
                                    return response.json();
                                })
                                .then(data => {
                                    if (data.success) {
                                        // Remover visualmente a tarefa com uma animação suave
                                        taskCard.style.opacity = '0';
                                        taskCard.style.transform = 'translateX(20px)';
                                        taskCard.style.transition = 'all 0.3s ease';
                                        
                                        setTimeout(() => {
                                            taskCard.remove();
                                        }, 300);
                                        
                                        // Atualizar o seletor de tarefas se o projeto atual é o mesmo da tarefa removida
                                        const selectedProjectId = projectSelector.value;
                                        if (selectedProjectId && allProjectIssues[selectedProjectId]) {
                                            const taskInfo = allProjectIssues[selectedProjectId].find(t => t.id == taskId);
                                            if (taskInfo) {
                                                const option = new Option(`#${taskInfo.id} - ${taskInfo.subject}`, taskInfo.id);
                                                taskSelector.appendChild(option);
                                                
                                                // Se é a primeira opção a ser adicionada, limpar a mensagem "Todas as tarefas já estão adicionadas"
                                                if (taskSelector.options.length === 1 && taskSelector.options[0].value === '') {
                                                    taskSelector.innerHTML = '';
                                                    taskSelector.appendChild(new Option('Selecione uma tarefa...', ''));
                                                    taskSelector.appendChild(option);
                                                }
                                            }
                                        }
                                    } else {
                                        alert('Erro ao remover tarefa: ' + (data.message || 'Erro desconhecido'));
                                        // Restaurar o botão
                                        btn.innerHTML = '<i class="bi bi-trash"></i>';
                                        btn.disabled = false;
                                    }
                                })
                                .catch(error => {
                                    console.error('Erro:', error);
                                    alert('Erro de conexão ao remover tarefa: ' + error.message);
                                    // Restaurar o botão
                                    btn.innerHTML = '<i class="bi bi-trash"></i>';
                                    btn.disabled = false;
                                });
                            }
                        }
                    });
                    
                    // Função para verificar se uma tarefa já está na milestone
                    function isMilestoneTask(taskId) {
                        const allTaskCards = document.querySelectorAll('.task-card');
                        for (let i = 0; i < allTaskCards.length; i++) {
                            if (allTaskCards[i].dataset.taskId == taskId) {
                                return true;
                            }
                        }
                        return false;
                    }
                    
                    // Função para atualizar o estado do botão de adicionar
                    function updateAddTaskButton() {
                        addTaskBtn.disabled = !taskSelector.value;
                    }
                                        
                    // Função para adicionar uma tarefa visualmente a uma coluna
                    function addTaskToColumn(task, columnId) {
                        const column = document.getElementById(columnId);
                        
                        // Criar o HTML da tarefa
                        const taskCard = document.createElement('div');
                        taskCard.className = 'card mb-2 task-card';
                        taskCard.dataset.taskId = task.id;
                        
                        // Preparar o HTML para projeto e assignee
                        let projectHtml = '';
                        if (task.project && task.project.name) {
                            projectHtml = `<span class="badge bg-info">${task.project.name}</span>`;
                        }
                        
                        let assigneeHtml = '';
                        if (task.assignee && task.assignee.name) {
                            assigneeHtml = `
                                <div class="mt-1 small">
                                    <i class="bi bi-person-fill"></i> 
                                    ${task.assignee.name}
                                </div>
                            `;
                        }
                        
                        taskCard.innerHTML = `
                            <div class="card-body p-2">
                                <p class="mb-1">
                                    <small class="text-muted">#${task.id}</small>
                                    ${projectHtml}
                                </p>
                                <h6 class="card-title mb-0 task-title">${task.title}</h6>
                                ${assigneeHtml}
                                <div class="mt-2 text-end">
                                    <button class="btn btn-sm btn-outline-danger remove-task-btn" title="Remover da milestone">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <a href="<?= $BASE_URL ?>/redmine/issues/${task.id}" target="_blank" class="btn btn-sm btn-outline-secondary ms-1" title="Ver no Redmine">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                </div>
                            </div>
                        `;
                        
                        column.appendChild(taskCard);
                    }
                    
                    // Função para atualizar o status de uma tarefa
                    function updateTaskStatus(taskId, newStatus) {
                        // Adicionar classe visual para mostrar que a tarefa está sendo atualizada
                        const taskElem = document.querySelector(`.task-card[data-task-id="${taskId}"]`);
                        if (taskElem) {
                            taskElem.classList.add('bg-light');
                            taskElem.style.opacity = '0.7';
                        }
                        
                        // Atualizar o status da tarefa via AJAX
                        return fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new URLSearchParams({
                                'action': 'move_task',
                                'milestone_id': milestoneId,
                                'task_id': taskId,
                                'new_status': newStatus
                            })
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Erro na resposta do servidor: ' + response.status);
                            }
                            
                            // Check if content type is JSON
                            const contentType = response.headers.get('content-type');
                            if (!contentType || !contentType.includes('application/json')) {
                                throw new Error('Resposta do servidor não é JSON válido. Tipo de conteúdo: ' + (contentType || 'desconhecido'));
                            }
                            
                            return response.json();
                        })
                        .then(data => {
                            if (!data.success) {
                                throw new Error(data.message || 'Erro desconhecido');
                            }
                            
                            // Restaurar a aparência da tarefa
                            if (taskElem) {
                                taskElem.classList.remove('bg-light');
                                taskElem.style.opacity = '1';
                            }
                            
                            return data;
                        })
                        .catch(error => {
                            console.error('Erro:', error);
                            alert('Erro ao mover tarefa: ' + error.message);
                            
                            // Restaurar a aparência da tarefa
                            if (taskElem) {
                                taskElem.classList.remove('bg-light');
                                taskElem.style.opacity = '1';
                            }
                            
                            // Recarregar a página para garantir que a interface está sincronizada
                            window.location.reload();
                            throw error;
                        });
                    }
                });
            </script>
            
            <!-- Adicionar CSS customizado para a interface de arraste e solte -->
            <style>
                .task-column {
                    height: 100%;
                }
                
                .task-list {
                    min-height: 300px;
                    padding: 8px;
                    overflow-y: auto;
                    max-height: 500px;
                }
                
                .task-card {
                    cursor: grab;
                    transition: all 0.2s ease;
                    border-left: 4px solid #aaa;
                }
                
                .task-card:hover {
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                }
                
                /* Cores para as bordas dos cartões por coluna */
                #backlog-tasks .task-card {
                    border-left-color: #0d6efd;
                }
                
                #in-progress-tasks .task-card {
                    border-left-color: #ffc107;
                }
                
                #paused-tasks .task-card {
                    border-left-color: #6c757d;
                }
                
                #closed-tasks .task-card {
                    border-left-color: #198754;
                }
                
                /* Estilo para quando está sendo arrastado */
                .sortable-ghost {
                    opacity: 0.4;
                }
                
                .sortable-drag {
                    opacity: 0.9;
                    transform: rotate(2deg);
                    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
                }
                
                .task-title {
                    word-break: break-word;
                }
                
                /* Estilo para destacar uma área de soltar válida */
                .sortable-over {
                    background-color: #e9ecef;
                    border-radius: 5px;
                }
                .task-card .badge {
                    display: inline-block;
                    margin-left: 5px;
                    font-size: 0.7em;
                    vertical-align: middle;
                }

                .task-card .card-body {
                    padding: 0.75rem;
                }

                .task-card .assignee {
                    font-size: 0.8em;
                    color: #6c757d;
                    margin-top: 5px;
                    display: flex;
                    align-items: center;
                }

                .task-card .assignee i {
                    margin-right: 3px;
                }
            </style>
        <?php endif; ?>
    <?php endif; ?>
</div>