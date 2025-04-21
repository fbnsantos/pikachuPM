<?php
// calendar.php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$db_path = __DIR__ . '/../eventos.sqlite';
$nova_base = !file_exists($db_path);

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($nova_base) {
        $db->exec("CREATE TABLE eventos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data TEXT NOT NULL,
            tipo TEXT NOT NULL,
            descricao TEXT,
            criador TEXT,
            cor TEXT NOT NULL
        )");
    }
} catch (Exception $e) {
    die("Erro ao inicializar base de dados: " . $e->getMessage());
}

$hoje = new DateTime();
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$hoje->modify("$offset days");
$inicioSemana = clone $hoje;
$inicioSemana->modify('monday this week');
$datas = [];
$numSemanas = isset($_GET['semanas']) ? max(1, min(10, (int)$_GET['semanas'])) : 4;
for ($i = 0; $i < $numSemanas * 7; $i++) {
    $data = clone $inicioSemana;
    $data->modify("+$i days");
    $datas[] = $data;
}

// Debug
// echo '<pre>'; print_r($datas); echo '</pre>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['data'], $_POST['tipo'], $_POST['descricao'])) {
        $stmt = $db->prepare("INSERT INTO eventos (data, tipo, descricao, criador, cor) VALUES (:data, :tipo, :descricao, :criador, :cor)");
        $cor = match ($_POST['tipo']) {
            'ferias' => 'green',
            'demo' => 'blue',
            'campo' => 'orange',
            'aulas' => 'yellow',
            default => 'red'
        };
        $stmt->execute([
            ':data' => $_POST['data'],
            ':tipo' => $_POST['tipo'],
            ':descricao' => $_POST['descricao'],
            ':criador' => $_SESSION['user'] ?? 'anon',
            ':cor' => $cor
        ]);
    } elseif (isset($_POST['delete'])) {
        $stmt = $db->prepare("DELETE FROM eventos WHERE id = :id");
        $stmt->execute([':id' => $_POST['delete']]);
    }
    header("Location: index.php?tab=calendar&offset=$offset");
    exit;
}

$stmt = $db->query("SELECT * FROM eventos");
$eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$eventos_por_dia = [];
foreach ($eventos as $e) {
    $eventos_por_dia[$e['data']][] = $e;
}

?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .calendario { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr 0.6fr 0.6fr; gap: 5px; }
    .dia { border: 1px solid #ccc; min-height: 150px; padding: 5px; position: relative; background: #f9f9f9;
    box-sizing: border-box; }
    .data { font-weight: bold; }
    .evento { font-size: 0.85em; padding: 2px 4px; margin-top: 2px; border-radius: 4px; color: white; display: block; }
    .fade { opacity: 0; transition: opacity 0.3s ease-in-out; }
    .fade.show { opacity: 1; }
    .hoje { background: #fff4cc !important; border: 2px solid #f5b041; }
    .fimsemana { background: #f0f0f0; opacity: 0.6; font-size: 0.85em; }
    
</style>

<div class="container mt-4">
    <h2 class="mb-4">Calendário Colaborativo (4 semanas)</h2>
    <div class="d-flex justify-content-between mb-3">
    <a class="btn btn-secondary" href="?tab=calendar&offset=<?= $offset - 7 ?>">&laquo; Semana anterior</a>
    <a class="btn btn-outline-primary" href="?tab=calendar">Hoje</a>
    <a class="btn btn-secondary" href="?tab=calendar&offset=<?= $offset + 7 ?>">Semana seguinte &raquo;</a>
</div>

    <form method="get" class="mb-3">
    <input type="hidden" name="tab" value="calendar">
    <input type="hidden" name="offset" value="<?= $offset ?>">
    <label for="semanas" class="form-label">Número de semanas a mostrar:</label>
    <select name="semanas" id="semanas" class="form-select form-select-sm w-auto d-inline-block" onchange="this.form.submit()">
        <?php for ($i = 1; $i <= 10; $i++): ?>
            <option value="<?= $i ?>" <?= $i == $numSemanas ? 'selected' : '' ?>><?= $i ?></option>
        <?php endfor; ?>
    </select>
</form>
<div class="calendario">
        <?php foreach ($datas as $data): 
            $data_str = $data->format('Y-m-d');
        ?>
        <?php $isHoje = $data->format('Y-m-d') === (new DateTime())->format('Y-m-d'); ?>
<?php
    $diaSemana = $data->format('N');
    $isHoje = $data->format('Y-m-d') === (new DateTime())->format('Y-m-d');
    $classeExtra = ($diaSemana == 6 || $diaSemana == 7) ? ' fimsemana' : '';
?>
<div class="dia<?= $isHoje ? ' hoje' : '' ?><?= $classeExtra ?>">
            <div class="data"><?= $data->format('D d/m/Y') ?></div>
            <?php if (isset($eventos_por_dia[$data_str])): ?>
                <?php foreach ($eventos_por_dia[$data_str] as $ev): ?>
                    <form method="post" class="d-flex justify-content-between align-items-center">
                        <span class="evento" style="background: <?= $ev['cor'] ?>;">
                            <?= htmlspecialchars($ev['tipo']) ?>: <?= htmlspecialchars($ev['descricao']) ?>
                        </span>
                        <button type="submit" name="delete" value="<?= $ev['id'] ?>" class="btn btn-sm btn-outline-danger ms-1">x</button>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="toggleForm(this)">+ Adicionar</button>
<form method="post" class="mt-2 d-none">
                <input type="hidden" name="data" value="<?= $data_str ?>">
                <select name="tipo" class="form-select form-select-sm mb-1">
                    <option value="ferias">Férias</option>
                    <option value="demo">Demonstração</option>
                    <option value="campo">Saída de campo</option>
                    <option value="aulas">Aulas</option>
                    <option value="outro">Outro</option>
                </select>
                <input type="text" name="descricao" placeholder="Descrição" class="form-control form-control-sm mb-1" required>
                <button type="submit" class="btn btn-sm btn-primary">Adicionar</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
function toggleForm(button) {
    const form = button.nextElementSibling;
    form.classList.toggle('d-none');
    button.classList.toggle('d-none');
    form.classList.add('fade');
    form.classList.add('show');
}

// Fechar formulários ao submeter
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form').forEach(f => {
        f.addEventListener('submit', () => {
            const parent = f.closest('.dia');
            const btn = parent.querySelector('button[type="button"]');
            f.classList.add('d-none');
            if (btn) btn.classList.remove('d-none');
        });
    });
});
</script>
