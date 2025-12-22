<?php
// login.php - Sistema de autenticação com suporte a Redmine e contas locais

// Configurar timeout de sessão para 24 horas
ini_set('session.gc_maxlifetime', 86400);
ini_set('session.cookie_lifetime', 86400);

session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

include_once __DIR__ . '/config.php';

// Redirecionar se já estiver logado
if (isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

$erro = null;
$sucesso = null;
$modo = $_GET['modo'] ?? 'login'; // login, registar, recuperar

// Conectar à base de dados
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

// Função para registar tentativa de login
function registarTentativaLogin($pdo, $username, $success) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)");
    $stmt->execute([$username, $ip, $success ? 1 : 0]);
}

// Função para verificar rate limiting (máximo 5 tentativas em 15 minutos)
function verificarRateLimit($pdo, $username) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as tentativas 
        FROM login_attempts 
        WHERE (username = ? OR ip_address = ?)
        AND success = 0 
        AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$username, $ip]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['tentativas'] < 5;
}

// ===== PROCESSAR REGISTO =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $modo === 'registar') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validações
    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $erro = "Todos os campos são obrigatórios.";
    } elseif (strlen($username) < 3) {
        $erro = "Nome de utilizador deve ter pelo menos 3 caracteres.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = "Email inválido.";
    } elseif (strlen($password) < 6) {
        $erro = "Password deve ter pelo menos 6 caracteres.";
    } elseif ($password !== $confirm_password) {
        $erro = "As passwords não coincidem.";
    } else {
        // Verificar se username ou email já existem
        $stmt = $pdo->prepare("SELECT id FROM user_tokens WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        
        if ($stmt->fetch()) {
            $erro = "Nome de utilizador ou email já está registado.";
        } else {
            // Verificar se é o primeiro utilizador local
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM user_tokens WHERE is_local_user = 1");
            $total_local_users = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            $is_first_user = ($total_local_users == 0);
            
            // Obter o próximo user_id disponível
            $stmt = $pdo->query("SELECT COALESCE(MAX(user_id), 0) + 1 as next_id FROM user_tokens");
            $next_user_id = $stmt->fetch(PDO::FETCH_ASSOC)['next_id'];
            
            // Se for o primeiro utilizador, aprovar automaticamente
            $is_approved = $is_first_user ? 1 : 0;
            
            // Criar conta local
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $token = bin2hex(random_bytes(32));
            
            $stmt = $pdo->prepare("
                INSERT INTO user_tokens 
                (user_id, username, token, password_hash, is_local_user, is_approved, email, full_name, approved_at) 
                VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)
            ");
            
            $approved_at = $is_first_user ? date('Y-m-d H:i:s') : null;
            
            if ($stmt->execute([$next_user_id, $username, $token, $password_hash, $is_approved, $email, $full_name, $approved_at])) {
                if ($is_first_user) {
                    $sucesso = "Registo realizado com sucesso! Você é o primeiro utilizador e foi automaticamente aprovado. Pode fazer login agora.";
                } else {
                    $sucesso = "Registo realizado com sucesso! A sua conta está pendente de aprovação por um administrador.";
                }
                $modo = 'login';
            } else {
                $erro = "Erro ao criar conta. Tente novamente.";
            }
        }
    }
}

