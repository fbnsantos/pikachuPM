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
                'updated_at' => $row['updated_at'],
                'tempo_decorrido' => $tempo_decorrido,
                'url' => $url,
                'autor_nome' => $row['autor_nome'],
                'responsavel_nome' => $row['responsavel_nome'],
                'projeto_id' => $row['projeto_id'],
                'task_id' => $row['task_id']
            ];
        }
        
        $stmt->close();
        $db->close();
        
        return $atividades;
        
    } catch (Exception $e) {
        error_log("Erro ao buscar atividades do utilizador: " . $e->getMessage());
        return [];
    }
}

function getNomeUtilizador($id, $lista) {
    foreach ($lista as $u) {
        if ($u['id'] == $id) return   $u['firstname'] . ' ' . $u['lastname'];
    }

    return "ID $id";
}

function getNomeUtilizador_append($text, $id, $lista) {
    foreach ($lista as $u) {
        if ($u['id'] == $id) return  $text . $u['firstname'] . ' ' . $u['lastname'];
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

// Função para gerar lista de próximos gestores para os próximos 10 dias úteis
function gerarListaProximosGestores($db, $equipa) {
    if (empty($equipa)) {
        return; // Não faz nada se a equipe estiver vazia
    }

    // Limpar registros antigos não concluídos
    $stmt = $db->prepare("DELETE FROM proximos_gestores 
                          WHERE data_prevista < date('now' ) ");
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
    
    // Se tiver menos de 10 dias planejados para o futuro, gera novos
    $dias_necessarios = 10; // Alterado de 20 para 10
    if ($count < $dias_necessarios) {
        // Obter o último dia agendado
        $stmt = $db->query("SELECT MAX(data_prevista) FROM proximos_gestores WHERE data_prevista >= date('now')");
        $ultima_data = $stmt->fetchColumn();
        
        // Se não houver data futura, usar hoje como ponto de partida
        $inicio = new DateTime($ultima_data ?: $hoje);
        
        // Criar uma cópia embaralhada da equipe para distribuir aleatoriamente
        $equipe_copia = $equipa;
        shuffle($equipe_copia);
        
        // Calcular quantos dias precisamos adicionar
        $dias_necessarios = $dias_necessarios - $count;
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
function getProximosGestores($db, $limite = 15) {
    // Primeiro, buscar o gestor para o dia atual
    $hoje = date('Y-m-d');
    $stmt = $db->prepare("SELECT redmine_id, data_prevista 
                         FROM proximos_gestores 
                         WHERE data_prevista = :hoje
                         LIMIT 1");
    $stmt->execute([':hoje' => $hoje]);
    $gestor_hoje = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Depois, buscar os próximos gestores (excluindo o de hoje)
    $stmt = $db->prepare("SELECT redmine_id, data_prevista 
                         FROM proximos_gestores 
                         WHERE data_prevista > :hoje AND concluido = 0
                         ORDER BY data_prevista ASC
                         LIMIT :limite");
    $stmt->bindValue(':hoje', $hoje, PDO::PARAM_STR);
    $stmt->bindValue(':limite', $limite - 1, PDO::PARAM_INT); // -1 para reservar espaço para o gestor de hoje
    $stmt->execute();
    $proximos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combinar o gestor de hoje com os próximos
    $resultado = [];
    if ($gestor_hoje) {
        $resultado[] = $gestor_hoje;
    }
    return array_merge($resultado, $proximos);
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

// Funções para gerenciar as notas markdown
function salvarNota($db, $titulo, $conteudo, $id = null) {
    if ($id) {
        // Atualizar nota existente
        $stmt = $db->prepare("UPDATE notas_markdown SET titulo = :titulo, conteudo = :conteudo, 
                            data_atualizacao = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([
            ':id' => $id,
            ':titulo' => $titulo,
            ':conteudo' => $conteudo
        ]);
    } else {
        // Criar nova nota
        $stmt = $db->prepare("INSERT INTO notas_markdown (titulo, conteudo) VALUES (:titulo, :conteudo)");
        $stmt->execute([
            ':titulo' => $titulo,
            ':conteudo' => $conteudo
        ]);
        return $db->lastInsertId();
    }
    return $id;
}

function getNota($db, $id = null) {
    // Verificar se a tabela existe
    try {
        $verificar_tabela = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='notas_markdown'");
        if ($verificar_tabela->fetchColumn() === false) {
            // A tabela não existe, retornar array vazio
            return [];
        }
        
        if ($id) {
            $stmt = $db->prepare("SELECT * FROM notas_markdown WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Retornar a última nota criada/atualizada
            $stmt = $db->query("SELECT * FROM notas_markdown ORDER BY data_atualizacao DESC LIMIT 1");
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Em caso de erro, retornar array vazio
        return [];
    }
}

function getTodasNotas($db) {
    // Verificar se a tabela existe
    try {
        $verificar_tabela = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='notas_markdown'");
        if ($verificar_tabela->fetchColumn() === false) {
            // A tabela não existe, retornar array vazio
            return [];
        }
        
        $stmt = $db->query("SELECT * FROM notas_markdown ORDER BY data_atualizacao DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Em caso de erro, retornar array vazio
        return [];
    }
}

function excluirNota($db, $id) {
    try {
        $stmt = $db->prepare("DELETE FROM notas_markdown WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
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
}

$gestor = $_SESSION['gestor'];
$em_reuniao = $_SESSION['em_reuniao'];
$oradores = $_SESSION['oradores'];
$orador_atual = $_SESSION['orador_atual'];

// Processar ações de gestão de gestores
if (isset($_POST['gerar_gestores_aleatorios'])) {
    try {
        $membros_ativos = $db->query("SELECT redmine_id FROM equipa")->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($membros_ativos)) {
            $_SESSION['msg_erro'] = "Não há membros na equipa para gerar gestores.";
        } else {
            // Limpar gestores futuros (não concluídos)
            $db->exec("DELETE FROM proximos_gestores WHERE concluido = 0 AND data_prevista > date('now')");
            
            // Gerar gestores para os próximos 30 dias
            $data_inicio = new DateTime();
            $data_inicio->modify('+1 day'); // Começar a partir de amanhã
            
            for ($i = 0; $i < 30; $i++) {
                // Pular fins de semana
                while (in_array($data_inicio->format('N'), ['6', '7'])) {
                    $data_inicio->modify('+1 day');
                }
                
                $data_prevista = $data_inicio->format('Y-m-d');
                $gestor_aleatorio = $membros_ativos[array_rand($membros_ativos)];
                
                $stmt = $db->prepare("INSERT INTO proximos_gestores (redmine_id, data_prevista, concluido) VALUES (?, ?, 0)");
                $stmt->execute([$gestor_aleatorio, $data_prevista]);
                
                $data_inicio->modify('+1 day');
            }
            
            $_SESSION['msg_sucesso'] = "Gestores aleatórios gerados para os próximos 30 dias úteis!";
        }
    } catch (Exception $e) {
        $_SESSION['msg_erro'] = "Erro ao gerar gestores: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=equipa");
    exit;
}

if (isset($_POST['atribuir_gestor_manual']) && isset($_POST['redmine_id']) && isset($_POST['data_prevista'])) {
    try {
        $redmine_id = intval($_POST['redmine_id']);
        $data_prevista = $_POST['data_prevista'];
        
        // Verificar se já existe um gestor para essa data
        $stmt = $db->prepare("SELECT id FROM proximos_gestores WHERE data_prevista = ? AND concluido = 0");
        $stmt->execute([$data_prevista]);
        $existe = $stmt->fetch();
        
        if ($existe) {
            // Atualizar gestor existente
            $stmt = $db->prepare("UPDATE proximos_gestores SET redmine_id = ? WHERE data_prevista = ? AND concluido = 0");
            $stmt->execute([$redmine_id, $data_prevista]);
            $_SESSION['msg_sucesso'] = "Gestor atualizado para " . date('d/m/Y', strtotime($data_prevista)) . "!";
        } else {
            // Inserir novo gestor
            $stmt = $db->prepare("INSERT INTO proximos_gestores (redmine_id, data_prevista, concluido) VALUES (?, ?, 0)");
            $stmt->execute([$redmine_id, $data_prevista]);
            $_SESSION['msg_sucesso'] = "Gestor atribuído para " . date('d/m/Y', strtotime($data_prevista)) . "!";
        }
    } catch (Exception $e) {
        $_SESSION['msg_erro'] = "Erro ao atribuir gestor: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=equipa");
    exit;
}

if (isset($_POST['limpar_proximos_gestores'])) {
    try {
        $db->exec("DELETE FROM proximos_gestores WHERE concluido = 0 AND data_prevista > date('now')");
        $_SESSION['msg_sucesso'] = "Próximos gestores limpos com sucesso!";
    } catch (Exception $e) {
        $_SESSION['msg_erro'] = "Erro ao limpar gestores: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?tab=equipa");
    exit;
}

// Processar ações para as notas Markdown
if (isset($_POST['acao_nota'])) {
    switch ($_POST['acao_nota']) {
        case 'salvar':
            $titulo = $_POST['titulo_nota'] ?? 'Nota sem título';
            $conteudo = $_POST['conteudo_nota'] ?? '';
            $id = !empty($_POST['id_nota']) ? (int)$_POST['id_nota'] : null;
            salvarNota($db, $titulo, $conteudo, $id);
            break;
            
        case 'excluir':
            if (!empty($_POST['id_nota'])) {
                excluirNota($db, (int)$_POST['id_nota']);
            }
            break;
    }
    
    // Redirecionar para evitar resubmissão do form ao atualizar a página
    header("Location: ?tab=equipa#secao-notas");
    exit;
}

// Obter a nota atual para exibir (última atualizada)
$nota_atual = getNota($db);
$todas_notas = getTodasNotas($db);

// Apenas responda com JSON para requisições AJAX
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    if (isset($_POST['acao'])) {
        $resposta = ['sucesso' => true];
        
        switch ($_POST['acao']) {
            case 'proximo_orador':
                // Avançar para o próximo orador
                $_SESSION['orador_atual']++;
                $resposta['mensagem'] = 'Avançado para o próximo orador';
                break;
                
            case 'tempo_esgotado':
                // Registrar que o tempo acabou para este orador
                $resposta['mensagem'] = 'Tempo esgotado registrado';
                break;
                
            case 'terminar_reuniao':
                // Finalizar a reunião
                $_SESSION['em_reuniao'] = false;
                $resposta['mensagem'] = 'Reunião finalizada';
                break;
                
            default:
                $resposta['sucesso'] = false;
                $resposta['mensagem'] = 'Ação desconhecida';
        }
        
        header('Content-Type: application/json');
        echo json_encode($resposta);
        exit;
    }
}

// Processar ações POST
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Adicionar membro à equipe
    if (isset($_POST['adicionar'])) {
        $id = (int)$_POST['adicionar'];
        $stmt = $db->prepare("INSERT OR IGNORE INTO equipa (redmine_id) VALUES (:id)");
        $stmt->execute([':id' => $id]);
        
        // Regenerar a lista de próximos gestores
        //$equipa = $db->query("SELECT redmine_id FROM equipa")->fetchAll(PDO::FETCH_COLUMN);
        //gerarListaProximosGestores($db, $equipa);
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
        //$equipa = $db->query("SELECT redmine_id FROM equipa")->fetchAll(PDO::FETCH_COLUMN);
        //gerarListaProximosGestores($db, $equipa);
    }
    
    // Iniciar reunião
    if (isset($_POST['iniciar'])) {
        // Verificar se existe um gestor agendado para hoje
    // Verificar se existe um gestor agendado para hoje
    $hoje = date('Y-m-d');
    $stmt = $db->prepare("SELECT redmine_id FROM proximos_gestores 
                         WHERE data_prevista = :hoje 
                         LIMIT 1");
    $stmt->execute([':hoje' => $hoje]);
    $gestor_hoje = $stmt->fetchColumn();
    
    if ($gestor_hoje && in_array($gestor_hoje, $equipa)) {
        $_SESSION['gestor'] = $gestor_hoje;
        
        // Remover ou comentar esta parte:
        // $stmt = $db->prepare("UPDATE proximos_gestores SET concluido = 1 
        //                      WHERE redmine_id = :id AND data_prevista = :hoje");
        // $stmt->execute([':id' => $gestor_hoje, ':hoje' => $hoje]);
    } else {
        // Se não houver gestor agendado para hoje, selecionar aleatoriamente
        $_SESSION['gestor'] = $equipa[array_rand($equipa)];
    }
        
        $_SESSION['oradores'] = $equipa;
        shuffle($_SESSION['oradores']);
        $_SESSION['em_reuniao'] = true;
        $_SESSION['orador_atual'] = 0;
        $_SESSION['inicio_reuniao'] = time();
    }
    
    // Terminar reunião
    if (isset($_POST['terminar'])) {
        // Limpar a sessão
        $_SESSION['gestor'] = null;
        $_SESSION['em_reuniao'] = false;
        $_SESSION['oradores'] = [];
        $_SESSION['orador_atual'] = 0;
        $_SESSION['inicio_reuniao'] = null;
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
if ($em_reuniao && isset($_SESSION['inicio_reuniao'])) {
    $tempo_total = time() - $_SESSION['inicio_reuniao'];
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked@4.0.0/marked.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/github-markdown-css@5.1.0/github-markdown.min.css">
    <style>
        .hover-highlight:hover {
         background-color: #f8f9fa;
       transition: background-color 0.2s ease;
      }

        .hover-highlight a:hover {
                color: #0d6efd !important;
     }
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
        .debug-area {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        /* Estilos para a seção de notas Markdown */
        .markdown-preview {
            padding: 1rem;
            border: 1px solid #dee2e6;
            border-radius: .25rem;
            background-color: #f8f9fa;
            min-height: 200px;
            overflow-y: auto;
        }
        .markdown-editor {
            min-height: 200px;
            font-family: monospace;
        }
        .nota-lista-item {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .nota-lista-item:hover {
            background-color: #f0f0f0;
        }
        .nota-lista-item.active {
            background-color: #e9ecef;
            border-left: 3px solid #0d6efd;
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
                                <?php
                                // Verificar se o orador fez o Daily Report hoje
                                $has_daily_report = false;
                                try {
                                    $db_check = new mysqli($db_host, $db_user, $db_pass, $db_name);
                                    $db_check->set_charset('utf8mb4');
                                    
                                    $stmt_report = $db_check->prepare("SELECT id FROM daily_reports WHERE user_id = ? AND report_date = CURDATE()");
                                    $stmt_report->bind_param('i', $oradorId);
                                    $stmt_report->execute();
                                    $has_daily_report = $stmt_report->get_result()->num_rows > 0;
                                    $stmt_report->close();
                                    $db_check->close();
                                } catch (Exception $e) {
                                    // Tabela pode não existir ainda, ignorar erro
                                }
                                ?>
                                
                                <?php if (!$has_daily_report): ?>
                                    <div class="alert alert-warning mb-3">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong><?= htmlspecialchars(getNomeUtilizador($oradorId, $utilizadores)) ?></strong> 
                                        ainda não fez o Daily Report de hoje.
                                        <a href="?tab=todos#daily-report" class="alert-link">Ir para Daily Report</a>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-success mb-3">
                                        <i class="bi bi-check-circle"></i>
                                        <strong><?= htmlspecialchars(getNomeUtilizador($oradorId, $utilizadores)) ?></strong> 
                                        já completou o Daily Report de hoje! ✅
                                    </div>
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <!-- INÍCIO DO CRONÔMETRO -->
                                        <div class="card mb-3">
                                            <div class="card-header bg-info text-white">
                                                <h5 class="mb-0"><i class="bi bi-mic-fill"></i> Orador atual: <?= htmlspecialchars(getNomeUtilizador_append($orador_atual.'/'.count($oradores).' ',$oradorId, $utilizadores)) ?></h5>
                                            </div>
                                            <div class="card-body text-center">
                                                <!-- Mostrador do cronômetro -->
                                                <div id="cronometro" class="timer-display my-2">30</div>
                                                
                                                <!-- Barra de progresso -->
                                                <div class="progress mb-3">
                                                    <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 100%"></div>
                                                </div>
                                                
                                                <!-- Audio para o alerta -->
                                                <audio id="beep" src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg" preload="auto"></audio>
                                                
                                                <!-- Área de debug (opcional) -->
                                                <div id="debug-area" class="text-start mt-3 border-top pt-2 debug-area d-none">
                                                    <small>Status: <span id="status-display">Ativo</span></small><br>
                                                    <small>Último evento: <span id="event-log">Iniciando cronômetro</span></small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <button id="btn-pausar" class="btn btn-warning btn-action">
                                                <i class="bi bi-pause-fill"></i> Pausar
                                            </button>
                                            
                                            <button id="btn-reiniciar" class="btn btn-secondary btn-action">
                                                <i class="bi bi-arrow-repeat"></i> Reiniciar
                                            </button>
                                            
                                            <form method="post" class="d-inline">
                                                <button type="submit" name="proximo" class="btn btn-primary btn-action">
                                                    <i class="bi bi-skip-forward-fill"></i> Próximo
                                                </button>
                                            </form>
                                            
                                            <button type="button" class="btn btn-danger btn-action" data-bs-toggle="modal" data-bs-target="#faltaModal">
                                                <i class="bi bi-x-circle"></i> Marcar Falta
                                            </button>
                                            
                                            <form method="post" class="d-inline mt-2">
                                                <input type="hidden" name="mover_final" value="<?= $oradorId ?>">
                                                <button type="submit" class="btn btn-secondary btn-action">
                                                    <i class="bi bi-arrow-return-right"></i> Mover para Final
                                                </button>
                                            </form>
                                        </div>
                                        <!-- FIM DO CRONÔMETRO -->
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
                                                        <li class="list-group-item text-muted text-center py-4">
                                                            <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                                            <p class="mb-0 mt-2">Nenhuma atividade recente encontrada.</p>
                                                        </li>
                                                    <?php else: ?>
                                                        <?php foreach ($atividades as $act): ?>
                                                            <li class="list-group-item hover-highlight">
                                                                <div class="d-flex justify-content-between align-items-start mb-1">
                                                                    <div class="flex-grow-1">
                                                                        <a href="<?= htmlspecialchars($act['url']) ?>" 
                                                                        class="text-decoration-none fw-bold text-dark"
                                                                        <?= strpos($act['url'], 'http') === 0 ? 'target="_blank"' : '' ?>>
                                                                            <?= htmlspecialchars($act['titulo']) ?>
                                                                            <?php if (strpos($act['url'], 'http') === 0): ?>
                                                                                <i class="bi bi-box-arrow-up-right small"></i>
                                                                            <?php endif; ?>
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                                
                                                                <?php if (!empty($act['descritivo'])): ?>
                                                                    <p class="text-muted small mb-2">
                                                                        <?= htmlspecialchars(substr($act['descritivo'], 0, 80)) ?>
                                                                        <?= strlen($act['descritivo']) > 80 ? '...' : '' ?>
                                                                    </p>
                                                                <?php endif; ?>
                                                                
                                                                <div class="d-flex flex-wrap gap-1 mb-2">
                                                                    <?= $act['estado_badge'] ?>
                                                                    <?= $act['estagio_badge'] ?>
                                                                    <?= $act['projeto_badge'] ?>
                                                                    <?= $act['deadline_info'] ?>
                                                                </div>
                                                                
                                                                <div class="d-flex justify-content-between align-items-center">
                                                                    <small class="text-muted">
                                                                        <i class="bi bi-clock-history"></i> 
                                                                        <?= $act['tempo_decorrido'] ?>
                                                                    </small>
                                                                    <?php if ($act['responsavel_nome'] && $act['responsavel_nome'] != getNomeUtilizador($oradorId, getUtilizadoresRedmine())): ?>
                                                                        <small class="text-muted">
                                                                            <i class="bi bi-person"></i> 
                                                                            <?= htmlspecialchars($act['responsavel_nome']) ?>
                                                                        </small>
                                                                    <?php endif; ?>
                                                                </div>
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
                    <!-- Mensagens de feedback -->
                    <?php if (isset($_SESSION['msg_sucesso'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle"></i> <?= $_SESSION['msg_sucesso'] ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['msg_sucesso']); ?>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['msg_erro'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle"></i> <?= $_SESSION['msg_erro'] ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['msg_erro']); ?>
                    <?php endif; ?>
                    
                    <!-- Controles de Gestão de Gestores -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="btn-group d-flex gap-2" role="group">
                                <form method="post" class="flex-fill">
                                    <button type="submit" name="gerar_gestores_aleatorios" class="btn btn-primary w-100" 
                                            onclick="return confirm('Isto irá substituir os gestores futuros agendados. Continuar?')">
                                        <i class="bi bi-shuffle"></i> Gerar Gestores Aleatoriamente (30 dias)
                                    </button>
                                </form>
                                
                                <form method="post" class="flex-fill">
                                    <button type="submit" name="limpar_proximos_gestores" class="btn btn-warning w-100"
                                            onclick="return confirm('Tem certeza que deseja limpar todos os gestores futuros?')">
                                        <i class="bi bi-trash"></i> Limpar Gestores Futuros
                                    </button>
                                </form>
                                
                                <button type="button" class="btn btn-info flex-fill" data-bs-toggle="collapse" data-bs-target="#atribuicaoManual">
                                    <i class="bi bi-pencil-square"></i> Atribuir Manualmente
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Interface de Atribuição Manual (colapsável) -->
                    <div class="collapse mb-4" id="atribuicaoManual">
                        <div class="card card-body bg-light">
                            <h5 class="mb-3"><i class="bi bi-calendar-plus"></i> Atribuir Gestor para Data Específica</h5>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="atribuir_gestor_manual" value="1">
                                <div class="col-md-6">
                                    <label for="data_prevista" class="form-label">Data:</label>
                                    <input type="date" name="data_prevista" id="data_prevista" class="form-control" 
                                           value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="redmine_id" class="form-label">Gestor:</label>
                                    <select name="redmine_id" id="redmine_id" class="form-select" required>
                                        <option value="">Selecione um gestor...</option>
                                        <?php foreach ($equipa as $membro_id): ?>
                                            <?php $nome = getNomeUtilizador($membro_id, $utilizadores); ?>
                                            <option value="<?= $membro_id ?>"><?= htmlspecialchars($nome) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-lg"></i> Atribuir Gestor
                                    </button>
                                </div>
                            </form>
                            
                            <!-- Atribuição Múltipla Rápida -->
                            <hr class="my-3">
                            <h6 class="mb-3"><i class="bi bi-lightning-charge"></i> Atribuição Rápida dos Próximos 30 Dias</h6>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-info-circle"></i> Os gestores são atribuídos automaticamente por ordem alfabética. 
                                Pode alterar individualmente cada dia conforme necessário.
                            </p>
                            <div id="atribuicao-rapida">
                                <div class="row g-2">
                                    <?php
                                    // Preparar lista de membros ordenada alfabeticamente
                                    $membros_ordenados = [];
                                    foreach ($equipa as $membro_id) {
                                        $nome = getNomeUtilizador($membro_id, $utilizadores);
                                        $membros_ordenados[] = [
                                            'id' => $membro_id,
                                            'nome' => $nome
                                        ];
                                    }
                                    
                                    // Ordenar por nome
                                    usort($membros_ordenados, function($a, $b) {
                                        return strcmp($a['nome'], $b['nome']);
                                    });
                                    
                                    // Gerar próximos 30 dias úteis
                                    $data_atual = new DateTime();
                                    $data_atual->modify('+1 day');
                                    $dias_gerados = 0;
                                    $max_dias = 30;
                                    $indice_membro = 0;
                                    
                                    while ($dias_gerados < $max_dias) {
                                        // Pular fins de semana
                                        if (in_array($data_atual->format('N'), ['6', '7'])) {
                                            $data_atual->modify('+1 day');
                                            continue;
                                        }
                                        
                                        $data_str = $data_atual->format('Y-m-d');
                                        $data_display = $data_atual->format('d/m');
                                        $dia_semana = ['', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'][(int)$data_atual->format('N')];
                                        
                                        // Verificar se já tem gestor atribuído
                                        $stmt = $db->prepare("SELECT redmine_id FROM proximos_gestores WHERE data_prevista = ? AND concluido = 0");
                                        $stmt->execute([$data_str]);
                                        $gestor_existente = $stmt->fetchColumn();
                                        
                                        // Atribuir gestor por ordem alfabética (circular)
                                        if (!$gestor_existente && !empty($membros_ordenados)) {
                                            $gestor_sugerido = $membros_ordenados[$indice_membro % count($membros_ordenados)]['id'];
                                            $indice_membro++;
                                        } else {
                                            $gestor_sugerido = $gestor_existente;
                                        }
                                        ?>
                                        <div class="col-md-6 col-lg-4 col-xl-3">
                                            <form method="post" class="card p-2 <?= $gestor_existente ? 'border-success' : '' ?>">
                                                <input type="hidden" name="atribuir_gestor_manual" value="1">
                                                <input type="hidden" name="data_prevista" value="<?= $data_str ?>">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <strong class="small"><?= $dia_semana ?> <?= $data_display ?></strong>
                                                    <?php if ($gestor_existente): ?>
                                                        <span class="badge bg-success">✓</span>
                                                    <?php endif; ?>
                                                </div>
                                                <select name="redmine_id" class="form-select form-select-sm mb-2" required>
                                                    <?php foreach ($membros_ordenados as $membro): ?>
                                                        <option value="<?= $membro['id'] ?>" <?= ($gestor_sugerido == $membro['id']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($membro['nome']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-check"></i> <?= $gestor_existente ? 'Atualizar' : 'Atribuir' ?>
                                                </button>
                                            </form>
                                        </div>
                                        <?php
                                        $data_atual->modify('+1 day');
                                        $dias_gerados++;
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
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
                                            <form method="post" class="d-inline" onsubmit="return confirm('Tem a certeza que deseja remover este item? Esta ação não pode ser desfeita.');">
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
            
            <!-- Nova Seção de Notas Markdown -->
            <div class="card mb-4" id="secao-notas">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0"><i class="bi bi-markdown"></i> Notas da Reunião</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Editor Markdown e Visualização -->
                        <div class="col-md-8">
                            <div class="border-bottom mb-3 pb-2 d-flex justify-content-between align-items-center">
                                <h5 class="mb-0" id="nota-titulo-display">
                                    <?= htmlspecialchars($nota_atual['titulo'] ?? 'Nova Nota') ?>
                                </h5>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-outline-primary" id="btn-editar">
                                        <i class="bi bi-pencil"></i> Editar
                                    </button>
                                    <button type="button" class="btn btn-outline-success" id="btn-visualizar" style="display: none;">
                                        <i class="bi bi-eye"></i> Visualizar
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Área de Visualização (Padrão) -->
                            <div id="markdown-preview" class="markdown-preview markdown-body">
                                <?php if (isset($nota_atual['conteudo']) && !empty($nota_atual['conteudo'])): ?>
                                    <!-- O conteúdo será renderizado via JavaScript -->
                                <?php else: ?>
                                    <div class="text-muted text-center py-5">
                                        <i class="bi bi-file-earmark-text fs-2"></i>
                                        <p>Não há conteúdo para mostrar.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Formulário de Edição (Inicialmente Oculto) -->
                            <form method="post" id="form-markdown" style="display: none;">
                                <input type="hidden" name="acao_nota" value="salvar">
                                <input type="hidden" name="id_nota" id="id_nota" value="<?= htmlspecialchars($nota_atual['id'] ?? '') ?>">
                                
                                <div class="mb-3">
                                    <label for="titulo_nota" class="form-label">Título da Nota:</label>
                                    <input type="text" class="form-control" id="titulo_nota" name="titulo_nota" 
                                           value="<?= htmlspecialchars($nota_atual['titulo'] ?? 'Nova Nota') ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="conteudo_nota" class="form-label">Conteúdo (Markdown):</label>
                                    <textarea class="form-control markdown-editor" id="conteudo_nota" name="conteudo_nota" 
                                              rows="10" placeholder="Escreva aqui utilizando Markdown..."><?= htmlspecialchars($nota_atual['conteudo'] ?? '') ?></textarea>
                                    
                                    <div class="form-text">
                                        <i class="bi bi-info-circle"></i> Use Markdown para formatar seu texto. 
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#markdownHelpModal">Ver guia de sintaxe</a>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Salvar
                                    </button>
                                    
                                    <button type="button" class="btn btn-outline-secondary" id="btn-cancelar">
                                        <i class="bi bi-x"></i> Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Lista de Notas Existentes -->
                        <div class="col-md-4">
                            <div class="border-bottom mb-3 pb-2">
                                <h5 class="mb-0"><i class="bi bi-journal-text"></i> Notas Salvas</h5>
                            </div>
                            
                            <div class="list-group">
                                <button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center nova-nota">
                                    <div>
                                        <i class="bi bi-plus-circle text-success"></i> Criar Nova Nota
                                    </div>
                                </button>
                                
                                <?php if (empty($todas_notas)): ?>
                                    <div class="list-group-item text-muted">
                                        <i class="bi bi-info-circle"></i> Nenhuma nota salva.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($todas_notas as $nota): ?>
                                        <div class="list-group-item nota-lista-item d-flex justify-content-between align-items-center <?= ($nota_atual && $nota['id'] == $nota_atual['id']) ? 'active' : '' ?>" 
                                             data-id="<?= $nota['id'] ?>" 
                                             data-titulo="<?= htmlspecialchars($nota['titulo']) ?>" 
                                             data-conteudo="<?= htmlspecialchars($nota['conteudo']) ?>">
                                            <div>
                                                <div class="fw-bold"><?= htmlspecialchars($nota['titulo']) ?></div>
                                                <small class="text-muted">
                                                    <i class="bi bi-clock"></i> 
                                                    <?= date('d/m/Y H:i', strtotime($nota['data_atualizacao'])) ?>
                                                </small>
                                            </div>
                                            
                                            <form method="post" class="nota-excluir-form" onsubmit="return confirm('Tem certeza que deseja excluir esta nota?');">
                                                <input type="hidden" name="acao_nota" value="excluir">
                                                <input type="hidden" name="id_nota" value="<?= $nota['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
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
                                                if (!empty($falta['motivo'])) {
                                                    $motivo = $falta['motivo'];
                                                    echo mb_strlen($motivo) > 50 ? mb_substr(htmlspecialchars($motivo), 0, 50) . '...' : htmlspecialchars($motivo);
                                                } else {
                                                    echo '<span class="text-muted">Não especificado</span>';
                                                }
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

<!-- Modal de Ajuda do Markdown -->
<div class="modal fade" id="markdownHelpModal" tabindex="-1" aria-labelledby="markdownHelpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="markdownHelpModalLabel">Guia Rápido de Markdown</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Formatação Básica</h6>
                        <ul class="list-unstyled">
                            <li><code># Título</code> - Título principal</li>
                            <li><code>## Subtítulo</code> - Subtítulo</li>
                            <li><code>**texto**</code> - <strong>Negrito</strong></li>
                            <li><code>*texto*</code> - <em>Itálico</em></li>
                            <li><code>~~texto~~</code> - <del>Riscado</del></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Elementos</h6>
                        <ul class="list-unstyled">
                            <li><code>- Item</code> - Lista não ordenada</li>
                            <li><code>1. Item</code> - Lista ordenada</li>
                            <li><code>[link](URL)</code> - Link</li>
                            <li><code>![alt](URL)</code> - Imagem</li>
                            <li><code>```código```</code> - Bloco de código</li>
                        </ul>
                    </div>
                </div>
                
                <div class="mt-3">
                    <h6>Exemplo:</h6>
                    <pre class="bg-light p-2">
# Reunião do dia 12/05/2025

## Pontos Discutidos:
- Ponto 1: **Urgente**
- Ponto 2: *Em andamento*

## Próximos Passos:
1. Verificar status do projeto
2. Agendar nova reunião

[Link para documentação](https://example.com)
                    </pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Script do cronômetro, totalmente no lado do cliente -->
<script>
// Executar quando o documento estiver carregado
document.addEventListener('DOMContentLoaded', function() {
    // Verificar se a reunião está ativa e não concluída
    <?php if ($em_reuniao && !$reuniao_concluida && isset($oradorId)): ?>
    
    // Elementos DOM
    const cronometroEl = document.getElementById('cronometro');
    const progressBarEl = document.getElementById('progress-bar');
    const btnPausarEl = document.getElementById('btn-pausar');
    const btnReiniciarEl = document.getElementById('btn-reiniciar');
    const beepEl = document.getElementById('beep');
    const statusEl = document.getElementById('status-display');
    const eventLogEl = document.getElementById('event-log');
    const debugArea = document.getElementById('debug-area');
    
    // Verificar se os elementos necessários existem
    if (!cronometroEl || !progressBarEl || !btnPausarEl) {
        console.error('Elementos essenciais do cronômetro não encontrados!');
        return;
    }
    
    // Mostrar área de debug para diagnóstico se necessário
    if (debugArea) {
        // Descomentar para mostrar a área de debug
        // debugArea.classList.remove('d-none');
    }
    
    // Configuração inicial
    const TEMPO_TOTAL = 30; // segundos
    let tempoRestante = TEMPO_TOTAL;
    let cronometroAtivo = true;
    let intervalId = null;
    let estaPausado = false;
    
    // Função para registrar eventos (para depuração)
    function registrarEvento(mensagem) {
        console.log(mensagem);
        if (eventLogEl) {
            const agora = new Date();
            const timestamp = agora.getHours().toString().padStart(2, '0') + ':' + 
                            agora.getMinutes().toString().padStart(2, '0') + ':' + 
                            agora.getSeconds().toString().padStart(2, '0');
            eventLogEl.textContent = mensagem + ' [' + timestamp + ']';
        }
    }
    
    // Função para atualizar o visual do cronômetro
    function atualizarCronometro() {
        // Atualizar o texto do cronômetro
        cronometroEl.textContent = tempoRestante;
        
        // Calcular a largura da barra de progresso
        const porcentagem = (tempoRestante / TEMPO_TOTAL) * 100;
        progressBarEl.style.width = porcentagem + '%';
        
        // Atualizar a cor da barra de progresso conforme o tempo restante
        if (tempoRestante <= 5) {
            progressBarEl.className = 'progress-bar progress-bar-striped progress-bar-animated bg-danger';
        } else if (tempoRestante <= 15) {
            progressBarEl.className = 'progress-bar progress-bar-striped progress-bar-animated bg-warning';
        } else {
            progressBarEl.className = 'progress-bar progress-bar-striped progress-bar-animated bg-primary';
        }
        
        // Tocar som de alerta quando chegar a 5 segundos
        if (tempoRestante === 5 && beepEl) {
            beepEl.play().catch(err => {
                registrarEvento('Erro ao tocar som: ' + err.message);
            });
        }
        
        // Quando o tempo acabar
        if (tempoRestante <= 0) {
            finalizarCronometro();
        }
    }
    
    // Função para iniciar ou reiniciar o cronômetro
    function iniciarCronometro() {
        // Limpar qualquer intervalo existente
        if (intervalId !== null) {
            clearInterval(intervalId);
            intervalId = null;
        }
        
        registrarEvento('Cronômetro iniciado');
        
        // Configurar um novo intervalo que execute a cada 1 segundo
        intervalId = setInterval(function() {
            if (!estaPausado && cronometroAtivo) {
                tempoRestante--;
                atualizarCronometro();
            }
        }, 1000);
    }
    
    // Função para finalizar o cronômetro quando o tempo acabar
    function finalizarCronometro() {
        clearInterval(intervalId);
        intervalId = null;
        cronometroAtivo = false;
        
        // Atualizar interface
        cronometroEl.textContent = 'Tempo Esgotado!';
        progressBarEl.style.width = '0%';
        progressBarEl.className = 'progress-bar bg-danger';
        
        registrarEvento('Tempo esgotado');
        
        // Desativar botão de pausa
        if (btnPausarEl) {
            btnPausarEl.disabled = true;
            btnPausarEl.classList.remove('btn-warning', 'btn-success');
            btnPausarEl.classList.add('btn-secondary');
        }
    }
    
    // Função para alternar o estado de pausa
    function alternarPausa() {
        estaPausado = !estaPausado;
        
        // Atualizar interface
        if (estaPausado) {
            btnPausarEl.classList.remove('btn-warning');
            btnPausarEl.classList.add('btn-success');
            btnPausarEl.innerHTML = '<i class="bi bi-play-fill"></i> Continuar';
            if (statusEl) statusEl.textContent = 'Pausado';
        } else {
            btnPausarEl.classList.remove('btn-success');
            btnPausarEl.classList.add('btn-warning');
            btnPausarEl.innerHTML = '<i class="bi bi-pause-fill"></i> Pausar';
            if (statusEl) statusEl.textContent = 'Ativo';
        }
        
        registrarEvento(estaPausado ? 'Cronômetro pausado' : 'Cronômetro retomado');
    }
    
    // Função para reiniciar o cronômetro
    function reiniciarCronometro() {
        tempoRestante = TEMPO_TOTAL;
        cronometroAtivo = true;
        estaPausado = false;
        
        // Atualizar interface
        if (btnPausarEl) {
            btnPausarEl.disabled = false;
            btnPausarEl.classList.remove('btn-success', 'btn-secondary');
            btnPausarEl.classList.add('btn-warning');
            btnPausarEl.innerHTML = '<i class="bi bi-pause-fill"></i> Pausar';
        }
        
        if (statusEl) statusEl.textContent = 'Ativo';
        
        // Atualizar cronômetro e iniciar contagem
        atualizarCronometro();
        iniciarCronometro();
        
        registrarEvento('Cronômetro reiniciado');
    }
    
    // Configurar eventos dos botões
    
    // Botão Pausar/Continuar
    if (btnPausarEl) {
        btnPausarEl.addEventListener('click', function(e) {
            e.preventDefault();
            alternarPausa();
        });
    }
    
    // Botão Reiniciar
    if (btnReiniciarEl) {
        btnReiniciarEl.addEventListener('click', function(e) {
            e.preventDefault();
            reiniciarCronometro();
        });
    }
    
    // Inicializar o cronômetro
    atualizarCronometro();
    iniciarCronometro();
    registrarEvento('Cronômetro configurado com sucesso');
    
    <?php endif; ?>
    
    // Atualizar o tempo total da reunião a cada segundo
    <?php if ($em_reuniao): ?>
    function atualizarTempoTotal() {
        const tempoTotalEl = document.getElementById('tempo-total');
        if (tempoTotalEl) {
            let segundos = <?= $tempo_total ?>;
            setInterval(function() {
                segundos++;
                
                // Formatar o tempo (HH:MM:SS)
                const horas = Math.floor(segundos / 3600).toString().padStart(2, '0');
                const minutos = Math.floor((segundos % 3600) / 60).toString().padStart(2, '0');
                const segs = (segundos % 60).toString().padStart(2, '0');
                
                tempoTotalEl.textContent = horas + ':' + minutos + ':' + segs;
            }, 1000);
        }
    }
    
    atualizarTempoTotal();
    <?php endif; ?>
    
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
    marked.setOptions({
        breaks: true,  // Quebras de linha são respeitadas
        gfm: true,     // GitHub Flavored Markdown
        headerIds: true, // Adiciona IDs aos cabeçalhos para navegação
        sanitize: false // Não sanitizar HTML (cuidado com conteúdo não confiável)
    });
    
    // Função para renderizar o markdown atual
    function renderizarMarkdown() {
        if (inputConteudo && markdownPreview) {
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
        // Atualizar o ID da nota atual
        inputId.value = id || '';
        
        // Atualizar título e conteúdo
        inputTitulo.value = titulo || 'Nova Nota';
        inputConteudo.value = conteudo || '';
        tituloDisplay.textContent = titulo || 'Nova Nota';
        
        // Renderizar o markdown
        renderizarMarkdown();
        
        // Atualizar a classe 'active' na lista de notas
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
        alternarModo(true); // Entrar em modo de edição
    }
    
    // Configurar eventos para os botões da interface
    
    // Botão Editar
    if (btnEditar) {
        btnEditar.addEventListener('click', function() {
            alternarModo(true);
        });
    }
    
    // Botão Visualizar
    if (btnVisualizar) {
        btnVisualizar.addEventListener('click', function() {
            alternarModo(false);
        });
    }
    
    // Botão Cancelar (voltar para modo de visualização sem salvar)
    if (btnCancelar) {
        btnCancelar.addEventListener('click', function() {
            alternarModo(false);
        });
    }
    
    // Botão Nova Nota
    if (btnNovaNota) {
        btnNovaNota.addEventListener('click', novaNota);
    }
    
    // Evento de clique nos itens da lista de notas
    notaItems.forEach(item => {
        item.addEventListener('click', function(e) {
            // Ignorar cliques no botão de excluir
            if (e.target.closest('.nota-excluir-form')) {
                return;
            }
            
            const id = this.dataset.id;
            const titulo = this.dataset.titulo;
            const conteudo = this.dataset.conteudo;
            
            carregarNota(id, titulo, conteudo);
            alternarModo(false); // Modo de visualização
        });
    });
    
    // Renderizar o markdown na carga inicial da página
    renderizarMarkdown();
});
</script>

</body>
</html>