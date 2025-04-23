<?php
// Configurar conexão com a base de dados SQLite
$dbFile = __DIR__ . '/database/content.db';
$isNewDB = !file_exists($dbFile);

// Garantir que a pasta da base de dados existe
if (!file_exists(dirname($dbFile))) {
    mkdir(dirname($dbFile), 0755, true);
}

// Definir layout padrão e salvar preferências
$displayMode = isset($_GET['display_mode']) ? $_GET['display_mode'] : (isset($_COOKIE['display_mode']) ? $_COOKIE['display_mode'] : 'dual');
setcookie('display_mode', $displayMode, time() + (86400 * 30), "/"); // Cookie válido por 30 dias

try {
    $db = new SQLite3($dbFile);
    $db->enableExceptions(true);

    // Criar tabela se não existir
    if ($isNewDB) {
        $db->exec('
            CREATE TABLE IF NOT EXISTS content (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                url TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_shown DATETIME,
                active INTEGER DEFAULT 1
            );
            
            CREATE TABLE IF NOT EXISTS notices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                text TEXT NOT NULL,
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                added_by TEXT,
                priority INTEGER DEFAULT 0,
                active INTEGER DEFAULT 1
            );
        ');
        
        // Adicionar alguns exemplos para demonstração
        $db->exec("
            INSERT INTO content (type, url, title) VALUES 
            ('youtube', 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'Rick Astley - Never Gonna Give You Up'),
            ('youtube', 'https://www.youtube.com/embed/jNQXAC9IVRw', 'Me at the zoo'),
            ('youtube', 'https://www.youtube.com/embed/9bZkp7q19f0', 'PSY - GANGNAM STYLE'),
            ('youtube', 'https://www.youtube.com/embed/kJQP7kiw5Fk', 'Luis Fonsi - Despacito ft. Daddy Yankee'),
            ('linkedin', 'https://www.linkedin.com/embed/feed/update/urn:li:share:6866123456789012345', 'Post do LinkedIn Exemplo 1'),
            ('linkedin', 'https://www.linkedin.com/embed/feed/update/urn:li:share:6866987654321098765', 'Post do LinkedIn Exemplo 2'),
            ('linkedin', 'https://www.linkedin.com/embed/feed/update/urn:li:share:6866123456789054321', 'Post do LinkedIn Exemplo 3'),
            ('linkedin', 'https://www.linkedin.com/embed/feed/update/urn:li:share:6866987654321012345', 'Post do LinkedIn Exemplo 4');
            
            INSERT INTO notices (text, added_by, priority) VALUES
            ('Bem-vindo ao novo dashboard! Aqui você pode visualizar conteúdos de YouTube e LinkedIn.', 'Admin', 1),
            ('Reunião semanal da equipe amanhã às 10:00.', 'Gerente', 2),
            ('Nova versão do sistema será lançada na próxima sexta-feira.', 'Desenvolvimento', 1)
        ");
    }
    // Verificar se a tabela de avisos está vazia e adicionar um aviso padrão
    $result = $db->query('SELECT COUNT(*) as count FROM notices');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row['count'] == 0) {
        $db->exec("
            INSERT INTO notices (text, added_by, priority) VALUES
            ('Bem-vindo ao novo dashboard com sistema de avisos! Clique no botão + para adicionar novos avisos.', 'Sistema', 1)
        ");
    }
    // Processar operações CRUD para conteúdo
    $message = '';
    
    // Adicionar novo conteúdo
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $type = $_POST['type'] ?? '';
        $url = $_POST['url'] ?? '';
        $title = $_POST['title'] ?? '';
        
        if (!empty($type) && !empty($url) && !empty($title)) {
            // Formatar a URL corretamente
            if ($type === 'youtube') {
                // Converter URL normal para URL de embed
                if (strpos($url, 'youtube.com/watch?v=') !== false) {
                    $videoId = substr($url, strpos($url, 'v=') + 2);
                    if (strpos($videoId, '&') !== false) {
                        $videoId = substr($videoId, 0, strpos($videoId, '&'));
                    }
                    $url = "https://www.youtube.com/embed/$videoId";
                } elseif (strpos($url, 'youtu.be/') !== false) {
                    $videoId = substr($url, strrpos($url, '/') + 1);
                    $url = "https://www.youtube.com/embed/$videoId";
                }
            } elseif ($type === 'linkedin') {
                // Verificar se a URL já está no formato de embed
                if (strpos($url, 'linkedin.com/embed') === false && strpos($url, 'urn:li:share:') === false) {
                    // Extrair o ID do post se possível
                    if (preg_match('/activity-(\d+)/', $url, $matches)) {
                        $url = "https://www.linkedin.com/embed/feed/update/urn:li:activity:" . $matches[1];
                    }
                }
            }
            
            try {
                $stmt = $db->prepare('INSERT INTO content (type, url, title) VALUES (:type, :url, :title)');
                $stmt->bindValue(':type', $type, SQLITE3_TEXT);
                $stmt->bindValue(':url', $url, SQLITE3_TEXT);
                $stmt->bindValue(':title', $title, SQLITE3_TEXT);
                $stmt->execute();
                $message = "Novo conteúdo adicionado com sucesso!";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                    $message = "Erro: Este URL já existe na base de dados.";
                } else {
                    $message = "Erro ao adicionar: " . $e->getMessage();
                }
            }
        } else {
            $message = "Erro: Todos os campos são obrigatórios.";
        }
    }
    
    // Remover conteúdo
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        $stmt = $db->prepare('DELETE FROM content WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        $message = "Conteúdo removido com sucesso!";
    }
    
    // Ativar/Desativar conteúdo
    if (isset($_POST['action']) && $_POST['action'] === 'toggle' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        $active = isset($_POST['active']) ? 1 : 0;
        $stmt = $db->prepare('UPDATE content SET active = :active WHERE id = :id');
        $stmt->bindValue(':active', $active, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        $message = "Status alterado com sucesso!";
    }
    
    // Processar operações CRUD para avisos
    
    // Adicionar novo aviso
    if (isset($_POST['action']) && $_POST['action'] === 'add_notice') {
        $text = $_POST['notice_text'] ?? '';
        $added_by = $_POST['notice_by'] ?? 'Usuário';
        $priority = intval($_POST['notice_priority'] ?? 0);
        
        if (!empty($text)) {
            try {
                $stmt = $db->prepare('INSERT INTO notices (text, added_by, priority) VALUES (:text, :added_by, :priority)');
                $stmt->bindValue(':text', $text, SQLITE3_TEXT);
                $stmt->bindValue(':added_by', $added_by, SQLITE3_TEXT);
                $stmt->bindValue(':priority', $priority, SQLITE3_INTEGER);
                $stmt->execute();
                $message = "Novo aviso adicionado com sucesso!";
            } catch (Exception $e) {
                $message = "Erro ao adicionar aviso: " . $e->getMessage();
            }
        } else {
            $message = "Erro: O texto do aviso é obrigatório.";
        }
    }
    
    // Remover aviso
    if (isset($_POST['action']) && $_POST['action'] === 'delete_notice' && isset($_POST['notice_id'])) {
        $id = intval($_POST['notice_id']);
        $stmt = $db->prepare('DELETE FROM notices WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        $message = "Aviso removido com sucesso!";
    }
    
    // Função para obter conteúdo aleatório por tipo
    function getRandomContent($db, $type) {
        $stmt = $db->prepare('
            SELECT * FROM content 
            WHERE active = 1 AND type = :type
            ORDER BY RANDOM() 
            LIMIT 1
        ');
        $stmt->bindValue(':type', $type, SQLITE3_TEXT);
        $result = $stmt->execute();
        $content = $result->fetchArray(SQLITE3_ASSOC);
        
        // Atualizar última exibição
        if ($content) {
            $stmt = $db->prepare('UPDATE content SET last_shown = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->bindValue(':id', $content['id'], SQLITE3_INTEGER);
            $stmt->execute();
        }
        
        return $content;
    }
    
    // Função para obter múltiplos conteúdos aleatórios por tipo
    function getMultipleRandomContent($db, $type, $count) {
        $contents = [];
        $stmt = $db->prepare('
            SELECT * FROM content 
            WHERE active = 1 AND type = :type
            ORDER BY RANDOM() 
            LIMIT :count
        ');
        $stmt->bindValue(':type', $type, SQLITE3_TEXT);
        $stmt->bindValue(':count', $count, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $contents[] = $row;
            
            // Atualizar última exibição
            $updateStmt = $db->prepare('UPDATE content SET last_shown = CURRENT_TIMESTAMP WHERE id = :id');
            $updateStmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $updateStmt->execute();
        }
        
        return $contents;
    }
    
    // Buscar avisos ativos
    $notices = [];
    $stmt = $db->prepare('SELECT * FROM notices WHERE active = 1 ORDER BY priority DESC, added_at DESC');
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $notices[] = $row;
    }
    
    // Buscar conteúdo com base no modo de exibição selecionado
    switch ($displayMode) {
        case 'quad':
            $youtubeContents = getMultipleRandomContent($db, 'youtube', 2);
            $linkedinContents = getMultipleRandomContent($db, 'linkedin', 2);
            $content = null; // Não será usado no modo quad
            $youtubeContent = null;
            $linkedinContent = null;
            break;
            
        case 'single':
            // Selecionar conteúdo aleatório para exibir (apenas ativos)
            $stmt = $db->prepare('
                SELECT * FROM content 
                WHERE active = 1 
                ORDER BY RANDOM() 
                LIMIT 1
            ');
            $result = $stmt->execute();
            $content = $result->fetchArray(SQLITE3_ASSOC);
            
            // Atualizar última exibição
            if ($content) {
                $stmt = $db->prepare('UPDATE content SET last_shown = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->bindValue(':id', $content['id'], SQLITE3_INTEGER);
                $stmt->execute();
            }
            
            $youtubeContent = null;
            $linkedinContent = null;
            $youtubeContents = null;
            $linkedinContents = null;
            break;
            
        case 'dual':
        default:
            $youtubeContent = getRandomContent($db, 'youtube');
            $linkedinContent = getRandomContent($db, 'linkedin');
            $content = null; // Não será usado no modo dual
            $youtubeContents = null;
            $linkedinContents = null;
            break;
    }
    
    // Buscar todos os conteúdos para a tabela de gestão
    $allContent = [];
    $result = $db->query('SELECT * FROM content ORDER BY added_at DESC');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $allContent[] = $row;
    }
    
} catch (Exception $e) {
    echo "Erro na base de dados: " . $e->getMessage();
    exit;
}

// Função para renderizar um frame de conteúdo
function renderContentFrame($content, $height = 450) {
    if (!$content) return '<div class="content-frame" style="display: flex; align-items: center; justify-content: center; background-color: #f8f9fa; height: '.$height.'px;">
        <p>Nenhum conteúdo disponível.</p>
    </div>';
    
    $html = '<div class="content-info">
        <div>
            <span class="content-title">'.htmlspecialchars($content['title']).'</span>
            <span class="content-type '.htmlspecialchars($content['type']).'">
                '.htmlspecialchars($content['type']).'
            </span>
        </div>
    </div>';
    
    if ($content['type'] === 'youtube') {
        $html .= '<iframe 
            class="content-frame"
            src="'.htmlspecialchars($content['url']).'?autoplay=1&mute=1"
            title="'.htmlspecialchars($content['title']).'"
            frameborder="0"
            style="height: '.$height.'px;"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen>
        </iframe>';
    } elseif ($content['type'] === 'linkedin') {
        $html .= '<iframe 
            class="content-frame"
            src="'.htmlspecialchars($content['url']).'"
            title="'.htmlspecialchars($content['title']).'"
            frameborder="0"
            style="height: '.$height.'px;"
            allowfullscreen>
        </iframe>';
    } else {
        $html .= '<div class="content-frame" style="display: flex; align-items: center; justify-content: center; background-color: #f8f9fa; height: '.$height.'px;">
            <p>Tipo de conteúdo não suportado.</p>
        </div>';
    }
    
    return $html;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Conteúdo Multimídia</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f9f9f9;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .page-title {
            font-size: 1.4rem;
            margin: 0;
            color: #666;
            font-weight: 400;
        }
        
        .content-container {
            width: 100%;
            margin: 0 auto 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            background: white;
            overflow: hidden;
        }
        
        .content-frame {
            width: 100%;
            border: none;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .content-info {
            padding: 10px 15px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .content-title {
            font-size: 1.1em;
            font-weight: bold;
            margin-right: 10px;
        }
        
        .content-type {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75em;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .youtube {
            background-color: #FF0000;
            color: white;
        }
        
        .linkedin {
            background-color: #0077B5;
            color: white;
        }
        
        .management-section {
            margin-top: 30px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            background-color: #f9f9f9;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .btn-primary {
            background-color: #0d6efd;
            color: white;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-circle {
            width: 34px;
            height: 34px;
            padding: 6px 0;
            border-radius: 17px;
            text-align: center;
            font-size: 16px;
            line-height: 1.42;
            margin-left: 8px;
        }
        
        .btn-settings {
            color: #666;
            background-color: transparent;
            border: none;
            font-size: 1.2rem;
            transition: color 0.2s;
            padding: 5px;
        }
        
        .btn-settings:hover {
            color: #333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        table th, table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        table th {
            background-color: #f2f2f2;
        }
        
        .alert {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #2196F3;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .countdown {
            font-size: 1.2em;
            font-weight: bold;
            margin-left: 15px;
        }
        
        .display-options {
            background-color: #f0f8ff;
            border: 1px solid #d0e5ff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            display: none;
        }
        
        .display-option {
            display: inline-block;
            margin-right: 20px;
            padding: 8px 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background-color: white;
            cursor: pointer;
        }
        
        .display-option:hover {
            background-color: #f0f0f0;
        }
        
        .display-option input {
            margin-right: 5px;
        }
        
        .display-option-selected {
            border-color: #007bff;
            background-color: #e6f2ff;
        }
        
        .row-cols-2 > * {
            padding: 10px;
        }
        
        .notices-container {
            background-color: #fffcf5;
            border: 1px solid #ffe8a8;
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 20px;
            position: relative;
            max-height: 100px;
            overflow: hidden;
        }
        
        .notices-title {
            font-weight: bold;
            margin-bottom: 5px;
            color: #856404;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notices-content {
            height: 60px;
            overflow: hidden;
            position: relative;
        }
        
        .notices-scroller {
            position: absolute;
            width: 100%;
            animation: scroll-y 15s linear infinite;
            padding-right: 15px;
        }
        
        .notice-item {
            padding: 5px 0;
            border-bottom: 1px dotted #ffe8a8;
        }
        
        .notice-item:last-child {
            border-bottom: none;
        }
        
        .notice-text {
            font-size: 0.95em;
        }
        
        .notice-meta {
            font-size: 0.75em;
            color: #856404;
            opacity: 0.7;
        }
        
        .notice-actions {
            position: absolute;
            right: 10px;
            top: 10px;
            z-index: 100;
        }
        
        @keyframes scroll-y {
            0% { top: 0; }
            100% { top: -100%; }
        }
        
        .notice-form {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: none;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="header-container">
            <h1 class="page-title">Dashboard</h1>
            <div>
                <button type="button" class="btn-settings" id="settings-btn" title="Configurações de exibição">
                    <i class="bi bi-gear"></i>
                </button>
            </div>
        </div>
        
        <div id="display-options" class="display-options">
            <h5 class="mb-3">Modo de Exibição:</h5>
            <form id="displayForm" method="get" action="">
                <label class="display-option <?= $displayMode === 'single' ? 'display-option-selected' : '' ?>">
                    <input type="radio" name="display_mode" value="single" <?= $displayMode === 'single' ? 'checked' : '' ?> onchange="this.form.submit()">
                    Um bloco aleatório
                </label>
                <label class="display-option <?= $displayMode === 'dual' ? 'display-option-selected' : '' ?>">
                    <input type="radio" name="display_mode" value="dual" <?= $displayMode === 'dual' ? 'checked' : '' ?> onchange="this.form.submit()">
                    Dois blocos lado a lado
                </label>
                <label class="display-option <?= $displayMode === 'quad' ? 'display-option-selected' : '' ?>">
                    <input type="radio" name="display_mode" value="quad" <?= $displayMode === 'quad' ? 'checked' : '' ?> onchange="this.form.submit()">
                    Quatro blocos (2x2)
                </label>
            </form>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert <?= strpos($message, 'Erro') !== false ? 'alert-danger' : 'alert-success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <div class="notices-container">
            <div class="notices-title">
                <span>Avisos</span>
                <button type="button" class="btn btn-sm btn-warning btn-circle" id="add-notice-btn">
                    <i class="bi bi-plus"></i>
                </button>
            </div>
            
            <div class="notices-content">
                <?php if (!empty($notices)): ?>
                <div class="notices-scroller">
                    <?php foreach ($notices as $notice): ?>
                    <div class="notice-item">
                        <div class="notice-text"><?= htmlspecialchars($notice['text']) ?></div>
                        <div class="notice-meta">
                            Por: <?= htmlspecialchars($notice['added_by']) ?> | 
                            <?= date('d/m/Y H:i', strtotime($notice['added_at'])) ?>
                            
                            <form method="post" action="" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir este aviso?');">
                                <input type="hidden" name="action" value="delete_notice">
                                <input type="hidden" name="notice_id" value="<?= $notice['id'] ?>">
                                <button type="submit" class="btn btn-sm text-danger border-0 p-0 ms-2">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Repetir os mesmos avisos para scroll contínuo -->
                    <?php foreach ($notices as $notice): ?>
                    <div class="notice-item">
                        <div class="notice-text"><?= htmlspecialchars($notice['text']) ?></div>
                        <div class="notice-meta">
                            Por: <?= htmlspecialchars($notice['added_by']) ?> | 
                            <?= date('d/m/Y H:i', strtotime($notice['added_at'])) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="p-3 text-center">
                    <em>Não há avisos no momento.</em>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="notice-form" id="notice-form">
                <form method="post" action="">
                    <div class="mb-3">
                        <label for="notice_text" class="form-label">Texto do aviso:</label>
                        <textarea class="form-control" id="notice_text" name="notice_text" rows="2" required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="notice_by" class="form-label">Autor:</label>
                                <input type="text" class="form-control" id="notice_by" name="notice_by" value="Usuário">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="notice_priority" class="form-label">Prioridade:</label>
                                <select class="form-select" id="notice_priority" name="notice_priority">
                                    <option value="0">Normal</option>
                                    <option value="1">Importante</option>
                                    <option value="2">Urgente</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="action" value="add_notice">
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary btn-sm" id="cancel-notice-btn">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm">Adicionar</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($displayMode === 'single' && $content): ?>
            <div class="content-container">
                <div class="content-info">
                    <div>
                        <span class="content-title"><?= htmlspecialchars($content['title']) ?></span>
                        <span class="content-type <?= htmlspecialchars($content['type']) ?>">
                            <?= htmlspecialchars($content['type']) ?>
                        </span>
                    </div>
                    <span class="countdown" id="countdown">60</span>
                </div>
                
                <?php if ($content['type'] === 'youtube'): ?>
                    <iframe 
                        class="content-frame"
                        src="<?= htmlspecialchars($content['url']) ?>?autoplay=1&mute=1"
                        title="<?= htmlspecialchars($content['title']) ?>"
                        height="450"
                        frameborder="0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen>
                    </iframe>
                <?php elseif ($content['type'] === 'linkedin'): ?>
                    <iframe 
                        class="content-frame"
                        src="<?= htmlspecialchars($content['url']) ?>"
                        title="<?= htmlspecialchars($content['title']) ?>"
                        height="450"
                        frameborder="0"
                        allowfullscreen>
                    </iframe>
                <?php else: ?>
                    <div class="content-frame" style="display: flex; align-items: center; justify-content: center; background-color: #f8f9fa; height: 450px;">
                        <p>Tipo de conteúdo não suportado.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($displayMode === 'dual'): ?>
            <div class="row row-cols-2">
                <div class="col">
                    <div class="content-container h-100">
                        <?= renderContentFrame($youtubeContent, 400) ?>
                    </div>
                </div>
                <div class="col">
                    <div class="content-container h-100">
                        <?= renderContentFrame($linkedinContent, 400) ?>
                    </div>
                </div>
            </div>
            <div class="text-center mt-2">
                <span class="countdown" id="countdown">60</span>
            </div>
        <?php elseif ($displayMode === 'quad'): ?>
            <div class="row row-cols-2">
                <?php foreach ($youtubeContents as $ytContent): ?>
                <div class="col mb-3">
                    <div class="content-container h-100">
                        <?= renderContentFrame($ytContent, 350) ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php foreach ($linkedinContents as $liContent): ?>
                <div class="col mb-3">
                    <div class="content-container h-100">
                        <?= renderContentFrame($liContent, 350) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-center mt-2">
                <span class="countdown" id="countdown">60</span>
            </div>
        <?php else: ?>
            <div class="content-container">
                <div style="display: flex; align-items: center; justify-content: center; height: 450px; background-color: #f8f9fa;">
                    <p>Nenhum conteúdo disponível. Adicione conteúdo abaixo.</p>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="management-section">
            <h2>Gerenciar Conteúdo</h2>
            
            <form method="post" action="">
                <div style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="type">Tipo:</label>
                        <select name="type" id="type" required>
                            <option value="">Selecione...</option>
                            <option value="youtube">YouTube</option>
                            <option value="linkedin">LinkedIn</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="flex: 2;">
                        <label for="url">URL:</label>
                        <input type="url" name="url" id="url" required placeholder="https://...">
                    </div>
                    
                    <div class="form-group" style="flex: 2;">
                        <label for="title">Título:</label>
                        <input type="text" name="title" id="title" required>
                    </div>
                </div>
                
                <input type="hidden" name="action" value="add">
                <button type="submit" class="btn btn-primary">Adicionar Conteúdo</button>
            </form>
            
            <h3 class="mt-4">Conteúdo Disponível</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo</th>
                        <th>Título</th>
                        <th>URL</th>
                        <th>Adicionado em</th>
                        <th>Última exibição</th>
                        <th>Ativo</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allContent as $item): ?>
                        <tr>
                            <td><?= $item['id'] ?></td>
                            <td>
                                <span class="content-type <?= htmlspecialchars($item['type']) ?>">
                                    <?= htmlspecialchars($item['type']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($item['title']) ?></td>
                            <td>
                                <a href="<?= htmlspecialchars($item['url']) ?>" target="_blank">
                                    <?= htmlspecialchars(substr($item['url'], 0, 30)) ?>...
                                </a>
                            </td>
                            <td><?= $item['added_at'] ?></td>
                            <td><?= $item['last_shown'] ?: 'Nunca' ?></td>
                            <td>
                                <form method="post" action="" class="toggle-form">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="active" value="1" <?= $item['active'] ? 'checked' : '' ?> 
                                            onchange="this.form.submit()">
                                        <span class="slider"></span>
                                    </label>
                                </form>
                            </td>
                            <td>
                                <form method="post" action="" onsubmit="return confirm('Tem certeza que deseja excluir este item?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <button type="submit" class="btn btn-danger">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    // Substitua a parte do script no final do arquivo dashboard.php com este código:

<script>
    // Contador regressivo
    let countdown = 60;
    const countdownElement = document.getElementById('countdown');
    
    if (countdownElement) {
        const timer = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                window.location.reload(); // Recarregar para exibir próximo conteúdo
            }
        }, 1000);
    }
    
    // Botão de configurações
    const settingsBtn = document.getElementById('settings-btn');
    if (settingsBtn) {
        settingsBtn.addEventListener('click', function() {
            const displayOptions = document.getElementById('display-options');
            if (displayOptions.style.display === 'none' || displayOptions.style.display === '') {
                displayOptions.style.display = 'block';
            } else {
                displayOptions.style.display = 'none';
            }
        });
    }
    
    // Estilização para opções de exibição
    document.querySelectorAll('.display-option').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.display-option').forEach(opt => {
                opt.classList.remove('display-option-selected');
            });
            this.classList.add('display-option-selected');
            this.querySelector('input').checked = true;
        });
    });
    
    // Controles para formulário de avisos
    const addNoticeBtn = document.getElementById('add-notice-btn');
    if (addNoticeBtn) {
        addNoticeBtn.addEventListener('click', function() {
            const noticeForm = document.getElementById('notice-form');
            if (noticeForm) {
                noticeForm.style.display = 'block';
            }
        });
    }
    
    const cancelNoticeBtn = document.getElementById('cancel-notice-btn');
    if (cancelNoticeBtn) {
        cancelNoticeBtn.addEventListener('click', function() {
            const noticeForm = document.getElementById('notice-form');
            if (noticeForm) {
                noticeForm.style.display = 'none';
            }
        });
    }
    
    // Ajuda com extração de ID para YouTube
    const urlInput = document.getElementById('url');
    const typeSelect = document.getElementById('type');
    
    if (urlInput && typeSelect) {
        typeSelect.addEventListener('change', function() {
            const selectedType = this.value;
            if (selectedType === 'youtube') {
                urlInput.placeholder = "https://www.youtube.com/watch?v=VIDEOID ou https://youtu.be/VIDEOID";
            } else if (selectedType === 'linkedin') {
                urlInput.placeholder = "https://www.linkedin.com/posts/... ou https://www.linkedin.com/feed/update/...";
            } else {
                urlInput.placeholder = "https://...";
            }
        });
    }
    
    // Ajusta a velocidade da animação com base na quantidade de avisos
    function adjustScrollSpeed() {
        const noticesScroller = document.querySelector('.notices-scroller');
        if (noticesScroller) {
            const noticeItems = document.querySelectorAll('.notice-item');
            if (noticeItems.length > 0) {
                // Calcular a altura total do conteúdo
                let totalHeight = 0;
                noticeItems.forEach(item => {
                    totalHeight += item.offsetHeight;
                });
                
                // Ajustar a duração da animação baseado na quantidade de conteúdo
                // Metade dos avisos (porque repetimos para scroll contínuo)
                const uniqueNotices = Math.max(1, noticeItems.length / 2);
                const duration = Math.max(10, uniqueNotices * 5); // Mínimo 10s, 5s por aviso
                
                noticesScroller.style.animationDuration = duration + 's';
            }
        }
    }
    
    // Executar quando a página estiver carregada
    window.addEventListener('load', adjustScrollSpeed);
    
    // Verificar se os botões estão presentes e exibir mensagem no console para debug
    console.log('Botão de adicionar aviso:', addNoticeBtn ? 'encontrado' : 'não encontrado');
    console.log('Botão de cancelar aviso:', cancelNoticeBtn ? 'encontrado' : 'não encontrado');
    console.log('Formulário de aviso:', document.getElementById('notice-form') ? 'encontrado' : 'não encontrado');

    // Garantir que o formulário de avisos esteja inicialmente oculto
    const noticeForm = document.getElementById('notice-form');
    if (noticeForm) {
        noticeForm.style.display = 'none';
    }
</script>
</body>
</html>