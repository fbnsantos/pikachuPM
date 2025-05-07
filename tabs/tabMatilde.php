<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3D Trajectory Builder</title>
    <script src="https://cdn.jsdelivr.net/npm/p5@1.6.0/lib/p5.js"></script>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; }
        #canvas-container { display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f4f4f9; }
        #controls { position: absolute; top: 20px; left: 20px; background-color: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.15); }
        input, button { margin-bottom: 10px; width: 100%; }
    </style>
</head>
<body>
    <div id="controls">
        <h3>Configuração do Taboleiro</h3>
        <label>Dimensão do Taboleiro (mm):</label>
        <input type="number" id="boardWidth" placeholder="Largura">
        <input type="number" id="boardHeight" placeholder="Altura">
        <label>Diâmetro dos Pinos (mm):</label>
        <input type="number" id="pinDiameter" placeholder="Diâmetro">
        <label>Diâmetro do Fio (mm):</label>
        <input type="number" id="wireDiameter" placeholder="Diâmetro">
        <button onclick="saveSettings()">Salvar Configurações</button>
        <button onclick="generateTrajectory()">Gerar Trajetória</button>
    </div>
    <div id="canvas-container">
        <canvas id="workspace"></canvas>
    </div>
    <script>
        let points = [];

        function setup() {
            let canvas = createCanvas(window.innerWidth, window.innerHeight);
            canvas.parent('canvas-container');
            background(240);
            loadPoints();
        }

        function mousePressed() {
            let x = mouseX;
            let y = mouseY;
            addPoint(x, y);
        }

        function addPoint(x, y) {
            points.push({x, y});
            ellipse(x, y, 10, 10);
            savePoint(x, y);
        }

        function savePoint(x, y) {
            fetch('save_point.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ x: x, y: y })
            });
        }

        function loadPoints() {
            fetch('load_points.php')
                .then(response => response.json())
                .then(loadedPoints => {
                    points = loadedPoints;
                    points.forEach(point => {
                        ellipse(point.x, point.y, 10, 10);
                    });
                });
        }

        function saveSettings() {
            const width = document.getElementById('boardWidth').value;
            const height = document.getElementById('boardHeight').value;
            const pinDiameter = document.getElementById('pinDiameter').value;
            const wireDiameter = document.getElementById('wireDiameter').value;
            fetch('save_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ width, height, pinDiameter, wireDiameter })
            });
        }

        function generateTrajectory() {
            if (points.length < 2) return alert('Adiciona pelo menos dois pontos.');
            background(240);
            for (let i = 0; i < points.length - 1; i++) {
                let p1 = points[i];
                let p2 = points[i + 1];
                drawSpline(p1, p2);
            }
        }

        function drawSpline(p1, p2) {
            noFill();
            stroke(0);
            beginShape();
            for (let t = 0; t <= 1; t += 0.05) {
                let x = lerp(p1.x, p2.x, t);
                let y = lerp(p1.y, p2.y, t);
                vertex(x, y);
            }
            endShape();
        }
    </script>
</body>
</html>

