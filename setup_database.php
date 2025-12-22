<?php
/**
 * setup_database.php
 * 
 * Script de configuração inicial da base de dados PikachuPM
 * Gerado automaticamente em: 2025-12-22 23:35:42
 * 
 * COMO USAR:
 * 1. Crie uma base de dados vazia no MySQL
 * 2. Configure as credenciais no config.php
 * 3. Execute: php setup_database.php
 * 4. A estrutura completa será criada
 * 
 * ATENÇÃO: Este script NÃO contém dados, apenas a estrutura das tabelas
 */

// Ativar display de erros para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Definir charset UTF-8
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// ========================================
// CONFIGURAÇÃO
// ========================================

// Verificar se config.php existe
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die("\n❌ ERRO: Ficheiro config.php não encontrado!\n\nCrie o ficheiro config.php com:\n<?php\n\$db_host = 'localhost';\n\$db_name = 'sua_bd';\n\$db_user = 'seu_user';\n\$db_pass = 'sua_pass';\n?>\n");
}

include_once $config_file;

// Verificar se as variáveis foram definidas
if (!isset($db_host) || !isset($db_name) || !isset($db_user) || !isset($db_pass)) {
    die("\n❌ ERRO: Variáveis de configuração não definidas no config.php!\n");
}

// ========================================
// ESTATÍSTICAS DA BD ORIGINAL
// ========================================

/*
Total de tabelas: 46

Tabelas encontradas:
- admin_users (5 colunas, 2 registos na BD original)
- calendar_eventos (9 colunas, 931 registos na BD original)
- daily_focus (5 colunas, 0 registos na BD original)
[... outras tabelas ...]
*/

// ========================================
// CONEXÃO
// ========================================

echo "\n🔧 PikachuPM - Setup da Base de Dados\n";
echo "=====================================\n\n";

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conectado à base de dados '$db_name'\n\n";
} catch (PDOException $e) {
    die("❌ Erro de conexão: " . $e->getMessage() . "\n\nVerifique se:\n1. A base de dados existe\n2. As credenciais estão corretas\n3. O MySQL está a correr\n");
}

// Desativar verificação de chaves estrangeiras temporariamente
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// ========================================
// CRIAR TABELAS
// ========================================

$tables_created = 0;
$tables_failed = 0;
$errors = [];

