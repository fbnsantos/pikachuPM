<?php
// config/database.php
return [
    'host' => 'localhost',
    'dbname' => 'auth_system',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4'
];

// config/jwt.php
return [
    'secret' => 'your_jwt_secret_key_here',
    'expiration' => 3600 // Token expira em 1 hora
];

// src/Database/Connection.php
namespace App\Database;

class Connection {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $config = require __DIR__ . '/../../config/database.php';
        
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
        
        try {
            $this->pdo = new \PDO($dsn, $config['username'], $config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (\PDOException $e) {
            throw new \Exception("Connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo(): \PDO {
        return $this->pdo;
    }
}

// src/Models/User.php
namespace App\Models;

class User {
    private $db;

    public function __construct(\PDO $db) {
        $this->db = $db;
    }

    public function create(array $data): array {
        $sql = "INSERT INTO users (name, email, password, created_at) 
                VALUES (:name, :email, :password, NOW())";
        
        try {
            $stmt = $this->db->prepare($sql);
            
            $stmt->execute([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => password_hash($data['password'], PASSWORD_DEFAULT)
            ]);

            return [
                'success' => true,
                'message' => 'User created successfully',
                'userId' => $this->db->lastInsertId()
            ];
        } catch (\PDOException $e) {
            if ($e->getCode() == '23000') { // Duplicate entry
                return [
                    'success' => false,
                    'message' => 'Email already exists'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    public function findByEmail(string $email): ?array {
        $sql = "SELECT * FROM users WHERE email = :email";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['email' => $email]);
        
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findById(int $id): ?array {
        $sql = "SELECT id, name, email, created_at FROM users WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        
        $user = $stmt->fetch();
        return $user ?: null;
    }
}

// src/Services/AuthService.php
namespace App\Services;

class AuthService {
    private $user;
    private $jwt;

    public function __construct(\App\Models\User $user, JWTService $jwt) {
        $this->user = $user;
        $this->jwt = $jwt;
    }

    public function register(array $data): array {
        if (!$this->validateRegistrationData($data)) {
            return [
                'success' => false,
                'message' => 'Invalid data provided'
            ];
        }

        return $this->user->create($data);
    }

    public function login(string $email, string $password): array {
        $user = $this->user->findByEmail($email);

        if (!$user || !password_verify($password, $user['password'])) {
            return [
                'success' => false,
                'message' => 'Invalid credentials'
            ];
        }

        $token = $this->jwt->generate([
            'user_id' => $user['id'],
            'email' => $user['email']
        ]);

        return [
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email']
            ]
        ];
    }

    private function validateRegistrationData(array $data): bool {
        return !empty($data['name']) &&
               !empty($data['email']) &&
               filter_var($data['email'], FILTER_VALIDATE_EMAIL) &&
               !empty($data['password']) &&
               strlen($data['password']) >= 6;
    }
}

// src/Services/JWTService.php
namespace App\Services;

class JWTService {
    private $secret;
    private $expiration;

    public function __construct() {
        $config = require __DIR__ . '/../../config/jwt.php';
        $this->secret = $config['secret'];
        $this->expiration = $config['expiration'];
    }

    public function generate(array $payload): string {
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256'
        ]);

        $payload['iat'] = time();
        $payload['exp'] = time() + $this->expiration;
        
        $base64Header = $this->base64UrlEncode($header);
        $base64Payload = $this->base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', 
            $base64Header . "." . $base64Payload, 
            $this->secret, 
            true
        );
        
        $base64Signature = $this->base64UrlEncode($signature);
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }

    public function validate(string $token): ?array {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return null;
        }

        $header = $this->base64UrlDecode($parts[0]);
        $payload = $this->base64UrlDecode($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);

        $headerData = json_decode($header, true);
        $payloadData = json_decode($payload, true);

        if (!$headerData || !$payloadData) {
            return null;
        }

        // Verificar assinatura
        $expectedSignature = hash_hmac('sha256', 
            $parts[0] . "." . $parts[1], 
            $this->secret, 
            true
        );

        if ($signature !== $expectedSignature) {
            return null;
        }

        // Verificar expiração
        if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
            return null;
        }

        return $payloadData;
    }

    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

// public/api/register.php
require_once __DIR__ . '/../../vendor/autoload.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $db = \App\Database\Connection::getInstance()->getPdo();
    $userModel = new \App\Models\User($db);
    $jwtService = new \App\Services\JWTService();
    $authService = new \App\Services\AuthService($userModel, $jwtService);

    $result = $authService->register($data);
    
    http_response_code($result['success'] ? 201 : 400);
    echo json_encode($result);
}

// public/api/login.php
require_once __DIR__ . '/../../vendor/autoload.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['email']) || !isset($data['password'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email and password are required'
        ]);
        exit;
    }

    $db = \App\Database\Connection::getInstance()->getPdo();
    $userModel = new \App\Models\User($db);
    $jwtService = new \App\Services\JWTService();
    $authService = new \App\Services\AuthService($userModel, $jwtService);

    $result = $authService->login($data['email'], $data['password']);
    
    http_response_code($result['success'] ? 200 : 401);
    echo json_encode($result);
}

// SQL para criar a tabela users
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
