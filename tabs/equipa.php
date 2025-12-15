<?php
// tabs/equipa.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once __DIR__ . '/../config.php';

// Verificar e criar base de dados SQLite e tabelas, se necessário
$db_path = __DIR__ . '/../equipa2.sqlite';
$nova_base_dados = !file_exists($db_path);

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Criar tabelas se necessário
    $db->exec("CREATE TABLE IF NOT EXISTS equipa (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        redmine_id INTEGER UNIQUE
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS proximos_gestores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        redmine_id INTEGER,
        data_prevista TEXT,
        concluido INTEGER DEFAULT 0,
        FOREIGN KEY (redmine_id) REFERENCES equipa(redmine_id)
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS faltas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        redmine_id INTEGER,
        data TEXT DEFAULT CURRENT_TIMESTAMP,
        motivo TEXT,
        FOREIGN KEY (redmine_id) REFERENCES equipa(redmine_id)
    )");
    
    // Verificar se a tabela notas_markdown existe
    $verificar_tabela = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='notas_markdown'");
    if ($verificar_tabela->fetchColumn() === false) {
        // Criar tabela para notas em markdown se não existir
        $db->exec("CREATE TABLE IF NOT EXISTS notas_markdown (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            titulo TEXT,
            conteudo TEXT,
            data_criacao TEXT DEFAULT CURRENT_TIMESTAMP,
            data_atualizacao TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    }
} catch (Exception $e) {
    die("Erro ao inicializar a base de dados: " . $e->getMessage());
}

function getUtilizadoresRedmine() {
    global $API_KEY, $BASE_URL;
    $url = "$BASE_URL/users.json?limit=200";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Redmine-API-Key: $API_KEY"]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http_code !== 200) {
        echo "<div class='alert alert-danger'>Erro ao obter utilizadores da API Redmine.<br>
              Código HTTP: $http_code<br>
              Erro CURL: $curl_error<br>
              URL: $url</div>";
        return [];
    }

    $data = json_decode($resp, true);
    if (empty($data['users'])) {
        echo "<div class='alert alert-warning'>⚠️ A resposta da API Redmine foi recebida mas não contém utilizadores.</div>";
    }
    return $data['users'] ?? [];
}

