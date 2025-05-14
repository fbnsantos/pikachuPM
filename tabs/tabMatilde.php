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
    <title>Tabuleiro com Pontos e Splines</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; flex-direction: column; align-items: center; }
        #board { width: 800px; height: 600px; border: 1px solid #333; position: relative; margin-bottom: 20px; }
        .point { position: absolute; border-radius: 50%; background-color: #007bff; opacity: 0.8; }
        canvas { position: absolute; top: 0; left: 0; pointer-events: none; }
    </style>
</head>
<body>
    <h2>Tabuleiro com Pontos e Splines</h2>
    <div id="board" onclick="addPoint(event)">
        <canvas id="splineCanvas" width="800" height="600"></canvas>
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

        // Desenhar as splines
        function drawSplines() {
            const canvas = document.getElementById('splineCanvas');
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const points = [
                <?php foreach ($points as $point) : ?>
                    { x: <?= $point['x'] ?>, y: <?= $point['y'] ?> },
                <?php endforeach; ?>
            ];
            if (points.length < 2) return;
            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) {
                const cp1x = (points[i - 1].x + points[i].x) / 2;
                const cp1y = (points[i - 1].y + points[i].y) / 2;
                ctx.quadraticCurveTo(points[i - 1].x, points[i - 1].y, cp1x, cp1y);
            }
            ctx.lineTo(points[points.length - 1].x, points[points.length - 1].y);
            ctx.strokeStyle = '#ff5733';
            ctx.lineWidth = 2;
            ctx.stroke();
        }
        window.onload = drawSplines;
    </script>
</body>
</html>
