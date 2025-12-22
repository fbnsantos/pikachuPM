<?php
/**
 * Integração Microsoft Teams - Notificações de Reunião
 * 
 * Este arquivo envia notificações para o canal Microsoft Teams
 * quando a reunião diária é iniciada.
 * 
 * Uso:
 * 1. Chamada direta via AJAX: teams_notification.php?action=meeting_started
 * 2. Inclusão no código: include 'teams_notification.php'; sendTeamsNotification();
 */

// Iniciar sessão se necessário
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Incluir configurações
require_once __DIR__ . '/config.php';

/**
 * Envia notificação para Microsoft Teams
 * 
 * @param string $tipo Tipo de notificação ('meeting_started', 'meeting_ended')
 * @param array $dados Dados adicionais para a notificação
 * @return array Resultado da operação
 */
function sendTeamsNotification($tipo = 'meeting_started', $dados = []) {
    global $POWER_AUTOMATE_URL, $TEAMS_NOTIFICATIONS_ENABLED;
    
    // Verificar se as notificações estão habilitadas
    if (!$TEAMS_NOTIFICATIONS_ENABLED) {
        return [
            'success' => false,
            'message' => 'Notificações Teams desabilitadas na configuração'
        ];
    }
    
    // Verificar se a URL do Power Automate está configurada
    if (empty($POWER_AUTOMATE_URL)) {
        return [
            'success' => false,
            'message' => 'URL do Power Automate não configurada'
        ];
    }
    
    try {
        // Preparar dados da notificação baseado no tipo
        $notification = prepareNotificationData($tipo, $dados);
        
        // Enviar para o Teams via Power Automate
        $result = sendToTeams($notification);
        
        // Log da operação
        logTeamsNotification($tipo, $notification, $result);
        
        return $result;
        
    } catch (Exception $e) {
        $errorResult = [
            'success' => false,
            'message' => 'Erro ao enviar notificação: ' . $e->getMessage()
        ];
        
        logTeamsNotification($tipo, [], $errorResult);
        return $errorResult;
    }
}

/**
 * Prepara os dados da notificação baseado no tipo
 */
function prepareNotificationData($tipo, $dados = []) {
    $timestamp = date('d/m/Y H:i:s');
    $username = $_SESSION['username'] ?? 'Sistema';
    
    switch ($tipo) {
        case 'meeting_started':
            return prepareMeetingStartedNotification($dados, $timestamp, $username);
            
        case 'meeting_ended':
            return prepareMeetingEndedNotification($dados, $timestamp, $username);
            
        default:
            return prepareGenericNotification($tipo, $dados, $timestamp, $username);
    }
}

/**
 * Prepara notificação de início de reunião
 */
function prepareMeetingStartedNotification($dados, $timestamp, $username) {
    // Obter informações da reunião
    $gestor_nome = $dados['gestor_nome'] ?? 'Não definido';
    $total_membros = $dados['total_membros'] ?? 0;
    $membros_disponiveis = $dados['membros_disponiveis'] ?? 0;
    
    // Mensagem principal
    $titulo = "🚀 REUNIÃO DIÁRIA INICIADA";
    $mensagem = "A reunião diária foi iniciada com sucesso!";
    
    // Detalhes da reunião
    $detalhes = [
        "📅 **Data/Hora:** {$timestamp}",
        "👤 **Iniciado por:** {$username}",
        "🎯 **Gestor da reunião:** {$gestor_nome}",
        "👥 **Membros da equipa:** {$total_membros}",
        "✅ **Membros disponíveis:** {$membros_disponiveis}"
    ];
    
    // Se há membros indisponíveis, mencionar
    if ($total_membros > $membros_disponiveis) {
        $indisponiveis = $total_membros - $membros_disponiveis;
        $detalhes[] = "⚠️ **Membros indisponíveis:** {$indisponiveis} (férias/aulas)";
    }
    
    // Texto motivacional aleatório
    $frases_motivacionais = [
        "Vamos que é para fazer um dia produtivo! 💪",
        "Bora trabalhar pessoal! 🔥",
        "Mais um dia, mais conquistas! ⭐",
        "Time CRIIS em ação! 🚀",
        "Produtividade máxima ativada! ⚡",
        "Let's make it happen! 🎯"
    ];
    $frase_motivacional = $frases_motivacionais[array_rand($frases_motivacionais)];
    
    return [
        'type' => 'meeting_started',
        'title' => $titulo,
        'summary' => $mensagem,
        'details' => implode("\n", $detalhes),
        'motivational' => $frase_motivacional,
        'color' => '28a745', // Verde
        'timestamp' => $timestamp,
        'priority' => 'high'
    ];
}

/**
 * Prepara notificação de fim de reunião
 */
