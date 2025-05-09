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
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Erro ao conectar ao banco de dados: ' . $e->getMessage() . '</div>';
    exit;
}

?>

<div class="container-fluid">
    <h2><i class="bi bi-clock-history"></i> Dashboard 18 Minutos</h2>
    
    <!-- Planejamento Matinal -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5>Planejamento Matinal (5 minutos)</h5>
        </div>
        <div class="card-body">
            <form method="post" action="" id="daily-focus-form">
                <input type="hidden" name="action" value="set_daily_focus">
                <div class="mb-3">
                    <label for="daily_focus_tasks" class="form-label">Selecione as principais tarefas para o dia:</label>
                    <select multiple class="form-select" id="daily_focus_tasks" name="task_ids[]">
                        <?php foreach ($all_tasks as $task): ?>
                            <option value="<?= $task['id'] ?>" <?= in_array($task, $focus_tasks) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($task['titulo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Escolha até 5 tarefas.</small>
                </div>
                <button type="submit" class="btn btn-primary">Salvar Foco do Dia</button>
            </form>
        </div>
    </div>
    
    <!-- Reflexão Noturna -->
    <div class="card mb-4">
        <div class="card-header bg-secondary text-white">
            <h5>Reflexão Noturna (5 minutos)</h5>
        </div>
        <div class="card-body">
            <form method="post" action="" id="daily-reflection-form">
                <input type="hidden" name="action" value="save_daily_reflection">
                <div class="mb-3">
                    <label for="daily_reflection" class="form-label">Reflexão do dia:</label>
                    <textarea class="form-control" id="daily_reflection" name="reflection_text" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-secondary">Salvar Reflexão</button>
            </form>
        </div>
    </div>
    
    <!-- Dashboard de Progresso -->
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <h5>Progresso Semanal</h5>
        </div>
        <div class="card-body">
            <div id="progress-chart" style="height: 300px;"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Placeholder para dados do progresso semanal (exemplo)
    const ctx = document.getElementById('progress-chart').getContext('2d');
    const myChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
            datasets: [{
                label: 'Tarefas Concluídas',
                data: [3, 2, 4, 5, 1, 0, 0],
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1
            }]
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