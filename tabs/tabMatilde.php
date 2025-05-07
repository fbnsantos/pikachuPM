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
        #controls { position: absolute; top: 20px; left: 20px; background-color: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.15); width: 250px; }
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
        <label>Número de Camadas:</label>
        <input type="number" id="layers" placeholder="Camadas">
        <button onclick="saveSettings()">Salvar Configurações</button>
        <button onclick="generateBoard()">Gerar Taboleiro</button>
        <button onclick="generateTrajectory()">Gerar Trajetória</button>
    </div>
    <div id="canvas-container">
        <canvas id="workspace"></canvas>
    </div>
    <script>
        let points = [];
        let boardWidth = 500;
        let boardHeight = 500;
        let pinDiameter = 20;
        let wireDiameter = 2;
        let layers = 1;

        function setup() {
            let canvas = createCanvas(window.innerWidth, window.innerHeight, WEBGL);
            canvas.parent('canvas-container');
            background(240);
        }

        function generateBoard() {
            background(240);
            const cols = Math.floor(boardWidth / pinDiameter);
            const rows = Math.floor(boardHeight / pinDiameter);
            points = [];
            for (let i = 0; i < cols; i++) {
                for (let j = 0; j < rows; j++) {
                    let x = i * pinDiameter + pinDiameter / 2 - boardWidth / 2;
                    let y = j * pinDiameter + pinDiameter / 2 - boardHeight / 2;
                    let z = 0;
                    points.push({x, y, z});
                    push();
                    translate(x, y, z);
                    fill(150);
                    sphere(pinDiameter / 4);
                    pop();
                }
            }
        }

        function generateTrajectory() {
            if (points.length < 2) return alert('Adiciona pelo menos dois pontos.');
            background(240);
            generateBoard();
            strokeWeight(wireDiameter);
            noFill();
            stroke(0, 100, 200);
            for (let layer = 0; layer < layers; layer++) {
                beginShape();
                points.forEach((p, index) => {
                    let zOffset = layer * wireDiameter * 2;
                    vertex(p.x, p.y, zOffset);
                });
                endShape(CLOSE);
            }
        }

        function saveSettings() {
            boardWidth = parseInt(document.getElementById('boardWidth').value) || 500;
            boardHeight = parseInt(document.getElementById('boardHeight').value) || 500;
            pinDiameter = parseInt(document.getElementById('pinDiameter').value) || 20;
            wireDiameter = parseInt(document.getElementById('wireDiameter').value) || 2;
            layers = parseInt(document.getElementById('layers').value) || 1;
            alert('Configurações salvas!');
        }
    </script>
</body>
</html>

