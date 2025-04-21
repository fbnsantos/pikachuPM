<?php
session_start();

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $url = "http://criis-projects.inesctec.pt/users/current.json";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password"); // autenticação básica
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $data = json_decode($response, true);
        $_SESSION['username'] = $data['user']['login'];
        $_SESSION['user_id'] = $data['user']['id'];
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
  <title>Login com Redmine</title>
</head>
<body>
  <h2>Login com Redmine</h2>
  <?php if ($erro): ?>
    <p style="color: red"><?= htmlspecialchars($erro) ?></p>
  <?php endif; ?>
  <form method="post">
    <label>Utilizador:</label><br>
    <input type="text" name="username" required><br><br>

    <label>Password:</label><br>
    <input type="password" name="password" required><br><br>

    <button type="submit">Entrar</button>
  </form>
</body>
</html>