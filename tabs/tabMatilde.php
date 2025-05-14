<?php
// Configuração do banco de dados SQLite
$dbPath = "taboleiro.db";
$createTable = false;

// Criar conexão com o banco de dados
try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verificar se a tabela existe
    $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pontos_controle'");
    if ($tableCheck->fetchColumn() === false) {
        $createTable = true;
    }
    
    // Criar tabela se não existir
    if ($createTable) {
        $db->exec("CREATE TABLE pontos_controle (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            x REAL NOT NULL,
            y REAL NOT NULL,
            raio REAL DEFAULT 5
        )");
        
        $db->exec("CREATE TABLE caminhos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ponto_inicio INTEGER,
            sequencia TEXT,
            FOREIGN KEY (ponto_inicio) REFERENCES pontos_controle(id)
        )");
        
        $db->exec("CREATE TABLE detalhes_caminho (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            caminho_id INTEGER,
            ponto_id INTEGER,
            lado TEXT,
            distancia REAL,
            FOREIGN KEY (caminho_id) REFERENCES caminhos(id),
            FOREIGN KEY (ponto_id) REFERENCES pontos_controle(id)
        )");
    }
} catch (PDOException $e) {
    die("Erro na conexão com o banco de dados: " . $e->getMessage());
}

// Processar requisições AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['action'])) {
        switch ($data['action']) {
            case 'adicionar_ponto':
                if (isset($data['x'], $data['y'], $data['raio'])) {
                    try {
                        $stmt = $db->prepare("INSERT INTO pontos_controle (x, y, raio) VALUES (:x, :y, :raio)");
                        $stmt->execute([
                            ':x' => $data['x'],
                            ':y' => $data['y'],
                            ':raio' => $data['raio']
                        ]);
                        echo json_encode(['status' => 'success', 'id' => $db->lastInsertId()]);
                    } catch (PDOException $e) {
                        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'remover_ponto':
                if (isset($data['id'])) {
                    try {
                        $stmt = $db->prepare("DELETE FROM pontos_controle WHERE id = :id");
                        $stmt->execute([':id' => $data['id']]);
                        echo json_encode(['status' => 'success']);
                    } catch (PDOException $e) {
                        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'atualizar_ponto':
                if (isset($data['id'], $data['raio'])) {
                    try {
                        $stmt = $db->prepare("UPDATE pontos_controle SET raio = :raio WHERE id = :id");
                        $stmt->execute([
                            ':id' => $data['id'],
                            ':raio' => $data['raio']
                        ]);
                        echo json_encode(['status' => 'success']);
                    } catch (PDOException $e) {
                        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'iniciar_caminho':
                if (isset($data['ponto_inicio'])) {
                    try {
                        $stmt = $db->prepare("INSERT INTO caminhos (ponto_inicio) VALUES (:ponto_inicio)");
                        $stmt->execute([':ponto_inicio' => $data['ponto_inicio']]);
                        echo json_encode(['status' => 'success', 'id' => $db->lastInsertId()]);
                    } catch (PDOException $e) {
                        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    }
                }
                break;
                
            case 'adicionar_detalhe_caminho':
                if (isset($data['caminho_id'], $data['ponto_id'], $data['lado'], $data['distancia'])) {
                    try {
                        $stmt = $db->prepare("INSERT INTO detalhes_caminho 
                            (caminho_id, ponto_id, lado, distancia) 
                            VALUES (:caminho_id, :ponto_id, :lado, :distancia)");
                        $stmt->execute([
                            ':caminho_id' => $data['caminho_id'],
                            ':ponto_id' => $data['ponto_id'],
                            ':lado' => $data['lado'],
                            ':distancia' => $data['distancia']
                        ]);
                        echo json_encode(['status' => 'success']);
                    } catch (PDOException $e) {
                        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    }
                }
                break;
        }
        exit;
    }
}

// Obter todos os pontos
$pontos = [];
try {
    $stmt = $db->query("SELECT * FROM pontos_controle");
    $pontos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Erro silencioso
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabuleiro de Entrelaçamento</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        
        #container {
            display: flex;
            gap: 20px;
        }
        
        #taboleiro {
            width: 400px;
            height: 400px;
            border: 1px solid #000;
            position: relative;
            background-color: #f5f5f5;
        }
        
        .ponto {
            position: absolute;
            background-color: #3498db;
            border-radius: 50%;
            cursor: pointer;
            transform: translate(-50%, -50%);
        }
        
        .ponto.selecionado {
            background-color: #e74c3c;
        }
        
        .ponto.inicio {
            background-color: #2ecc71;
        }
        
        .linha {
            position: absolute;
            background-color: #2c3e50;
            transform-origin: 0 0;
            pointer-events: none;
        }
        
        #controles {
            width: 300px;
        }
        
        .form-grupo {
            margin-bottom: 15px;
        }
        
        .form-grupo label {
            display: block;
            margin-bottom: 5px;
        }
        
        .form-grupo input, .form-grupo select, .form-grupo button {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }
        
        button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 15px;
            cursor: pointer;
            margin-top: 5px;
        }
        
        button:hover {
            background-color: #2980b9;
        }
        
        button.iniciar {
            background-color: #2ecc71;
        }
        
        button.iniciar:hover {
            background-color: #27ae60;
        }
        
        .espacador {
            margin-top: 20px;
            border-top: 1px solid #ccc;
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <h1>Tabuleiro de Entrelaçamento</h1>
    
    <div id="container">
        <div id="taboleiro">
            <!-- Os pontos e linhas serão adicionados dinamicamente aqui -->
        </div>
        
        <div id="controles">
            <h2>Adicionar Ponto</h2>
            <div class="form-grupo">
                <label for="raio">Raio do ponto (em pixels):</label>
                <input type="number" id="raio" min="2" max="20" value="5">
            </div>
            
            <button id="btn-modo-adicionar">Modo: Adicionar Pontos</button>
            
            <p>Clique no tabuleiro para adicionar pontos. Clique com o botão direito em um ponto para removê-lo.</p>
            
            <div class="espacador"></div>
            
            <h2>Entrelaçar Fios</h2>
            <div class="form-grupo">
                <label for="distancia">Distância do ponto (em pixels):</label>
                <input type="number" id="distancia" min="1" max="50" value="10">
            </div>
            
            <div class="form-grupo">
                <label for="lado">Lado do ponto:</label>
                <select id="lado">
                    <option value="esquerda">Esquerda</option>
                    <option value="direita">Direita</option>
                </select>
            </div>
            
            <button id="btn-iniciar-caminho" class="iniciar">Iniciar Entrelaçamento</button>
            <button id="btn-finalizar-caminho" disabled>Finalizar Entrelaçamento</button>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const taboleiro = document.getElementById('taboleiro');
            const btnModoAdicionar = document.getElementById('btn-modo-adicionar');
            const btnIniciarCaminho = document.getElementById('btn-iniciar-caminho');
            const btnFinalizarCaminho = document.getElementById('btn-finalizar-caminho');
            const inputRaio = document.getElementById('raio');
            const inputDistancia = document.getElementById('distancia');
            const selectLado = document.getElementById('lado');
            
            let modo = 'adicionar'; // 'adicionar' ou 'entrelacar'
            let pontos = <?php echo json_encode($pontos); ?>;
            let caminhoAtual = null;
            let pontoInicioId = null;
            let sequenciaPontos = [];
            
            // Fator de escala: 400px no taboleiro = 20cm físicos
            const escala = 400 / 20; // pixels por cm
            
            // Inicializar o tabuleiro com os pontos existentes
            function inicializarTaboleiro() {
                // Limpar o taboleiro
                while (taboleiro.firstChild) {
                    taboleiro.removeChild(taboleiro.firstChild);
                }
                
                // Adicionar pontos existentes
                pontos.forEach(ponto => {
                    criarElementoPonto(ponto);
                });
            }
            
            // Criar um elemento visual para um ponto
            function criarElementoPonto(ponto) {
                const elemento = document.createElement('div');
                elemento.className = 'ponto';
                elemento.dataset.id = ponto.id;
                elemento.style.left = ponto.x + 'px';
                elemento.style.top = ponto.y + 'px';
                elemento.style.width = (ponto.raio * 2) + 'px';
                elemento.style.height = (ponto.raio * 2) + 'px';
                
                elemento.addEventListener('click', function(e) {
                    if (modo === 'entrelacar') {
                        selecionarPonto(ponto.id);
                    }
                    e.stopPropagation();
                });
                
                elemento.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    removerPonto(ponto.id);
                });
                
                taboleiro.appendChild(elemento);
                return elemento;
            }
            
            // Adicionar um novo ponto
            function adicionarPonto(x, y, raio) {
                // Enviar para o servidor
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'adicionar_ponto',
                        x: x,
                        y: y,
                        raio: raio
                    }),
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const novoPonto = {
                            id: data.id,
                            x: x,
                            y: y,
                            raio: raio
                        };
                        pontos.push(novoPonto);
                        criarElementoPonto(novoPonto);
                    } else {
                        alert('Erro ao adicionar ponto: ' + data.message);
                    }
                });
            }
            
            // Remover um ponto
            function removerPonto(id) {
                // Enviar para o servidor
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'remover_ponto',
                        id: id
                    }),
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Remover do array local
                        pontos = pontos.filter(p => p.id != id);
                        
                        // Remover do DOM
                        const elemento = document.querySelector(`.ponto[data-id="${id}"]`);
                        if (elemento) {
                            taboleiro.removeChild(elemento);
                        }
                    } else {
                        alert('Erro ao remover ponto: ' + data.message);
                    }
                });
            }
            
            // Iniciar um novo caminho
            function iniciarCaminho() {
                modo = 'entrelacar';
                btnModoAdicionar.disabled = true;
                btnIniciarCaminho.disabled = true;
                btnFinalizarCaminho.disabled = false;
                
                // Limpar qualquer caminho anterior
                limparLinhas();
                limparSelecaoPontos();
                
                caminhoAtual = null;
                pontoInicioId = null;
                sequenciaPontos = [];
                
                alert('Selecione o ponto de início do entrelaçamento');
            }
            
            // Finalizar o caminho atual
            function finalizarCaminho() {
                modo = 'adicionar';
                btnModoAdicionar.disabled = false;
                btnIniciarCaminho.disabled = false;
                btnFinalizarCaminho.disabled = true;
                
                // Limpar seleção
                limparSelecaoPontos();
                
                alert('Entrelaçamento finalizado');
            }
            
            // Selecionar um ponto para o caminho
            function selecionarPonto(id) {
                const ponto = pontos.find(p => p.id == id);
                if (!ponto) return;
                
                // Primeiro ponto = início do caminho
                if (pontoInicioId === null) {
                    pontoInicioId = id;
                    
                    // Marcar visualmente
                    const elemento = document.querySelector(`.ponto[data-id="${id}"]`);
                    if (elemento) {
                        elemento.classList.add('inicio');
                    }
                    
                    // Iniciar caminho no servidor
                    fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'iniciar_caminho',
                            ponto_inicio: id
                        }),
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            caminhoAtual = data.id;
                            sequenciaPontos.push({id: id, elemento: ponto});
                            alert('Ponto inicial selecionado. Agora selecione o próximo ponto.');
                        } else {
                            alert('Erro ao iniciar caminho: ' + data.message);
                        }
                    });
                    
                    return;
                }
                
                // Não permitir selecionar o mesmo ponto duas vezes seguidas
                if (sequenciaPontos.length > 0 && sequenciaPontos[sequenciaPontos.length - 1].id == id) {
                    alert('Você já selecionou este ponto.');
                    return;
                }
                
                // Adicionar ao caminho
                sequenciaPontos.push({id: id, elemento: ponto});
                
                // Marcar visualmente
                const elemento = document.querySelector(`.ponto[data-id="${id}"]`);
                if (elemento) {
                    elemento.classList.add('selecionado');
                }
                
                // Se temos pelo menos dois pontos, desenhar a linha
                if (sequenciaPontos.length >= 2) {
                    const pontoAnterior = sequenciaPontos[sequenciaPontos.length - 2].elemento;
                    const pontoAtual = ponto;
                    const lado = selectLado.value;
                    const distancia = parseFloat(inputDistancia.value);
                    
                    desenharLinha(pontoAnterior, pontoAtual, lado, distancia);
                    
                    // Salvar detalhe do caminho
                    fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'adicionar_detalhe_caminho',
                            caminho_id: caminhoAtual,
                            ponto_id: id,
                            lado: lado,
                            distancia: distancia
                        }),
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') {
                            alert('Erro ao adicionar detalhe do caminho: ' + data.message);
                        }
                    });
                }
            }
            
            // Desenhar uma linha entre dois pontos com desvio
            function desenharLinha(pontoA, pontoB, lado, distancia) {
                // Calcular ângulo entre os pontos
                const dx = pontoB.x - pontoA.x;
                const dy = pontoB.y - pontoA.y;
                const angulo = Math.atan2(dy, dx);
                const comprimento = Math.sqrt(dx * dx + dy * dy);
                
                // Calcular os pontos de controle (com desvio)
                let anguloDesvio;
                if (lado === 'esquerda') {
                    anguloDesvio = angulo - Math.PI/2; // -90 graus
                } else {
                    anguloDesvio = angulo + Math.PI/2; // +90 graus
                }
                
                const pontoControleAX = pontoA.x + pontoA.raio * Math.cos(angulo) + distancia * Math.cos(anguloDesvio);
                const pontoControleAY = pontoA.y + pontoA.raio * Math.sin(angulo) + distancia * Math.sin(anguloDesvio);
                
                const pontoControleBX = pontoB.x - pontoB.raio * Math.cos(angulo) + distancia * Math.cos(anguloDesvio);
                const pontoControleBY = pontoB.y - pontoB.raio * Math.sin(angulo) + distancia * Math.sin(anguloDesvio);
                
                // Criar um elemento SVG para desenhar a curva bezier
                const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
                svg.style.position = 'absolute';
                svg.style.top = '0';
                svg.style.left = '0';
                svg.style.width = '100%';
                svg.style.height = '100%';
                svg.style.pointerEvents = 'none';
                
                const caminho = document.createElementNS("http://www.w3.org/2000/svg", "path");
                caminho.setAttribute('d', `M ${pontoA.x} ${pontoA.y} 
                                        C ${pontoControleAX} ${pontoControleAY}, 
                                        ${pontoControleBX} ${pontoControleBY}, 
                                        ${pontoB.x} ${pontoB.y}`);
                caminho.setAttribute('stroke', '#27ae60');
                caminho.setAttribute('stroke-width', '2');
                caminho.setAttribute('fill', 'none');
                
                svg.appendChild(caminho);
                taboleiro.appendChild(svg);
            }
            
            // Limpar todas as linhas
            function limparLinhas() {
                const svgs = taboleiro.querySelectorAll('svg');
                svgs.forEach(svg => {
                    taboleiro.removeChild(svg);
                });
            }
            
            // Limpar seleção visual dos pontos
            function limparSelecaoPontos() {
                const pontosSelecionados = taboleiro.querySelectorAll('.ponto.selecionado, .ponto.inicio');
                pontosSelecionados.forEach(ponto => {
                    ponto.classList.remove('selecionado', 'inicio');
                });
            }
            
            // Evento de clique no taboleiro para adicionar ponto
            taboleiro.addEventListener('click', function(e) {
                if (modo === 'adicionar') {
                    const rect = taboleiro.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    const raio = parseFloat(inputRaio.value);
                    
                    adicionarPonto(x, y, raio);
                }
            });
            
            // Evento de botão modo adicionar
            btnModoAdicionar.addEventListener('click', function() {
                modo = 'adicionar';
                btnModoAdicionar.disabled = true;
                btnIniciarCaminho.disabled = false;
                btnFinalizarCaminho.disabled = true;
                
                limparSelecaoPontos();
            });
            
            // Evento de botão iniciar caminho
            btnIniciarCaminho.addEventListener('click', iniciarCaminho);
            
            // Evento de botão finalizar caminho
            btnFinalizarCaminho.addEventListener('click', finalizarCaminho);
            
            // Inicializar taboleiro
            inicializarTaboleiro();
        });
    </script>
</body>
</html>