function getAtividadesUtilizador($user_id) {
    global $db_host, $db_user, $db_pass, $db_name;
    
    try {
        // Conectar à base de dados MySQL
        $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
        
        if ($db->connect_error) {
            error_log("Erro de conexão ao buscar atividades: " . $db->connect_error);
            return [];
        }
        
        $db->set_charset("utf8mb4");
        
        // Buscar tarefas do utilizador ordenadas pela última atualização
        $stmt = $db->prepare('
            SELECT 
                t.id,
                t.titulo,
                t.descritivo,
                t.estado,
                t.estagio,
                t.data_limite,
                t.updated_at,
                t.created_at,
                t.projeto_id,
                t.task_id,
                autor.username as autor_nome,
                resp.username as responsavel_nome
            FROM todos t
            LEFT JOIN user_tokens autor ON t.autor = autor.user_id
            LEFT JOIN user_tokens resp ON t.responsavel = resp.user_id
            WHERE t.autor = ? OR t.responsavel = ?
            ORDER BY t.updated_at DESC
            LIMIT 10
        ');
        
        if (!$stmt) {
            error_log("Erro ao preparar query de atividades: " . $db->error);
            $db->close();
            return [];
        }
        
        $stmt->bind_param('ii', $user_id, $user_id);
        
        if (!$stmt->execute()) {
            error_log("Erro ao executar query de atividades: " . $stmt->error);
            $stmt->close();
            $db->close();
            return [];
        }
        
        $result = $stmt->get_result();
        $atividades = [];
        
        while ($row = $result->fetch_assoc()) {
            // Calcular tempo decorrido
            $updated = new DateTime($row['updated_at']);
            $now = new DateTime();
            $diff = $updated->diff($now);
            
            if ($diff->days > 0) {
                $tempo_decorrido = $diff->days . ' dia(s) atrás';
            } elseif ($diff->h > 0) {
                $tempo_decorrido = $diff->h . ' hora(s) atrás';
            } elseif ($diff->i > 0) {
                $tempo_decorrido = $diff->i . ' minuto(s) atrás';
            } else {
                $tempo_decorrido = 'Agora mesmo';
            }
            
            // Determinar o link
            if ($row['projeto_id'] == 9999) {
                $url = 'index.php?tab=phd_kanban&user_id=' . $user_id;
            } elseif (!empty($row['task_id'])) {
                global $BASE_URL;
                $url = $BASE_URL . '/issues/' . $row['task_id'];
            } else {
                $url = 'index.php?tab=todos#task-' . $row['id'];
            }
            
            // Badges de estado
            $estado_badge = '';
            switch ($row['estado']) {
                case 'aberta':
                    $estado_badge = '<span class="badge bg-secondary">Aberta</span>';
                    break;
                case 'em execução':
                case 'em execucao':
                    $estado_badge = '<span class="badge bg-primary">Em Execução</span>';
                    break;
                case 'suspensa':
                    $estado_badge = '<span class="badge bg-warning text-dark">Suspensa</span>';
                    break;
                case 'concluída':
                case 'concluida':
                    $estado_badge = '<span class="badge bg-success">Concluída</span>';
                    break;
                default:
                    $estado_badge = '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($row['estado'])) . '</span>';
            }
            
            // Badge de estágio
            $estagio_badge = '';
            if (!empty($row['estagio'])) {
                switch ($row['estagio']) {
                    case 'pensada':
                        $estagio_badge = ' <span class="badge bg-light text-dark">Pensada</span>';
                        break;
                    case 'execucao':
                        $estagio_badge = ' <span class="badge bg-info">Em Execução</span>';
                        break;
                    case 'espera':
                        $estagio_badge = ' <span class="badge bg-warning text-dark">Em Espera</span>';
                        break;
                    case 'concluida':
                        $estagio_badge = ' <span class="badge bg-success">Concluída</span>';
                        break;
                }
            }
            
            // Badge de projeto
            $projeto_badge = '';
            if ($row['projeto_id'] == 9999) {
                $projeto_badge = ' <span class="badge bg-info"><i class="bi bi-mortarboard-fill"></i> Doutoramento</span>';
            }
            
            // Deadline info
            $deadline_info = '';
            if (!empty($row['data_limite'])) {
                $deadline = new DateTime($row['data_limite']);
                $dias_diff = $now->diff($deadline);
                $dias_restantes = $dias_diff->days;
                
                if ($now > $deadline) {
                    $deadline_info = ' <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Atrasada</span>';
                } elseif ($dias_restantes <= 3) {
                    $deadline_info = ' <span class="badge bg-warning text-dark"><i class="bi bi-calendar"></i> ' . $dias_restantes . ' dias</span>';
                } elseif ($dias_restantes <= 7) {
                    $deadline_info = ' <span class="badge bg-info"><i class="bi bi-calendar"></i> ' . $dias_restantes . ' dias</span>';
                }
            }
            
            $atividades[] = [
                'id' => $row['id'],
                'titulo' => $row['titulo'],
                'descritivo' => $row['descritivo'],
                'estado' => $row['estado'],
                'estado_badge' => $estado_badge,
                'estagio_badge' => $estagio_badge,
                'projeto_badge' => $projeto_badge,
                'deadline_info' => $deadline_info,
                'tempo_decorrido' => $tempo_decorrido,
                'url' => $url,
                'autor_nome' => $row['autor_nome'],
                'responsavel_nome' => $row['responsavel_nome']
            ];
        }
        
        $stmt->close();
        $db->close();
        
        return $atividades;
        
    } catch (Exception $e) {
        error_log("Erro ao buscar atividades: " . $e->getMessage());
        return [];
    }
}

// Processar ações do formulário
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao == 'adicionar' && isset($_POST['redmine_id'])) {
        $redmine_id = (int)$_POST['redmine_id'];
        try {
            $stmt = $db->prepare("INSERT INTO equipa (redmine_id) VALUES (?)");
            $stmt->execute([$redmine_id]);
        } catch (Exception $e) {
            // Ignorar duplicados
        }
    }

    if ($acao == 'remover' && isset($_POST['redmine_id'])) {
        $redmine_id = (int)$_POST['redmine_id'];
        $stmt = $db->prepare("DELETE FROM equipa WHERE redmine_id = ?");
        $stmt->execute([$redmine_id]);
    }

    if ($acao == 'registrar_falta' && isset($_POST['redmine_id'], $_POST['motivo'])) {
        $redmine_id = (int)$_POST['redmine_id'];
        $motivo = $_POST['motivo'];
        $stmt = $db->prepare("INSERT INTO faltas (redmine_id, motivo) VALUES (?, ?)");
        $stmt->execute([$redmine_id, $motivo]);
    }

    if ($acao == 'adicionar_gestor' && isset($_POST['redmine_id'], $_POST['data_prevista'])) {
        $redmine_id = (int)$_POST['redmine_id'];
        $data_prevista = $_POST['data_prevista'];
        $stmt = $db->prepare("INSERT INTO proximos_gestores (redmine_id, data_prevista) VALUES (?, ?)");
        $stmt->execute([$redmine_id, $data_prevista]);
    }
    
    // NOVA AÇÃO: Atribuir gestor manual para uma data específica
    if ($acao == 'atribuir_gestor_manual' && isset($_POST['data'], $_POST['redmine_id'])) {
        $data = $_POST['data'];
        $redmine_id = (int)$_POST['redmine_id'];
        
        // Verificar se já existe um gestor para esta data
        $stmt = $db->prepare("SELECT id FROM proximos_gestores WHERE data_prevista = ? AND concluido = 0");
        $stmt->execute([$data]);
        $existente = $stmt->fetch();
        
        if ($existente) {
            // Atualizar o gestor existente
            $stmt = $db->prepare("UPDATE proximos_gestores SET redmine_id = ? WHERE data_prevista = ? AND concluido = 0");
            $stmt->execute([$redmine_id, $data]);
        } else {
            // Inserir novo gestor
            $stmt = $db->prepare("INSERT INTO proximos_gestores (redmine_id, data_prevista) VALUES (?, ?)");
            $stmt->execute([$redmine_id, $data]);
        }
    }

    if ($acao == 'marcar_concluido' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("UPDATE proximos_gestores SET concluido = 1 WHERE id = ?");
        $stmt->execute([$id]);
    }

    if ($acao == 'remover_gestor' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM proximos_gestores WHERE id = ?");
        $stmt->execute([$id]);
    }
    
    // NOVA AÇÃO: Gerar atribuições automáticas para os próximos 30 dias
    if ($acao == 'gerar_proximos_30_dias') {
        // Buscar membros da equipa
        $stmt = $db->query("SELECT redmine_id FROM equipa ORDER BY redmine_id");
        $membros = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($membros) > 0) {
            // Buscar o último gestor atribuído
            $stmt = $db->query("SELECT redmine_id, data_prevista FROM proximos_gestores WHERE concluido = 0 ORDER BY data_prevista DESC LIMIT 1");
            $ultimo = $stmt->fetch();
            
            $data_inicio = new DateTime();
            $indice_atual = 0;
            
            if ($ultimo) {
                $data_inicio = new DateTime($ultimo['data_prevista']);
                $data_inicio->modify('+1 day');
                
                // Encontrar o índice do último membro
                $indice_atual = array_search($ultimo['redmine_id'], $membros);
                if ($indice_atual === false) {
                    $indice_atual = 0;
                } else {
                    $indice_atual = ($indice_atual + 1) % count($membros);
                }
            }
            
            // Gerar atribuições para os próximos 30 dias
            for ($i = 0; $i < 30; $i++) {
                $data = clone $data_inicio;
                $data->modify("+$i days");
                $data_str = $data->format('Y-m-d');
                
                // Verificar se já existe atribuição para esta data
                $stmt = $db->prepare("SELECT id FROM proximos_gestores WHERE data_prevista = ?");
                $stmt->execute([$data_str]);
                
                if (!$stmt->fetch()) {
                    // Inserir nova atribuição
                    $redmine_id = $membros[$indice_atual];
                    $stmt = $db->prepare("INSERT INTO proximos_gestores (redmine_id, data_prevista) VALUES (?, ?)");
                    $stmt->execute([$redmine_id, $data_str]);
                    
                    // Avançar para o próximo membro
                    $indice_atual = ($indice_atual + 1) % count($membros);
                }
            }
        }
    }
    
    // NOVA AÇÃO: Limpar gestores futuros não concluídos
    if ($acao == 'limpar_gestores_futuros') {
        $stmt = $db->prepare("DELETE FROM proximos_gestores WHERE concluido = 0 AND data_prevista >= date('now')");
        $stmt->execute();
    }

    // Salvar nota markdown
    if ($acao == 'salvar_nota') {
        $id = !empty($_POST['id_nota']) ? (int)$_POST['id_nota'] : null;
        $titulo = $_POST['titulo_nota'] ?? 'Sem Título';
        $conteudo = $_POST['conteudo_nota'] ?? '';
        
        if ($id) {
            // Atualizar nota existente
            $stmt = $db->prepare("UPDATE notas_markdown SET titulo = ?, conteudo = ?, data_atualizacao = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$titulo, $conteudo, $id]);
        } else {
            // Criar nova nota
            $stmt = $db->prepare("INSERT INTO notas_markdown (titulo, conteudo) VALUES (?, ?)");
            $stmt->execute([$titulo, $conteudo]);
        }
    }
    
    // Excluir nota markdown
    if ($acao == 'excluir_nota' && isset($_POST['id_nota'])) {
        $id = (int)$_POST['id_nota'];
        $stmt = $db->prepare("DELETE FROM notas_markdown WHERE id = ?");
        $stmt->execute([$id]);
    }

    // Redirecionar para evitar reenvio de formulário
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=equipa");
    exit;
}

