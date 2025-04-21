<?php
// tabs/equipa.php
session_start();

$equipa = $_SESSION['equipa'] ?? [];
$gestor = $_SESSION['gestor'] ?? null;
$em_reuniao = $_SESSION['em_reuniao'] ?? false;
$em_orador = $_SESSION['orador_atual'] ?? 0;

// Simulação de utilizadores do Redmine (poderia vir da API)
$utilizadores = [
    ['id' => 1, 'nome' => 'Ana'],
    ['id' => 2, 'nome' => 'Bruno'],
    ['id' => 3, 'nome' => 'Carla'],
    ['id' => 4, 'nome' => 'Daniel'],
    ['id' => 5, 'nome' => 'Eduarda'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['criar_equipa'])) {
        $_SESSION['equipa'] = $_POST['membros'] ?? [];
        $_SESSION['gestor'] = $_SESSION['equipa'][array_rand($_SESSION['equipa'])];
        $_SESSION['em_reuniao'] = false;
        $_SESSION['orador_atual'] = 0;
        header("Location: ?tab=equipa");
        exit;
    }
    if (isset($_POST['iniciar'])) {
        $_SESSION['em_reuniao'] = true;
        $_SESSION['orador_atual'] = 0;
        header("Location: ?tab=equipa");
        exit;
    }
    if (isset($_POST['proximo'])) {
        $_SESSION['orador_atual']++;
        header("Location: ?tab=equipa");
        exit;
    }
}

function getNomeById($id, $lista) {
    foreach ($lista as $u) {
        if ($u['id'] == $id) return $u['nome'];
    }
    return 'Desconhecido';
}
?>

<h2>Equipa e Reunião Diária</h2>

<?php if (!$equipa): ?>
  <form method="post">
    <p>Seleciona os membros da equipa:</p>
    <?php foreach ($utilizadores as $u): ?>
      <label>
        <input type="checkbox" name="membros[]" value="<?= $u['id'] ?>">
        <?= htmlspecialchars($u['nome']) ?>
      </label><br>
    <?php endforeach; ?>
    <br>
    <button type="submit" name="criar_equipa">Criar Equipa</button>
  </form>
<?php else: ?>
  <p><strong>Equipa criada:</strong> 
    <?= implode(', ', array_map(fn($id) => getNomeById($id, $utilizadores), $equipa)) ?>
  </p>
  <p><strong>Gestor da Reunião:</strong> <?= getNomeById($gestor, $utilizadores) ?></p>

  <?php if (!$em_reuniao): ?>
    <form method="post">
      <button type="submit" name="iniciar">Iniciar Reunião</button>
    </form>
  <?php else: ?>
    <?php
      $oradorAtualId = $equipa[$em_orador] ?? null;
      $fim = $em_orador >= count($equipa);
    ?>

    <?php if ($fim): ?>
      <p><strong>✅ Reunião concluída!</strong></p>
      <?php session_destroy(); ?>
    <?php else: ?>
      <div id="cronometro" style="font-size: 2em; margin: 20px 0;">30</div>
      <p><strong>Orador atual:</strong> <?= getNomeById($oradorAtualId, $utilizadores) ?></p>

      <?php if ($_SESSION['user_id'] == $gestor): ?>
        <form method="post">
          <button type="submit" name="proximo">➡️ Próximo</button>
        </form>
      <?php else: ?>
        <p>A aguardar passagem do gestor...</p>
      <?php endif; ?>

      <script>
        let tempo = 30;
        const cron = document.getElementById('cronometro');
        const intervalo = setInterval(() => {
          tempo--;
          cron.textContent = tempo;
          if (tempo <= 0) clearInterval(intervalo);
        }, 1000);
      </script>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
