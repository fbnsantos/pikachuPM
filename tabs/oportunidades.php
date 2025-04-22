<?php
// tabs/oportunidades.php
session_start();
include_once __DIR__ . '/../config.php';

$user = $_SESSION['user'] ?? '';
$pass = $_SESSION['password'] ?? '';
if (!$user || !$pass) {
    echo "<div class='alert alert-warning'>É necessário login para aceder às oportunidades.</div>";
    return;
}

$project_id = 'leads';

function redmine_request($endpoint, $method = 'GET', $data = null) {
    global $BASE_URL, $user, $pass;
    $url = "$BASE_URL/$endpoint";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Accept: application/json"]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code >= 400 || $resp === false) {
        echo "<div class='alert alert-danger'>Erro Redmine ($code): $err</div>";
        return null;
    }

    return json_decode($resp, true);
}

// Verifica se projeto 'leads' existe
$res_proj = redmine_request("projects/$project_id.json");
if (!$res_proj) {
    $proj_data = [
        'project' => [
            'name' => 'LEADS',
            'identifier' => $project_id,
            'description' => 'Projeto para oportunidades criadas via interface PHP',
            'is_public' => false
        ]
    ];
    $create = redmine_request('projects.json', 'POST', $proj_data);
    if (!$create) {
        echo "<div class='alert alert-danger'>Falha ao criar projeto LEADS</div>";
        return;
    }
    echo "<div class='alert alert-success'>Projeto LEADS criado.</div>";
}

// Atualização de TODOs via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_todo'])) {
    $issue_id = $_POST['issue_id'] ?? 0;
    $todo_index = $_POST['todo_index'] ?? 0;
    $checked = $_POST['checked'] === 'true';
    
    if ($issue_id) {
        // Buscar issue atual
        $issue = redmine_request("issues/$issue_id.json?include=description")['issue'] ?? null;
        if ($issue) {
            $description = $issue['description'];
            $tags = extrair_tags($description);
            
            // Atualizar o estado do TODO
            if (isset($tags['todos'][$todo_index])) {
                $tags['todos'][$todo_index]['checked'] = $checked;
                
                // Reconstruir a descrição
                $descricao_simples = extrair_descricao_simples($description);
                $nova_descricao = $descricao_simples;
                
                // Adicionar TODOs atualizados
                if (!empty($tags['todos'])) {
                    $todos_texto = [];
                    foreach ($tags['todos'] as $todo) {
                        $todos_texto[] = "- [" . ($todo['checked'] ? 'x' : ' ') . "] " . $todo['text'];
                    }
                    $nova_descricao .= "\n\n### TODOs:\n" . implode("\n", $todos_texto);
                }
                
                // Adicionar tags de volta
                if (!empty($tags['deadline'])) {
                    $nova_descricao .= "\n\n#deadline:" . $tags['deadline'];
                }
                if ($tags['relevance'] > 0) {
                    $nova_descricao .= "\n#relevance:" . $tags['relevance'];
                }
                
                // Atualizar a issue
                $data = [
                    'issue' => [
                        'description' => $nova_descricao
                    ]
                ];
                
                redmine_request("issues/$issue_id.json", 'PUT', $data);
                
                // Retornar status de sucesso
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        }
    }
    
    // Se chegou aqui, houve erro
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Não foi possível atualizar o TODO']);
    exit;
}

