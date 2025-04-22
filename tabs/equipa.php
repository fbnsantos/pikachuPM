<?php
// tabs/equipa.php
session_start();
include_once __DIR__ . '/../config.php';

// Verificar e criar base de dados SQLite e tabelas, se necessário
$db_path = __DIR__ . '/../equipa2.sqlite';
$nova_base_dados = !file_exists($db_path);

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($nova_base_dados) {
        // Tabela principal da equipe
        $db->exec("CREATE TABLE IF NOT EXISTS equipa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            redmine_id INTEGER UNIQUE
        )");
        
        // Tabela para próximos gestores
        $db->exec("CREATE TABLE IF NOT EXISTS proximos_gestores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            redmine_id INTEGER,
            data_prevista TEXT,
            concluido INTEGER DEFAULT 0,
            FOREIGN KEY (redmine_id) REFERENCES equipa(redmine_id)
        )");
        
        // Tabela para registrar faltas
        $db->exec("CREATE TABLE IF NOT EXISTS faltas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            redmine_id INTEGER,
            data TEXT DEFAULT CURRENT_TIMESTAMP,
            motivo TEXT,
            FOREIGN KEY (redmine_id) REFERENCES equipa(redmine_id)
        )");
    }
} catch (Exception $e) {
    die("Erro ao inicializar a base de dados: " . $e->getMessage());
}

function getUtilizadoresRedmine() {
    global $API_KEY, $BASE_URL;
    $url = "$BASE_URL/users.json?limit=170";
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

function getAtividadesUtilizador($id) {
    global $API_KEY, $BASE_URL;

    $url = "$BASE_URL/issues.json?status_id=*&sort=updated_on:desc&limit=20";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Redmine-API-Key: $API_KEY",
        "Accept: application/json"
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    $atividades = [];

    foreach ($data['issues'] ?? [] as $issue) {
        $issue_id = $issue['id'];
        $issue_url = "$BASE_URL/issues/$issue_id.json?include=journals";

        $ch2 = curl_init($issue_url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            "X-Redmine-API-Key: $API_KEY",
            "Accept: application/json"
        ]);
        $resp2 = curl_exec($ch2);
        curl_close($ch2);

        $detalhe = json_decode($resp2, true);

        foreach ($detalhe['issue']['journals'] ?? [] as $journal) {
            if ($journal['user']['id'] == $id) {
                $atividades[] = [
                    'issue_id' => $issue_id,
                    'subject' => $detalhe['issue']['subject'],
                    'updated_on' => $journal['created_on'],
                    'url' => "$BASE_URL/issues/$issue_id"
                ];
            }
        }
    }

    // Ordenar por data (descendente)
    usort($atividades, function ($a, $b) {
        return strtotime($b['updated_on']) - strtotime($a['updated_on']);
    });

    return array_slice($atividades, 0, 5);
}

function getNomeUtilizador($id, $lista) {
    foreach ($lista as $u) {
        if ($u['id'] == $id) return $u['firstname'] . ' ' . $u['lastname'];
    }
    return "ID $id";
}

function calcularDataProximaReuniao($inicio, $diasAdicionais) {
    $data = clone $inicio;
    $conta = 0;
    while ($conta < $diasAdicionais) {
        $data->modify('+1 day');
        if (!in_array($data->format('N'), ['6', '7'])) {
            $conta++;
        }
    }
    return $data;
}

