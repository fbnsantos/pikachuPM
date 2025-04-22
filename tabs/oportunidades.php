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
            'identifier' => $project_id ,
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
        $descricao .= "\n#relevance:5"; // valor padrão de relevância
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
    
    // Calcular dias até deadline
    $dias_restantes = null;
    if (!empty($dl[1])) {
        $deadline_date = new DateTime($dl[1]);
        $hoje = new DateTime('today');
        $intervalo = $hoje->diff($deadline_date);
        $dias_restantes = $intervalo->invert ? -$intervalo->days : $intervalo->days;
    }
    
    return [
        'deadline' => $dl[1] ?? '',
        'relevance' => (int)($rl[1] ?? 0),
        'todos' => $todos,
        'dias_restantes' => $dias_restantes
    ];
}

function extrair_descricao_simples($texto) {
    // Remove as seções de TODOs e tags
    $texto = preg_replace('/### TODOs:\n((?:- \[[ x]\] .+\n?)*)/', '', $texto);
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
        .card-header .badge {
            font-size: 0.85rem;
        }
        .oportunidade-card {
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        .oportunidade-card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
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

    <!-- Lista de oportunidades -->
    <div class="row">
        <?php 
        if (empty($issues)) {
            echo '<div class="col-12"><div class="alert alert-info">Nenhuma oportunidade cadastrada.</div></div>';
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
        ?>
        <div class="col-md-6">
            <div class="card oportunidade-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">📝 <?= htmlspecialchars($i['subject']) ?></h5>
                    <div>
                        <?php if ($tags['relevance']): ?>
                            <span class="badge bg-warning text-dark">Relevância: <?= $tags['relevance'] ?></span>
                        <?php endif; ?>
                        
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" 
                                data-bs-target="#collapse<?= $i['id'] ?>">
                            <i class="bi bi-pencil"></i> Editar
                        </button>
                    </div>
                </div>
                
                <div class="card-body">
                    <p class="text-muted small">
                        <i class="bi bi-hash"></i> <?= $i['id'] ?> | 
                        <i class="bi bi-person"></i> <?= htmlspecialchars($i['author']['name']) ?> | 
                        <i class="bi bi-calendar"></i> <?= date('d/m/Y', strtotime($i['created_on'])) ?>
                    </p>
                    
                    <?php if ($tags['deadline']): ?>
                    <div class="mb-3">
                        <strong>Deadline:</strong> 
                        <span class="<?= $deadline_class ?>">
                            <?= date('d/m/Y', strtotime($tags['deadline'])) ?> 
                            (<?= $dias_texto ?>)
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($descricao_simples)): ?>
                    <div class="mb-3">
                        <h6>Descrição:</h6>
                        <div style="white-space: pre-wrap;"><?= htmlspecialchars($descricao_simples) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($tags['todos'])): ?>
                    <div class="mb-3">
                        <h6>TODOs:</h6>
                        <ul class="todo-list">
                            <?php foreach ($tags['todos'] as $todo): ?>
                            <li>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                           <?= $todo['checked'] ? 'checked' : '' ?> disabled>
                                    <label class="form-check-label <?= $todo['checked'] ? 'text-decoration-line-through' : '' ?>">
                                        <?= htmlspecialchars($todo['text']) ?>
                                    </label>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Formulário de edição colapsável -->
                <div class="collapse" id="collapse<?= $i['id'] ?>">
                    <div class="card-body border-top">
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
                            
                            <div class="col-md-12">
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
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- JavaScript para manipulação dos TODOs -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
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