// Atualizar uma oportunidade existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_issue'])) {
    $issue_id = $_POST['issue_id'] ?? 0;
    if ($issue_id) {
        // Extrair todos os TODOs do formulário
        $todos = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'todo_text_') === 0) {
                $id = str_replace('todo_text_', '', $key);
                $checked = isset($_POST['todo_check_' . $id]) ? '1' : '0';
                $todos[] = "- [" . ($checked === '1' ? 'x' : ' ') . "] " . $value;
            }
        }
        
        // Montar a descrição
        $description = '';
        if (isset($_POST['descricao'])) {
            $description = $_POST['descricao'];
        }
        
        // Adicionar os TODOs à descrição
        if (!empty($todos)) {
            $description .= "\n\n### TODOs:\n" . implode("\n", $todos);
        }
        
        // Adicionar links se especificado
        if (!empty($_POST['links'])) {
            $links = explode("\n", $_POST['links']);
            $links_formatados = [];
            foreach ($links as $link) {
                $link = trim($link);
                if (!empty($link)) {
                    // Verificar se já é um link markdown
                    if (!preg_match('/^\[.+\]\(.+\)$/', $link)) {
                        // Se for apenas uma URL sem formatação
                        if (filter_var($link, FILTER_VALIDATE_URL)) {
                            $link = "[$link]($link)";
                        } elseif (preg_match('/^(.+)\s+(\S+)$/', $link, $matches)) {
                            // Se for "texto url"
                            $texto = $matches[1];
                            $url = $matches[2];
                            if (filter_var($url, FILTER_VALIDATE_URL)) {
                                $link = "[$texto]($url)";
                            }
                        }
                    }
                    $links_formatados[] = "- " . $link;
                }
            }
            
            if (!empty($links_formatados)) {
                $description .= "\n\n### Links:\n" . implode("\n", $links_formatados);
            }
        }
        
        // Adicionar deadline se especificado
        if (!empty($_POST['deadline'])) {
            $description .= "\n\n#deadline:" . $_POST['deadline'];
        }
        
        // Adicionar relevância se especificado
        if (isset($_POST['relevance'])) {
            $description .= "\n#relevance:" . $_POST['relevance'];
        }
        
        $data = [
            'issue' => [
                'description' => $description
            ]
        ];
        
        // Atualizar título se fornecido
        if (!empty($_POST['titulo'])) {
            $data['issue']['subject'] = $_POST['titulo'];
        }
        
        redmine_request("issues/$issue_id.json", 'PUT', $data);
        
        // Redirecionamento para evitar envios duplicados
        header("Location: index.php?tab=oportunidades");
        exit;
    }
}

// Criar nova oportunidade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['novo_titulo'])) {
    $descricao = $_POST['novo_conteudo'] ?? '';
    
    // Adicionar deadline se fornecido
    if (!empty($_POST['novo_deadline'])) {
        $descricao .= "\n\n#deadline:" . $_POST['novo_deadline'];
    }
    
    // Adicionar relevância padrão se não especificada
    if (!strpos($descricao, '#relevance:')) {
        $descricao .= "\n#relevance:" . ($_POST['novo_relevancia'] ?? 5);
    }
    
    $data = [
        'issue' => [
            'project_id' => $project_id,
            'subject' => $_POST['novo_titulo'],
            'description' => $descricao
        ]
    ];
    redmine_request('issues.json', 'POST', $data);
    header("Location: index.php?tab=oportunidades");
    exit;
}

// Buscar oportunidades
$res = redmine_request("projects/$project_id/issues.json?limit=100&status_id=*");
$issues = $res['issues'] ?? [];

function extrair_tags($texto) {
    preg_match('/#deadline:(\d{4}-\d{2}-\d{2})/', $texto, $dl);
    preg_match('/#relevance:(\d+)/', $texto, $rl);
    
    // Extrair TODOs
    $todos = [];
    if (preg_match('/### TODOs:\n((?:- \[[ x]\] .+\n?)*)/', $texto, $matches)) {
        preg_match_all('/- \[([ x])\] (.+)/', $matches[1], $todoMatches, PREG_SET_ORDER);
        foreach ($todoMatches as $todoMatch) {
            $todos[] = [
                'checked' => $todoMatch[1] === 'x',
                'text' => $todoMatch[2]
            ];
        }
    }
    
    // Extrair links
    $links = [];
    if (preg_match('/### Links:\n((?:- .+\n?)*)/', $texto, $matches)) {
        preg_match_all('/- (.+)/', $matches[1], $linkMatches, PREG_SET_ORDER);
        foreach ($linkMatches as $linkMatch) {
            $links[] = $linkMatch[1];
        }
    }
    
    // Calcular dias até deadline
    $dias_restantes = null;
    if (!empty($dl[1])) {
        $deadline_date = new DateTime($dl[1]);
        $hoje = new DateTime('today');
        $intervalo = $hoje->diff($deadline_date);
        $dias_restantes = $intervalo->invert ? -$intervalo->days : $intervalo->days;
    }
    
    // Calcular percentagem de conclusão
    $percent_concluido = 0;
    $total_todos = count($todos);
    if ($total_todos > 0) {
        $concluidos = 0;
        foreach ($todos as $todo) {
            if ($todo['checked']) {
                $concluidos++;
            }
        }
        $percent_concluido = round(($concluidos / $total_todos) * 100);
    }
    
    return [
        'deadline' => $dl[1] ?? '',
        'relevance' => (int)($rl[1] ?? 0),
        'todos' => $todos,
        'links' => $links,
        'dias_restantes' => $dias_restantes,
        'percent_concluido' => $percent_concluido
    ];
}