// Função para gerar lista de próximos gestores para os próximos 20 dias úteis
function gerarListaProximosGestores($db, $equipa) {
    if (empty($equipa)) {
        return; // Não faz nada se a equipe estiver vazia
    }

    // Limpar registros antigos não concluídos
    $stmt = $db->prepare("DELETE FROM proximos_gestores 
                          WHERE data_prevista < date('now') AND concluido = 0");
    $stmt->execute();

    // Verificar se já existe uma entrada para o dia atual
    $hoje = date('Y-m-d');
    $stmt = $db->prepare("SELECT COUNT(*) FROM proximos_gestores WHERE data_prevista = :hoje");
    $stmt->execute([':hoje' => $hoje]);
    $tem_hoje = $stmt->fetchColumn() > 0;

    // Se não tiver agendamento para hoje, criar um
    if (!$tem_hoje) {
        $membro_hoje = $equipa[array_rand($equipa)];
        $stmt = $db->prepare("INSERT INTO proximos_gestores (redmine_id, data_prevista) VALUES (:id, :data)");
        $stmt->execute([':id' => $membro_hoje, ':data' => $hoje]);
    }
    
    // Verificar quantos dias futuros estão planejados
    $stmt = $db->query("SELECT COUNT(*) FROM proximos_gestores WHERE data_prevista >= date('now') AND concluido = 0");
    $count = $stmt->fetchColumn();
    
    // Se tiver menos de 20 dias planejados para o futuro, gera novos
    if ($count < 20) {
        // Obter o último dia agendado
        $stmt = $db->query("SELECT MAX(data_prevista) FROM proximos_gestores WHERE data_prevista >= date('now')");
        $ultima_data = $stmt->fetchColumn();
        
        // Se não houver data futura, usar hoje como ponto de partida
        $inicio = new DateTime($ultima_data ?: $hoje);
        
        // Criar uma cópia embaralhada da equipe para distribuir aleatoriamente
        $equipe_copia = $equipa;
        shuffle($equipe_copia);
        
        // Calcular quantos dias precisamos adicionar
        $dias_necessarios = 20 - $count;
        $dias_adicionados = 0;
        $indice_equipe = 0;
        
        while ($dias_adicionados < $dias_necessarios) {
            // Pegar próximo membro da equipe, voltando ao início se necessário
            if ($indice_equipe >= count($equipe_copia)) {
                shuffle($equipe_copia); // Embaralhar novamente para variar a ordem
                $indice_equipe = 0;
            }
            
            $membro_id = $equipe_copia[$indice_equipe];
            $indice_equipe++;
            
            // Calcular próxima data útil
            $inicio->modify('+1 day');
            // Pular finais de semana
            while (in_array($inicio->format('N'), ['6', '7'])) {
                $inicio->modify('+1 day');
            }
            
            // Verificar se este membro já está agendado para esta data
            $stmt = $db->prepare("SELECT COUNT(*) FROM proximos_gestores 
                                 WHERE data_prevista = :data");
            $stmt->execute([':data' => $inicio->format('Y-m-d')]);
            
            if ($stmt->fetchColumn() == 0) {
                // Inserir novo agendamento
                $stmt = $db->prepare("INSERT INTO proximos_gestores (redmine_id, data_prevista) 
                                     VALUES (:id, :data)");
                $stmt->execute([
                    ':id' => $membro_id,
                    ':data' => $inicio->format('Y-m-d')
                ]);
                
                $dias_adicionados++;
            }
        }
    }
}

// Obter lista de próximos gestores
function getProximosGestores($db, $limite = 20) {
    $stmt = $db->prepare("SELECT redmine_id, data_prevista 
                         FROM proximos_gestores 
                         WHERE data_prevista >= date('now') AND concluido = 0
                         ORDER BY data_prevista ASC
                         LIMIT :limite");
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Funções para gerenciamento de faltas
function registrarFalta($db, $redmine_id, $motivo = '') {
    $stmt = $db->prepare("INSERT INTO faltas (redmine_id, motivo) VALUES (:id, :motivo)");
    $stmt->execute([
        ':id' => $redmine_id,
        ':motivo' => $motivo
    ]);
    
    return $stmt->rowCount() > 0;
}

function getFaltas($db, $redmine_id = null) {
    if ($redmine_id) {
        $stmt = $db->prepare("SELECT * FROM faltas WHERE redmine_id = :id ORDER BY data DESC");
        $stmt->execute([':id' => $redmine_id]);
    } else {
        $stmt = $db->query("SELECT * FROM faltas ORDER BY data DESC LIMIT 20");
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getNumeroFaltas($db, $redmine_id) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM faltas WHERE redmine_id = :id");
    $stmt->execute([':id' => $redmine_id]);
    return $stmt->fetchColumn();
}

// Obter dados
$utilizadores = getUtilizadoresRedmine();
$equipa = $db->query("SELECT redmine_id FROM equipa")->fetchAll(PDO::FETCH_COLUMN);

// Gerar lista de próximos gestores se necessário
gerarListaProximosGestores($db, $equipa);
$proximos_gestores = getProximosGestores($db);

// Inicializar ou recuperar variáveis de sessão
if (!isset($_SESSION['gestor'])) {
    $_SESSION['gestor'] = null;
    $_SESSION['em_reuniao'] = false;
    $_SESSION['oradores'] = [];
    $_SESSION['orador_atual'] = 0;
    $_SESSION['inicio_reuniao'] = null;
    $_SESSION['tempo_pausado'] = 0;
    $_SESSION['esta_pausado'] = false;
    $_SESSION['momento_pausa'] = null;
}

$gestor = $_SESSION['gestor'];
$em_reuniao = $_SESSION['em_reuniao'];
$oradores = $_SESSION['oradores'];
$orador_atual = $_SESSION['orador_atual'];
$tempo_pausado = $_SESSION['tempo_pausado'];
$esta_pausado = $_SESSION['esta_pausado'];

// Processar ações POST
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Adicionar membro à equipe
    if (isset($_POST['adicionar'])) {
        $id = (int)$_POST['adicionar'];
        $stmt = $db->prepare("INSERT OR IGNORE INTO equipa (redmine_id) VALUES (:id)");
        $stmt->execute([':id' => $id]);
        
        // Regenerar a lista de próximos gestores
        $equipa = $db->query("SELECT redmine_id FROM equipa")->fetchAll(PDO::FETCH_COLUMN);
        gerarListaProximosGestores($db, $equipa);
    }
    
    // Remover membro da equipe
    if (isset($_POST['remover'])) {
        $id = (int)$_POST['remover'];
        $stmt = $db->prepare("DELETE FROM equipa WHERE redmine_id = :id");
        $stmt->execute([':id' => $id]);
        
        // Limpar da lista de próximos gestores
        $stmt = $db->prepare("DELETE FROM proximos_gestores WHERE redmine_id = :id AND concluido = 0");
        $stmt->execute([':id' => $id]);
        
        if ($id === $_SESSION['gestor']) {
            $_SESSION['gestor'] = null;
        }
        
        // Regenerar a lista de próximos gestores
        $equipa = $db->query("SELECT redmine_id FROM equipa")->fetchAll(PDO::FETCH_COLUMN);
        gerarListaProximosGestores($db, $equipa);
    }
    
    // Iniciar reunião
    if (isset($_POST['iniciar'])) {
        // Verificar se existe um gestor agendado para hoje
        $hoje = date('Y-m-d');
        $stmt = $db->prepare("SELECT redmine_id FROM proximos_gestores 
                             WHERE data_prevista = :hoje AND concluido = 0 
                             LIMIT 1");
        $stmt->execute([':hoje' => $hoje]);
        $gestor_hoje = $stmt->fetchColumn();
        
        if ($gestor_hoje && in_array($gestor_hoje, $equipa)) {
            $_SESSION['gestor'] = $gestor_hoje;
            
            // Marcar como concluído
            $stmt = $db->prepare("UPDATE proximos_gestores SET concluido = 1 
                                 WHERE redmine_id = :id AND data_prevista = :hoje");
            $stmt->execute([':id' => $gestor_hoje, ':hoje' => $hoje]);
        } else {
            // Se não houver gestor agendado para hoje, selecionar aleatoriamente
            $_SESSION['gestor'] = $equipa[array_rand($equipa)];
        }
        
        $_SESSION['oradores'] = $equipa;
        shuffle($_SESSION['oradores']);
        $_SESSION['em_reuniao'] = true;
        $_SESSION['orador_atual'] = 0;
        $_SESSION['inicio_reuniao'] = time();
        $_SESSION['tempo_pausado'] = 0;
        $_SESSION['esta_pausado'] = false;
        $_SESSION['momento_pausa'] = null;
    }
    
    // Terminar reunião
    if (isset($_POST['terminar'])) {
        // Limpar a sessão
        $_SESSION['gestor'] = null;
        $_SESSION['em_reuniao'] = false;
        $_SESSION['oradores'] = [];
        $_SESSION['orador_atual'] = 0;
        $_SESSION['inicio_reuniao'] = null;
        $_SESSION['tempo_pausado'] = 0;
        $_SESSION['esta_pausado'] = false;
        $_SESSION['momento_pausa'] = null;
    }
    
    // Pausar/Continuar reunião
    if (isset($_POST['pausar'])) {
        if ($_SESSION['esta_pausado']) {
            // Calcular tempo decorrido durante a pausa
            $tempo_pausa = time() - $_SESSION['momento_pausa'];
            $_SESSION['tempo_pausado'] += $tempo_pausa;
            $_SESSION['esta_pausado'] = false;
            $_SESSION['momento_pausa'] = null;
        } else {
            $_SESSION['esta_pausado'] = true;
            $_SESSION['momento_pausa'] = time();
        }
        
        // Não redirecionamos aqui para que o JavaScript continue funcionando
        echo json_encode(['success' => true, 'pausado' => $_SESSION['esta_pausado']]);
        exit;
    }
    
    // Próximo orador
    if (isset($_POST['proximo'])) {
        $_SESSION['orador_atual']++;
    }
    
    // Recusar ser gestor
    if (isset($_POST['recusar'])) {
        $idRecusado = (int)$_POST['recusar'];
        $dataRecusada = $_POST['data_recusada'] ?? '';
        
        if (!empty($dataRecusada)) {
            // Remover este gestor da data específica
            $stmt = $db->prepare("DELETE FROM proximos_gestores 
                                 WHERE redmine_id = :id AND data_prevista = :data AND concluido = 0");
            $stmt->execute([':id' => $idRecusado, ':data' => $dataRecusada]);
            
            // Adicionar outro gestor nesta data
            if (count($equipa) > 1) {
                $equipe_copia = array_filter($equipa, function($e) use ($idRecusado) {
                    return $e != $idRecusado;
                });
                $novo_gestor = $equipe_copia[array_rand($equipe_copia)];
                
                $stmt = $db->prepare("INSERT INTO proximos_gestores (redmine_id, data_prevista) 
                                     VALUES (:id, :data)");
                $stmt->execute([':id' => $novo_gestor, ':data' => $dataRecusada]);
            }
        } else {
            // Remover este gestor de todas as datas futuras
            $stmt = $db->prepare("DELETE FROM proximos_gestores 
                                 WHERE redmine_id = :id AND data_prevista >= date('now') AND concluido = 0");
            $stmt->execute([':id' => $idRecusado]);
        }
        
        // Regenerar a lista
        gerarListaProximosGestores($db, $equipa);
    }
    
    // Marcar falta
    if (isset($_POST['marcar_falta'])) {
        $id = (int)$_POST['marcar_falta'];
        $motivo = $_POST['motivo_falta'] ?? '';
        
        $resultado = registrarFalta($db, $id, $motivo);
        
        // Se for o orador atual, passar para o próximo
        if ($resultado && $em_reuniao && isset($oradores[$orador_atual]) && $oradores[$orador_atual] == $id) {
            $_SESSION['orador_atual']++;
        }
    }
    
    // Mover para o final da fila
    if (isset($_POST['mover_final'])) {
        $id = (int)$_POST['mover_final'];
        
        // Se for o orador atual
        if ($em_reuniao && isset($oradores[$orador_atual]) && $oradores[$orador_atual] == $id) {
            // Remover da posição atual e adicionar ao final
            $orador = $oradores[$orador_atual];
            array_splice($_SESSION['oradores'], $orador_atual, 1);
            $_SESSION['oradores'][] = $orador;
            
            // Passar para o próximo orador
            if (count($_SESSION['oradores']) > $_SESSION['orador_atual']) {
                // Não precisamos incrementar o índice porque já removemos o elemento
            } else {
                // Se removemos o último elemento, voltamos para o início
                $_SESSION['orador_atual'] = 0;
            }
        }
    }
    
    header("Location: ?tab=equipa");
    exit;
}

// Calcular tempo total de reunião
$tempo_total = 0;
if ($em_reuniao) {
    if ($esta_pausado) {
        $tempo_total = ($_SESSION['momento_pausa'] - $_SESSION['inicio_reuniao']) - $tempo_pausado;
    } else {
        $tempo_total = (time() - $_SESSION['inicio_reuniao']) - $tempo_pausado;
    }
}

// Verificar se a reunião terminou (todos os oradores falaram)
$reuniao_concluida = $em_reuniao && $orador_atual >= count($oradores);

?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .reuniao-card {
            border-left: 5px solid #0d6efd;
        }
        .progress {
            height: 15px;
        }
        .badge {
            font-size: 0.9em;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .card {
            margin-bottom: 20px;
        }
        .timer-display {
            font-size: 2.5rem;
            font-weight: bold;
            font-family: monospace;
        }
        .btn-action {
            min-width: 120px;
        }
    </style>
</head>
<body>

<div class="container-fluid py-3">
    <div class="row">
        <div class="col-lg-8">
            <h2 class="mt-3 mb-4">
                <i class="bi bi-people-fill"></i> Reunião Diária
                <?php if ($em_reuniao): ?>
                    <span class="badge bg-success">Em Progresso</span>
                <?php endif; ?>
            </h2>

            <?php if (empty($equipa)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle-fill"></i> A equipa ainda não foi configurada. Por favor adicione membros abaixo para iniciar.
                </div>
            <?php endif; ?>

            <!-- Área de Reunião -->
            <?php if ($em_reuniao): ?>
                <div class="card reuniao-card mb-4">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0"><i class="bi bi-calendar-check"></i> Reunião em Progresso</h4>
                            <form method="post" class="d-inline">
                                <button type="submit" name="terminar" class="btn btn-sm btn-danger">
                                    <i class="bi bi-stop-circle"></i> Encerrar Reunião
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong><i class="bi bi-person-circle"></i> Gestor da reunião:</strong> 
                                <?= htmlspecialchars(getNomeUtilizador($gestor, $utilizadores)) ?>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <strong><i class="bi bi-clock"></i> Tempo total:</strong> 
                                <span id="tempo-total" class="badge bg-secondary"><?= gmdate('H:i:s', $tempo_total) ?></span>
                            </div>
                        </div>
                        
                        <?php if ($reuniao_concluida): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill"></i> Reunião concluída! Todos os membros se pronunciaram.
                                <div class="mt-3">
                                    <form method="post">
                                        <button type="submit" name="terminar" class="btn btn-primary">
                                            Finalizar e voltar ao início
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php $oradorId = $oradores[$orador_atual] ?? null; ?>
                            <?php if ($oradorId): ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="card mb-3">
                                            <div class="card-header bg-info text-white">
                                                <h5 class="mb-0"><i class="bi bi-mic-fill"></i> Orador atual: <?= htmlspecialchars(getNomeUtilizador($oradorId, $utilizadores)) ?></h5>
                                            </div>
                                            <div class="card-body text-center">
                                                <div id="cronometro" class="timer-display my-2">30</div>
                                                <div class="progress mb-3">
                                                    <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 100%"></div>
                                                </div>
                                                <audio id="beep" src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg"></audio>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <button type="button" id="btn-pausar" class="btn <?= $esta_pausado ? 'btn-success' : 'btn-warning' ?> btn-action">
                                                <?= $esta_pausado ? '<i class="bi bi-play-fill"></i> Continuar' : '<i class="bi bi-pause-fill"></i> Pausar' ?>
                                            </button>
                                            
                                            <form method="post" class="d-inline">
                                                <button type="submit" name="proximo" class="btn btn-primary btn-action">
                                                    <i class="bi bi-skip-forward-fill"></i> Próximo
                                                </button>
                                            </form>
                                            
                                            <button type="button" class="btn btn-danger btn-action" data-bs-toggle="modal" data-bs-target="#faltaModal">
                                                <i class="bi bi-x-circle"></i> Marcar Falta
                                            </button>
                                            
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="mover_final" value="<?= $oradorId ?>">
                                                <button type="submit" class="btn btn-secondary btn-action">
                                                    <i class="bi bi-arrow-return-right"></i> Mover para Final
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="card">
                                            <div class="card-header bg-secondary text-white">
                                                <h5 class="mb-0"><i class="bi bi-activity"></i> Atividades recentes:</h5>
                                            </div>
                                            <div class="card-body p-0">
                                                <ul class="list-group list-group-flush">
                                                    <?php 
                                                    $atividades = getAtividadesUtilizador($oradorId);
                                                    if (empty($atividades)):
                                                    ?>
                                                        <li class="list-group-item text-muted">
                                                            <i class="bi bi-info-circle"></i> Nenhuma atividade recente encontrada.
                                                        </li>
                                                    <?php else: ?>
                                                        <?php foreach ($atividades as $act): ?>
                                                            <li class="list-group-item">
                                                                <a href="<?= htmlspecialchars($act['url']) ?>" target="_blank" class="text-decoration-none">
                                                                    <strong class="text-primary">#<?= $act['issue_id'] ?></strong> 
                                                                    <?= htmlspecialchars($act['subject']) ?>
                                                                </a>
                                                                <br>
                                                                <small class="text-muted">
                                                                    <i class="bi bi-clock-history"></i> 
                                                                    <?= date('d/m/Y H:i', strtotime($act['updated_on'])) ?>
                                                                </small>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Modal para Marcar Falta -->
                                <div class="modal fade" id="faltaModal" tabindex="-1" aria-labelledby="faltaModalLabel" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title" id="faltaModalLabel">
                                                    <i class="bi bi-exclamation-triangle"></i> 
                                                    Marcar Falta para <?= htmlspecialchars(getNomeUtilizador($oradorId, $utilizadores)) ?>
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form method="post">
                                                <div class="modal-body">
                                                    <input type="hidden" name="marcar_falta" value="<?= $oradorId ?>">
                                                    <div class="mb-3">
                                                        <label for="motivo_falta" class="form-label">Motivo da falta:</label>
                                                        <textarea class="form-control" id="motivo_falta" name="motivo_falta" rows="3" placeholder="Descreva o motivo da falta..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                    <button type="submit" class="btn btn-danger">
                                                        <i class="bi bi-check-lg"></i> Confirmar Falta
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif (count($equipa) >= 2): ?>
                <div class="card mb-4">
                    <div class="card-body text-center">
                        <p class="lead mb-3">A reunião ainda não foi iniciada.</p>
                        <form method="post">
                            <button type="submit" name="iniciar" class="btn btn-success btn-lg">
                                <i class="bi bi-play-fill"></i> Iniciar Reunião
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Próximos Gestores -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0"><i class="bi bi-calendar-week"></i> Próximos Gestores de Reunião</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($proximos_gestores)): ?>
                        <p class="text-muted">Nenhum gestor agendado para os próximos dias.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Data Prevista</th>
                                        <th>Gestor</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($proximos_gestores as $prox): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                    $data = new DateTime($prox['data_prevista']);
                                                    $hoje = new DateTime('today');
                                                    $eh_hoje = $data->format('Y-m-d') === $hoje->format('Y-m-d');
                                                    
                                                    if ($eh_hoje) {
                                                        echo '<span class="badge bg-primary">Hoje</span> ';
                                                    }
                                                    
                                                    // Dia da semana em português
                                                    $dias_semana = [
                                                        1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 
                                                        4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'
                                                    ];
                                                    
                                                    echo $data->format('d/m/Y') . ' (' . $dias_semana[(int)$data->format('N')] . ')';
                                                ?>
                                            </td>
                                            <td><?= htmlspecialchars(getNomeUtilizador($prox['redmine_id'], $utilizadores)) ?></td>
                                            <td>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="recusar" value="<?= $prox['redmine_id'] ?>">
                                                    <input type="hidden" name="data_recusada" value="<?= $prox['data_prevista'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-x-lg"></i> Recusar
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Gestão da Equipe -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-people"></i> Gestão da Equipa</h4>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label for="adicionar" class="form-label">Adicionar elemento à equipa:</label>
                            <select name="adicionar" id="adicionar" class="form-select">
                                <?php foreach ($utilizadores as $u): ?>
                                    <?php if (!in_array($u['id'], $equipa)): ?>
                                        <option value="<?= $u['id'] ?>"> 
                                            <?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname']) ?> 
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 align-self-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Adicionar
                            </button>
                        </div>
                    </form>

                    <h5><i class="bi bi-person-lines-fill"></i> Membros da Equipa:</h5>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th class="text-center">Faltas</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (empty($equipa)): 
                                ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted">
                                            Nenhum membro na equipe.
                                        </td>
                                    </tr>
                                <?php 
                                else:
                                    foreach ($equipa as $id): 
                                        // Contar faltas deste membro
                                        $total_faltas = getNumeroFaltas($db, $id);
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars(getNomeUtilizador($id, $utilizadores)) ?></td>
                                        <td class="text-center">
                                            <?php if ($total_faltas > 0): ?>
                                                <span class="badge bg-danger"><?= $total_faltas ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="remover" value="<?= $id ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i> Remover
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php 
                                    endforeach;
                                endif;
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Coluna Lateral - Faltas Recentes -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-warning">
                    <h4 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Faltas Recentes</h4>
                </div>
                <div class="card-body">
                    <?php 
                    $faltas = getFaltas($db);
                    if (empty($faltas)):
                    ?>
                        <p class="text-muted text-center">
                            <i class="bi bi-emoji-smile"></i> Nenhuma falta registrada.
                        </p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Membro</th>
                                        <th>Motivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($faltas as $falta): ?>
                                        <tr>
                                            <td><?= date('d/m/Y', strtotime($falta['data'])) ?></td>
                                            <td><?= htmlspecialchars(getNomeUtilizador($falta['redmine_id'], $utilizadores)) ?></td>
                                            <td>
                                                <?php 
                                                $motivo = $falta['motivo'] ?: 'Não especificado';
                                                echo mb_strlen($motivo) > 50 ? mb_substr(htmlspecialchars($motivo), 0, 50) . '...' : htmlspecialchars($motivo);
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 
    Cronômetro diretamente incorporado na página - evitando problemas com JavaScript externo 
    Inspirado em https://codepen.io/dcode-software/pen/XWNWoYm
-->
<script>
    // Esta função será executada uma vez que o documento estiver carregado
    const cronometroSimples = () => {
        // Elemento onde mostraremos o tempo
        const displayElement = document.getElementById('cronometro');
        const progressBar = document.getElementById('progress-bar');
        const beepSound = document.getElementById('beep');
        
        // Não iniciar se não encontrarmos os elementos
        if (!displayElement || !progressBar) return;
        
        // Tempo total em segundos
        let timeLeft = 30;
        const totalTime = 30;
        
        // Função que atualiza a exibição do temporizador
        function atualizarDisplay() {
            displayElement.textContent = timeLeft;
            
            // Atualizar barra de progresso
            const percentual = (timeLeft / totalTime) * 100;
            progressBar.style.width = percentual + "%";
            
            // Mudar cor conforme o tempo restante
            if (timeLeft <= 5) {
                progressBar.className = "progress-bar progress-bar-striped progress-bar-animated bg-danger";
                if (timeLeft === 5 && beepSound) {
                    beepSound.play().catch(err => console.log('Erro ao tocar som:', err));
                }
            } else if (timeLeft <= 15) {
                progressBar.className = "progress-bar progress-bar-striped progress-bar-animated bg-warning";
            }
        }
        
        // Estado de controle
        let isPaused = <?= $esta_pausado ? 'true' : 'false' ?>;
        let timerId = null;
        
        // Iniciar o timer
        function iniciarTimer() {
            if (timerId) return; // Evitar múltiplos temporizadores
            
            timerId = setInterval(() => {
                if (!isPaused) {
                    timeLeft -= 1;
                    atualizarDisplay();
                    
                    if (timeLeft <= 0) {
                        clearInterval(timerId);
                        displayElement.textContent = "Tempo Esgotado!";
                        progressBar.style.width = "0%";
                        progressBar.className = "progress-bar bg-danger";
                    }
                }
            }, 1000);
        }
        
        // Lidar com botão de pausa
        const pauseButton = document.getElementById('btn-pausar');
        if (pauseButton) {
            pauseButton.addEventListener('click', () => {
                fetch('?tab=equipa', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'pausar=1'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        isPaused = data.pausado;
                        
                        // Atualizar botão
                        if (isPaused) {
                            pauseButton.classList.remove('btn-warning');
                            pauseButton.classList.add('btn-success');
                            pauseButton.innerHTML = '<i class="bi bi-play-fill"></i> Continuar';
                        } else {
                            pauseButton.classList.remove('btn-success');
                            pauseButton.classList.add('btn-warning');
                            pauseButton.innerHTML = '<i class="bi bi-pause-fill"></i> Pausar';
                        }
                    }
                })
                .catch(error => console.error('Erro:', error));
            });
        }
        
        // Inicializar
        atualizarDisplay();
        iniciarTimer();
    };

    document.addEventListener('DOMContentLoaded', () => {
        console.log("Documento carregado, verificando se cronômetro deve ser iniciado...");
        <?php if ($em_reuniao && !$reuniao_concluida && isset($oradorId)): ?>
        console.log("Iniciando cronômetro simples...");
        cronometroSimples();
        <?php else: ?>
        console.log("Cronômetro não iniciado - reunião não em andamento ou concluída");
        <?php endif; ?>
    });
</script>

<?php
// Endpoint para obter o tempo total (para atualização via AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'get_tempo_total' && $em_reuniao) {
    $tempo_total = 0;
    if ($esta_pausado) {
        $tempo_total = ($_SESSION['momento_pausa'] - $_SESSION['inicio_reuniao']) - $tempo_pausado;
    } else {
        $tempo_total = (time() - $_SESSION['inicio_reuniao']) - $tempo_pausado;
    }
    echo gmdate('H:i:s', $tempo_total);
    exit;
}
?>
</body>
</html>