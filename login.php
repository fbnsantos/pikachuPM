<?php
session_start();

// Configurar timeout de sessão para 24 horas (86400 segundos)
ini_set('session.gc_maxlifetime', 86400);
ini_set('session.cookie_lifetime', 86400);

// Configurar parâmetros do cookie de sessão
session_set_cookie_params([
    'lifetime' => 86400,  // 24 horas
    'path' => '/',
    'secure' => false,    // Mude para true se usar HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $url = "http://criis-projects.inesctec.pt/users/current.json";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password"); // autenticação básica
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout da requisição HTTP

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $data = json_decode($response, true);
        $_SESSION['username'] = $data['user']['login'];
        $_SESSION['user_id'] = $data['user']['id'];
        $_SESSION['user'] = $username;
        $_SESSION['password'] = $password;
        
        // Registrar o tempo de início da sessão (já está no index.php, mas pode adicionar aqui também)
        $_SESSION['inicio'] = time();
        
        // Atualizar o tempo de última atividade
        $_SESSION['ultima_atividade'] = time();
        
        header('Location: index.php');
        exit;
    } else {
        $erro = "Login inválido.";
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login com Redmine</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      margin: 0;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    
    .login-container {
      background: white;
      padding: 2rem;
      border-radius: 10px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.2);
      width: 100%;
      max-width: 400px;
    }
    
    h2 {
      text-align: center;
      color: #333;
      margin-bottom: 1.5rem;
    }
    
    .error {
      color: #d32f2f;
      background: #ffebee;
      padding: 0.75rem;
      border-radius: 5px;
      margin-bottom: 1rem;
      text-align: center;
    }
    
    label {
      display: block;
      margin-bottom: 0.5rem;
      color: #555;
      font-weight: 500;
    }
    
    input[type="text"],
    input[type="password"] {
      width: 100%;
      padding: 0.75rem;
      margin-bottom: 1rem;
      border: 2px solid #ddd;
      border-radius: 5px;
      font-size: 1rem;
      box-sizing: border-box;
      transition: border-color 0.3s;
    }
    
    input[type="text"]:focus,
    input[type="password"]:focus {
      outline: none;
      border-color: #667eea;
    }
    
    button {
      width: 100%;
      padding: 0.75rem;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border: none;
      border-radius: 5px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    
    button:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    }
    
    button:active {
      transform: translateY(0);
    }
  </style>
</head>
<body>
  <div class="login-container">
    <h2>Login com Redmine</h2>
    <?php if ($erro): ?>
      <p class="error"><?= htmlspecialchars($erro) ?></p>
    <?php endif; ?>
    <form method="post">
      <label>Utilizador:</label>
      <input type="text" name="username" required autofocus>

      <label>Password:</label>
      <input type="password" name="password" required>

      <button type="submit">Entrar</button>
    </form>
  </div>
</body>
</html>