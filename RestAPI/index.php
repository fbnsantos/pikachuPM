<?php
// Permitir requisições de qualquer origem
header("Access-Control-Allow-Origin: *");
// Permitir métodos HTTP específicos (GET, POST, etc.)
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
// Se você precisar de cabeçalhos adicionais permitidos
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Se a requisição for uma preflight (usada no CORS para verificar permissões)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Inclui o arquivo config.php
include 'config.php';
// Verificar se o Composer e a biblioteca JWT estão disponíveis
require 'php-jwt-main/src/JWT.php';
require 'php-jwt-main/src/Key.php';

// Criar um array com dados
$response = [
    "status" => "sucesso",
    "message" => "Login realizado com sucesso",
    "user_id" => 1234,
    "toke" => "abc123token"
];

// Definir o cabeçalho de resposta como JSON
header('Content-Type: application/json');






use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

// Faz echo da variável definida em config.php
$response["message"].= "O host do banco de dados é: " . $database_host;

try {
    // Tentar criar uma nova conexão PDO
    $pdo = new PDO("mysql:host=$database_host;dbname=$dbname", $database_user, $database_password);
    
    // Definir o modo de erro PDO para exceção
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Teste de conexão bem-sucedida
    $response["message"].= "Conexão com o banco de dados bem-sucedida!";
    
} catch (PDOException $e) {
    // Se houver erro de conexão, ele será capturado aqui
    $response["message"].= "Erro na conexão com o banco de dados: " . $e->getMessage();
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
$response["message"].= "Token JWT gerado: " . $jwt . "<br>";

// Decodificar o token JWT
$decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
$decoded_string = json_encode($decoded);
$response["message"].= "Dados decodificados do token JWT:<br>";
$response["message"].= $decoded_string;



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
    $response["message"].= "Nome de usuário está vazio.";
   return;
} else {
    $response["message"].= "O nome de usuário é: " . $data->username;
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
    // Converter o array em JSON e exibir
    $response['token']  =  $jwt;
    echo json_encode($response);
    //echo json_encode([
   //     "message" => "Autenticação bem-sucedida",
    //    "token" => $jwt
    //]);
} else {
    // Credenciais inválidas
    //http_response_code(401);
    $response["message"].="Credenciais inválidas";
    echo json_encode($response);
  
}
?>