function prepareMeetingEndedNotification($dados, $timestamp, $username) {
    $duracao = $dados['duracao'] ?? 'N/A';
    
    return [
        'type' => 'meeting_ended',
        'title' => "✅ REUNIÃO DIÁRIA FINALIZADA",
        'summary' => "A reunião diária foi concluída.",
        'details' => implode("\n", [
            "📅 **Finalizada em:** {$timestamp}",
            "👤 **Finalizada por:** {$username}",
            "⏱️ **Duração:** {$duracao}",
            "🎉 **Status:** Concluída com sucesso"
        ]),
        'motivational' => "Ótimo trabalho pessoal! Vamos produzir! 🚀",
        'color' => '007bff', // Azul
        'timestamp' => $timestamp,
        'priority' => 'medium'
    ];
}

/**
 * Prepara notificação genérica
 */
function prepareGenericNotification($tipo, $dados, $timestamp, $username) {
    return [
        'type' => $tipo,
        'title' => "📢 NOTIFICAÇÃO DO SISTEMA",
        'summary' => $dados['message'] ?? "Evento do sistema: {$tipo}",
        'details' => "📅 **Data/Hora:** {$timestamp}\n👤 **Usuário:** {$username}",
        'motivational' => "",
        'color' => '6c757d', // Cinza
        'timestamp' => $timestamp,
        'priority' => 'low'
    ];
}

/**
 * Envia os dados para o Microsoft Teams via Power Automate
 */
function sendToTeams($notification) {
    global $POWER_AUTOMATE_URL;
    
    // Preparar payload para o Power Automate
    $payload = [
        '@type' => 'MessageCard',
        '@context' => 'http://schema.org/extensions',
        'themeColor' => $notification['color'],
        'summary' => $notification['summary'],
        'sections' => [
            [
                'activityTitle' => $notification['title'],
                'activitySubtitle' => $notification['summary'],
                'activityImage' => 'https://cdn-icons-png.flaticon.com/512/906/906334.png', // Ícone de reunião
                'facts' => [
                    [
                        'name' => 'Detalhes',
                        'value' => $notification['details']
                    ]
                ],
                'markdown' => true
            ]
        ]
    ];
    
    // Adicionar frase motivacional se existir
    if (!empty($notification['motivational'])) {
        $payload['sections'][0]['text'] = $notification['motivational'];
    }
    
    // Adicionar botões de ação para notificação de reunião iniciada
    if ($notification['type'] === 'meeting_started') {
        $payload['potentialAction'] = [
            [
                '@type' => 'OpenUri',
                'name' => '🔗 Ir para Reunião',
                'targets' => [
                    [
                        'os' => 'default',
                        'uri' => getCurrentPageUrl() . '?tab=equipa'
                    ]
                ]
            ]
        ];
    }
    
    // Configurar cURL
    $ch = curl_init($POWER_AUTOMATE_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: CRIIS-PikachuPM/1.0'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desenvolvimento, remover em produção
    
    // Executar request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Verificar resultado
    if ($response === false) {
        throw new Exception("Erro cURL: " . $curl_error);
    }
    
    if ($http_code < 200 || $http_code >= 300) {
        throw new Exception("HTTP Error {$http_code}: " . $response);
    }
    
    return [
        'success' => true,
        'message' => 'Notificação enviada com sucesso para o Teams',
        'http_code' => $http_code,
        'response' => $response
    ];
}

/**
 * Obtém a URL atual da página
 */
function getCurrentPageUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = dirname($_SERVER['SCRIPT_NAME']);
    return $protocol . '://' . $host . $script;
}

/**
 * Registra log das notificações Teams
 */
function logTeamsNotification($tipo, $notification, $result) {
    $log_dir = __DIR__ . '/logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/teams_notifications.log';
    $timestamp = date('Y-m-d H:i:s');
    $username = $_SESSION['username'] ?? 'sistema';
    
    $log_entry = [
        'timestamp' => $timestamp,
        'user' => $username,
        'type' => $tipo,
        'success' => $result['success'],
        'message' => $result['message'],
        'notification_data' => $notification
    ];
    
    $log_line = $timestamp . " | " . $username . " | " . $tipo . " | " . 
               ($result['success'] ? 'SUCCESS' : 'ERROR') . " | " . 
               $result['message'] . "\n";
    
    file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
}

/**
 * Obtém dados da reunião atual para notificação
 */
