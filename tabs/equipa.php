<?php
// tabs/equipa.php
session_start();
include_once __DIR__ . '/../config.php';

echo '<pre>';
var_dump($_SESSION);
echo '</pre>';

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
    $ch = curl_init("$BASE_URL/users.json?limit=100");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Redmine-API-Key: $API_KEY"]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['users'] ?? [];
}

function getAtividadesUtilizador($id) {
    global $API_KEY, $BASE_URL;
    $ch = curl_init("$BASE_URL/activity.json?user_id=$id&limit=5");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Redmine-API-Key: $API_KEY"]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['activities'] ?? [];
}

function getNomeUtilizador($id, $lista) {
    foreach ($lista as $u) {
        if ($u['id'] == $id) return $u['firstname'] . ' ' . $u['lastname'];
    }
    return "ID $id";
}

function calcularDataProximaReuniao($inicio, $diasAdicionais) {
    $data = clone $inicio;
    $conta = 0;
    while ($conta < $diasAdicionais) {
        $data->modify('+1 day');
        if (!in_array($data->format('N'), ['6', '7'])) { // 6 = sábado, 7 = domingo
            $conta++;
        }
    }
    return $data;
}

$utilizadores = getUtilizadoresRedmine();
$equipa = $db->query("SELECT redmine_id FROM equipa")->fetchAll(PDO::FETCH_COLUMN);

if (!isset($_SESSION['gestor'])) {
    $_SESSION['gestor'] = null;
    $_SESSION['em_reuniao'] = false;
    $_SESSION['oradores'] = [];
    $_SESSION['orador_atual'] = 0;
    $_SESSION['inicio_reuniao'] = null;
}
$gestor = $_SESSION['gestor'];
$em_reuniao = $_SESSION['em_reuniao'];
$oradores = $_SESSION['oradores'];
$orador_atual = $_SESSION['orador_atual'];

// Ações do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $_SESSION['orador_atual'] = 0;
        $_SESSION['inicio_reuniao'] = time();
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

<?php if (!$em_reuniao && count($equipa) >= 2): ?>
  <form method="post" class="mb-4">
    <button type="submit" name="iniciar" class="btn btn-success">Iniciar Reunião</button>
  </form>
<?php endif; ?>

<?php if ($em_reuniao): ?>
  <div class="mb-3">
    <strong>👤 Gestor da reunião:</strong> <?= getNomeUtilizador($gestor, $utilizadores) ?><br>
    <strong>⏱️ Tempo total:</strong> <?= gmdate('H:i:s', time() - $_SESSION['inicio_reuniao']) ?>
  </div>
  <?php $fim = $orador_atual >= count($oradores); ?>
  <?php if ($fim): ?>
    <div class="alert alert-success">✅ Reunião concluída!</div>
    <?php session_destroy(); ?>
  <?php else: ?>
    <?php $oradorId = $oradores[$orador_atual]; ?>
    <h3 class="text-primary">🎤 Orador atual: <?= getNomeUtilizador($oradorId, $utilizadores) ?></h3>
    <div id="cronometro" class="display-4 my-3">30</div>
    <audio id="beep" src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg"></audio>
    <script>
      let tempo = 30;
      const el = document.getElementById("cronometro");
      const beep = document.getElementById("beep");
      const intervalo = setInterval(() => {
        tempo--;
        el.textContent = tempo;
        if (tempo === 5) beep.play();
        if (tempo <= 0) clearInterval(intervalo);
      }, 1000);
    </script>

    <h5>Últimas atividades:</h5>
    <ul class="list-group">
      <?php foreach (getAtividadesUtilizador($oradorId) as $act): ?>
        <li class="list-group-item"><?= htmlspecialchars($act['title']) ?></li>
      <?php endforeach; ?>
    </ul>

    <form method="post" class="mt-3">
      <button type="submit" name="proximo" class="btn btn-warning">➡️ Próximo</button>
    </form>
  <?php endif; ?>
<?php endif; ?>

<hr class="my-5">
<h4>Próximos possíveis gestores de reunião (10 aleatórios):</h4>
<ul class="list-group mb-4">
  <?php
    $candidatos = $equipa;
    shuffle($candidatos);
    $proximos = array_slice($candidatos, 0, 10);
    $hoje = new DateTime();
    $diasAdicionais = 0;
    foreach ($proximos as $id):
      $dataPrevista = calcularDataProximaReuniao($hoje, $diasAdicionais);
      $diasAdicionais++;
  ?>
    <li class="list-group-item d-flex justify-content-between align-items-center">
      <span>
        <?= getNomeUtilizador($id, $utilizadores) ?> - <small><?= $dataPrevista->format('d/m/Y') ?></small>
      </span>
      <form method="post" class="d-inline">
        <input type="hidden" name="recusar" value="<?= $id ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger">Recusar</button>
      </form>
    </li>
  <?php endforeach; ?>
</ul>

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
