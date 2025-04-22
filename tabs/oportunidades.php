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

// Criar nova oportunidade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['novo_titulo'])) {
    $data = [
        'issue' => [
            'project_id' => 'LEADS',
            'subject' => $_POST['novo_titulo'],
            'description' => $_POST['novo_conteudo'] ?? ''
        ]
    ];
    redmine_request('issues.json', 'POST', $data);
    header("Location: index.php?tab=oportunidades");
    exit;
}

// Verificar se projeto LEADS existe, senão criar
$res_proj = redmine_request('projects/LEADS');
if (!$res_proj) {
    \$create_resp = redmine_request('projects.json', 'POST', [
        'project' => [
            'name' => 'LEADS',
            'identifier' => 'leads',
            'description' => 'Projeto de oportunidades criadas pela interface PHP',
            'is_public' => false
        ]
    ]
    ]);
    if (!$create_resp) {
        echo "<div class='alert alert-danger'>Erro ao criar o projeto LEADS automaticamente.</div>";
    } else {
        echo "<div class='alert alert-success'>Projeto LEADS criado automaticamente.</div>";
    }
}

// Buscar oportunidades
$res = redmine_request('projects/LEADS/issues.json?limit=100&status_id=*');
$issues = $res['issues'] ?? [];

function extrair_tags($texto) {
    preg_match('/#deadline:(\d{4}-\d{2}-\d{2})/', $texto, $dl);
    preg_match('/#relevance:(\d+)/', $texto, $rl);
    return [
        'deadline' => $dl[1] ?? '',
        'relevance' => (int)($rl[1] ?? 0)
    ];
}

// Ordenar
$ordenar = $_GET['ordenar'] ?? 'relevance';
usort($issues, function($a, $b) use ($ordenar) {
    $ta = extrair_tags($a['description']);
    $tb = extrair_tags($b['description']);
    if ($ordenar === 'deadline') {
        return strtotime($ta['deadline'] ?? '') <=> strtotime($tb['deadline'] ?? '');
    }
    return $tb['relevance'] <=> $ta['relevance'];
});

?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<div class="container mt-4">
    <h2>Oportunidades</h2>

    <form method="post" class="mb-4 row g-2">
        <div class="col-md-4">
            <input type="text" name="novo_titulo" class="form-control" placeholder="Título da oportunidade" required>
        </div>
        <div class="col-md-6">
            <input type="text" name="novo_conteudo" class="form-control" placeholder="Descrição com tags (ex: #todo, $todo(id,user), #deadline:YYYY-MM-DD, #relevance:X)">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-success w-100">Criar</button>
        </div>
    </form>

    <div class="d-flex justify-content-end mb-3">
        <a href="?tab=oportunidades&ordenar=relevance" class="btn btn-outline-primary btn-sm me-2">Ordenar por relevância</a>
        <a href="?tab=oportunidades&ordenar=deadline" class="btn btn-outline-secondary btn-sm">Ordenar por deadline</a>
    </div>

    <ul class="list-group">
        <?php foreach ($issues as $i): 
            $tags = extrair_tags($i['description']);
        ?>
        <li class="list-group-item">
            <strong contenteditable="true">📝 <?= htmlspecialchars($i['subject']) ?></strong><br>
            <small class="text-muted">#<?= $i['id'] ?> por <?= $i['author']['name'] ?> em <?= date('d/m/Y', strtotime($i['created_on'])) ?></small>
            <div class="mt-2" contenteditable="true" style="white-space: pre-wrap;"><?= htmlspecialchars($i['description']) ?></div>
            <div class="mt-2">
                <span class="badge text-bg-warning">Relevância: <?= $tags['relevance'] ?></span>
                <?php if ($tags['deadline']): ?>
                    <span class="badge text-bg-info">Deadline: <?= $tags['deadline'] ?></span>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