function getMeetingData() {
    // Incluir funções do equipa.php se necessário
    if (function_exists('getUtilizadoresRedmine') && function_exists('getNomeUtilizador')) {
        try {
            // Obter dados dos usuários
            $utilizadores = getUtilizadoresRedmine();
            
            // Dados da sessão
            $gestor_id = $_SESSION['gestor'] ?? null;
            $oradores = $_SESSION['oradores'] ?? [];
            $total_membros = count($oradores);
            
            // Nome do gestor
            $gestor_nome = 'Não definido';
            if ($gestor_id) {
                $gestor_nome = getNomeUtilizador($gestor_id, $utilizadores);
            }
            
            return [
                'gestor_nome' => $gestor_nome,
                'total_membros' => $total_membros,
                'membros_disponiveis' => $total_membros // Assumindo que todos em $oradores estão disponíveis
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao obter dados da reunião: " . $e->getMessage());
        }
    }
    
    // Dados padrão se houver erro
    return [
        'gestor_nome' => 'Sistema',
        'total_membros' => 0,
        'membros_disponiveis' => 0
    ];
}

// ===== PROCESSAMENTO DE REQUISIÇÕES =====

// Se for uma requisição AJAX ou GET
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'meeting_started':
            $dados = getMeetingData();
            $result = sendTeamsNotification('meeting_started', $dados);
            echo json_encode($result);
            break;
            
        case 'meeting_ended':
            $duracao = $_GET['duration'] ?? 'N/A';
            $result = sendTeamsNotification('meeting_ended', ['duracao' => $duracao]);
            echo json_encode($result);
            break;
            
        case 'test':
            // Endpoint de teste
            $result = sendTeamsNotification('meeting_started', [
                'gestor_nome' => 'Teste',
                'total_membros' => 5,
                'membros_disponiveis' => 4
            ]);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Ação não reconhecida'
            ]);
    }
    exit;
}

// Se for requisição POST (webhook interno)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['teams_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['teams_action'];
    $dados = $_POST['dados'] ?? [];
    
    $result = sendTeamsNotification($action, $dados);
    echo json_encode($result);
    exit;
}

// ===== FUNÇÃO PARA USO DIRETO NO CÓDIGO =====

/**
 * Função para ser chamada diretamente no equipa.php quando a reunião iniciar
 */
function notifyMeetingStarted() {
    $dados = getMeetingData();
    return sendTeamsNotification('meeting_started', $dados);
}

/**
 * Função para ser chamada quando a reunião terminar
 */
function notifyMeetingEnded($duracao = null) {
    $dados = [];
    if ($duracao) {
        $dados['duracao'] = $duracao;
    }
    return sendTeamsNotification('meeting_ended', $dados);
}

// ===== PÁGINA DE TESTE E CONFIGURAÇÃO =====
if (!isset($_GET['action']) && !isset($_POST['teams_action'])) {
    ?>
    <!DOCTYPE html>
    <html lang="pt">
    <head>
        <meta charset="UTF-8">
        <title>Teams Notifications - Teste e Configuração</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            .status-success { color: #28a745; }
            .status-error { color: #dc3545; }
        </style>
    </head>
    <body>
        <div class="container mt-4">
            <h1><i class="bi bi-microsoft"></i> Microsoft Teams - Configuração e Teste</h1>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Status da Configuração</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Power Automate URL:</strong> 
                                <span class="<?= !empty($POWER_AUTOMATE_URL) ? 'status-success' : 'status-error' ?>">
                                    <?= !empty($POWER_AUTOMATE_URL) ? '✅ Configurado' : '❌ Não configurado' ?>
                                </span>
                            </p>
                            <p><strong>Notificações Teams:</strong> 
                                <span class="<?= $TEAMS_NOTIFICATIONS_ENABLED ? 'status-success' : 'status-error' ?>">
                                    <?= $TEAMS_NOTIFICATIONS_ENABLED ? '✅ Habilitado' : '❌ Desabilitado' ?>
                                </span>
                            </p>
                            
                            <?php if (!empty($POWER_AUTOMATE_URL)): ?>
                            <hr>
                            <button class="btn btn-primary" onclick="testarNotificacao()">
                                <i class="bi bi-send"></i> Enviar Teste
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5>Como Usar</h5>
                        </div>
                        <div class="card-body">
                            <h6>1. Via JavaScript (Recomendado)</h6>
                            <pre><code>// Quando reunião iniciar
fetch('teams_notification.php?action=meeting_started')
.then(r => r.json())
.then(data => console.log(data));</code></pre>
                            
                            <h6>2. Via PHP (Direto)</h6>
                            <pre><code>include 'teams_notification.php';
$result = notifyMeetingStarted();</code></pre>
                            
                            <h6>3. Logs</h6>
                            <p>Verifique o arquivo <code>logs/teams_notifications.log</code> para debug.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-4">
                <div id="resultado" style="display: none;" class="alert"></div>
            </div>
        </div>
        
        <script>
        function testarNotificacao() {
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';
            
            fetch('teams_notification.php?action=test')
                .then(response => response.json())
                .then(data => {
                    const resultado = document.getElementById('resultado');
                    resultado.style.display = 'block';
                    
                    if (data.success) {
                        resultado.className = 'alert alert-success';
                        resultado.innerHTML = '<h6>✅ Sucesso!</h6>' + data.message;
                    } else {
                        resultado.className = 'alert alert-danger';
                        resultado.innerHTML = '<h6>❌ Erro!</h6>' + data.message;
                    }
                })
                .catch(error => {
                    const resultado = document.getElementById('resultado');
                    resultado.style.display = 'block';
                    resultado.className = 'alert alert-danger';
                    resultado.innerHTML = '<h6>❌ Erro de Conexão!</h6>' + error.message;
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-send"></i> Enviar Teste';
                });
        }
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>