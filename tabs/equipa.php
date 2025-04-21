<?php
// tabs/equipa.php
session_start();
include_once __DIR__ . '/../config.php';

// Verificar e criar base de dados SQLite e tabela, se necessário
$db_path = __DIR__ . '/../equipa.sqlite';
$nova_base_dados = !file_exists($db_path);

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($nova_base_dados) {
        $db->exec("CREATE TABLE IF NOT EXISTS equipa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            redmine_id INTEGER UNIQUE
        )");
    }
} catch (Exception $e) {
    die("Erro ao inicializar a base de dados: " . $e->getMessage());
}

function getUtilizadoresRedmine() {
    global $API_KEY, $BASE_URL;
    $url = "$BASE_URL/users.json?limit=100";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Redmine-API-Key: $API_KEY", "Accept: application/json"]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http_code !== 200) {
        echo "<div class='alert alert-danger'>Erro ao obter utilizadores da API Redmine.<br>
              Código HTTP: $http_code<br>
              Erro CURL: $curl_error<br>
              URL: $url</div>";
        return [];
    }

    $data = json_decode($resp, true);
    if (empty($data['users'])) {
        echo "<div class='alert alert-warning'>⚠️ A resposta da API Redmine foi recebida mas não contém utilizadores.</div>";
    }
    return $data['users'] ?? [];
}

function getAtividadesUtilizador($id) {
    global $API_KEY, $BASE_URL;
    $url = "$BASE_URL/issues.json?author_id=$id&limit=5&sort=updated_on:desc";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Redmine-API-Key: $API_KEY",
        "Accept: application/json"
    ]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http_code !== 200) {
        echo "<div class='alert alert-danger'>Erro ao obter issues do utilizador ID $id.<br>
              Código HTTP: $http_code<br>
              Erro CURL: $curl_error<br>
              URL: $url</div>";
        return [];
    }

    $data = json_decode($resp, true);
    return $data['issues'] ?? [];
}

function mostrarAtividades($atividades) {
    global $BASE_URL;
    foreach ($atividades as $issue) {
        $id = htmlspecialchars($issue['id']);
        $titulo = htmlspecialchars($issue['subject']);
        $data = isset($issue['updated_on']) ? date('d/m/Y H:i', strtotime($issue['updated_on'])) : 'Sem data';
        echo "<li class='list-group-item'>
                <a href='$BASE_URL/issues/$id' target='_blank'>[#{$id}] $titulo</a>
                <br><small class='text-muted'>Atualizado em $data</small>
              </li>";
    }
}

if (!isset($_SESSION['gestor'])) {
    $_SESSION['gestor'] = null;
    $_SESSION['em_reuniao'] = false;
    $_SESSION['reuniao_pausada'] = false;
    $_SESSION['oradores'] = [];
    $_SESSION['orador_atual'] = 0;
    $_SESSION['inicio_reuniao'] = null;
}

$utilizadores = getUtilizadoresRedmine();
$equipa = $db->query("SELECT redmine_id FROM equipa")->fetchAll(PDO::FETCH_COLUMN);

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['adicionar'])) {
        $id = (int)$_POST['adicionar'];
        $stmt = $db->prepare("INSERT OR IGNORE INTO equipa (redmine_id) VALUES (:id)");
        $stmt->execute([':id' => $id]);
    }
    if (isset($_POST['remover'])) {
        $id = (int)$_POST['remover'];
        $stmt = $db->prepare("DELETE FROM equipa WHERE redmine_id = :id");
        $stmt->execute([':id' => $id]);
        if ($id === $_SESSION['gestor']) {
            $_SESSION['gestor'] = null;
        }
    }
    if (isset($_POST['iniciar'])) {
        $_SESSION['gestor'] = $equipa[array_rand($equipa)];
        $_SESSION['oradores'] = $equipa;
        shuffle($_SESSION['oradores']);
        $_SESSION['em_reuniao'] = true;
        $_SESSION['reuniao_pausada'] = false;
        $_SESSION['orador_atual'] = 0;
        $_SESSION['inicio_reuniao'] = time();
    }
    if (isset($_POST['pausar'])) {
        $_SESSION['reuniao_pausada'] = true;
    }
    if (isset($_POST['retomar'])) {
        $_SESSION['reuniao_pausada'] = false;
    }
    if (isset($_POST['proximo'])) {
        $_SESSION['orador_atual']++;
    }
    if (isset($_POST['recusar'])) {
        $idRecusado = (int)$_POST['recusar'];
        $index = array_search($idRecusado, $equipa);
        if ($index !== false) {
            unset($equipa[$index]);
            $_SESSION['gestor'] = $equipa[array_rand($equipa)];
        }
    }
    header("Location: ?tab=equipa");
    exit;
}
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<h2 class="mt-3">Reunião Diária</h2>