// Obter dados
$utilizadores_redmine = getUtilizadoresRedmine();
$stmt_equipa = $db->query("SELECT redmine_id FROM equipa");
$equipa_ids = $stmt_equipa->fetchAll(PDO::FETCH_COLUMN);

// Criar mapa de utilizadores Redmine
$mapa_redmine = [];
foreach ($utilizadores_redmine as $u) {
    $mapa_redmine[$u['id']] = $u;
}

// Obter próximos gestores (próximos 30 dias a partir de hoje)
$data_inicio = date('Y-m-d');
$data_fim = date('Y-m-d', strtotime('+30 days'));

$stmt = $db->prepare("
    SELECT pg.*, e.redmine_id 
    FROM proximos_gestores pg
    INNER JOIN equipa e ON pg.redmine_id = e.redmine_id
    WHERE pg.data_prevista BETWEEN ? AND ?
    ORDER BY pg.data_prevista ASC
");
$stmt->execute([$data_inicio, $data_fim]);
$proximos_gestores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obter últimas 10 faltas registradas
$stmt_faltas = $db->query("
    SELECT f.*, e.redmine_id 
    FROM faltas f
    INNER JOIN equipa e ON f.redmine_id = e.redmine_id
    ORDER BY f.data DESC
    LIMIT 10
");
$ultimas_faltas = $stmt_faltas->fetchAll(PDO::FETCH_ASSOC);

// Obter notas markdown
$stmt_notas = $db->query("SELECT * FROM notas_markdown ORDER BY data_atualizacao DESC");
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
$nota_selecionada = $notas[0] ?? null;

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gestão da Equipa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        .equipa-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .membro-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .membro-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .membro-header {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .membro-nome {
            font-weight: 600;
            font-size: 1.1em;
            color: #2c3e50;
            margin: 0;
        }
        
        .membro-info {
            font-size: 0.85em;
            color: #6c757d;
            margin-bottom: 8px;
        }
        
        .atividades-section {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f0f0f0;
        }
        
        .atividade-item {
            font-size: 0.85em;
            padding: 6px 8px;
            margin-bottom: 6px;
            background: #f8f9fa;
            border-radius: 4px;
            border-left: 3px solid #007bff;
        }
        
        .atividade-titulo {
            font-weight: 500;
            color: #2c3e50;
            display: block;
            margin-bottom: 4px;
        }
        
        .atividade-meta {
            font-size: 0.9em;
            color: #6c757d;
        }
        
        .sem-atividades {
            color: #999;
            font-style: italic;
            font-size: 0.85em;
            padding: 8px;
            text-align: center;
        }
        
        .gestores-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .gestor-item {
            padding: 10px 15px;
            margin-bottom: 8px;
            background: white;
            border-radius: 6px;
            border-left: 4px solid #28a745;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .gestor-item.concluido {
            border-left-color: #6c757d;
            opacity: 0.7;
        }
        
        .gestor-info {
            flex: 1;
        }
        
        .gestor-nome {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .gestor-data {
            font-size: 0.9em;
            color: #6c757d;
        }
        
        .notas-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 20px;
            margin-top: 30px;
            min-height: 500px;
        }
        
        .notas-lista {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .nota-lista-item {
            padding: 12px;
            margin-bottom: 8px;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .nota-lista-item:hover {
            background: #e9ecef;
        }
        
        .nota-lista-item.active {
            border-color: #007bff;
            background: #e7f3ff;
        }
        
        .nota-conteudo {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        #markdown-preview {
            min-height: 300px;
            line-height: 1.6;
        }
        
        #markdown-preview h1, #markdown-preview h2, #markdown-preview h3 {
            margin-top: 1.5em;
            margin-bottom: 0.5em;
        }
        
        #markdown-preview code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        
        #markdown-preview pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
        }
        
        .gestores-calendar {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .dia-gestor {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        
        .dia-gestor:hover {
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0,123,255,0.2);
            transform: translateY(-2px);
        }
        
        .dia-gestor.atribuido {
            border-color: #28a745;
            background: #f0fff4;
        }
        
        .dia-gestor.hoje {
            border-color: #ffc107;
            background: #fffbf0;
        }
        
        .dia-data {
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9em;
            margin-bottom: 8px;
        }
        
        .dia-nome {
            font-size: 0.85em;
            color: #6c757d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .dia-gestor.atribuido .dia-nome {
            color: #28a745;
            font-weight: 500;
        }
        
        .modal-gestor-select {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .membro-option {
            padding: 12px;
            margin-bottom: 8px;
            background: #f8f9fa;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .membro-option:hover {
            background: #e9ecef;
            border-color: #007bff;
        }
        
        .membro-option.selecionado {
            background: #e7f3ff;
            border-color: #007bff;
        }
    </style>
</head>
<body>

<div class="container-fluid mt-4">
    <h2 class="mb-4"><i class="bi bi-people-fill"></i> Gestão da Equipa</h2>

    <!-- Adicionar Membro à Equipa -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-person-plus-fill"></i> Adicionar Membro à Equipa</h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="acao" value="adicionar">
                <div class="col-md-10">
                    <select name="redmine_id" class="form-select" required>
                        <option value="">Selecione um utilizador</option>
                        <?php foreach ($utilizadores_redmine as $u): ?>
                            <?php if (!in_array($u['id'], $equipa_ids)): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-plus-circle"></i> Adicionar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Membros da Equipa -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0"><i class="bi bi-people"></i> Membros da Equipa (<?= count($equipa_ids) ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($equipa_ids)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Nenhum membro na equipa. Adicione membros usando o formulário acima.
                </div>
            <?php else: ?>
                <div class="equipa-container">
                    <?php foreach ($equipa_ids as $rid): ?>
                        <?php if (isset($mapa_redmine[$rid])): ?>
                            <?php 
                            $u = $mapa_redmine[$rid];
                            $nome_completo = htmlspecialchars($u['firstname'] . ' ' . $u['lastname']);
                            
                            // Buscar atividades recentes do utilizador
                            $atividades = getAtividadesUtilizador($rid);
                            ?>
                            <div class="membro-card">
                                <div class="membro-header">
                                    <div style="flex: 1;">
                                        <h6 class="membro-nome"><?= $nome_completo ?></h6>
                                    </div>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="acao" value="remover">
                                        <input type="hidden" name="redmine_id" value="<?= $rid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                onclick="return confirm('Remover <?= $nome_completo ?> da equipa?')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                                
                                <div class="membro-info">
                                    <i class="bi bi-envelope"></i> <?= htmlspecialchars($u['mail'] ?? 'N/A') ?>
                                </div>
                                
                                <div class="atividades-section">
                                    <strong style="font-size: 0.9em; color: #495057;">
                                        <i class="bi bi-clock-history"></i> Atividades Recentes
                                    </strong>
                                    <?php if (empty($atividades)): ?>
                                        <div class="sem-atividades">Sem atividades recentes</div>
                                    <?php else: ?>
                                        <?php foreach (array_slice($atividades, 0, 3) as $atividade): ?>
                                            <div class="atividade-item">
                                                <a href="<?= htmlspecialchars($atividade['url']) ?>" 
                                                   class="atividade-titulo text-decoration-none"
                                                   target="_blank">
                                                    <?= htmlspecialchars(mb_substr($atividade['titulo'], 0, 50)) ?>
                                                    <?= mb_strlen($atividade['titulo']) > 50 ? '...' : '' ?>
                                                </a>
                                                <div class="atividade-meta">
                                                    <?= $atividade['estado_badge'] ?>
                                                    <?= $atividade['estagio_badge'] ?>
                                                    <?= $atividade['projeto_badge'] ?>
                                                    <?= $atividade['deadline_info'] ?>
                                                    <br>
                                                    <small><i class="bi bi-clock"></i> <?= $atividade['tempo_decorrido'] ?></small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Botão para registrar falta -->
                                <div class="mt-3">
                                    <button class="btn btn-sm btn-warning w-100" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalFalta<?= $rid ?>">
                                        <i class="bi bi-calendar-x"></i> Registrar Falta
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Modal para Registrar Falta -->
                            <div class="modal fade" id="modalFalta<?= $rid ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Registrar Falta - <?= $nome_completo ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="acao" value="registrar_falta">
                                                <input type="hidden" name="redmine_id" value="<?= $rid ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Motivo da Falta</label>
                                                    <textarea name="motivo" class="form-control" rows="3" required></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-warning">Registrar</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Próximos Gestores de Reunião - VERSÃO ATUALIZADA -->
    <div class="card mb-4">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-calendar-check"></i> Próximos Gestores de Reunião (30 Dias)</h5>
            <div>
                <button class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalGerenciarGestores">
                    <i class="bi bi-pencil-square"></i> Editar Atribuições
                </button>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Isto irá gerar automaticamente atribuições para os próximos 30 dias. Continuar?')">
                    <input type="hidden" name="acao" value="gerar_proximos_30_dias">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Gerar Automático
                    </button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($proximos_gestores)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> Nenhum gestor de reunião atribuído para os próximos 30 dias. 
                    Use o botão "Gerar Automático" ou "Editar Atribuições" para configurar.
                </div>
            <?php else: ?>
                <div class="gestores-calendar">
                    <?php
                    $hoje = date('Y-m-d');
                    $gestores_map = [];
                    foreach ($proximos_gestores as $pg) {
                        $gestores_map[$pg['data_prevista']] = $pg;
                    }
                    
                    for ($i = 0; $i < 30; $i++) {
                        $data = date('Y-m-d', strtotime("+$i days"));
                        $data_formatada = date('d/m', strtotime($data));
                        $dia_semana = date('D', strtotime($data));
                        
                        $classes = ['dia-gestor'];
                        if ($data === $hoje) {
                            $classes[] = 'hoje';
                        }
                        
                        $gestor_nome = 'Não atribuído';
                        if (isset($gestores_map[$data])) {
                            $classes[] = 'atribuido';
                            $pg = $gestores_map[$data];
                            if (isset($mapa_redmine[$pg['redmine_id']])) {
                                $u = $mapa_redmine[$pg['redmine_id']];
                                $gestor_nome = htmlspecialchars($u['firstname']);
                            }
                        }
                        ?>
                        <div class="<?= implode(' ', $classes) ?>" 
                             data-data="<?= $data ?>"
                             onclick="abrirModalAtribuirGestor('<?= $data ?>', '<?= $data_formatada ?>')">
                            <div class="dia-data">
                                <?= $data_formatada ?>
                                <small style="display: block; font-size: 0.8em; font-weight: normal;"><?= $dia_semana ?></small>
                            </div>
                            <div class="dia-nome"><?= $gestor_nome ?></div>
                        </div>
                    <?php } ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal para Gerenciar Gestores -->
    <div class="modal fade" id="modalGerenciarGestores" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-event"></i> Gerenciar Atribuições de Gestores</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <form method="POST" onsubmit="return confirm('Isto irá remover todas as atribuições futuras não concluídas. Continuar?')">
                            <input type="hidden" name="acao" value="limpar_gestores_futuros">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="bi bi-trash"></i> Limpar Todas as Atribuições Futuras
                            </button>
                        </form>
                    </div>
                    
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Gestor</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($proximos_gestores as $pg): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($pg['data_prevista'])) ?></td>
                                    <td>
                                        <?php
                                        if (isset($mapa_redmine[$pg['redmine_id']])) {
                                            $u = $mapa_redmine[$pg['redmine_id']];
                                            echo htmlspecialchars($u['firstname'] . ' ' . $u['lastname']);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="abrirModalAtribuirGestor('<?= $pg['data_prevista'] ?>', '<?= date('d/m/Y', strtotime($pg['data_prevista'])) ?>')">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="acao" value="remover_gestor">
                                            <input type="hidden" name="id" value="<?= $pg['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" 
                                                    onclick="return confirm('Remover esta atribuição?')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Atribuir Gestor a uma Data Específica -->
    <div class="modal fade" id="modalAtribuirGestor" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-check"></i> Atribuir Gestor para <span id="modal-data-display"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formAtribuirGestor">
                    <div class="modal-body">
                        <input type="hidden" name="acao" value="atribuir_gestor_manual">
                        <input type="hidden" name="data" id="input-data-atribuicao">
                        
                        <div class="modal-gestor-select">
                            <?php foreach ($equipa_ids as $rid): ?>
                                <?php if (isset($mapa_redmine[$rid])): ?>
                                    <?php 
                                    $u = $mapa_redmine[$rid];
                                    $nome_completo = htmlspecialchars($u['firstname'] . ' ' . $u['lastname']);
                                    ?>
                                    <div class="membro-option" data-redmine-id="<?= $rid ?>" onclick="selecionarMembro(this, <?= $rid ?>)">
                                        <strong><?= $nome_completo ?></strong>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($u['mail'] ?? 'N/A') ?></small>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" name="redmine_id" id="input-redmine-id-selecionado">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btn-confirmar-atribuicao" disabled>
                            <i class="bi bi-check-circle"></i> Confirmar Atribuição
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Últimas Faltas Registradas -->
    <?php if (!empty($ultimas_faltas)): ?>
    <div class="card mb-4">
        <div class="card-header bg-warning">
            <h5 class="mb-0"><i class="bi bi-calendar-x"></i> Últimas Faltas Registradas</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Membro</th>
                            <th>Motivo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimas_faltas as $falta): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i', strtotime($falta['data'])) ?></td>
                                <td>
                                    <?php
                                    if (isset($mapa_redmine[$falta['redmine_id']])) {
                                        $u = $mapa_redmine[$falta['redmine_id']];
                                        echo htmlspecialchars($u['firstname'] . ' ' . $u['lastname']);
                                    }
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($falta['motivo']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Seção de Notas Markdown -->
    <div class="card mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0"><i class="bi bi-journal-text"></i> Notas da Equipa (Markdown)</h5>
        </div>
        <div class="card-body">
            <div class="notas-container">
                <!-- Lista de Notas -->
                <div class="notas-lista">
                    <button class="btn btn-success btn-sm w-100 mb-3 nova-nota">
                        <i class="bi bi-plus-circle"></i> Nova Nota
                    </button>
                    
                    <?php if (empty($notas)): ?>
                        <p class="text-muted text-center">Nenhuma nota criada</p>
                    <?php else: ?>
                        <?php foreach ($notas as $nota): ?>
                            <div class="nota-lista-item <?= $nota === $nota_selecionada ? 'active' : '' ?>"
                                 data-id="<?= $nota['id'] ?>"
                                 data-titulo="<?= htmlspecialchars($nota['titulo']) ?>"
                                 data-conteudo="<?= htmlspecialchars($nota['conteudo']) ?>">
                                <strong><?= htmlspecialchars($nota['titulo']) ?></strong>
                                <br>
                                <small class="text-muted">
                                    <?= date('d/m/Y H:i', strtotime($nota['data_atualizacao'])) ?>
                                </small>
                                <form method="POST" class="nota-excluir-form" style="display: inline;">
                                    <input type="hidden" name="acao" value="excluir_nota">
                                    <input type="hidden" name="id_nota" value="<?= $nota['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger float-end"
                                            onclick="return confirm('Excluir esta nota?')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Conteúdo da Nota -->
                <div class="nota-conteudo">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 id="nota-titulo-display">
                            <?= $nota_selecionada ? htmlspecialchars($nota_selecionada['titulo']) : 'Nova Nota' ?>
                        </h4>
                        <div>
                            <button id="btn-editar" class="btn btn-primary btn-sm">
                                <i class="bi bi-pencil"></i> Editar
                            </button>
                            <button id="btn-visualizar" class="btn btn-success btn-sm" style="display: none;">
                                <i class="bi bi-eye"></i> Visualizar
                            </button>
                        </div>
                    </div>
                    
                    <!-- Preview do Markdown -->
                    <div id="markdown-preview">
                        <?php if ($nota_selecionada): ?>
                            <!-- O conteúdo será renderizado pelo JavaScript -->
                        <?php else: ?>
                            <p class="text-muted">Selecione uma nota ou crie uma nova.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Formulário de Edição -->
                    <form method="POST" id="form-markdown" style="display: none;">
                        <input type="hidden" name="acao" value="salvar_nota">
                        <input type="hidden" name="id_nota" id="id_nota" 
                               value="<?= $nota_selecionada ? $nota_selecionada['id'] : '' ?>">
                        
                        <div class="mb-3">
                            <label for="titulo_nota" class="form-label">Título</label>
                            <input type="text" class="form-control" id="titulo_nota" name="titulo_nota" 
                                   value="<?= $nota_selecionada ? htmlspecialchars($nota_selecionada['titulo']) : 'Nova Nota' ?>" 
                                   required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="conteudo_nota" class="form-label">Conteúdo (Markdown)</label>
                            <textarea class="form-control" id="conteudo_nota" name="conteudo_nota" 
                                      rows="15" style="font-family: monospace;"><?= $nota_selecionada ? htmlspecialchars($nota_selecionada['conteudo']) : '' ?></textarea>
                            <small class="text-muted">
                                Suporta Markdown: **negrito**, *itálico*, # Título, - Lista, etc.
                            </small>
                        </div>
                        
                        <div>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save"></i> Salvar
                            </button>
                            <button type="button" id="btn-cancelar" class="btn btn-secondary">
                                Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Funcionalidades da Seção de Notas Markdown
    
    // Elementos DOM
    const btnEditar = document.getElementById('btn-editar');
    const btnVisualizar = document.getElementById('btn-visualizar');
    const btnCancelar = document.getElementById('btn-cancelar');
    const markdownPreview = document.getElementById('markdown-preview');
    const formMarkdown = document.getElementById('form-markdown');
    const notaItems = document.querySelectorAll('.nota-lista-item');
    const btnNovaNota = document.querySelector('.nova-nota');
    const tituloDisplay = document.getElementById('nota-titulo-display');
    const inputConteudo = document.getElementById('conteudo_nota');
    const inputTitulo = document.getElementById('titulo_nota');
    const inputId = document.getElementById('id_nota');
    
    // Configuração do Marked.js para renderizar Markdown
    if (typeof marked !== 'undefined') {
        marked.setOptions({
            breaks: true,
            gfm: true,
            headerIds: true
        });
    }
    
    // Função para renderizar o markdown atual
    function renderizarMarkdown() {
        if (inputConteudo && markdownPreview && typeof marked !== 'undefined') {
            const conteudoAtual = inputConteudo.value || '';
            markdownPreview.innerHTML = marked.parse(conteudoAtual);
        }
    }
    
    // Função para alternar entre edição e visualização
    function alternarModo(modoEdicao) {
        if (modoEdicao) {
            formMarkdown.style.display = 'block';
            markdownPreview.style.display = 'none';
            btnEditar.style.display = 'none';
            btnVisualizar.style.display = 'inline-block';
        } else {
            renderizarMarkdown();
            formMarkdown.style.display = 'none';
            markdownPreview.style.display = 'block';
            btnEditar.style.display = 'inline-block';
            btnVisualizar.style.display = 'none';
        }
    }
    
    // Função para carregar uma nota
    function carregarNota(id, titulo, conteudo) {
        inputId.value = id || '';
        inputTitulo.value = titulo || 'Nova Nota';
        inputConteudo.value = conteudo || '';
        tituloDisplay.textContent = titulo || 'Nova Nota';
        
        renderizarMarkdown();
        
        notaItems.forEach(item => {
            if (id && item.dataset.id === id) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
    }
    
    // Função para criar uma nova nota
    function novaNota() {
        carregarNota('', 'Nova Nota', '');
        alternarModo(true);
    }
    
    // Configurar eventos
    if (btnEditar) {
        btnEditar.addEventListener('click', function() {
            alternarModo(true);
        });
    }
    
    if (btnVisualizar) {
        btnVisualizar.addEventListener('click', function() {
            alternarModo(false);
        });
    }
    
    if (btnCancelar) {
        btnCancelar.addEventListener('click', function() {
            alternarModo(false);
        });
    }
    
    if (btnNovaNota) {
        btnNovaNota.addEventListener('click', novaNota);
    }
    
    notaItems.forEach(item => {
        item.addEventListener('click', function(e) {
            if (e.target.closest('.nota-excluir-form')) {
                return;
            }
            
            const id = this.dataset.id;
            const titulo = this.dataset.titulo;
            const conteudo = this.dataset.conteudo;
            
            carregarNota(id, titulo, conteudo);
            alternarModo(false);
        });
    });
    
    // Renderizar markdown inicial
    renderizarMarkdown();
});

// Funções para o modal de atribuição de gestor
let modalAtribuirGestor;
let dataAtribuicaoAtual = '';

function abrirModalAtribuirGestor(data, dataFormatada) {
    dataAtribuicaoAtual = data;
    document.getElementById('modal-data-display').textContent = dataFormatada;
    document.getElementById('input-data-atribuicao').value = data;
    document.getElementById('input-redmine-id-selecionado').value = '';
    document.getElementById('btn-confirmar-atribuicao').disabled = true;
    
    // Limpar seleção anterior
    document.querySelectorAll('.membro-option').forEach(opt => {
        opt.classList.remove('selecionado');
    });
    
    // Mostrar o modal
    if (!modalAtribuirGestor) {
        modalAtribuirGestor = new bootstrap.Modal(document.getElementById('modalAtribuirGestor'));
    }
    modalAtribuirGestor.show();
}

function selecionarMembro(elemento, redmineId) {
    // Remover seleção anterior
    document.querySelectorAll('.membro-option').forEach(opt => {
        opt.classList.remove('selecionado');
    });
    
    // Adicionar seleção ao elemento clicado
    elemento.classList.add('selecionado');
    
    // Atualizar o input hidden com o ID selecionado
    document.getElementById('input-redmine-id-selecionado').value = redmineId;
    
    // Habilitar o botão de confirmação
    document.getElementById('btn-confirmar-atribuicao').disabled = false;
}
</script>

</body>
</html>