// Array com todas as definições de tabelas
$table_definitions = [
    'admin_users' => "
        CREATE TABLE `admin_users` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `added_by` int DEFAULT NULL,
          `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_user` (`user_id`),
          KEY `idx_username` (`username`)
        ) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'calendar_eventos' => "
        CREATE TABLE `calendar_eventos` (
          `id` int NOT NULL AUTO_INCREMENT,
          `data` date NOT NULL,
          `tipo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
          `descricao` text COLLATE utf8mb4_unicode_ci,
          `hora` time DEFAULT NULL,
          `criador` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `cor` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_data` (`data`),
          KEY `idx_tipo` (`tipo`)
        ) ENGINE=InnoDB AUTO_INCREMENT=946 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'user_tokens' => "
        CREATE TABLE `user_tokens` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `username` varchar(100) NOT NULL,
          `token` varchar(64) NOT NULL,
          `password_hash` varchar(255) DEFAULT NULL,
          `is_local_user` tinyint(1) DEFAULT '0',
          `is_approved` tinyint(1) DEFAULT '0',
          `email` varchar(255) DEFAULT NULL,
          `full_name` varchar(255) DEFAULT NULL,
          `approved_by` int DEFAULT NULL,
          `approved_at` timestamp NULL DEFAULT NULL,
          `last_login` timestamp NULL DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`),
          UNIQUE KEY `token` (`token`),
          KEY `idx_is_local` (`is_local_user`),
          KEY `idx_is_approved` (`is_approved`),
          KEY `idx_email` (`email`)
        ) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ",
    
    'todos' => "
        CREATE TABLE `todos` (
          `id` int NOT NULL AUTO_INCREMENT,
          `titulo` varchar(255) NOT NULL,
          `descritivo` text,
          `data_limite` date DEFAULT NULL,
          `autor` int NOT NULL,
          `responsavel` int DEFAULT NULL,
          `task_id` int DEFAULT NULL,
          `todo_issue` text,
          `milestone_id` int DEFAULT NULL,
          `projeto_id` int DEFAULT NULL,
          `estado` varchar(20) DEFAULT 'aberta',
          `estagio` varchar(20) DEFAULT 'pensada',
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `autor` (`autor`),
          KEY `responsavel` (`responsavel`),
          CONSTRAINT `todos_ibfk_1` FOREIGN KEY (`autor`) REFERENCES `user_tokens` (`user_id`),
          CONSTRAINT `todos_ibfk_2` FOREIGN KEY (`responsavel`) REFERENCES `user_tokens` (`user_id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=1135 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ",
    
    'daily_focus' => "
        CREATE TABLE `daily_focus` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `task_id` int NOT NULL,
          `focus_date` date NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`),
          KEY `task_id` (`task_id`),
          CONSTRAINT `daily_focus_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user_tokens` (`user_id`),
          CONSTRAINT `daily_focus_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `todos` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ",
    
    'daily_reflections' => "
        CREATE TABLE `daily_reflections` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `reflection_date` date NOT NULL,
          `reflection_text` text,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`),
          CONSTRAINT `daily_reflections_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user_tokens` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ",
    
    'daily_reports' => "
        CREATE TABLE `daily_reports` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `report_date` date NOT NULL,
          `tarefas_alteradas` text COLLATE utf8mb4_unicode_ci,
          `tarefas_em_execucao` text COLLATE utf8mb4_unicode_ci,
          `correu_bem` text COLLATE utf8mb4_unicode_ci,
          `correu_mal` text COLLATE utf8mb4_unicode_ci,
          `plano_proximas_horas` text COLLATE utf8mb4_unicode_ci,
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_user_date` (`user_id`,`report_date`),
          KEY `idx_user_id` (`user_id`),
          KEY `idx_report_date` (`report_date`)
        ) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'projects' => "
        CREATE TABLE `projects` (
          `id` int NOT NULL AUTO_INCREMENT,
          `short_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
          `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `description` text COLLATE utf8mb4_unicode_ci,
          `owner_id` int DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `short_name` (`short_name`),
          KEY `idx_owner` (`owner_id`),
          KEY `idx_short_name` (`short_name`)
        ) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'project_deliverables' => "
        CREATE TABLE `project_deliverables` (
          `id` int NOT NULL AUTO_INCREMENT,
          `project_id` int NOT NULL,
          `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `description` text COLLATE utf8mb4_unicode_ci,
          `due_date` date DEFAULT NULL,
          `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_project` (`project_id`),
          KEY `idx_status` (`status`),
          CONSTRAINT `project_deliverables_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=90 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'deliverable_sprints' => "
        CREATE TABLE `deliverable_sprints` (
          `id` int NOT NULL AUTO_INCREMENT,
          `deliverable_id` int NOT NULL,
          `sprint_id` int NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_deliverable_sprint` (`deliverable_id`,`sprint_id`),
          KEY `idx_deliverable` (`deliverable_id`),
          KEY `idx_sprint` (`sprint_id`),
          CONSTRAINT `deliverable_sprints_ibfk_1` FOREIGN KEY (`deliverable_id`) REFERENCES `project_deliverables` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'deliverable_tasks' => "
        CREATE TABLE `deliverable_tasks` (
          `id` int NOT NULL AUTO_INCREMENT,
          `deliverable_id` int NOT NULL,
          `todo_id` int NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_deliverable_task` (`deliverable_id`,`todo_id`),
          KEY `idx_deliverable` (`deliverable_id`),
          KEY `idx_todo` (`todo_id`),
          CONSTRAINT `deliverable_tasks_ibfk_1` FOREIGN KEY (`deliverable_id`) REFERENCES `project_deliverables` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lab_authorized_users' => "
        CREATE TABLE `lab_authorized_users` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `authorized_by` int DEFAULT NULL,
          `authorized_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_user` (`user_id`),
          KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lab_issues' => "
        CREATE TABLE `lab_issues` (
          `id` int NOT NULL AUTO_INCREMENT,
          `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `descricao` text COLLATE utf8mb4_unicode_ci,
          `status` enum('ativo','suspenso','resolvido') COLLATE utf8mb4_unicode_ci DEFAULT 'ativo',
          `prioridade` enum('baixa','media','alta','urgente') COLLATE utf8mb4_unicode_ci DEFAULT 'media',
          `criado_por` int NOT NULL,
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `resolvido_em` timestamp NULL DEFAULT NULL,
          `resolvido_por` int DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_status` (`status`),
          KEY `idx_criado_por` (`criado_por`),
          KEY `idx_prioridade` (`prioridade`)
        ) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lab_issue_attachments' => "
        CREATE TABLE `lab_issue_attachments` (
          `id` int NOT NULL AUTO_INCREMENT,
          `issue_id` int NOT NULL,
          `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `filepath` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
          `filesize` int NOT NULL,
          `uploaded_by` int NOT NULL,
          `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_issue_id` (`issue_id`),
          CONSTRAINT `lab_issue_attachments_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `lab_issues` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lab_issue_participants' => "
        CREATE TABLE `lab_issue_participants` (
          `id` int NOT NULL AUTO_INCREMENT,
          `issue_id` int NOT NULL,
          `user_id` int NOT NULL,
          `role` enum('responsavel','colaborador','observador') COLLATE utf8mb4_unicode_ci DEFAULT 'colaborador',
          `adicionado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_issue_participant` (`issue_id`,`user_id`),
          KEY `idx_issue_id` (`issue_id`),
          KEY `idx_user_id` (`user_id`),
          CONSTRAINT `lab_issue_participants_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `lab_issues` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lab_issue_tasks' => "
        CREATE TABLE `lab_issue_tasks` (
          `id` int NOT NULL AUTO_INCREMENT,
          `issue_id` int NOT NULL,
          `todo_id` int NOT NULL,
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_issue_task` (`issue_id`,`todo_id`),
          KEY `idx_issue_id` (`issue_id`),
          KEY `idx_todo_id` (`todo_id`),
          CONSTRAINT `lab_issue_tasks_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `lab_issues` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lab_issue_updates' => "
        CREATE TABLE `lab_issue_updates` (
          `id` int NOT NULL AUTO_INCREMENT,
          `issue_id` int NOT NULL,
          `user_id` int NOT NULL,
          `tipo` enum('decisao','comentario','update') COLLATE utf8mb4_unicode_ci DEFAULT 'comentario',
          `conteudo` text COLLATE utf8mb4_unicode_ci NOT NULL,
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_issue_id` (`issue_id`),
          KEY `idx_user_id` (`user_id`),
          KEY `idx_tipo` (`tipo`),
          CONSTRAINT `lab_issue_updates_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `lab_issues` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'leads' => "
        CREATE TABLE `leads` (
          `id` int NOT NULL AUTO_INCREMENT,
          `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `descricao` text COLLATE utf8mb4_unicode_ci,
          `relevancia` int DEFAULT '5',
          `responsavel_id` int DEFAULT NULL,
          `data_inicio` date DEFAULT NULL,
          `data_fim` date DEFAULT NULL,
          `estado` enum('aberta','fechada') COLLATE utf8mb4_unicode_ci DEFAULT 'aberta',
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `responsavel_id` (`responsavel_id`),
          CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`responsavel_id`) REFERENCES `user_tokens` (`user_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lead_kanban' => "
        CREATE TABLE `lead_kanban` (
          `id` int NOT NULL AUTO_INCREMENT,
          `lead_id` int NOT NULL,
          `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `coluna` enum('todo','doing','done') COLLATE utf8mb4_unicode_ci DEFAULT 'todo',
          `posicao` int DEFAULT '0',
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `lead_id` (`lead_id`),
          CONSTRAINT `lead_kanban_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lead_links' => "
        CREATE TABLE `lead_links` (
          `id` int NOT NULL AUTO_INCREMENT,
          `lead_id` int NOT NULL,
          `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `url` text COLLATE utf8mb4_unicode_ci NOT NULL,
          `adicionado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `lead_id` (`lead_id`),
          CONSTRAINT `lead_links_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lead_members' => "
        CREATE TABLE `lead_members` (
          `id` int NOT NULL AUTO_INCREMENT,
          `lead_id` int NOT NULL,
          `user_id` int NOT NULL,
          `adicionado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_member` (`lead_id`,`user_id`),
          KEY `user_id` (`user_id`),
          CONSTRAINT `lead_members_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
          CONSTRAINT `lead_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user_tokens` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'lead_tasks' => "
        CREATE TABLE `lead_tasks` (
          `id` int NOT NULL AUTO_INCREMENT,
          `lead_id` int NOT NULL,
          `todo_id` int NOT NULL,
          `coluna` enum('todo','doing','done') COLLATE utf8mb4_unicode_ci DEFAULT 'todo',
          `posicao` int DEFAULT '0',
          `adicionado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_lead_task` (`lead_id`,`todo_id`),
          KEY `todo_id` (`todo_id`),
          CONSTRAINT `lead_tasks_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
          CONSTRAINT `lead_tasks_ibfk_2` FOREIGN KEY (`todo_id`) REFERENCES `todos` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'login_attempts' => "
        CREATE TABLE `login_attempts` (
          `id` int NOT NULL AUTO_INCREMENT,
          `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
          `success` tinyint(1) DEFAULT '0',
          `attempted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_username` (`username`),
          KEY `idx_ip` (`ip_address`),
          KEY `idx_attempted` (`attempted_at`)
        ) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'phd_info' => "
        CREATE TABLE `phd_info` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `data_inicio` date DEFAULT NULL,
          `titulo_doutoramento` text,
          `orientador` varchar(255) DEFAULT NULL,
          `coorientador` varchar(255) DEFAULT NULL,
          `instituicao` varchar(255) DEFAULT NULL,
          `departamento` varchar(255) DEFAULT NULL,
          `link_tese` text,
          `notas` text,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`),
          CONSTRAINT `phd_info_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user_tokens` (`user_id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ",
    
    'phd_artigos' => "
        CREATE TABLE `phd_artigos` (
          `id` int NOT NULL AUTO_INCREMENT,
          `user_id` int NOT NULL,
          `titulo` varchar(500) DEFAULT NULL,
          `autores` text,
          `revista_conferencia` varchar(255) DEFAULT NULL,
          `ano` int DEFAULT NULL,
          `link` text,
          `status` varchar(50) DEFAULT 'publicado',
          `tipo` varchar(50) DEFAULT 'artigo',
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`),
          CONSTRAINT `phd_artigos_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user_tokens` (`user_id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ",
    
    'project_files' => "
        CREATE TABLE `project_files` (
          `id` int NOT NULL AUTO_INCREMENT,
          `project_id` int NOT NULL,
          `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
          `file_size` bigint NOT NULL,
          `uploaded_by` int DEFAULT NULL,
          `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_project` (`project_id`),
          KEY `idx_uploaded_by` (`uploaded_by`),
          CONSTRAINT `project_files_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'project_links' => "
        CREATE TABLE `project_links` (
          `id` int NOT NULL AUTO_INCREMENT,
          `project_id` int NOT NULL,
          `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `url` text COLLATE utf8mb4_unicode_ci NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_project` (`project_id`),
          CONSTRAINT `project_links_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'project_members' => "
        CREATE TABLE `project_members` (
          `id` int NOT NULL AUTO_INCREMENT,
          `project_id` int NOT NULL,
          `user_id` int NOT NULL,
          `role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'member',
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_member` (`project_id`,`user_id`),
          KEY `idx_project` (`project_id`),
          KEY `idx_user` (`user_id`),
          CONSTRAINT `project_members_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'prototypes' => "
        CREATE TABLE `prototypes` (
          `id` int NOT NULL AUTO_INCREMENT,
          `parent_id` int DEFAULT NULL,
          `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `identifier` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `description` text COLLATE utf8mb4_unicode_ci,
          `short_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `responsavel_id` int DEFAULT NULL,
          `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `vision` text COLLATE utf8mb4_unicode_ci,
          `target_group` text COLLATE utf8mb4_unicode_ci,
          `needs` text COLLATE utf8mb4_unicode_ci,
          `product_description` text COLLATE utf8mb4_unicode_ci,
          `business_goals` text COLLATE utf8mb4_unicode_ci,
          `sentence` text COLLATE utf8mb4_unicode_ci,
          `repo_links` text COLLATE utf8mb4_unicode_ci,
          `documentation_links` text COLLATE utf8mb4_unicode_ci,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `responsible` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nome do responsável pelo protótipo',
          `participants` text COLLATE utf8mb4_unicode_ci COMMENT 'Array JSON com lista de participantes',
          PRIMARY KEY (`id`),
          KEY `idx_short_name` (`short_name`),
          KEY `idx_title` (`title`),
          KEY `idx_responsible` (`responsible`),
          KEY `idx_parent_id` (`parent_id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'project_prototypes' => "
        CREATE TABLE `project_prototypes` (
          `id` int NOT NULL AUTO_INCREMENT,
          `project_id` int NOT NULL,
          `prototype_id` int NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_project_prototype` (`project_id`,`prototype_id`),
          KEY `idx_project` (`project_id`),
          KEY `idx_prototype` (`prototype_id`),
          CONSTRAINT `project_prototypes_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
          CONSTRAINT `project_prototypes_ibfk_2` FOREIGN KEY (`prototype_id`) REFERENCES `prototypes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'prototype_members' => "
        CREATE TABLE `prototype_members` (
          `id` int NOT NULL AUTO_INCREMENT,
          `prototype_id` int NOT NULL,
          `user_id` int NOT NULL,
          `role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'member',
          `joined_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_prototype_user` (`prototype_id`,`user_id`),
          KEY `idx_prototype` (`prototype_id`),
          KEY `idx_user` (`user_id`),
          CONSTRAINT `prototype_members_ibfk_1` FOREIGN KEY (`prototype_id`) REFERENCES `prototypes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'prototype_participants' => "
        CREATE TABLE `prototype_participants` (
          `id` int NOT NULL AUTO_INCREMENT,
          `prototype_id` int NOT NULL,
          `user_id` int NOT NULL,
          `role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'member',
          `is_leader` tinyint(1) DEFAULT '0',
          `joined_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_user_prototype` (`prototype_id`,`user_id`),
          KEY `idx_prototype` (`prototype_id`),
          KEY `idx_user` (`user_id`),
          KEY `idx_leader` (`is_leader`),
          CONSTRAINT `prototype_participants_ibfk_1` FOREIGN KEY (`prototype_id`) REFERENCES `prototypes` (`id`) ON DELETE CASCADE,
          CONSTRAINT `prototype_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user_tokens` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'research_ideas' => "
        CREATE TABLE `research_ideas` (
          `id` int NOT NULL AUTO_INCREMENT,
          `title` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
          `description` text COLLATE utf8mb4_unicode_ci,
          `author` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `status` enum('nova','em análise','aprovada','arquivada') COLLATE utf8mb4_unicode_ci DEFAULT 'nova',
          `priority` enum('baixa','normal','alta','urgente') COLLATE utf8mb4_unicode_ci DEFAULT 'normal',
          PRIMARY KEY (`id`),
          KEY `idx_author` (`author`),
          KEY `idx_status` (`status`),
          KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'research_idea_interested' => "
        CREATE TABLE `research_idea_interested` (
          `id` int NOT NULL AUTO_INCREMENT,
          `idea_id` int NOT NULL,
          `user_login` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `notes` text COLLATE utf8mb4_unicode_ci,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_interested` (`idea_id`,`user_login`),
          KEY `idx_idea` (`idea_id`),
          KEY `idx_user` (`user_login`),
          CONSTRAINT `research_idea_interested_ibfk_1` FOREIGN KEY (`idea_id`) REFERENCES `research_ideas` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'research_idea_links' => "
        CREATE TABLE `research_idea_links` (
          `id` int NOT NULL AUTO_INCREMENT,
          `idea_id` int NOT NULL,
          `url` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
          `title` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `description` text COLLATE utf8mb4_unicode_ci,
          `added_by` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_idea` (`idea_id`),
          KEY `idx_added_by` (`added_by`),
          CONSTRAINT `research_idea_links_ibfk_1` FOREIGN KEY (`idea_id`) REFERENCES `research_ideas` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'sprints' => "
        CREATE TABLE `sprints` (
          `id` int NOT NULL AUTO_INCREMENT,
          `nome` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `descricao` text COLLATE utf8mb4_unicode_ci,
          `data_inicio` date DEFAULT NULL,
          `data_fim` date DEFAULT NULL,
          `estado` enum('aberta','pausa','fechada') COLLATE utf8mb4_unicode_ci DEFAULT 'aberta',
          `responsavel_id` int DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_estado` (`estado`),
          KEY `idx_responsavel` (`responsavel_id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'sprint_members' => "
        CREATE TABLE `sprint_members` (
          `id` int NOT NULL AUTO_INCREMENT,
          `sprint_id` int NOT NULL,
          `user_id` int NOT NULL,
          `role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'member',
          `joined_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_sprint_user` (`sprint_id`,`user_id`),
          KEY `idx_sprint` (`sprint_id`),
          KEY `idx_user` (`user_id`),
          CONSTRAINT `sprint_members_ibfk_1` FOREIGN KEY (`sprint_id`) REFERENCES `sprints` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=173 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'sprint_projects' => "
        CREATE TABLE `sprint_projects` (
          `id` int NOT NULL AUTO_INCREMENT,
          `sprint_id` int NOT NULL,
          `project_id` int NOT NULL,
          `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_sprint_project` (`sprint_id`,`project_id`),
          KEY `project_id` (`project_id`),
          KEY `idx_sprint` (`sprint_id`),
          CONSTRAINT `sprint_projects_ibfk_1` FOREIGN KEY (`sprint_id`) REFERENCES `sprints` (`id`) ON DELETE CASCADE,
          CONSTRAINT `sprint_projects_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'sprint_prototypes' => "
        CREATE TABLE `sprint_prototypes` (
          `id` int NOT NULL AUTO_INCREMENT,
          `sprint_id` int NOT NULL,
          `prototype_id` int NOT NULL,
          `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_sprint_prototype` (`sprint_id`,`prototype_id`),
          KEY `prototype_id` (`prototype_id`),
          KEY `idx_sprint` (`sprint_id`),
          CONSTRAINT `sprint_prototypes_ibfk_1` FOREIGN KEY (`sprint_id`) REFERENCES `sprints` (`id`) ON DELETE CASCADE,
          CONSTRAINT `sprint_prototypes_ibfk_2` FOREIGN KEY (`prototype_id`) REFERENCES `prototypes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'sprint_tasks' => "
        CREATE TABLE `sprint_tasks` (
          `id` int NOT NULL AUTO_INCREMENT,
          `sprint_id` int NOT NULL,
          `todo_id` int NOT NULL,
          `position` int DEFAULT '0',
          `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_sprint_task` (`sprint_id`,`todo_id`),
          KEY `idx_sprint` (`sprint_id`),
          KEY `idx_todo` (`todo_id`),
          CONSTRAINT `sprint_tasks_ibfk_1` FOREIGN KEY (`sprint_id`) REFERENCES `sprints` (`id`) ON DELETE CASCADE,
          CONSTRAINT `sprint_tasks_ibfk_2` FOREIGN KEY (`todo_id`) REFERENCES `todos` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=598 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'user_stories' => "
        CREATE TABLE `user_stories` (
          `id` int NOT NULL AUTO_INCREMENT,
          `prototype_id` int NOT NULL,
          `story_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
          `moscow_priority` enum('Must','Should','Could','Won''t') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Should',
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `priority` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Should',
          `status` enum('open','closed') COLLATE utf8mb4_unicode_ci DEFAULT 'open',
          `completion_percentage` int DEFAULT '0',
          PRIMARY KEY (`id`),
          KEY `idx_prototype` (`prototype_id`),
          KEY `idx_priority` (`moscow_priority`),
          CONSTRAINT `user_stories_ibfk_1` FOREIGN KEY (`prototype_id`) REFERENCES `prototypes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=433 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'story_tasks' => "
        CREATE TABLE `story_tasks` (
          `id` int NOT NULL AUTO_INCREMENT,
          `story_id` int NOT NULL,
          `todo_id` int NOT NULL,
          `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_story_task` (`story_id`,`todo_id`),
          KEY `idx_story` (`story_id`),
          KEY `idx_task` (`todo_id`),
          CONSTRAINT `story_tasks_ibfk_1` FOREIGN KEY (`story_id`) REFERENCES `user_stories` (`id`) ON DELETE CASCADE,
          CONSTRAINT `story_tasks_ibfk_2` FOREIGN KEY (`todo_id`) REFERENCES `todos` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'user_story_sprints' => "
        CREATE TABLE `user_story_sprints` (
          `id` int NOT NULL AUTO_INCREMENT,
          `story_id` int NOT NULL,
          `sprint_id` int NOT NULL,
          `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_story_sprint` (`story_id`,`sprint_id`),
          KEY `idx_story` (`story_id`),
          KEY `idx_sprint` (`sprint_id`),
          CONSTRAINT `user_story_sprints_ibfk_1` FOREIGN KEY (`story_id`) REFERENCES `user_stories` (`id`) ON DELETE CASCADE,
          CONSTRAINT `user_story_sprints_ibfk_2` FOREIGN KEY (`sprint_id`) REFERENCES `sprints` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'user_story_tasks' => "
        CREATE TABLE `user_story_tasks` (
          `id` int NOT NULL AUTO_INCREMENT,
          `story_id` int NOT NULL,
          `task_id` int NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_story_task` (`story_id`,`task_id`),
          KEY `idx_story` (`story_id`),
          KEY `idx_task` (`task_id`),
          CONSTRAINT `user_story_tasks_ibfk_1` FOREIGN KEY (`story_id`) REFERENCES `user_stories` (`id`) ON DELETE CASCADE,
          CONSTRAINT `user_story_tasks_ibfk_2` FOREIGN KEY (`task_id`) REFERENCES `todos` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'task_checklist' => "
        CREATE TABLE `task_checklist` (
          `id` int NOT NULL AUTO_INCREMENT,
          `todo_id` int NOT NULL,
          `item_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
          `is_checked` tinyint(1) DEFAULT '0',
          `position` int NOT NULL DEFAULT '0',
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_todo` (`todo_id`),
          KEY `idx_position` (`position`),
          CONSTRAINT `task_checklist_ibfk_1` FOREIGN KEY (`todo_id`) REFERENCES `todos` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=1596 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'task_files' => "
        CREATE TABLE `task_files` (
          `id` int NOT NULL AUTO_INCREMENT,
          `todo_id` int NOT NULL,
          `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
          `file_size` int DEFAULT '0',
          `notes` text COLLATE utf8mb4_unicode_ci,
          `uploaded_by` int NOT NULL,
          `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_todo` (`todo_id`),
          KEY `idx_uploaded` (`uploaded_by`),
          CONSTRAINT `task_files_ibfk_1` FOREIGN KEY (`todo_id`) REFERENCES `todos` (`id`) ON DELETE CASCADE,
          CONSTRAINT `task_files_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `user_tokens` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // === TABELAS DE CONTACTOS COMERCIAIS ===
    'comercial_contactos' => "
        CREATE TABLE `comercial_contactos` (
          `id` int NOT NULL AUTO_INCREMENT,
          `tipo` enum('cliente','fornecedor','parceiro','outro') COLLATE utf8mb4_unicode_ci DEFAULT 'cliente',
          `empresa` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `pessoa_contacto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `cargo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `telefone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `telemovel` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `morada` text COLLATE utf8mb4_unicode_ci,
          `cidade` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `pais` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'Portugal',
          `codigo_postal` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `nif` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `notas` text COLLATE utf8mb4_unicode_ci,
          `tags` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `estado` enum('ativo','inativo','potencial') COLLATE utf8mb4_unicode_ci DEFAULT 'ativo',
          `criado_por` int DEFAULT NULL,
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_tipo` (`tipo`),
          KEY `idx_empresa` (`empresa`),
          KEY `idx_estado` (`estado`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'comercial_interacoes' => "
        CREATE TABLE `comercial_interacoes` (
          `id` int NOT NULL AUTO_INCREMENT,
          `contacto_id` int NOT NULL,
          `tipo` enum('reuniao','email','telefone','proposta','negociacao','outro') COLLATE utf8mb4_unicode_ci DEFAULT 'outro',
          `assunto` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `descricao` text COLLATE utf8mb4_unicode_ci,
          `data_interacao` datetime NOT NULL,
          `proximo_followup` date DEFAULT NULL,
          `user_id` int NOT NULL,
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_contacto` (`contacto_id`),
          KEY `idx_data` (`data_interacao`),
          KEY `idx_user` (`user_id`),
          CONSTRAINT `comercial_interacoes_ibfk_1` FOREIGN KEY (`contacto_id`) REFERENCES `comercial_contactos` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // === TABELAS DE GESTÃO FINANCEIRA ===
    'financeiro_categorias' => "
        CREATE TABLE `financeiro_categorias` (
          `id` int NOT NULL AUTO_INCREMENT,
          `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `tipo` enum('receita','despesa') COLLATE utf8mb4_unicode_ci NOT NULL,
          `cor` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT '#6c757d',
          `icone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `ativa` tinyint(1) DEFAULT '1',
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_nome_tipo` (`nome`,`tipo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'financeiro_transacoes' => "
        CREATE TABLE `financeiro_transacoes` (
          `id` int NOT NULL AUTO_INCREMENT,
          `tipo` enum('receita','despesa') COLLATE utf8mb4_unicode_ci NOT NULL,
          `categoria_id` int DEFAULT NULL,
          `valor` decimal(15,2) NOT NULL,
          `descricao` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `detalhes` text COLLATE utf8mb4_unicode_ci,
          `data_transacao` date NOT NULL,
          `data_vencimento` date DEFAULT NULL,
          `estado` enum('pendente','pago','cancelado') COLLATE utf8mb4_unicode_ci DEFAULT 'pago',
          `metodo_pagamento` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `referencia` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `fornecedor_cliente` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `nif` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `projeto_id` int DEFAULT NULL,
          `anexo_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `criado_por` int NOT NULL,
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `atualizado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_tipo` (`tipo`),
          KEY `idx_data` (`data_transacao`),
          KEY `idx_estado` (`estado`),
          KEY `idx_categoria` (`categoria_id`),
          CONSTRAINT `financeiro_transacoes_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `financeiro_categorias` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'financeiro_orcamentos' => "
        CREATE TABLE `financeiro_orcamentos` (
          `id` int NOT NULL AUTO_INCREMENT,
          `ano` int NOT NULL,
          `categoria_id` int NOT NULL,
          `valor_mensal` decimal(15,2) NOT NULL,
          `notas` text COLLATE utf8mb4_unicode_ci,
          `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_ano_categoria` (`ano`,`categoria_id`),
          KEY `fk_categoria` (`categoria_id`),
          CONSTRAINT `financeiro_orcamentos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `financeiro_categorias` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "
];

// Criar as tabelas
foreach ($table_definitions as $table_name => $sql) {
    echo "📦 Criando tabela: $table_name...";
    try {
        $pdo->exec($sql);
        echo " ✅\n";
        $tables_created++;
    } catch (PDOException $e) {
        echo " ❌\n";
        echo "   Erro: " . $e->getMessage() . "\n";
        $errors[] = ['table' => $table_name, 'error' => $e->getMessage()];
        $tables_failed++;
    }
}

// Reativar verificação de chaves estrangeiras
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// ========================================
// RELATÓRIO FINAL
// ========================================

echo "\n=====================================\n";
echo "📊 RELATÓRIO FINAL\n";
echo "=====================================\n\n";

echo "✅ Tabelas criadas com sucesso: $tables_created\n";
if ($tables_failed > 0) {
    echo "❌ Tabelas com erros: $tables_failed\n\n";
    echo "Detalhes dos erros:\n";
    foreach ($errors as $error) {
        echo "  - {$error['table']}: {$error['error']}\n";
    }
}

echo "\n";

if ($tables_failed == 0) {
    echo "🎉 Base de dados configurada com sucesso!\n";
    echo "\nPróximos passos:\n";
    echo "1. Configure o ficheiro config.php com estas credenciais\n";
    echo "2. Execute admin_permissions.sql para criar o primeiro admin\n";
    echo "3. (Opcional) Execute auth_local_tables.sql para login local\n";
} else {
    echo "⚠️  Algumas tabelas não foram criadas.\n";
    echo "Verifique os erros acima e corrija-os.\n";
}

echo "\n=====================================\n";

// ========================================
// VERIFICAÇÃO FINAL
// ========================================

echo "\n🔍 Verificando estrutura criada...\n\n";

try {
    $stmt = $pdo->query("SHOW TABLES");
    $created_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($created_tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "  - $table: $count registos\n";
    }
    
    echo "\n✅ Verificação concluída!\n";
} catch (PDOException $e) {
    echo "❌ Erro na verificação: " . $e->getMessage() . "\n";
}