<?php if (empty($equipa)): ?>
  <div class="alert alert-info">⚙️ A equipa ainda não foi configurada. Por favor adicione membros abaixo para iniciar.</div>
<?php endif; ?>

<?php if (!$_SESSION['em_reuniao'] && count($equipa) >= 2): ?>
  <form method="post" class="mb-4">
    <button type="submit" name="iniciar" class="btn btn-success">Iniciar Reunião</button>
  </form>
<?php endif; ?>

<?php if ($_SESSION['em_reuniao']): ?>
  <div class="mb-3">
    <strong>👤 Gestor da reunião:</strong> <?= getNomeUtilizador($_SESSION['gestor'], $utilizadores) ?><br>
    <strong>⏱️ Tempo total:</strong> <?= gmdate('H:i:s', time() - $_SESSION['inicio_reuniao']) ?><br>
    <?php if ($_SESSION['reuniao_pausada']): ?>
      <div class="alert alert-warning">⏸️ Reunião pausada</div>
      <form method="post"><button type="submit" name="retomar" class="btn btn-primary">Retomar</button></form>
    <?php else: ?>
      <form method="post" class="d-inline"><button type="submit" name="pausar" class="btn btn-warning">Pausar</button></form>
    <?php endif; ?>
  </div>
  <?php
    $orador_atual = $_SESSION['orador_atual'];
    $oradores = $_SESSION['oradores'];
    $fim = $orador_atual >= count($oradores);
    if ($fim):
      echo "<div class='alert alert-success'>✅ Reunião concluída!</div>";
      session_destroy();
    else:
      $oradorId = $oradores[$orador_atual];
  ?>
      <h3 class="text-primary">🎤 Orador atual: <?= getNomeUtilizador($oradorId, $utilizadores) ?></h3>
      <div id="cronometro" class="display-4 my-3">30</div>
      <audio id="beep" src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg"></audio>
      <script>
        let tempo = 30;
        const el = document.getElementById("cronometro");
        const beep = document.getElementById("beep");
        const intervalo = setInterval(() => {
          if (<?= $_SESSION['reuniao_pausada'] ? 'true' : 'false' ?>) return;
          tempo--;
          el.textContent = tempo;
          if (tempo === 5) beep.play();
          if (tempo <= 0) clearInterval(intervalo);
        }, 1000);
      </script>

      <h5>Últimas atividades:</h5>
      <ul class="list-group">
        <?php mostrarAtividades(getAtividadesUtilizador($oradorId)); ?>
      </ul>

      <form method="post" class="mt-3">
        <button type="submit" name="proximo" class="btn btn-warning">➡️ Próximo</button>
      </form>
  <?php endif; ?>
<?php endif; ?>

<hr class="my-4">
<h3>Gestão da Equipa</h3>
<form method="post" class="row g-3 mb-4">
  <div class="col-auto">
    <label for="adicionar" class="form-label">Adicionar elemento à equipa:</label>
    <select name="adicionar" id="adicionar" class="form-select">
      <?php foreach ($utilizadores as $u): ?>
        <?php if (!in_array($u['id'], $equipa)): ?>
          <option value="<?= $u['id'] ?>"> <?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname']) ?> </option>
        <?php endif; ?>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto align-self-end">
    <button type="submit" class="btn btn-primary">Adicionar</button>
  </div>
</form>

<h4>Membros da Equipa:</h4>
<ul class="list-group mb-4">
  <?php foreach ($equipa as $id): ?>
    <li class="list-group-item d-flex justify-content-between align-items-center">
      <?= getNomeUtilizador($id, $utilizadores) ?>
      <form method="post">
        <input type="hidden" name="remover" value="<?= $id ?>">
        <button type="submit" class="btn btn-sm btn-danger">Remover</button>
      </form>
    </li>
  <?php endforeach; ?>
</ul>