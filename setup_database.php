<?php
/**
 * setup_database.php
 * 
 * Script de configuraÃ§Ã£o inicial da base de dados PikachuPM
 * Gerado automaticamente em: 2025-12-22 23:35:42
 * 
 * COMO USAR:
 * 1. Crie uma base de dados vazia no MySQL
 * 2. Configure as credenciais abaixo
 * 3. Execute: php setup_database.php
 * 4. A estrutura completa serÃ¡ criada
 * 
 * ATENÃ‡ÃƒO: Este script NÃƒO contÃ©m dados, apenas a estrutura das tabelas
 */

// ========================================
// CONFIGURAÃ‡ÃƒO
// ========================================

$db_host = 'localhost';
$db_name = 'pikachupm_new';  // Nome da nova base de dados
$db_user = 'root';            // Utilizador MySQL
$db_pass = '';                // Password MySQL

// ========================================
// ESTATÃSTICAS DA BD ORIGINAL
// ========================================

/*
Total de tabelas: 46

Tabelas encontradas:
- admin_users (5 colunas, 2 registos na BD original)
- calendar_eventos (9 colunas, 931 registos na BD original)
- daily_focus (5 colunas, 0 registos na BD original)
- daily_reflections (5 colunas, 0 registos na BD original)
- daily_reports (10 colunas, 18 registos na BD original)
- deliverable_sprints (4 colunas, 5 registos na BD original)
- deliverable_tasks (4 colunas, 6 registos na BD original)
- lab_authorized_users (5 colunas, 4 registos na BD original)
- lab_issue_attachments (7 colunas, 0 registos na BD original)
- lab_issue_participants (5 colunas, 13 registos na BD original)
- lab_issue_tasks (4 colunas, 1 registos na BD original)
- lab_issue_updates (6 colunas, 2 registos na BD original)
- lab_issues (10 colunas, 13 registos na BD original)
- lead_kanban (6 colunas, 1 registos na BD original)
- lead_links (5 colunas, 14 registos na BD original)
- lead_members (4 colunas, 13 registos na BD original)
- lead_tasks (6 colunas, 15 registos na BD original)
- leads (10 colunas, 12 registos na BD original)
- login_attempts (5 colunas, 8 registos na BD original)
- phd_artigos (10 colunas, 59 registos na BD original)
- phd_info (12 colunas, 14 registos na BD original)
- project_deliverables (8 colunas, 55 registos na BD original)
- project_files (7 colunas, 4 registos na BD original)
- project_links (5 colunas, 23 registos na BD original)
- project_members (5 colunas, 54 registos na BD original)
- project_prototypes (4 colunas, 16 registos na BD original)
- projects (7 colunas, 17 registos na BD original)
- prototype_members (5 colunas, 110 registos na BD original)
- prototype_participants (6 colunas, 0 registos na BD original)
- prototypes (20 colunas, 60 registos na BD original)
- research_idea_interested (5 colunas, 0 registos na BD original)
- research_idea_links (7 colunas, 0 registos na BD original)
- research_ideas (8 colunas, 3 registos na BD original)
- sprint_members (5 colunas, 157 registos na BD original)
- sprint_projects (4 colunas, 28 registos na BD original)
- sprint_prototypes (4 colunas, 64 registos na BD original)
- sprint_tasks (5 colunas, 519 registos na BD original)
- sprints (9 colunas, 63 registos na BD original)
- story_tasks (4 colunas, 21 registos na BD original)
- task_checklist (6 colunas, 459 registos na BD original)
- task_files (8 colunas, 86 registos na BD original)
- todos (14 colunas, 917 registos na BD original)
- user_stories (9 colunas, 383 registos na BD original)
- user_story_sprints (4 colunas, 72 registos na BD original)
- user_story_tasks (4 colunas, 17 registos na BD original)
- user_tokens (13 colunas, 36 registos na BD original)

*/

// ========================================
// CONEXÃƒO
// ========================================

echo "ðŸ”§ PikachuPM - Setup da Base de Dados\n";
echo "=====================================\n\n";

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "âœ… Conectado Ã  base de dados '$db_name'\n\n";
} catch (PDOException $e) {
    die("âŒ Erro de conexÃ£o: " . $e->getMessage() . "\n\nVerifique se:\n1. A base de dados existe\n2. As credenciais estÃ£o corretas\n3. O MySQL estÃ¡ a correr\n");
}

// Desativar verificaÃ§Ã£o de chaves estrangeiras temporariamente
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// ========================================
// CRIAR TABELAS
// ========================================

$tables_created = 0;
$tables_failed = 0;