// ===== PROCESSAR LOGIN =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $modo === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $tipo_login = $_POST['tipo_login'] ?? 'local'; // local ou redmine
    
    if (empty($username) || empty($password)) {
        $erro = "Nome de utilizador e password são obrigatórios.";
    } elseif (!verificarRateLimit($pdo, $username)) {
        $erro = "Demasiadas tentativas falhadas. Aguarde 15 minutos.";
    } else {
        $login_sucesso = false;
        
        // ===== LOGIN LOCAL =====
        if ($tipo_login === 'local') {
            $stmt = $pdo->prepare("
                SELECT * FROM user_tokens 
                WHERE username = ? 
                AND is_local_user = 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['is_approved'] == 1) {
                    // Login bem-sucedido
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['user'] = $user['username'];
                    $_SESSION['inicio'] = time();
                    $_SESSION['ultima_atividade'] = time();
                    $_SESSION['is_local_user'] = true;
                    
                    // Atualizar último login
                    $stmt = $pdo->prepare("UPDATE user_tokens SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    
                    registarTentativaLogin($pdo, $username, true);
                    
                    header('Location: index.php');
                    exit;
                } else {
                    $erro = "Conta pendente de aprovação por um administrador.";
                    registarTentativaLogin($pdo, $username, false);
                }
            } else {
                $erro = "Nome de utilizador ou password incorretos.";
                registarTentativaLogin($pdo, $username, false);
            }
        }
        
        // ===== LOGIN REDMINE =====
        elseif ($tipo_login === 'redmine') {
            $url = "http://criis-projects.inesctec.pt/users/current.json";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($code === 200) {
                $data = json_decode($response, true);
                $_SESSION['username'] = $data['user']['login'];
                $_SESSION['user_id'] = $data['user']['id'];
                $_SESSION['user'] = $username;
                $_SESSION['password'] = $password;
                $_SESSION['inicio'] = time();
                $_SESSION['ultima_atividade'] = time();
                $_SESSION['is_local_user'] = false;
                
                registarTentativaLogin($pdo, $username, true);
                
                header('Location: index.php');
                exit;
            } else {
                $erro = "Login Redmine inválido.";
                registarTentativaLogin($pdo, $username, false);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo === 'registar' ? 'Registar' : 'Login' ?> - PikachuPM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            background: white;
            padding: 2.5rem;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 450px;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo h2 {
            color: #667eea;
            font-weight: 700;
            margin: 0;
        }
        
        .logo p {
            color: #666;
            margin: 0.5rem 0 0 0;
            font-size: 0.9rem;
        }
        
        .nav-tabs {
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .nav-tabs .nav-link {
            color: #666;
            border: none;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            color: #667eea;
            border-bottom: 3px solid #667eea;
            background: transparent;
        }
        
        .form-label {
            font-weight: 500;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem;
            font-weight: 600;
            border-radius: 8px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-outline-secondary {
            border: 2px solid #e0e0e0;
            color: #666;
            border-radius: 8px;
            padding: 0.75rem;
            font-weight: 500;
        }
        
        .btn-outline-secondary:hover {
            background: #f5f5f5;
            border-color: #d0d0d0;
            color: #333;
        }
        
        .link-secondary {
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .link-secondary:hover {
            color: #667eea;
        }
        
        .divider {
            text-align: center;
            margin: 1.5rem 0;
            position: relative;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 100%;
            height: 1px;
            background: #e0e0e0;
        }
        
        .divider span {
            background: white;
            padding: 0 1rem;
            position: relative;
            color: #999;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h2><i class="bi bi-lightning-charge-fill"></i> PikachuPM</h2>
            <p>Sistema de Gestão de Projetos</p>
        </div>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($erro) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($sucesso) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($modo === 'login'): ?>
            <!-- FORMULÁRIO DE LOGIN -->
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#local-tab">
                        <i class="bi bi-person-fill"></i> Login Local
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#redmine-tab">
                        <i class="bi bi-building"></i> INESC TEC
                    </button>
                </li>
            </ul>
            
            <div class="tab-content">
                <!-- TAB: LOGIN LOCAL -->
                <div class="tab-pane fade show active" id="local-tab">
                    <form method="post">
                        <input type="hidden" name="tipo_login" value="local">
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-person"></i> Nome de Utilizador
                            </label>
                            <input type="text" name="username" class="form-control" required autofocus>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-lock"></i> Password
                            </label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100 mb-3">
                            <i class="bi bi-box-arrow-in-right"></i> Entrar
                        </button>
                    </form>
                    
                    <div class="text-center">
                        <a href="?modo=registar" class="link-secondary">
                            <i class="bi bi-person-plus"></i> Criar nova conta
                        </a>
                    </div>
                </div>
                
                <!-- TAB: LOGIN REDMINE -->
                <div class="tab-pane fade" id="redmine-tab">
                    <form method="post">
                        <input type="hidden" name="tipo_login" value="redmine">
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-person"></i> Utilizador INESC TEC
                            </label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-lock"></i> Password
                            </label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-building"></i> Entrar com INESC TEC
                        </button>
                    </form>
                </div>
            </div>
            
        <?php elseif ($modo === 'registar'): ?>
            <!-- FORMULÁRIO DE REGISTO -->
            <h4 class="mb-4">
                <i class="bi bi-person-plus-fill"></i> Criar Nova Conta
            </h4>
            
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">
                        <i class="bi bi-person"></i> Nome Completo *
                    </label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">
                        <i class="bi bi-at"></i> Email *
                    </label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">
                        <i class="bi bi-person-badge"></i> Nome de Utilizador *
                    </label>
                    <input type="text" name="username" class="form-control" 
                           minlength="3" required>
                    <small class="text-muted">Mínimo 3 caracteres</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">
                        <i class="bi bi-lock"></i> Password *
                    </label>
                    <input type="password" name="password" class="form-control" 
                           minlength="6" required>
                    <small class="text-muted">Mínimo 6 caracteres</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">
                        <i class="bi bi-lock-fill"></i> Confirmar Password *
                    </label>
                    <input type="password" name="confirm_password" class="form-control" 
                           minlength="6" required>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 mb-3">
                    <i class="bi bi-check-circle"></i> Criar Conta
                </button>
                
                <a href="?modo=login" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-arrow-left"></i> Voltar ao Login
                </a>
            </form>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>