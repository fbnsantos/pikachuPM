<?php
// Configuração da base de dados
$dbFile = 'points.db';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Criar a tabela se não existir
$db->exec('CREATE TABLE IF NOT EXISTS points (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    x INTEGER NOT NULL,
    y INTEGER NOT NULL,
    diameter INTEGER NOT NULL
);');

// Adicionar ponto se os dados forem enviados via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['x']) && isset($_POST['y']) && isset($_POST['diameter'])) {
    $x = (int)$_POST['x'];
    $y = (int)$_POST['y'];
    $diameter = (int)$_POST['diameter'];
    $stmt = $db->prepare('INSERT INTO points (x, y, diameter) VALUES (?, ?, ?)');
    $stmt->execute([$x, $y, $diameter]);
}

// Obter todos os pontos existentes
$points = $db->query('SELECT * FROM points')->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabuleiro com Pontos</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; flex-direction: column; align-items: center; }
        #board { width: 800px; height: 600px; border: 1px solid #333; position: relative; margin-bottom: 20px; }
        .point { position: absolute; border-radius: 50%; background-color: #007bff; opacity: 0.8; }
    </style>
</head>
<body>
    <h2>Tabuleiro com Pontos</h2>
    <div id="board" onclick="addPoint(event)">
        <?php foreach ($points as $point) : ?>
            <div class="point" style="width: <?= $point['diameter'] ?>px; height: <?= $point['diameter'] ?>px; left: <?= $point['x'] ?>px; top: <?= $point['y'] ?>px;"></div>
        <?php endforeach; ?>
    </div>
    <form method="POST" id="pointForm">
        <input type="hidden" name="x" id="pointX">
        <input type="hidden" name="y" id="pointY">
        <label>Diâmetro: <input type="number" name="diameter" required></label>
        <button type="submit">Adicionar Ponto</button>
    </form>
    <script>
        function addPoint(event) {
            const board = document.getElementById('board');
            const rect = board.getBoundingClientRect();
            const x = event.clientX - rect.left;
            const y = event.clientY - rect.top;
            document.getElementById('pointX').value = x;
            document.getElementById('pointY').value = y;
            document.getElementById('pointForm').submit();
        }
    </script>
</body>
</html>

