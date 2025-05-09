<?php
// tabs/18-minutos-dashboard.php - Dashboard 18 Minutos

// Verificar se o utilizador está autenticado
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Acesso não autorizado. Por favor, faça login.</div>';
    exit;
}

// Incluir arquivo de configuração
include_once __DIR__ . '/../config.php';

// Conectar ao banco de dados MySQL
try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    // Verificar conexão
    if ($db->connect_error) {
        throw new Exception("Falha na conexão: " . $db->connect_error);
    }
    
    // Definir conjunto de caracteres para UTF-8
    $db->set_charset("utf8mb4");
    
    // Criar tabela de foco diário se não existir
    $db->query('CREATE TABLE IF NOT EXISTS daily_focus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        task_id INT NOT NULL,
        focus_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES user_tokens(user_id),
        FOREIGN KEY (task_id) REFERENCES todos(id)
    )');

    // Criar tabela de reflexões diárias se não existir
    $db->query('CREATE TABLE IF NOT EXISTS daily_reflections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        reflection_date DATE NOT NULL,
        reflection_text TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES user_tokens(user_id)
    )');
    
    // Obter foco do dia atual
    $today = date('Y-m-d');
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare('SELECT t.* FROM todos t JOIN daily_focus f ON t.id = f.task_id WHERE f.user_id = ? AND f.focus_date = ?');
    $stmt->bind_param('is', $user_id, $today);
    $stmt->execute();
    $focus_result = $stmt->get_result();
    $focus_tasks = $focus_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Obter tarefas para seleção de foco
    $stmt = $db->prepare('SELECT * FROM todos WHERE (autor = ? OR responsavel = ?) AND estado != "completada" ORDER BY data_limite ASC, created_at DESC');
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $all_tasks_result = $stmt->get_result();
    $all_tasks = $all_tasks_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Dados para o gráfico de progresso
    $stmt = $db->prepare('SELECT DATE(updated_at) as completed_date, COUNT(*) as completed_tasks FROM todos WHERE (autor = ? OR responsavel = ?) AND estado = "completada" AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(updated_at) ORDER BY completed_date ASC');
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $progress_result = $stmt->get_result();
    $progress_data = [];
    while ($row = $progress_result->fetch_assoc()) {
        $progress_data[$row['completed_date']] = $row['completed_tasks'];
    }
    $stmt->close();
    
    // Dados para o gráfico de foco semanal
    $focus_weekly_data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        
        // Total de tarefas focais
        $stmt = $db->prepare('SELECT COUNT(*) as total_focus FROM daily_focus WHERE user_id = ? AND focus_date = ?');
        $stmt->bind_param('is', $user_id, $date);
        $stmt->execute();
        $focus_count_result = $stmt->get_result()->fetch_assoc();
        $total_focus = $focus_count_result['total_focus'];
        $stmt->close();
        
        // Total de tarefas focais completadas
        $stmt = $db->prepare('SELECT COUNT(*) as completed_focus FROM daily_focus f JOIN todos t ON f.task_id = t.id WHERE f.user_id = ? AND f.focus_date = ? AND t.estado = "completada"');
        $stmt->bind_param('is', $user_id, $date);
        $stmt->execute();
        $completed_focus_result = $stmt->get_result()->fetch_assoc();
        $completed_focus = $completed_focus_result['completed_focus'];
        $stmt->close();
        
        // Adicionar ao array de dados
        $focus_weekly_data[$date] = [
            'total' => $total_focus,
            'completed' => $completed_focus
        ];
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Erro ao conectar ao banco de dados: ' . $e->getMessage() . '</div>';
    exit;
}

?>

<div class="container-fluid">
    <div class="alert alert-info mb-4">
        <h4><i class="bi bi-hourglass-split"></i> Método 18 Minutos</h4>
        <p>O método 18 minutos é uma técnica de produtividade desenvolvida por Peter Bregman, que ajuda a focar no que realmente importa todos os dias. Ele é dividido em três etapas:</p>
        <ul>
            <li><strong>Planeamento Matinal (5 minutos):</strong> Definir as tarefas mais importantes para o dia.</li>
            <li><strong>Pausas de Reavaliação (1 minuto a cada hora):</strong> Reavaliar o progresso e garantir foco.</li>
            <li><strong>Reflexão Noturna (5 minutos):</strong> Revisar o que foi feito e planejar melhorias para o próximo dia.</li>
        </ul>
        <p>Use esta ferramenta para aplicar este método de forma prática e eficaz.</p>
    </div>
    
    <!-- Gráfico de Foco Semanal -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5>Resumo do Foco Semanal</h5>
        </div>
        <div class="card-body">
            <canvas id="focus-weekly-chart" style="height: 300px;"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const focusLabels = [];
    const focusTotals = [];
    const focusCompleted = [];
    <?php foreach ($focus_weekly_data as $date => $data): ?>
        focusLabels.push("<?= date('D', strtotime($date)) ?>");
        focusTotals.push(<?= $data['total'] ?>);
        focusCompleted.push(<?= $data['completed'] ?>);
    <?php endforeach; ?>

    new Chart(document.getElementById('focus-weekly-chart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: focusLabels,
            datasets: [
                {
                    label: 'Foco Total',
                    data: focusTotals,
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Foco Concluído',
                    data: focusCompleted,
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
</script>
