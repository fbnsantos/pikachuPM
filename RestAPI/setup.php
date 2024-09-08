<?php
// Inclui o arquivo config.php
include 'config.php';

try {
    $pdo = new PDO("mysql:host=$database_host;dbname=$dbname", $database_user, $database_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


       // Tabela de Utilizadores
       $sqlUsers = "
       CREATE TABLE IF NOT EXISTS users (
           id INT AUTO_INCREMENT PRIMARY KEY,
           username VARCHAR(100) NOT NULL UNIQUE,
           password VARCHAR(255) NOT NULL,
           email VARCHAR(100) NOT NULL UNIQUE,
           web_link_profile VARCHAR(255),
           created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
       );";
       /*
       •	username: Nome de utilizadores único.
       •	password: Senha armazenada (de preferência, com hash, por exemplo, usando password_hash()).
       •	email: E-mail único associado ao usuário.
       •	created_at: Data e hora de criação do usuário.
   */
       $pdo->exec($sqlUsers);
       echo "Tabela users criada com sucesso.<br>";

       // Inserir um usuário por omissão, se não houver nenhum usuário ainda
    $sqlInsertDefaultUser = "
    INSERT INTO users (username, password, email) 
    SELECT * FROM (SELECT 'admin' AS username, :password AS password, 'admin@example.com' AS email) AS tmp
    WHERE NOT EXISTS (
        SELECT username FROM users WHERE username = 'admin'
    ) LIMIT 1;
    ";

    // Usar password_hash() para criar uma senha segura
    $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);

    // Executar a inserção do usuário padrão
    $stmt = $pdo->prepare($sqlInsertDefaultUser);
    $stmt->execute([':password' => $hashedPassword]);
    
    echo "Usuário 'admin' inserido com sucesso (se não existia).<br>";


    // Tabela de Tokens JWT
    $sqlTokens = "
    CREATE TABLE IF NOT EXISTS jwt_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(255) NOT NULL,
        issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        is_revoked TINYINT(1) DEFAULT 0
    );";

    /*•	user_id: ID do usuário associado ao token.
•	token: O próprio token JWT (ou seu identificador) armazenado no banco de dados.
•	issued_at: Data e hora de emissão do token.
•	expires_at: Data e hora de expiração do token.
•	is_revoked: Indica se o token foi revogado.
*/
    $pdo->exec($sqlTokens);
    echo "Tabela jwt_tokens criada com sucesso.<br>";


 


    // Criar tabela de Tipos de Projetos
    $sqlProjectTypes = "
    CREATE TABLE IF NOT EXISTS project_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL
    );";
    $pdo->exec($sqlProjectTypes);
    echo "Tabela project_types criada com sucesso.<br>";

    // Inserir tipos de projetos na tabela project_types
    $sqlInsertTypes = "
    INSERT INTO project_types (name) VALUES
    ('project'),
    ('project_proposal'),
    ('prototype'),
    ('report'),
    ('article'),
    ('demonstration');";
    $pdo->exec($sqlInsertTypes);
    echo "Tipos de projeto inseridos com sucesso.<br>";

    // Criar tabela de Projetos
    $sqlProjects = "
    CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        start_date DATE,         
        end_date DATE,
        type_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (type_id) REFERENCES project_types(id) ON DELETE RESTRICT
    );";

    /*	•	name: Nome do projeto.
	•	description: Descrição do projeto.
	•	created_at: Data e hora de criação do projeto.
    */
    $pdo->exec($sqlProjects);
    echo "Tabela projects criada com sucesso.<br>";

    // Criar tabela de Links Relacionados aos Projetos
    $sqlProjectLinks = "
        CREATE TABLE IF NOT EXISTS project_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            web_link VARCHAR(255) NOT NULL,
            description VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );";
    $pdo->exec($sqlProjectLinks);
    echo "Tabela project_links criada com sucesso.<br>";

    // Criar tabela de Listas (colunas do Kanban)
    $sqlLists = "
    CREATE TABLE IF NOT EXISTS lists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        position INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    );";
    /*	
    •	project_id: Relaciona a lista a um projeto específico.
	•	name: Nome da lista (ex: “A Fazer”, “Em Progresso”).
	•	position: A posição da lista no quadro Kanban.
	•	created_at: Data de criação da lista.*/
    $pdo->exec($sqlLists);
    echo "Tabela lists criada com sucesso.<br>";

    // Criar tabela de Tarefas
    $sqlTasks = "
    CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        list_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        status ENUM('hours', 'days', 'weeks') DEFAULT 'days',
        due_date DATE,
        position INT NOT NULL,
        parent_task_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
        FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE SET NULL
    );";

    /*
    •	list_id: Relaciona a tarefa a uma lista específica.
	•	name: Nome da tarefa.
	•	description: Descrição da tarefa.
	•	status: O status da tarefa em termos de duração, como ‘hours’, ‘days’, ou ‘weeks’.
	•	due_date: Data de vencimento da tarefa.
	•	position: Posição da tarefa dentro da lista (usado para ordenação).
	•	parent_task_id: Relaciona a tarefa a uma tarefa “pai”, criando subtarefas.
	•	created_at: Data de criação da tarefa.
	•	updated_at: Data de última atualização da tarefa.
    */

    $pdo->exec($sqlTasks);
    echo "Tabela tasks criada com sucesso.<br>";


    // Criar tabela de Atribuições de Tarefas
    $sqlTaskAssignments = "
    CREATE TABLE IF NOT EXISTS task_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );";
    /*  •	task_id: Referência à tarefa que está sendo atribuída.
	•	user_id: Referência ao usuário que está sendo atribuído à tarefa.
	•	assigned_at: Data em que o usuário foi atribuído à tarefa.*/
    $pdo->exec($sqlTaskAssignments);
    echo "Tabela task_assignments criada com sucesso.<br>";
} catch (PDOException $e) {
    echo "Erro ao criar tabelas: " . $e->getMessage();
}
