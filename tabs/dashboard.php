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

    // Criar tabela de conteúdo se não existir
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
    ');
    
    // Criar tabela de avisos se não existir
    $db->exec('
        CREATE TABLE IF NOT EXISTS notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            text TEXT NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            added_by TEXT,
            priority INTEGER DEFAULT 0,
            active INTEGER DEFAULT 1
        );
    ');

    // Adicionar exemplos apenas se o banco de dados for novo
    if ($isNewDB) {
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
            ('linkedin', 'https://www.linkedin.com/embed/feed/update/urn:li:share:6866987654321012345', 'Post do LinkedIn Exemplo 4')
        ");
        
        // Adicionar avisos de exemplo
        $db->exec("
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
    <script src="https://unpkg.com/mqtt/dist/mqtt.min.js"></script>
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
        
        .section-card {
            margin-top: 30px;
            margin-bottom: 30px;
            border: 1px solid #ddd;
            border-radius: 10px;
            background-color: #f9f9f9;
            overflow: hidden;
        }
        
        .section-header {
            background-color: #f0f0f0;
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .section-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 500;
            color: #333;
        }
        
        .section-content {
            padding: 20px;
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
        
        .notices-table {
            width: 100%;
        }
        
        .notices-table th, .notices-table td {
            padding: 8px 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        
        .notices-table th {
            background-color: #f2f2f2;
        }
        
        .notices-container {
            background-color: #fffcf5;
            border: 1px solid #ffe8a8;
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 20px;
            position: relative;
            max-height: 100px; /* 10% da altura aproximadamente */
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
        
        .notice-priority-0 { background-color: transparent; }
        .notice-priority-1 { background-color: rgba(255, 243, 205, 0.5); }
        .notice-priority-2 { background-color: rgba(248, 215, 218, 0.5); }
        
        .notice-text {
            font-size: 0.95em;
        }
        
        .notice-meta {
            font-size: 0.75em;
            color: #856404;
            opacity: 0.7;
        }
        
        .notice-form {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            display: none;
        }
        
        .rotate-icon {
            transition: transform 0.3s ease;
        }
        
        .rotate-icon.open {
            transform: rotate(180deg);
        }
        
        @keyframes scroll-y {
            0% { top: 0; }
            100% { top: -100%; }
        }

        /* ── Noise Heatmap ── */
        #nh-canvas {
            display: block;
            width: 100%;
            border-radius: 8px;
            border: 1px solid #ddd;
            background: #f0f0f0;
        }
        .nh-legend-bar {
            width: 140px;
            height: 10px;
            border-radius: 5px;
            background: linear-gradient(to right, #2563eb, #22c55e, #eab308, #ef4444);
        }
        .nh-mic-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 12px;
            background: #f0f0f0;
            font-size: 12px;
            border: 1px solid #e0e0e0;
        }
        .nh-mic-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #ccc;
            flex-shrink: 0;
        }
        .nh-mic-dot.live { background: #22c55e; }
        .nh-mic-dot.stale { background: #f59e0b; }
        .nh-cfg-input {
            width: 100%;
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }
        .nh-cfg-table td, .nh-cfg-table th {
            padding: 5px 8px;
        }
        @keyframes nh-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        .nh-connecting { animation: nh-pulse 1s infinite; }
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
                <?php if (isset($youtubeContents) && is_array($youtubeContents)): ?>
                    <?php foreach ($youtubeContents as $ytContent): ?>
                    <div class="col mb-3">
                        <div class="content-container h-100">
                            <?= renderContentFrame($ytContent, 350) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if (isset($linkedinContents) && is_array($linkedinContents)): ?>
                    <?php foreach ($linkedinContents as $liContent): ?>
                    <div class="col mb-3">
                        <div class="content-container h-100">
                            <?= renderContentFrame($liContent, 350) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
        
        <!-- ══ Mapa de Ruído das Salas ══ -->
        <div class="section-card" id="noise-heatmap-card">
            <div class="section-header" onclick="toggleSection('noise-heatmap-section')">
                <h2 style="display:flex;align-items:center;gap:10px;">
                    <i class="bi bi-soundwave"></i> Mapa de Ruído das Salas
                    <span id="nh-mqtt-dot" style="width:9px;height:9px;border-radius:50%;background:#ccc;display:inline-block;flex-shrink:0;" title="MQTT: desligado"></span>
                </h2>
                <i class="bi bi-chevron-down rotate-icon" id="noise-heatmap-section-icon"></i>
            </div>

            <div class="section-content" id="noise-heatmap-section" style="display:none;">

                <!-- Canvas heatmap -->
                <div style="position:relative;width:100%;max-width:800px;margin:0 auto;">
                    <canvas id="nh-canvas"></canvas>
                </div>

                <!-- Legend + mic badges -->
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:10px;justify-content:center;">
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-size:11px;color:#666;">30 dB</span>
                        <div class="nh-legend-bar"></div>
                        <span style="font-size:11px;color:#666;">90+ dB</span>
                    </div>
                    <div id="nh-mics-status" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
                </div>

                <!-- Toolbar -->
                <div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap;">
                    <button onclick="nhToggleConfig()" class="btn btn-sm btn-outline-secondary" style="font-size:12px;">
                        <i class="bi bi-gear"></i> Configurar
                    </button>
                    <button onclick="nhReconnect()" class="btn btn-sm btn-outline-secondary" style="font-size:12px;" id="nh-reconnect-btn">
                        <i class="bi bi-arrow-repeat"></i> Religar
                    </button>
                    <span id="nh-last-update" style="font-size:11px;color:#999;"></span>
                </div>

                <!-- Config panel (hidden) -->
                <div id="nh-config-panel" style="display:none;margin-top:14px;padding:16px;background:#f8f9fa;border-radius:8px;border:1px solid #ddd;">
                    <h5 style="margin-top:0;margin-bottom:14px;">⚙ Configuração do Mapa de Ruído</h5>

                    <!-- MQTT -->
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                        <div style="flex:3;min-width:200px;">
                            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">MQTT Broker URL <span style="font-weight:400;color:#999;">(ws://host:porta)</span></label>
                            <input type="url" id="nh-cfg-broker" class="nh-cfg-input" placeholder="ws://192.168.1.10:9001">
                        </div>
                        <div style="flex:1;min-width:110px;">
                            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Utilizador <span style="font-weight:400;color:#999;">(opt.)</span></label>
                            <input type="text" id="nh-cfg-user" class="nh-cfg-input" placeholder="">
                        </div>
                        <div style="flex:1;min-width:110px;">
                            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Password <span style="font-weight:400;color:#999;">(opt.)</span></label>
                            <input type="password" id="nh-cfg-pass" class="nh-cfg-input" placeholder="">
                        </div>
                    </div>

                    <!-- Canvas size -->
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Resolução interna do canvas (px)</label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="number" id="nh-cfg-cw" class="nh-cfg-input" style="width:90px;" value="700" min="200" max="1400">
                            <span>×</span>
                            <input type="number" id="nh-cfg-ch" class="nh-cfg-input" style="width:90px;" value="350" min="100" max="800">
                        </div>
                    </div>

                    <!-- dB range -->
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                        <div style="min-width:110px;">
                            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">dB mínimo (azul)</label>
                            <input type="number" id="nh-cfg-dbmin" class="nh-cfg-input" style="width:90px;" value="30" min="0" max="80">
                        </div>
                        <div style="min-width:110px;">
                            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">dB máximo (vermelho)</label>
                            <input type="number" id="nh-cfg-dbmax" class="nh-cfg-input" style="width:90px;" value="90" min="40" max="140">
                        </div>
                        <div style="min-width:110px;">
                            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Dados válidos por (s)</label>
                            <input type="number" id="nh-cfg-ttl" class="nh-cfg-input" style="width:90px;" value="30" min="5" max="300">
                        </div>
                    </div>

                    <!-- Microphones -->
                    <label style="font-weight:600;font-size:12px;display:block;margin-bottom:6px;">Microfones <span style="font-weight:400;color:#999;">(tópico: /som/placa<b>N</b>/delta)</span></label>
                    <div style="overflow-x:auto;">
                        <table class="nh-cfg-table" style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr style="background:#e9ecef;text-align:left;">
                                    <th>Nº Placa</th>
                                    <th>Nome</th>
                                    <th>X (%)</th>
                                    <th>Y (%)</th>
                                    <th>Escala</th>
                                    <th>Ativo</th>
                                </tr>
                            </thead>
                            <tbody id="nh-mic-rows"></tbody>
                        </table>
                    </div>

                    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button onclick="nhSaveConfig()" class="btn btn-primary btn-sm"><i class="bi bi-floppy"></i> Guardar</button>
                        <button onclick="nhToggleConfig()" class="btn btn-secondary btn-sm">Cancelar</button>
                        <button onclick="nhAddMic()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plus"></i> Adicionar microfone</button>
                        <button onclick="nhResetConfig()" class="btn btn-outline-danger btn-sm">Repor padrões</button>
                    </div>
                </div><!-- /config -->

            </div><!-- /section-content -->
        </div><!-- /noise-heatmap-card -->

        <!-- Seção de Gerenciamento de Conteúdo -->
        <div class="section-card">
            <div class="section-header" onclick="toggleSection('content-section')">
                <h2><i class="bi bi-collection-play"></i> Gerenciar Conteúdo</h2>
                <i class="bi bi-chevron-down rotate-icon" id="content-section-icon"></i>
            </div>
            
            <div class="section-content" id="content-section" style="display: none;">
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
        
        <!-- Seção de Gerenciamento de Avisos -->
        <div class="section-card">
            <div class="section-header" onclick="toggleSection('notices-section')">
                <h2><i class="bi bi-bell"></i> Gerenciar Avisos</h2>
                <i class="bi bi-chevron-down rotate-icon" id="notices-section-icon"></i>
            </div>
            
            <div class="section-content" id="notices-section" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="m-0">Avisos Ativos</h3>
                    <button type="button" class="btn btn-primary" onclick="toggleNoticeForm()">
                        <i class="bi bi-plus"></i> Novo Aviso
                    </button>
                </div>
                
                <div id="notice-form" style="display: none;">
                    <form method="post" action="" class="bg-light p-3 rounded mb-4 border">
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
                            <button type="button" class="btn btn-secondary" onclick="toggleNoticeForm()">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Adicionar</button>
                        </div>
                    </form>
                </div>
                
                <?php if (empty($notices)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Não há avisos no momento.
                    </div>
                <?php else: ?>
                    <table class="notices-table">
                        <thead>
                            <tr>
                                <th width="50">ID</th>
                                <th>Texto</th>
                                <th width="150">Autor</th>
                                <th width="150">Data</th>
                                <th width="100">Prioridade</th>
                                <th width="100">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notices as $notice): ?>
                                <tr class="notice-priority-<?= $notice['priority'] ?>">
                                    <td><?= $notice['id'] ?></td>
                                    <td><?= htmlspecialchars($notice['text']) ?></td>
                                    <td><?= htmlspecialchars($notice['added_by']) ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($notice['added_at'])) ?></td>
                                    <td>
                                        <?php 
                                        switch($notice['priority']) {
                                            case 0: echo "Normal"; break;
                                            case 1: echo "Importante"; break;
                                            case 2: echo "Urgente"; break;
                                            default: echo "Normal";
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <form method="post" action="" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir este aviso?');">
                                            <input type="hidden" name="action" value="delete_notice">
                                            <input type="hidden" name="notice_id" value="<?= $notice['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-trash"></i> Excluir
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
        document.getElementById('settings-btn').addEventListener('click', function() {
            const displayOptions = document.getElementById('display-options');
            if (displayOptions.style.display === 'none' || displayOptions.style.display === '') {
                displayOptions.style.display = 'block';
            } else {
                displayOptions.style.display = 'none';
            }
        });
        
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
        
        // Função para exibir/ocultar seções
        function toggleSection(sectionId) {
            const section = document.getElementById(sectionId);
            const icon = document.getElementById(sectionId + '-icon');
            
            if (section.style.display === 'none') {
                section.style.display = 'block';
                icon.classList.add('open');
            } else {
                section.style.display = 'none';
                icon.classList.remove('open');
            }
        }
        
        // Função para exibir/ocultar formulário de aviso
        function toggleNoticeForm() {
            const form = document.getElementById('notice-form');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
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
        
        // Ajustar a velocidade da animação com base na quantidade de avisos
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
        document.addEventListener('DOMContentLoaded', function() {
            // Ajustar velocidade do scroll
            adjustScrollSpeed();

            // As seções de gerenciamento começam recolhidas por padrão
            // Os valores já estão definidos como style="display: none;" no HTML
        });

    // ══════════════════════════════════════════════
    // NOISE HEATMAP
    // ══════════════════════════════════════════════
    (function() {
        const STORAGE_KEY = 'pk_noise_heatmap_cfg_v2';
        const DEFAULT_CFG = {
            broker: '',
            user: '',
            pass: '',
            canvasW: 700,
            canvasH: 350,
            dbMin: 30,
            dbMax: 90,
            ttl: 30,       // seconds before reading is considered stale
            mics: [
                { plate: 1, name: 'Mic 1', x: 20, y: 50, scale: 1.0, active: true },
                { plate: 2, name: 'Mic 2', x: 50, y: 20, scale: 1.0, active: true },
                { plate: 3, name: 'Mic 3', x: 80, y: 50, scale: 1.0, active: true },
                { plate: 4, name: 'Mic 4', x: 30, y: 80, scale: 1.0, active: true },
                { plate: 5, name: 'Mic 5', x: 70, y: 80, scale: 1.0, active: true },
            ]
        };

        let cfg = JSON.parse(JSON.stringify(DEFAULT_CFG));
        let mqttClient = null;
        let micData = {};        // plate -> { db, ts }
        let animFrame = null;
        const RENDER_RES = 6;    // canvas pixels per IDW sample cell

        // ── Load / save config ─────────────────────────
        function loadCfg() {
            try {
                const s = localStorage.getItem(STORAGE_KEY);
                if (s) {
                    const parsed = JSON.parse(s);
                    cfg = Object.assign(JSON.parse(JSON.stringify(DEFAULT_CFG)), parsed);
                }
            } catch(e) {}
        }
        function saveCfg() {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(cfg)); } catch(e) {}
        }

        // ── Canvas ──────────────────────────────────────
        const canvas = document.getElementById('nh-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');

        function resizeCanvas() {
            canvas.width  = cfg.canvasW || 700;
            canvas.height = cfg.canvasH || 350;
        }

        // ── Color ramp: blue→green→yellow→red ──────────
        function colorRamp(t) {
            t = Math.max(0, Math.min(1, t));
            // Keyframes: 0=blue, 0.33=green, 0.66=yellow, 1=red
            const stops = [
                [37,  99,  235],   // blue
                [34,  197, 94 ],   // green
                [234, 179, 8  ],   // yellow
                [239, 68,  68 ],   // red
            ];
            const seg  = t * (stops.length - 1);
            const lo   = Math.floor(seg);
            const hi   = Math.min(lo + 1, stops.length - 1);
            const frac = seg - lo;
            return stops[lo].map((c, i) => Math.round(c + (stops[hi][i] - c) * frac));
        }

        // ── IDW interpolation at canvas coords (px, py) ─
        function idwAt(px, py) {
            const W = canvas.width, H = canvas.height;
            const pts = [];
            for (const m of cfg.mics) {
                if (!m.active) continue;
                const d = micData[m.plate];
                if (!d) continue;
                const age = (Date.now() - d.ts) / 1000;
                if (age > (cfg.ttl || 30)) continue;
                const mx = (m.x / 100) * W;
                const my = (m.y / 100) * H;
                const dx = px - mx, dy = py - my;
                const dist2 = dx * dx + dy * dy;
                const dbScaled = d.db * (m.scale || 1);
                pts.push({ dbScaled, dist2 });
            }
            if (pts.length === 0) return null;
            // If standing on a mic, return its value
            const exact = pts.find(p => p.dist2 < 4);
            if (exact) return exact.dbScaled;
            // IDW (power = 2)
            let num = 0, den = 0;
            for (const p of pts) {
                const w = 1 / p.dist2;
                num += w * p.dbScaled;
                den += w;
            }
            return den > 0 ? num / den : null;
        }

        // ── Render ──────────────────────────────────────
        function render() {
            const W = canvas.width, H = canvas.height;
            const dbMin = cfg.dbMin || 30, dbMax = cfg.dbMax || 90;

            // Background
            ctx.fillStyle = '#ececec';
            ctx.fillRect(0, 0, W, H);

            // Count live mics
            const now = Date.now();
            const liveMics = cfg.mics.filter(m => {
                if (!m.active) return false;
                const d = micData[m.plate];
                return d && (now - d.ts) / 1000 <= (cfg.ttl || 30);
            });

            if (liveMics.length > 0) {
                // Build low-res imageData, then scale up (smooth)
                const cols = Math.ceil(W / RENDER_RES);
                const rows = Math.ceil(H / RENDER_RES);
                const img  = ctx.createImageData(cols, rows);

                for (let row = 0; row < rows; row++) {
                    for (let col = 0; col < cols; col++) {
                        const px = col * RENDER_RES + RENDER_RES / 2;
                        const py = row * RENDER_RES + RENDER_RES / 2;
                        const db = idwAt(px, py);
                        const idx = (row * cols + col) * 4;
                        if (db !== null) {
                            const t = (db - dbMin) / (dbMax - dbMin);
                            const [r, g, b] = colorRamp(t);
                            img.data[idx]     = r;
                            img.data[idx + 1] = g;
                            img.data[idx + 2] = b;
                            img.data[idx + 3] = 210;
                        } else {
                            img.data[idx]     = 220;
                            img.data[idx + 1] = 220;
                            img.data[idx + 2] = 220;
                            img.data[idx + 3] = 255;
                        }
                    }
                }

                // Paint low-res buffer to offscreen canvas, then stretch to full size
                const offscreen = Object.assign(document.createElement('canvas'), { width: cols, height: rows });
                offscreen.getContext('2d').putImageData(img, 0, 0);
                ctx.save();
                ctx.imageSmoothingEnabled = true;
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(offscreen, 0, 0, W, H);
                ctx.restore();
            } else {
                // No data: placeholder
                ctx.fillStyle = 'rgba(0,0,0,0.25)';
                ctx.font = `bold 15px sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('A aguardar dados dos microfones…', W / 2, H / 2);
            }

            // Grid overlay
            ctx.strokeStyle = 'rgba(0,0,0,0.07)';
            ctx.lineWidth = 1;
            for (let x = W / 5; x < W; x += W / 5) {
                ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke();
            }
            for (let y = H / 3; y < H; y += H / 3) {
                ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
            }

            // Room border
            ctx.strokeStyle = 'rgba(0,0,0,0.2)';
            ctx.lineWidth = 2;
            ctx.strokeRect(1, 1, W - 2, H - 2);

            // Microphones
            for (const m of cfg.mics) {
                if (!m.active) continue;
                const mx = (m.x / 100) * W;
                const my = (m.y / 100) * H;
                const d    = micData[m.plate];
                const age  = d ? (now - d.ts) / 1000 : Infinity;
                const live = age <= (cfg.ttl || 30);
                const db   = d ? (d.db * (m.scale || 1)) : null;

                // Shadow
                ctx.save();
                ctx.shadowColor = 'rgba(0,0,0,0.3)';
                ctx.shadowBlur  = 6;

                // Circle
                ctx.beginPath();
                ctx.arc(mx, my, 15, 0, 2 * Math.PI);
                ctx.fillStyle = live ? 'rgba(15,23,42,0.82)' : 'rgba(150,150,150,0.75)';
                ctx.fill();
                ctx.strokeStyle = live ? '#ffffff' : '#bbb';
                ctx.lineWidth = 2;
                ctx.stroke();
                ctx.restore();

                // Microphone icon
                ctx.font = '12px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('🎙', mx, my - 1);

                // Name label
                ctx.font = 'bold 10px sans-serif';
                ctx.fillStyle = '#222';
                ctx.textBaseline = 'top';
                ctx.strokeStyle = 'rgba(255,255,255,0.8)';
                ctx.lineWidth = 3;
                ctx.strokeText(m.name || `Mic ${m.plate}`, mx, my + 17);
                ctx.fillText(m.name || `Mic ${m.plate}`, mx, my + 17);

                // dB value
                if (live && db !== null) {
                    const [r, g, b] = colorRamp((db - dbMin) / (dbMax - dbMin));
                    ctx.font = 'bold 11px sans-serif';
                    ctx.fillStyle = `rgb(${r},${g},${b})`;
                    ctx.strokeStyle = 'rgba(255,255,255,0.9)';
                    ctx.lineWidth = 3;
                    ctx.strokeText(`${db.toFixed(0)} dB`, mx, my + 28);
                    ctx.fillText(`${db.toFixed(0)} dB`, mx, my + 28);
                }
            }
        }

        // ── Animation loop ──────────────────────────────
        function startLoop() {
            if (animFrame) return;
            function tick() { render(); animFrame = requestAnimationFrame(tick); }
            tick();
        }

        // ── Status badges ───────────────────────────────
        function updateMicBadges() {
            const el = document.getElementById('nh-mics-status');
            if (!el) return;
            const now = Date.now();
            el.innerHTML = cfg.mics.filter(m => m.active).map(m => {
                const d    = micData[m.plate];
                const age  = d ? (now - d.ts) / 1000 : Infinity;
                const live = age <= (cfg.ttl || 30);
                const db   = live && d ? (d.db * (m.scale || 1)).toFixed(0) + ' dB' : '--';
                return `<span class="nh-mic-badge">
                    <span class="nh-mic-dot ${live ? 'live' : 'stale'}"></span>
                    ${escHtml(m.name || 'Mic ' + m.plate)}: ${db}
                </span>`;
            }).join('');
        }
        setInterval(updateMicBadges, 2000);

        // ── MQTT dot ────────────────────────────────────
        function setDot(state) {
            const el = document.getElementById('nh-mqtt-dot');
            if (!el) return;
            const map = { connected: '#22c55e', connecting: '#f59e0b', error: '#ef4444', disconnected: '#94a3b8' };
            el.style.background = map[state] || '#94a3b8';
            el.className = state === 'connecting' ? 'nh-connecting' : '';
            el.title = 'MQTT: ' + state;
        }

        // ── MQTT connection ─────────────────────────────
        function nhConnect() {
            if (mqttClient) { try { mqttClient.end(true); } catch(e) {} mqttClient = null; }
            if (!cfg.broker) { setDot('disconnected'); return; }

            setDot('connecting');
            try {
                const opts = {
                    clientId: 'pk_noise_' + Math.random().toString(16).slice(2),
                    keepalive: 60,
                    reconnectPeriod: 5000,
                };
                if (cfg.user) opts.username = cfg.user;
                if (cfg.pass) opts.password = cfg.pass;

                mqttClient = mqtt.connect(cfg.broker, opts);

                mqttClient.on('connect', () => {
                    setDot('connected');
                    mqttClient.subscribe('/som/+/delta', { qos: 0 });
                });

                mqttClient.on('message', (topic, payload) => {
                    // topic pattern: /som/placaN/delta
                    const m = topic.match(/\/som\/placa(\d+)\/delta/i);
                    if (!m) return;
                    const plate = parseInt(m[1]);
                    const raw   = payload.toString().trim();
                    // Payload: "delta=X" OR just "X"
                    let db;
                    if (/^delta=/i.test(raw)) {
                        db = parseFloat(raw.replace(/^delta=/i, ''));
                    } else {
                        db = parseFloat(raw);
                    }
                    if (!isNaN(db)) {
                        micData[plate] = { db, ts: Date.now() };
                        updateMicBadges();
                        const el = document.getElementById('nh-last-update');
                        if (el) el.textContent = 'Última leitura: ' + new Date().toLocaleTimeString() + ' — placa ' + plate + ' → ' + db.toFixed(1) + ' dB';
                    }
                });

                mqttClient.on('error',      () => setDot('error'));
                mqttClient.on('close',      () => setDot('disconnected'));
                mqttClient.on('offline',    () => setDot('disconnected'));
                mqttClient.on('reconnect',  () => setDot('connecting'));
            } catch (e) {
                setDot('error');
                console.error('[NoiseHeatmap] MQTT error:', e);
            }
        }

        window.nhReconnect = function() { nhConnect(); };

        // ── Config panel helpers ─────────────────────────
        window.nhToggleConfig = function() {
            const p = document.getElementById('nh-config-panel');
            if (!p) return;
            if (p.style.display === 'none') {
                nhFillForm();
                p.style.display = 'block';
            } else {
                p.style.display = 'none';
            }
        };

        function nhFillForm() {
            document.getElementById('nh-cfg-broker').value = cfg.broker || '';
            document.getElementById('nh-cfg-user').value   = cfg.user   || '';
            document.getElementById('nh-cfg-pass').value   = cfg.pass   || '';
            document.getElementById('nh-cfg-cw').value     = cfg.canvasW || 700;
            document.getElementById('nh-cfg-ch').value     = cfg.canvasH || 350;
            document.getElementById('nh-cfg-dbmin').value  = cfg.dbMin  || 30;
            document.getElementById('nh-cfg-dbmax').value  = cfg.dbMax  || 90;
            document.getElementById('nh-cfg-ttl').value    = cfg.ttl    || 30;
            renderMicRows(cfg.mics);
        }

        function renderMicRows(mics) {
            const tbody = document.getElementById('nh-mic-rows');
            if (!tbody) return;
            tbody.innerHTML = mics.map((m, i) => `
                <tr data-idx="${i}" style="border-bottom:1px solid #e0e0e0;">
                    <td><input type="number" class="nh-cfg-input" style="width:65px;" value="${m.plate}" min="1" max="30" data-field="plate"></td>
                    <td><input type="text"   class="nh-cfg-input" style="width:110px;" value="${escHtml(m.name||'')}" placeholder="Mic ${m.plate}" data-field="name"></td>
                    <td><input type="number" class="nh-cfg-input" style="width:70px;" value="${m.x}" min="0" max="100" step="0.5" data-field="x"> <small>%</small></td>
                    <td><input type="number" class="nh-cfg-input" style="width:70px;" value="${m.y}" min="0" max="100" step="0.5" data-field="y"> <small>%</small></td>
                    <td><input type="number" class="nh-cfg-input" style="width:70px;" value="${m.scale||1}" min="0.01" max="10" step="0.05" data-field="scale"></td>
                    <td style="text-align:center;"><input type="checkbox" data-field="active" ${m.active ? 'checked' : ''}></td>
                </tr>
            `).join('');
        }

        function readMicRows() {
            const rows = document.querySelectorAll('#nh-mic-rows tr[data-idx]');
            return Array.from(rows).map(row => {
                const g = (f) => row.querySelector(`[data-field="${f}"]`);
                return {
                    plate:  parseInt(g('plate')?.value)  || 1,
                    name:   g('name')?.value?.trim()     || '',
                    x:      parseFloat(g('x')?.value)    || 50,
                    y:      parseFloat(g('y')?.value)    || 50,
                    scale:  parseFloat(g('scale')?.value)|| 1,
                    active: g('active')?.checked ?? true,
                };
            });
        }

        window.nhAddMic = function() {
            const mics = readMicRows();
            const nextPlate = Math.max(0, ...mics.map(m => m.plate)) + 1;
            mics.push({ plate: nextPlate, name: 'Mic ' + nextPlate, x: 50, y: 50, scale: 1, active: true });
            renderMicRows(mics);
        };

        window.nhSaveConfig = function() {
            cfg.broker   = document.getElementById('nh-cfg-broker').value.trim();
            cfg.user     = document.getElementById('nh-cfg-user').value.trim();
            cfg.pass     = document.getElementById('nh-cfg-pass').value;
            cfg.canvasW  = parseInt(document.getElementById('nh-cfg-cw').value)    || 700;
            cfg.canvasH  = parseInt(document.getElementById('nh-cfg-ch').value)    || 350;
            cfg.dbMin    = parseInt(document.getElementById('nh-cfg-dbmin').value) || 30;
            cfg.dbMax    = parseInt(document.getElementById('nh-cfg-dbmax').value) || 90;
            cfg.ttl      = parseInt(document.getElementById('nh-cfg-ttl').value)   || 30;
            cfg.mics     = readMicRows();
            saveCfg();
            resizeCanvas();
            nhConnect();
            document.getElementById('nh-config-panel').style.display = 'none';
        };

        window.nhResetConfig = function() {
            if (!confirm('Repor configuração padrão do mapa de ruído?')) return;
            cfg = JSON.parse(JSON.stringify(DEFAULT_CFG));
            saveCfg();
            nhFillForm();
        };

        // ── Utility ──────────────────────────────────────
        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── Init ─────────────────────────────────────────
        function init() {
            loadCfg();
            resizeCanvas();
            updateMicBadges();
            startLoop();
            if (cfg.broker) nhConnect();
        }

        // Wait for mqtt.js CDN (async load)
        if (typeof mqtt !== 'undefined') {
            init();
        } else {
            const t = setInterval(() => {
                if (typeof mqtt !== 'undefined') { clearInterval(t); init(); }
            }, 100);
            // Timeout after 10s — run without MQTT
            setTimeout(() => { clearInterval(t); if (!mqttClient) init(); }, 10000);
        }

    })(); // end noise heatmap IIFE
    </script>
</body>
</html>