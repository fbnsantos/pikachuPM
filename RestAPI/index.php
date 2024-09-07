<?php

// Inclui o arquivo config.php
include 'config.php';
// Verificar se o Composer e a biblioteca JWT estão disponíveis
require 'vendor/autoload.php';  // Carregar as dependências do Composer


require 'php-jwt-main/src/JWT.php';
require 'php-jwt-main/src/Key.php';
require 'php-jwt-main/src/BeforeValidException.php';
require 'php-jwt-main/src/ExpiredException.php';
require 'php-jwt-main/src/SignatureInvalidException.php';

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
?>