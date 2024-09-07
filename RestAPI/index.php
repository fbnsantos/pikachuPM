<?php

// Inclui o arquivo config.php
include 'config.php';
// Verificar se o Composer e a biblioteca JWT estão disponíveis
require 'php-jwt-main/src/JWT.php';
require 'php-jwt-main/src/Key.php';


use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

// Faz echo da variável definida em config.php
echo "O host do banco de dados é: " . $database_host;

try {
    // Tentar criar uma nova conexão PDO
    $pdo = new PDO("mysql:host=$database_host;dbname=$dbname", $database_user, $database_password);
    
    // Definir o modo de erro PDO para exceção
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Teste de conexão bem-sucedida
    echo "Conexão com o banco de dados bem-sucedida!";
    
} catch (PDOException $e) {
    // Se houver erro de conexão, ele será capturado aqui
    echo "Erro na conexão com o banco de dados: " . $e->getMessage();
}



// Definir a chave secreta para assinar o token
$secret_key = "sua_chave_secreta";

// Gerar os dados para o token
$token_data = [
    "iat" => time(),
    "exp" => time() + 3600,  // O token expira em 1 hora
    "user_id" => 1234
];

// Gerar o token JWT
$jwt = JWT::encode($token_data, $secret_key, 'HS256');
echo "Token JWT gerado: " . $jwt . "<br>";

// Decodificar o token JWT
$decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
echo "Dados decodificados do token JWT:<br>";
print_r($decoded);



// Definir a chave secreta para assinar o token JWT
$secret_key = "sua_chave_secreta";
$issuer = "http://seusite.com"; // O domínio ou identificador do emissor
$audience = "http://seusite.com"; // A audiência do token
$issued_at = time(); // Tempo em que o token foi emitido
$expiration_time = $issued_at + 3600; // O token expira em 1 hora

// Receber as credenciais enviadas via POST
$data = json_decode(file_get_contents("php://input"));
$username = $data->username;
if (empty($data->username)) {
    return "Nome de usuário está vazio.";
} else {
    echo "O nome de usuário é: " . $data->username;
}

$password = $data->password;

// Verificar se as credenciais estão corretas
// Aqui você pode conectar-se a um banco de dados para verificar as credenciais
if ($username == 'usuario' && $password == 'senha') {
    // As credenciais estão corretas, gerar o token JWT
    $token = [
        "iss" => $issuer, // Emissor do token
        "aud" => $audience, // Audiência
        "iat" => $issued_at, // Tempo de emissão
        "exp" => $expiration_time, // Expiração
        "data" => [
            "id" => 1, // ID do usuário
            "username" => $username, // Nome de usuário
        ]
    ];

    // Gerar o token JWT
    $jwt = JWT::encode($token, $secret_key, 'HS256');

    // Retornar o token em formato JSON
    echo json_encode([
        "message" => "Autenticação bem-sucedida",
        "token" => $jwt
    ]);
} else {
    // Credenciais inválidas
    http_response_code(401);
    echo json_encode(["message" => "Credenciais inválidas"]);
}
?>