function extrair_descricao_simples($texto) {
    // Remove as seções de TODOs, links e tags
    $texto = preg_replace('/### TODOs:\n((?:- \[[ x]\] .+\n?)*)/', '', $texto);
    $texto = preg_replace('/### Links:\n((?:- .+\n?)*)/', '', $texto);
    $texto = preg_replace('/#deadline:\d{4}-\d{2}-\d{2}/', '', $texto);
    $texto = preg_replace('/#relevance:\d+/', '', $texto);
    
    // Remove linhas em branco extras
    $texto = preg_replace('/\n{3,}/', "\n\n", $texto);
    return trim($texto);
}

$ordenar = $_GET['ordenar'] ?? 'relevance';
usort($issues, function($a, $b) use ($ordenar) {
    $ta = extrair_tags($a['description']);
    $tb = extrair_tags($b['description']);
    if ($ordenar === 'deadline') {
        // Se uma das oportunidades não tem deadline, coloca no final
        if (empty($ta['deadline'])) return 1;
        if (empty($tb['deadline'])) return -1;
        return strtotime($ta['deadline']) <=> strtotime($tb['deadline']);
    }
    return $tb['relevance'] <=> $ta['relevance'];
});
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .todo-list {
            list-style-type: none;
            padding-left: 0;
        }
        .deadline-warning {
            color: #fd7e14;
        }
        .deadline-danger {
            color: #dc3545;
        }
        .deadline-expired {
            color: #dc3545;
            font-weight: bold;
        }
        .badge {
            font-size: 0.85rem;
        }
        .oportunidade-row {
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .oportunidade-row:hover {
            background-color: rgba(0,0,0,0.03);
            border-left-color: #0d6efd;
        }
        .oportunidade-details {
            display: none;
        }
        .todo-item {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        .todo-input {
            flex: 1;
            margin-left: 8px;
        }
        .add-todo-btn {
            margin-top: 10px;
        }
        .progress {
            height: 10px;
        }
        .todo-item-clickable {
            cursor: pointer;
        }
        .links-list {
            margin-top: 10px;
            padding-left: 0;
            list-style-type: none;
        }
        .links-list li {
            margin-bottom: 6px;
        }
    </style>
</head>
<body>
<div class="container mt-4">
    <h2>Oportunidades</h2>

    <!-- Formulário para criar nova oportunidade -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Nova Oportunidade</h5>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <div class="col-md-6">
                    <label for="novo_titulo" class="form-label">Título</label>
                    <input type="text" name="novo_titulo" id="novo_titulo" class="form-control" placeholder="Título da oportunidade" required>
                </div>
                <div class="col-md-3">
                    <label for="novo_deadline" class="form-label">Deadline</label>
                    <input type="date" name="novo_deadline" id="novo_deadline" class="form-control">
                </div>
                <div class="col-md-3">
                    <label for="novo_relevancia" class="form-label">Relevância (1-10)</label>
                    <input type="number" name="novo_relevancia" id="novo_relevancia" class="form-control" min="1" max="10" value="5">
                </div>
                <div class="col-md-12">
                    <label for="novo_conteudo" class="form-label">Descrição</label>
                    <textarea name="novo_conteudo" id="novo_conteudo" class="form-control" rows="3" placeholder="Descrição detalhada da oportunidade"></textarea>
                </div>
                <div class="col-md-12">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle"></i> Criar Oportunidade
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Opções de ordenação -->
    <div class="d-flex justify-content-between mb-3">
        <h4>Lista de Oportunidades</h4>
        <div>
            <a href="?tab=oportunidades&ordenar=relevance" class="btn btn-outline-primary btn-sm me-2">
                <i class="bi bi-sort-numeric-down"></i> Ordenar por relevância
            </a>
            <a href="?tab=oportunidades&ordenar=deadline" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-calendar-date"></i> Ordenar por deadline
            </a>
        </div>
    </div>

    <!-- Lista de oportunidades em formato de tabela -->
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Título</th>
                        <th class="text-center" style="width: 120px;">Relevância</th>
                        <th class="text-center" style="width: 150px;">Deadline</th>
                        <th class="text-center" style="width: 150px;">Progresso</th>
                        <th class="text-center" style="width: 80px;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if (empty($issues)) {
                        echo '<tr><td colspan="5" class="text-center py-3">Nenhuma oportunidade cadastrada.</td></tr>';
                    }
                    
                    foreach ($issues as $i): 
                        $tags = extrair_tags($i['description']);
                        $descricao_simples = extrair_descricao_simples($i['description']);
                        
                        // Determinar classe para deadline
                        $deadline_class = '';
                        $dias_texto = '';
                        if ($tags['dias_restantes'] !== null) {
                            if ($tags['dias_restantes'] < 0) {
                                $deadline_class = 'deadline-expired';
                                $dias_texto = 'Atrasado há ' . abs($tags['dias_restantes']) . ' dias';
                            } elseif ($tags['dias_restantes'] <= 3) {
                                $deadline_class = 'deadline-danger';
                                $dias_texto = 'Faltam ' . $tags['dias_restantes'] . ' dias';
                            } elseif ($tags['dias_restantes'] <= 7) {
                                $deadline_class = 'deadline-warning';
                                $dias_texto = 'Faltam ' . $tags['dias_restantes'] . ' dias';
                            } else {
                                $dias_texto = 'Faltam ' . $tags['dias_restantes'] . ' dias';
                            }
                        }
                        
                        // Determinar classe para barra de progresso
                        $progress_class = 'bg-primary';
                        if ($tags['percent_concluido'] >= 100) {
                            $progress_class = 'bg-success';
                        } elseif ($tags['percent_concluido'] >= 70) {
                            $progress_class = 'bg-info';
                        } elseif ($tags['percent_concluido'] >= 40) {
                            $progress_class = 'bg-primary';
                        } elseif ($tags['percent_concluido'] >= 20) {
                            $progress_class = 'bg-warning';
                        } else {
                            $progress_class = 'bg-danger';
                        }
                    ?>
                    <tr class="oportunidade-row" data-id="<?= $i['id'] ?>">
                        <td class="align-middle">
                            <span class="fw-bold"><?= htmlspecialchars($i['subject']) ?></span>
                        </td>
                        <td class="text-center align-middle">
                            <?php if ($tags['relevance']): ?>
                                <span class="badge bg-warning text-dark"><?= $tags['relevance'] ?>/10</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <?php if ($tags['deadline']): ?>
                                <span class="<?= $deadline_class ?>">
                                    <?= date('d/m/Y', strtotime($tags['deadline'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">--</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <div class="d-flex align-items-center">
                                <div class="progress w-100 me-2">
                                    <div class="progress-bar <?= $progress_class ?>" role="progressbar" 
                                         style="width: <?= $tags['percent_concluido'] ?>%;" 
                                         aria-valuenow="<?= $tags['percent_concluido'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="badge bg-secondary"><?= $tags['percent_concluido'] ?>%</span>
                            </div>
                        </td>
                        <td class="text-center align-middle">
                            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" 
                                    data-bs-target="#collapse<?= $i['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    </tr>
                    <!-- Detalhes expandidos -->
                    <tr class="oportunidade-details" id="details<?= $i['id'] ?>">
                        <td colspan="5" class="p-0">
                            <div class="p-3 bg-light border-top">
                                <div class="row">
                                    <div class="col-md-6">
                                        <?php if (!empty($descricao_simples)): ?>
                                        <div class="mb-3">
                                            <h6><i class="bi bi-file-text"></i> Descrição:</h6>
                                            <div class="p-2 bg-white rounded border" style="white-space: pre-wrap;"><?= htmlspecialchars($descricao_simples) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($tags['links'])): ?>
                                        <div class="mb-3">
                                            <h6><i class="bi bi-link-45deg"></i> Links:</h6>
                                            <ul class="links-list">
                                                <?php foreach ($tags['links'] as $link): 
                                                    // Parsear markdown links [text](url)
                                                    if (preg_match('/\[(.+?)\]\((.+?)\)/', $link, $matches)) {
                                                        $link_text = $matches[1];
                                                        $link_url = $matches[2];
                                                    } else {
                                                        $link_text = $link;
                                                        $link_url = $link;
                                                    }
                                                ?>
                                                <li>
                                                    <a href="<?= htmlspecialchars($link_url) ?>" target="_blank">
                                                        <i class="bi bi-box-arrow-up-right"></i> <?= htmlspecialchars($link_text) ?>
                                                    </a>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <?php if (!empty($tags['todos'])): ?>
                                        <div class="mb-3">
                                            <h6><i class="bi bi-check2-square"></i> TODOs:</h6>
                                            <ul class="todo-list">
                                                <?php foreach ($tags['todos'] as $index => $todo): ?>
                                                <li class="todo-item-clickable" data-issue-id="<?= $i['id'] ?>" data-todo-index="<?= $index ?>">
                                                    <div class="form-check">
                                                        <input class="form-check-input todo-checkbox" type="checkbox" 
                                                               <?= $todo['checked'] ? 'checked' : '' ?>>
                                                        <label class="form-check-label <?= $todo['checked'] ? 'text-decoration-line-through text-muted' : '' ?>">
                                                            <?= htmlspecialchars($todo['text']) ?>
                                                        </label>
                                                    </div>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <p class="small text-muted mb-0">
                                                <i class="bi bi-hash"></i> <?= $i['id'] ?> | 
                                                <i class="bi bi-person"></i> <?= htmlspecialchars($i['author']['name']) ?> | 
                                                <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($i['created_on'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Formulário de edição colapsável -->
                    <tr class="collapse" id="collapse<?= $i['id'] ?>">
                        <td colspan="5" class="p-0">
                            <div class="p-3 bg-white border-top">
                                <form method="post" class="row g-3">
                                    <input type="hidden" name="atualizar_issue" value="1">
                                    <input type="hidden" name="issue_id" value="<?= $i['id'] ?>">
                                    
                                    <div class="col-md-12">
                                        <label class="form-label">Título</label>
                                        <input type="text" name="titulo" class="form-control" 
                                               value="<?= htmlspecialchars($i['subject']) ?>">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Deadline</label>
                                        <input type="date" name="deadline" class="form-control" 
                                               value="<?= $tags['deadline'] ?>">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Relevância (1-10)</label>
                                        <input type="number" name="relevance" class="form-control" 
                                               min="1" max="10" value="<?= $tags['relevance'] ?>">
                                    </div>
                                    
                                    <div class="col-md-12">
                                        <label class="form-label">Descrição</label>
                                        <textarea name="descricao" class="form-control" rows="3"><?= htmlspecialchars($descricao_simples) ?></textarea>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Lista de TODOs</label>
                                        <div id="todoContainer<?= $i['id'] ?>">
                                            <?php 
                                            $todo_count = 0;
                                            foreach ($tags['todos'] as $todo): 
                                                $todo_id = "todo_{$i['id']}_{$todo_count}";
                                            ?>
                                            <div class="todo-item">
                                                <input class="form-check-input" type="checkbox" name="todo_check_<?= $todo_id ?>" 
                                                       <?= $todo['checked'] ? 'checked' : '' ?>>
                                                <input type="text" name="todo_text_<?= $todo_id ?>" class="form-control todo-input" 
                                                       value="<?= htmlspecialchars($todo['text']) ?>">
                                                <button type="button" class="btn btn-sm btn-outline-danger ms-2" 
                                                        onclick="this.parentNode.remove()">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                            <?php 
                                                $todo_count++; 
                                            endforeach; 
                                            
                                            // Se não houver TODOs, adicionar um vazio
                                            if ($todo_count == 0):
                                                $todo_id = "todo_{$i['id']}_0";
                                            ?>
                                            <div class="todo-item">
                                                <input class="form-check-input" type="checkbox" name="todo_check_<?= $todo_id ?>">
                                                <input type="text" name="todo_text_<?= $todo_id ?>" class="form-control todo-input" 
                                                       placeholder="Adicione um item TODO">
                                                <button type="button" class="btn btn-sm btn-outline-danger ms-2" 
                                                        onclick="this.parentNode.remove()">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <button type="button" class="btn btn-sm btn-outline-success add-todo-btn" 
                                                onclick="adicionarTodo(<?= $i['id'] ?>)">
                                            <i class="bi bi-plus-circle"></i> Adicionar TODO
                                        </button>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Links (um por linha)</label>
                                        <textarea name="links" class="form-control" rows="4" placeholder="Formato: texto url ou [texto](url)"><?php 
                                            if (!empty($tags['links'])) {
                                                echo htmlspecialchars(implode("\n", $tags['links']));
                                            }
                                        ?></textarea>
                                        <small class="form-text text-muted">
                                            Você pode adicionar links no formato "texto URL" ou "[texto](URL)"
                                        </small>
                                    </div>
                                    
                                    <div class="col-md-12 mt-3">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save"></i> Salvar Alterações
                                        </button>
                                        <button type="button" class="btn btn-secondary" 
                                                data-bs-toggle="collapse" data-bs-target="#collapse<?= $i['id'] ?>">
                                            Cancelar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- JavaScript para manipulação dos TODOs e expandir/colapsar detalhes -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Adicionar evento de clique nas linhas da tabela para expandir/colapsar
        const oportunidadeRows = document.querySelectorAll('.oportunidade-row');
        oportunidadeRows.forEach(row => {
            row.addEventListener('click', function(e) {
                // Verificar se o clique não foi no botão de editar
                if (!e.target.closest('.btn')) {
                    const id = this.getAttribute('data-id');
                    const detailsRow = document.getElementById('details' + id);
                    
                    // Esconder todos os outros detalhes
                    document.querySelectorAll('.oportunidade-details').forEach(detail => {
                        if (detail.id !== 'details' + id) {
                            detail.style.display = 'none';
                        }
                    });
                    
                    // Alternar a visibilidade dos detalhes
                    if (detailsRow.style.display === 'table-row') {
                        detailsRow.style.display = 'none';
                    } else {
                        detailsRow.style.display = 'table-row';
                    }
                }
            });
        });
        
        // Adicionar evento para checkbox de TODOs
        document.querySelectorAll('.todo-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const listItem = this.closest('.todo-item-clickable');
                const issueId = listItem.getAttribute('data-issue-id');
                const todoIndex = listItem.getAttribute('data-todo-index');
                const label = this.nextElementSibling;
                
                // Atualizar visualmente
                if (this.checked) {
                    label.classList.add('text-decoration-line-through', 'text-muted');
                } else {
                    label.classList.remove('text-decoration-line-through', 'text-muted');
                }
                
                // Enviar atualização via AJAX
                const formData = new FormData();
                formData.append('ajax_update_todo', '1');
                formData.append('issue_id', issueId);
                formData.append('todo_index', todoIndex);
                formData.append('checked', this.checked);
                
                fetch('index.php?tab=oportunidades', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        console.error('Erro ao atualizar TODO:', data.error);
                        // Reverter o checkbox se houver erro
                        this.checked = !this.checked;
                        if (this.checked) {
                            label.classList.add('text-decoration-line-through', 'text-muted');
                        } else {
                            label.classList.remove('text-decoration-line-through', 'text-muted');
                        }
                    }
                })
                .catch(error => {
                    console.error('Erro na requisição:', error);
                    // Reverter o checkbox se houver erro
                    this.checked = !this.checked;
                    if (this.checked) {
                        label.classList.add('text-decoration-line-through', 'text-muted');
                    } else {
                        label.classList.remove('text-decoration-line-through', 'text-muted');
                    }
                });
            });
        });
    });
    
    function adicionarTodo(issueId) {
        const container = document.getElementById(`todoContainer${issueId}`);
        const todoCount = container.children.length;
        const todoId = `todo_${issueId}_${todoCount}`;
        
        const todoDiv = document.createElement('div');
        todoDiv.className = 'todo-item';
        todoDiv.innerHTML = `
            <input class="form-check-input" type="checkbox" name="todo_check_${todoId}">
            <input type="text" name="todo_text_${todoId}" class="form-control todo-input" 
                   placeholder="Adicione um item TODO">
            <button type="button" class="btn btn-sm btn-outline-danger ms-2" 
                    onclick="this.parentNode.remove()">
                <i class="bi bi-trash"></i>
            </button>
        `;
        
        container.appendChild(todoDiv);
    }
</script>
</body>
</html>