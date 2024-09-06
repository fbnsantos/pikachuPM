<?php

// Inclui o arquivo config.php
include 'config.php';

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
?>