// Tabela: admin_users
echo "ðŸ“¦ Criando tabela: admin_users...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: calendar_eventos
echo "ðŸ“¦ Criando tabela: calendar_eventos...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: daily_focus
echo "ðŸ“¦ Criando tabela: daily_focus...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: daily_reflections
echo "ðŸ“¦ Criando tabela: daily_reflections...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: daily_reports
echo "ðŸ“¦ Criando tabela: daily_reports...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: deliverable_sprints
echo "ðŸ“¦ Criando tabela: deliverable_sprints...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: deliverable_tasks
echo "ðŸ“¦ Criando tabela: deliverable_tasks...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lab_authorized_users
echo "ðŸ“¦ Criando tabela: lab_authorized_users...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lab_issue_attachments
echo "ðŸ“¦ Criando tabela: lab_issue_attachments...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lab_issue_participants
echo "ðŸ“¦ Criando tabela: lab_issue_participants...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lab_issue_tasks
echo "ðŸ“¦ Criando tabela: lab_issue_tasks...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lab_issue_updates
echo "ðŸ“¦ Criando tabela: lab_issue_updates...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lab_issues
echo "ðŸ“¦ Criando tabela: lab_issues...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lead_kanban
echo "ðŸ“¦ Criando tabela: lead_kanban...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lead_links
echo "ðŸ“¦ Criando tabela: lead_links...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lead_members
echo "ðŸ“¦ Criando tabela: lead_members...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: lead_tasks
echo "ðŸ“¦ Criando tabela: lead_tasks...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: leads
echo "ðŸ“¦ Criando tabela: leads...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: login_attempts
echo "ðŸ“¦ Criando tabela: login_attempts...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: phd_artigos
echo "ðŸ“¦ Criando tabela: phd_artigos...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: phd_info
echo "ðŸ“¦ Criando tabela: phd_info...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: project_deliverables
echo "ðŸ“¦ Criando tabela: project_deliverables...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: project_files
echo "ðŸ“¦ Criando tabela: project_files...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: project_links
echo "ðŸ“¦ Criando tabela: project_links...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: project_members
echo "ðŸ“¦ Criando tabela: project_members...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: project_prototypes
echo "ðŸ“¦ Criando tabela: project_prototypes...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: projects
echo "ðŸ“¦ Criando tabela: projects...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: prototype_members
echo "ðŸ“¦ Criando tabela: prototype_members...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: prototype_participants
echo "ðŸ“¦ Criando tabela: prototype_participants...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: prototypes
echo "ðŸ“¦ Criando tabela: prototypes...";
try {
    $pdo->exec("
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
          `responsible` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nome do responsÃ¡vel pelo protÃ³tipo',
          `participants` text COLLATE utf8mb4_unicode_ci COMMENT 'Array JSON com lista de participantes',
          PRIMARY KEY (`id`),
          KEY `idx_short_name` (`short_name`),
          KEY `idx_title` (`title`),
          KEY `idx_responsible` (`responsible`),
          KEY `idx_parent_id` (`parent_id`)
        ) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: research_idea_interested
echo "ðŸ“¦ Criando tabela: research_idea_interested...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: research_idea_links
echo "ðŸ“¦ Criando tabela: research_idea_links...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: research_ideas
echo "ðŸ“¦ Criando tabela: research_ideas...";
try {
    $pdo->exec("
        CREATE TABLE `research_ideas` (
          `id` int NOT NULL AUTO_INCREMENT,
          `title` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
          `description` text COLLATE utf8mb4_unicode_ci,
          `author` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `status` enum('nova','em anÃ¡lise','aprovada','arquivada') COLLATE utf8mb4_unicode_ci DEFAULT 'nova',
          `priority` enum('baixa','normal','alta','urgente') COLLATE utf8mb4_unicode_ci DEFAULT 'normal',
          PRIMARY KEY (`id`),
          KEY `idx_author` (`author`),
          KEY `idx_status` (`status`),
          KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: sprint_members
echo "ðŸ“¦ Criando tabela: sprint_members...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: sprint_projects
echo "ðŸ“¦ Criando tabela: sprint_projects...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: sprint_prototypes
echo "ðŸ“¦ Criando tabela: sprint_prototypes...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: sprint_tasks
echo "ðŸ“¦ Criando tabela: sprint_tasks...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: sprints
echo "ðŸ“¦ Criando tabela: sprints...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: story_tasks
echo "ðŸ“¦ Criando tabela: story_tasks...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: task_checklist
echo "ðŸ“¦ Criando tabela: task_checklist...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: task_files
echo "ðŸ“¦ Criando tabela: task_files...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: todos
echo "ðŸ“¦ Criando tabela: todos...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: user_stories
echo "ðŸ“¦ Criando tabela: user_stories...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: user_story_sprints
echo "ðŸ“¦ Criando tabela: user_story_sprints...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: user_story_tasks
echo "ðŸ“¦ Criando tabela: user_story_tasks...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}

// Tabela: user_tokens
echo "ðŸ“¦ Criando tabela: user_tokens...";
try {
    $pdo->exec("
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
    ");
    echo " âœ…\n";
    $tables_created++;
} catch (PDOException $e) {
    echo " âŒ\n";
    echo "   Erro: " . $e->getMessage() . "\n";
    $tables_failed++;
}


// Reativar verificaÃ§Ã£o de chaves estrangeiras
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// ========================================
// RELATÃ“RIO FINAL
// ========================================

echo "\n=====================================\n";
echo "ðŸ“Š RELATÃ“RIO FINAL\n";
echo "=====================================\n\n";

echo "âœ… Tabelas criadas com sucesso: $tables_created\n";
if ($tables_failed > 0) {
    echo "âŒ Tabelas com erros: $tables_failed\n";
}

echo "\n";

if ($tables_failed == 0) {
    echo "ðŸŽ‰ Base de dados configurada com sucesso!\n";
    echo "\nPrÃ³ximos passos:\n";
    echo "1. Configure o ficheiro config.php com estas credenciais\n";
    echo "2. Execute admin_permissions.sql para criar o primeiro admin\n";
    echo "3. (Opcional) Execute auth_local_tables.sql para login local\n";
} else {
    echo "âš ï¸  Algumas tabelas nÃ£o foram criadas.\n";
    echo "Verifique os erros acima e corrija-os.\n";
}

echo "\n=====================================\n";

// ========================================
// VERIFICAÃ‡ÃƒO FINAL
// ========================================

echo "\nðŸ” Verificando estrutura criada...\n\n";

$stmt = $pdo->query("SHOW TABLES");
$created_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($created_tables as $table) {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table`");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "  - $table: $count registos\n";
}

echo "\nâœ… VerificaÃ§Ã£o concluÃ­da!\n";