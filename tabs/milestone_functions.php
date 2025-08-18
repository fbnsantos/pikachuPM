<?php


// Funções de utilidade para API Redmine
function callRedmineAPI($endpoint, $method = 'GET', $data = null) {
    global $API_KEY, $BASE_URL;
    
    $url = $BASE_URL . '/redmine/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    
    $headers = [
        'X-Redmine-API-Key: ' . $API_KEY,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout de 30 segundos
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Desativar verificação SSL em ambiente de desenvolvimento
    
    // Adicionar informações de debug
    $debugInfo = "API Request: $method $url";
    if ($data) {
        $debugInfo .= "\nData: " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    error_log($debugInfo);
    
    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            // Garantir que estamos enviando JSON corretamente formatado
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($jsonData === false) {
                error_log("Erro ao codificar JSON: " . json_last_error_msg());
                return ['error' => 'Erro ao codificar dados para API: ' . json_last_error_msg()];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            
            // Log da requisição
            error_log("Request payload: $jsonData");
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $errorMsg = 'Erro curl na chamada à API Redmine: ' . curl_error($ch) . ' (código: ' . curl_errno($ch) . ')';
        error_log($errorMsg);
        curl_close($ch);
        return ['error' => $errorMsg, 'code' => curl_errno($ch)];
    }
    
    curl_close($ch);
    
    // Log da resposta
    $responseForLog = substr($response, 0, 500); // Limitar tamanho do log
    if (strlen($response) > 500) {
        $responseForLog .= '... [truncado]';
    }
    error_log("API Response ($httpCode): $responseForLog");
    
    if ($httpCode >= 200 && $httpCode < 300) {
        // Verificar se a resposta é vazia
        if (empty($response)) {
            return []; // Algumas operações de sucesso retornam um corpo vazio
        }
        
        // Tentar decodificar a resposta JSON
        $decodedResponse = json_decode($response, true);
        $jsonError = json_last_error();
        
        if ($jsonError !== JSON_ERROR_NONE) {
            $errorMsg = 'Falha ao decodificar resposta JSON: ' . json_last_error_msg();
            error_log($errorMsg);
            error_log("Resposta bruta: " . $response);
            return [
                'error' => $errorMsg,
                'raw_response' => $response,
                'json_error_code' => $jsonError
            ];
        }
        
        return $decodedResponse;
    } else {
        $errorMsg = "API Redmine retornou código HTTP: $httpCode - $response";
        error_log($errorMsg);
        
        // Tentar decodificar a resposta de erro, se for JSON
        $decodedError = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $errorDetails = isset($decodedError['errors']) ? implode(", ", $decodedError['errors']) : "Erro desconhecido";
            return [
                'error' => "API Redmine retornou erro: $httpCode - $errorDetails",
                'http_code' => $httpCode,
                'decoded_response' => $decodedError
            ];
        }
        
        return [
            'error' => "API Redmine retornou erro: $httpCode",
            'response' => $response,
            'http_code' => $httpCode
        ];
    }
}

// Obter IDs dos projetos principais
function getMainProjectIds() {
    // Obter IDs dos projetos principais (tribemilestone, tribeprototypes, tribeprojects)
    $projects = callRedmineAPI('/projects.json?limit=100');
    
    $mainProjects = [
        'milestone_id' => null,
        'prototypes_id' => null,
        'projects_id' => null
    ];
    
    if (isset($projects['projects'])) {
        foreach ($projects['projects'] as $project) {
            if ($project['identifier'] === 'tribemilestone') {
                $mainProjects['milestone_id'] = $project['id'];
            } elseif ($project['identifier'] === 'tribeprototypes') {
                $mainProjects['prototypes_id'] = $project['id'];
            } elseif ($project['identifier'] === 'tribeprojects') {
                $mainProjects['projects_id'] = $project['id'];
            }
        }
    }
    
    return $mainProjects;
}

// Obter todas as milestones (issues do projeto tribemilestone)
function getMilestones() {
    $mainProjects = getMainProjectIds();
    
    if (!$mainProjects['milestone_id']) {
        return ['error' => 'Projeto de milestones não encontrado'];
    }
    
    $issues = callRedmineAPI('/issues.json?project_id=' . $mainProjects['milestone_id'] . '&status_id=*&limit=100');
    
    if (isset($issues['error'])) {
        return $issues;
    }
    
    $milestones = isset($issues['issues']) ? $issues['issues'] : [];
    
    // Calcular dias até deadline e estatísticas de tarefas para cada milestone
    $today = new DateTime();
    $today->setTime(0, 0, 0); // Remover horas, minutos, segundos para comparar apenas as datas
    
    foreach ($milestones as $key => &$milestone) {
        // Calcular dias até deadline
        if (isset($milestone['due_date']) && !empty($milestone['due_date'])) {
            $due_date = new DateTime($milestone['due_date']);
            $due_date->setTime(0, 0, 0); // Remover horas, minutos, segundos
            
            $interval = $today->diff($due_date);
            $days_diff = $interval->days;
            $is_past = $interval->invert; // 1 se due_date já passou, 0 se é no futuro
            
            $milestone['days_remaining'] = $is_past ? -$days_diff : $days_diff;
            
            if ($days_diff == 0 && $is_past == 0) {
                $milestone['deadline_text'] = 'Hoje';
                $milestone['deadline_color'] = 'warning';
            } elseif ($is_past) {
                $milestone['deadline_text'] = 'Atrasado ' . $days_diff . ' dia' . ($days_diff > 1 ? 's' : '');
                $milestone['deadline_color'] = 'danger';
            } else {
                $milestone['deadline_text'] = $days_diff . ' dia' . ($days_diff > 1 ? 's' : '') . ' restantes';
                $milestone['deadline_color'] = 'success';
            }
        } else {
            $milestone['days_remaining'] = PHP_INT_MAX; // Sem data, coloca por último
            $milestone['deadline_text'] = 'Sem data definida';
            $milestone['deadline_color'] = 'secondary';
        }
        
        // Calcular estatísticas de tarefas
        if (isset($milestone['description'])) {
            $tasks = extractTasksFromDescription($milestone['description']);
            
            $backlog_count = count($tasks['backlog']);
            $in_progress_count = count($tasks['in_progress']);
            $paused_count = count($tasks['paused']);
            $closed_count = count($tasks['closed']);
            $total_count = $backlog_count + $in_progress_count + $paused_count + $closed_count;
            
            $milestone['task_stats'] = [
                'backlog' => [
                    'count' => $backlog_count,
                    'percent' => $total_count > 0 ? round(($backlog_count / $total_count) * 100) : 0
                ],
                'in_progress' => [
                    'count' => $in_progress_count,
                    'percent' => $total_count > 0 ? round(($in_progress_count / $total_count) * 100) : 0
                ],
                'paused' => [
                    'count' => $paused_count,
                    'percent' => $total_count > 0 ? round(($paused_count / $total_count) * 100) : 0
                ],
                'closed' => [
                    'count' => $closed_count,
                    'percent' => $total_count > 0 ? round(($closed_count / $total_count) * 100) : 0
                ],
                'total' => $total_count,
                'completion' => $total_count > 0 ? round(($closed_count / $total_count) * 100) : 0
            ];
        } else {
            $milestone['task_stats'] = [
                'backlog' => ['count' => 0, 'percent' => 0],
                'in_progress' => ['count' => 0, 'percent' => 0],
                'paused' => ['count' => 0, 'percent' => 0],
                'closed' => ['count' => 0, 'percent' => 0],
                'total' => 0,
                'completion' => 0
            ];
        }
    }
    
    // Ordenar por dias restantes (milestones com menos dias restantes primeiro)
    usort($milestones, function($a, $b) {
        // Primeiro ordenar por dias restantes
        if ($a['days_remaining'] !== $b['days_remaining']) {
            return $a['days_remaining'] <=> $b['days_remaining'];
        }
        
        // Se os dias são iguais, ordenar por ID (mais recente primeiro)
        return $b['id'] <=> $a['id'];
    });
    
    return $milestones;
}


// Obter detalhes de uma milestone específica
function getMilestoneDetails($milestoneId) {
    $issue = callRedmineAPI('/issues/' . $milestoneId . '.json?include=journals');
    
    if (isset($issue['error'])) {
        return $issue;
    }
    
    $milestone = isset($issue['issue']) ? $issue['issue'] : null;
    
    if ($milestone) {
        // Extrair tarefas da descrição
        $tasks = extractTasksFromDescription($milestone['description'] ?? '');
        
        // Coletar todos os IDs de tarefas
        $taskIds = [];
        foreach (['backlog', 'in_progress', 'paused', 'closed'] as $status) {
            foreach ($tasks[$status] as $task) {
                $taskIds[] = $task['id'];
            }
        }
        
        // Obter detalhes adicionais das tarefas
        if (!empty($taskIds)) {
            $taskDetails = getTaskDetails($taskIds);
            
            // Atualizar as informações das tarefas
            foreach (['backlog', 'in_progress', 'paused', 'closed'] as $status) {
                foreach ($tasks[$status] as $key => $task) {
                    if (isset($taskDetails[$task['id']])) {
                        $tasks[$status][$key]['project'] = $taskDetails[$task['id']]['project'];
                        $tasks[$status][$key]['assignee'] = $taskDetails[$task['id']]['assignee'];
                    }
                }
            }
            
            // Adicionar as tarefas atualizadas à milestone
            $milestone['task_details'] = $tasks;
        }
    }
    
    return $milestone;
}

// Obter todos os protótipos (subprojetos de tribeprototypes)
function getPrototypes() {
    $mainProjects = getMainProjectIds();
    
    if (!$mainProjects['prototypes_id']) {
        return ['error' => 'Projeto de protótipos não encontrado'];
    }
    
    $projects = callRedmineAPI('/projects.json?parent_id=' . $mainProjects['prototypes_id'] . '&limit=100');
    
    if (isset($projects['error'])) {
        return $projects;
    }
    
    return isset($projects['projects']) ? $projects['projects'] : [];
}

// Obter todos os projetos (subprojetos de tribeprojects)
function getProjects() {
    $mainProjects = getMainProjectIds();
    
    if (!$mainProjects['projects_id']) {
        return ['error' => 'Projeto de projetos não encontrado'];
    }
    
    $projects = callRedmineAPI('/projects.json?parent_id=' . $mainProjects['projects_id'] . '&limit=100');
    
    if (isset($projects['error'])) {
        return $projects;
    }
    
    return isset($projects['projects']) ? $projects['projects'] : [];
}

// Obter todos os usuários para atribuição
function getUsers() {
    // Primeiro, obter usuários membros do projeto de milestone
    $mainProjects = getMainProjectIds();
    
    if (!$mainProjects['milestone_id']) {
        error_log("Projeto de milestones não encontrado");
        return [];
    }
    
    // Método 1: Tenta obter diretamente de todos os usuários do sistema
    $users = callRedmineAPI('/users.json?limit=100&status=1'); // Status=1 para usuários ativos apenas
    
    if (!isset($users['error']) && isset($users['users']) && !empty($users['users'])) {
        error_log("Obtidos " . count($users['users']) . " usuários via método direto");
        return $users['users'];
    }
    
    error_log("Tentativa direta falhou, tentando via membros do projeto");
    
    // Método 2: Se não conseguir diretamente, tenta obter via memberships
    $members = callRedmineAPI('/projects/' . $mainProjects['milestone_id'] . '/memberships.json');
    
    if (isset($members['error']) || !isset($members['memberships']) || empty($members['memberships'])) {
        error_log("Erro ao obter membros do projeto: " . (isset($members['error']) ? $members['error'] : 'Estrutura inesperada'));
        
        // Tenta com outro endpoint como último recurso
        $membersAlt = callRedmineAPI('/memberships.json?project_id=' . $mainProjects['milestone_id']);
        
        if (isset($membersAlt['error']) || !isset($membersAlt['memberships']) || empty($membersAlt['memberships'])) {
            error_log("Todas as tentativas falharam. Retornando array vazio.");
            return []; // Retorna array vazio como último recurso
        }
        
        $members = $membersAlt; // Use os resultados alternativos
    }
    
    // Processa os membros para extrair usuários
    $userList = [];
    foreach ($members['memberships'] as $membership) {
        if (isset($membership['user'])) {
            // Verificar se temos os campos necessários
            $user = $membership['user'];
            if (isset($user['id'])) {
                // Se temos apenas id sem nome, tenta adicionar informações mínimas
                if (!isset($user['name']) && !isset($user['firstname'])) {
                    $user['name'] = 'Usuário #' . $user['id'];
                }
                
                // Evita duplicatas
                $exists = false;
                foreach ($userList as $existingUser) {
                    if ($existingUser['id'] == $user['id']) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $userList[] = $user;
                }
            }
        }
    }
    
    // Log dos usuários encontrados
    error_log("Obtidos " . count($userList) . " usuários via membros do projeto");
    if (count($userList) > 0) {
        error_log("Exemplo do primeiro usuário: " . json_encode($userList[0]));
    }
    
    return $userList;
}

// Obter tarefas de um projeto
function getProjectIssues($projectId) {
    $issues = callRedmineAPI('/issues.json?project_id=' . $projectId . '&status_id=*&limit=100');
    
    if (isset($issues['error'])) {
        return $issues;
    }
    
    return isset($issues['issues']) ? $issues['issues'] : [];
}

// Obter status disponíveis
function getStatuses() {
    $statuses = callRedmineAPI('/issue_statuses.json');
    
    if (isset($statuses['error'])) {
        return $statuses;
    }
    
    return isset($statuses['issue_statuses']) ? $statuses['issue_statuses'] : [];
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

?>
