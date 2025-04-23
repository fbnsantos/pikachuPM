<?php
// Configurar conexão com a base de dados SQLite
$dbFile = __DIR__ . '/database/content.db';
$isNewDB = !file_exists($dbFile);

// Garantir que a pasta da base de dados existe
if (!file_exists(dirname($dbFile))) {
    mkdir(dirname($dbFile), 0755, true);
}

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
        ');
        
        // Adicionar alguns exemplos para demonstração
        $db->exec("
            INSERT INTO content (type, url, title) VALUES 
            ('youtube', 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'Rick Astley - Never Gonna Give You Up'),
            ('youtube', 'https://www.youtube.com/embed/jNQXAC9IVRw', 'Me at the zoo'),
            ('linkedin', 'https://www.linkedin.com/embed/feed/update/urn:li:share:6866123456789012345', 'Post do LinkedIn Exemplo 1'),
            ('linkedin', 'https://www.linkedin.com/embed/feed/update/urn:li:share:6866987654321098765', 'Post do LinkedIn Exemplo 2')
        ");
    }

    // Processar operações CRUD
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
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Conteúdo Multimídia</title>
    <style>
        .content-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            background: white;
        }
        
        .content-frame {
            width: 100%;
            height: 450px;
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .content-info {
            margin-top: 15px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .content-title {
            font-size: 1.2em;
            font-weight: bold;
        }
        
        .content-type {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8em;
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
            margin-top: 50px;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Dashboard de Conteúdo</h1>
        
        <?php if (!empty($message)): ?>
            <div class="alert <?= strpos($message, 'Erro') !== false ? 'alert-danger' : 'alert-success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($content): ?>
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
                        frameborder="0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen>
                    </iframe>
                <?php elseif ($content['type'] === 'linkedin'): ?>
                    <iframe 
                        class="content-frame"
                        src="<?= htmlspecialchars($content['url']) ?>"
                        title="<?= htmlspecialchars($content['title']) ?>"
                        frameborder="0"
                        allowfullscreen>
                    </iframe>
                <?php else: ?>
                    <div class="content-frame" style="display: flex; align-items: center; justify-content: center; background-color: #f8f9fa;">
                        <p>Tipo de conteúdo não suportado.</p>
                    </div>
                <?php endif; ?>
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
            
            <h3>Conteúdo Disponível</h3>
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
    </script>
</body>
</html>