<?php
// config.php
$API_KEY = 'AQUI_TUA_API_KEY';
$BASE_URL = 'http://criis-projects.inesctec.pt';

function redmine_request($endpoint, $method = 'GET', $data = null) {
    global $API_KEY, $BASE_URL;

    $url = rtrim($BASE_URL, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Redmine-API-Key: ' . $API_KEY
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$httpCode, $response];
}
?>

<!-- index.php -->
<?php include 'config.php'; ?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Backlog de Protótipos</title>
  <style>
    body { font-family: Arial; padding: 20px; background: #f5f5f5; }
    .card { background: white; border-radius: 8px; padding: 15px; margin-bottom: 10px; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
    h2 { margin-top: 40px; }
    form { margin-bottom: 30px; }
  </style>
</head>
<body>
<h1>📦 Backlog de Protótipos</h1>

<?php
// Submissão de novo protótipo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = $_POST['subject'] ?? '';
    $description = $_POST['description'] ?? '';
    $project_id = $_POST['project_id'] ?? '';

    if ($subject && $project_id) {
        $novo = [
            'issue' => [
                'subject' => $subject,
                'description' => $description,
                'project_id' => (int)$project_id
            ]
        ];
        [$code, $resp] = redmine_request('/issues.json', 'POST', $novo);

        if ($code === 201) {
            echo '<div class="card" style="background:#d4edda;">Protótipo criado com sucesso!</div>';
        } else {
            echo '<div class="card" style="background:#f8d7da;">Erro ao criar protótipo: HTTP ' . $code . '</div>';
        }
    }
}

// Obter lista de projetos
[$proj_code, $proj_resp] = redmine_request('/projects.json');
$projetos = ($proj_code === 200) ? json_decode($proj_resp, true)['projects'] : [];

// Obter backlog
[$issues_code, $issues_resp] = redmine_request('/issues.json?limit=50');
$issues = ($issues_code === 200) ? json_decode($issues_resp, true)['issues'] : [];
?>

<h2>➕ Novo Protótipo</h2>
<form method="post">
  <label>Nome:</label><br>
  <input type="text" name="subject" required style="width: 100%;"><br><br>

  <label>Descrição:</label><br>
  <textarea name="description" style="width: 100%; height: 80px;"></textarea><br><br>

  <label>Projeto:</label><br>
  <select name="project_id" required style="width: 100%;">
    <option value="">-- Selecione um projeto --</option>
    <?php foreach ($projetos as $proj): ?>
      <option value="<?= $proj['id'] ?>"><?= htmlspecialchars($proj['name']) ?></option>
    <?php endforeach; ?>
  </select><br><br>

  <button type="submit">Criar Protótipo</button>
</form>

<h2>📋 Backlog de Protótipos</h2>
<?php foreach ($issues as $issue): ?>
  <div class="card">
    <strong><?= htmlspecialchars($issue['subject']) ?></strong><br>
    Status: <?= $issue['status']['name'] ?><br>
    Projeto: <?= $issue['project']['name'] ?><br>
    <?= !empty($issue['assigned_to']) ? 'Responsável: ' . $issue['assigned_to']['name'] . '<br>' : '' ?>
    <small>#<?= $issue['id'] ?> | Criado em <?= date('d-m-Y', strtotime($issue['created_on'])) ?></small>
  </div>
<?php endforeach; ?>

<h2>📁 Projetos disponíveis</h2>
<?php foreach ($projetos as $proj): ?>
  <div class="card">
    <strong><?= htmlspecialchars($proj['name']) ?></strong><br>
    Identificador: <?= $proj['identifier'] ?><br>
    ID: <?= $proj['id'] ?>
  </div>
<?php endforeach; ?>

</body>
</html>
