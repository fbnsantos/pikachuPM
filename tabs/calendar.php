<?php
// calendar.php
session_start();
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
if (isset($_GET['offset'])) {
    $hoje->modify((int)$_GET['offset'] . ' days');
}
$inicioSemana = clone $hoje;
$inicioSemana->modify('monday this week');
$datas = [];
for ($i = 0; $i < 28; $i++) {
    $data = clone $inicioSemana;
    $data->modify("+$i days");
    $datas[] = $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['data'], $_POST['tipo'], $_POST['descricao'])) {
        $stmt = $db->prepare("INSERT INTO eventos (data, tipo, descricao, criador, cor) VALUES (:data, :tipo, :descricao, :criador, :cor)");
        $cor = match ($_POST['tipo']) {
            'ferias' => 'green',
            'demo' => 'blue',
            'campo' => 'orange',
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
    header("Location: calendar.php?offset=" . ($_GET['offset'] ?? 0));
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
    .calendario { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
    .dia { border: 1px solid #ccc; min-height: 150px; padding: 5px; position: relative; background: #f9f9f9; }
    .data { font-weight: bold; }
    .evento { font-size: 0.85em; padding: 2px 4px; margin-top: 2px; border-radius: 4px; color: white; display: block; }
</style>

<div class="container mt-4">
    <h2 class="mb-4">Calendário Colaborativo (4 semanas)</h2>
    <div class="d-flex