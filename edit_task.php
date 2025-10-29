<?php
/**
 * EDITOR DE TASKS UNIVERSAL
 * Modal overlay para edição completa de tasks
 * Pode ser incluído em qualquer página (todo.php, sprints.php, etc)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar se está logado
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Não autenticado']));
}

require_once __DIR__ . '/config.php';

// Conectar BD
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('Erro de conexão: ' . $e->getMessage());
}

// PROCESSAR UPLOAD DE FICHEIROS - VERSÃO CORRIGIDA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_file') {
    // Aumentar limites temporariamente
    @ini_set('upload_max_filesize', '50M');
    @ini_set('post_max_size', '52M');
    @ini_set('max_execution_time', '300');
    @ini_set('memory_limit', '256M');
    
    header('Content-Type: application/json');
    
    $todo_id = (int)$_POST['todo_id'];
    
    // Verificar permissão
    $stmt = $pdo->prepare('SELECT autor, responsavel FROM todos WHERE id = ?');
    $stmt->execute([$todo_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Regras de permissão:
    // - Autores podem sempre editar
    // - Responsáveis podem editar
    // - Se não houver responsável, qualquer um pode editar
    $is_author = ($task['autor'] == $_SESSION['user_id']);
    $is_responsible = (!empty($task['responsavel']) && $task['responsavel'] == $_SESSION['user_id']);
    $no_responsible = empty($task['responsavel']) || is_null($task['responsavel']);
    
    if (!$task || (!$is_author && !$is_responsible && !$no_responsible)) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão']);
        exit;
    }
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        // Validações
        $max_file_size = 10 * 1024 * 1024; // 10MB
        $allowed_extensions = [
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', // Imagens
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', // Documentos
            'txt', 'csv', 'json', 'xml', // Texto
            'zip', 'rar', '7z', 'tar', 'gz', // Arquivos
            'mp3', 'mp4', 'avi', 'mov', 'wmv' // Mídia
        ];
        
        $file_name = basename($_FILES['file']['name']);
        $file_size = $_FILES['file']['size'];
        $file_tmp = $_FILES['file']['tmp_name'];
        
        // Obter extensão (case-insensitive)
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Validar tamanho
        if ($file_size > $max_file_size) {
            echo json_encode(['success' => false, 'error' => 'Ficheiro muito grande. Máximo 10MB']);
            exit;
        }
        
        // Validar extensão
        if (!in_array($file_ext, $allowed_extensions)) {
            echo json_encode(['success' => false, 'error' => 'Tipo de ficheiro não permitido: .' . $file_ext]);
            exit;
        }
        
        // Validar se é realmente um ficheiro
        if (!is_uploaded_file($file_tmp)) {
            echo json_encode(['success' => false, 'error' => 'Ficheiro inválido']);
            exit;
        }
        
        // Criar diretório se não existir
        $upload_dir = __DIR__ . '/files/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                echo json_encode(['success' => false, 'error' => 'Erro ao criar diretório de upload']);
                exit;
            }
        }
        
        // Verificar permissões do diretório
        if (!is_writable($upload_dir)) {
            echo json_encode(['success' => false, 'error' => 'Diretório não tem permissões de escrita']);
            exit;
        }
        
        // Gerar nome único para o ficheiro (sempre em minúsculas)
        $new_name = uniqid() . '_' . time() . '.' . $file_ext;
        $file_path = $upload_dir . $new_name;
        
        // Mover ficheiro
        if (move_uploaded_file($file_tmp, $file_path)) {
            // Guardar na base de dados
            try {
                $stmt = $pdo->prepare('INSERT INTO task_files (todo_id, file_name, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $todo_id, 
                    $file_name, // Nome original do ficheiro
                    'files/' . $new_name, // Caminho com nome único
                    $file_size,
                    $_SESSION['user_id']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'file_id' => $pdo->lastInsertId(),
                    'file_name' => $file_name,
                    'file_path' => 'files/' . $new_name,
                    'file_size' => $file_size
                ]);
            } catch (PDOException $e) {
                // Se falhar a inserção na BD, apagar o ficheiro
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                echo json_encode(['success' => false, 'error' => 'Erro ao guardar na base de dados: ' . $e->getMessage()]);
            }
        } else {
            $error_info = error_get_last();
            echo json_encode(['success' => false, 'error' => 'Erro ao mover ficheiro: ' . ($error_info['message'] ?? 'Erro desconhecido')]);
        }
    } else {
        // Tratar diferentes tipos de erro de upload
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'Ficheiro excede o tamanho máximo permitido no PHP',
            UPLOAD_ERR_FORM_SIZE => 'Ficheiro excede o tamanho máximo do formulário',
            UPLOAD_ERR_PARTIAL => 'Upload parcial - tente novamente',
            UPLOAD_ERR_NO_FILE => 'Nenhum ficheiro foi enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao escrever ficheiro no disco',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão PHP'
        ];
        
        $error_code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $error_message = $error_messages[$error_code] ?? 'Erro desconhecido no upload';
        
        echo json_encode(['success' => false, 'error' => $error_message]);
    }
    exit;
}

// PROCESSAR ELIMINAÇÃO DE FICHEIROS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_file') {
    header('Content-Type: application/json');
    
    $file_id = (int)$_POST['file_id'];
    
    $stmt = $pdo->prepare('SELECT tf.*, t.autor, t.responsavel FROM task_files tf JOIN todos t ON tf.todo_id = t.id WHERE tf.id = ?');
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Regras de permissão:
    // - Autores podem sempre editar
    // - Responsáveis podem editar
    // - Se não houver responsável, qualquer um pode editar
    $is_author = ($file['autor'] == $_SESSION['user_id']);
    $is_responsible = (!empty($file['responsavel']) && $file['responsavel'] == $_SESSION['user_id']);
    $no_responsible = empty($file['responsavel']) || is_null($file['responsavel']);
    
    if (!$file || (!$is_author && !$is_responsible && !$no_responsible)) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão']);
        exit;
    }
    
    // Eliminar ficheiro físico
    if (file_exists(__DIR__ . '/' . $file['file_path'])) {
        unlink(__DIR__ . '/' . $file['file_path']);
    }
    
    // Eliminar registo
    $stmt = $pdo->prepare('DELETE FROM task_files WHERE id = ?');
    $stmt->execute([$file_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// PROCESSAR GUARDAR TASK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_task') {
    header('Content-Type: application/json');
    
    $todo_id = (int)$_POST['todo_id'];
    
    // Verificar permissão
    $stmt = $pdo->prepare('SELECT autor, responsavel FROM todos WHERE id = ?');
    $stmt->execute([$todo_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Regras de permissão:
    // - Autores podem sempre editar
    // - Responsáveis podem editar
    // - Se não houver responsável, qualquer um pode editar
    $is_author = ($task['autor'] == $_SESSION['user_id']);
    $is_responsible = (!empty($task['responsavel']) && $task['responsavel'] == $_SESSION['user_id']);
    $no_responsible = empty($task['responsavel']) || is_null($task['responsavel']);
    
    if (!$task || (!$is_author && !$is_responsible && !$no_responsible)) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão']);
        exit;
    }
    
    // Atualizar task
    $stmt = $pdo->prepare('UPDATE todos SET titulo = ?, descritivo = ?, data_limite = ?, responsavel = ?, estado = ? WHERE id = ?');
    $stmt->execute([
        $_POST['titulo'],
        $_POST['descritivo'],
        $_POST['data_limite'] ?: null,
        $_POST['responsavel'] ?: null,
        $_POST['estado'],
        $todo_id
    ]);
    
    // Guardar checklist
    $pdo->prepare('DELETE FROM task_checklist WHERE todo_id = ?')->execute([$todo_id]);
    
    if (isset($_POST['checklist']) && is_array($_POST['checklist'])) {
        $stmt = $pdo->prepare('INSERT INTO task_checklist (todo_id, item_text, is_checked, position) VALUES (?, ?, ?, ?)');
        foreach ($_POST['checklist'] as $index => $item) {
            $stmt->execute([
                $todo_id,
                $item['text'],
                $item['checked'] ? 1 : 0,
                $index
            ]);
        }
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// Obter lista de utilizadores para dropdown
$users = $pdo->query('SELECT user_id, username FROM user_tokens ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Marked.js para renderizar Markdown -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<!-- CSS do Editor de Tasks -->
<style>
#task-editor-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 9999;
    backdrop-filter: blur(5px);
}

#task-editor-modal {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 90%;
    max-width: 900px;
    max-height: 90vh;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.task-editor-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.task-editor-header h3 {
    margin: 0;
    font-size: 1.5rem;
}

.task-editor-close {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 20px;
    transition: all 0.3s;
}

.task-editor-close:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(90deg);
}

.task-editor-body {
    padding: 30px;
    overflow-y: auto;
    flex: 1;
}

.task-editor-footer {
    padding: 20px 30px;
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #333;
}

.form-control {
    width: 100%;
    padding: 10px 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.3s;
    box-sizing: border-box;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
}

textarea.form-control {
    min-height: 120px;
    resize: vertical;
    font-family: 'Courier New', monospace;
    font-size: 13px;
}

/* Editor Markdown */
.markdown-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.markdown-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #666;
}

.markdown-toggle input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.markdown-toolbar {
    display: flex;
    gap: 5px;
    margin-bottom: 10px;
    padding: 10px;
    background: #f5f5f5;
    border-radius: 8px;
    flex-wrap: wrap;
}

.markdown-btn {
    padding: 5px 10px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.2s;
}

.markdown-btn:hover {
    background: #667eea;
    color: white;
    border-color: #667eea;
}

/* Preview de Markdown */
#markdown-preview {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    min-height: 120px;
    background: #fafafa;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    line-height: 1.6;
}

#markdown-preview h1 { font-size: 2em; margin-top: 0; }
#markdown-preview h2 { font-size: 1.5em; margin-top: 1em; }
#markdown-preview h3 { font-size: 1.17em; margin-top: 1em; }
#markdown-preview code {
    background: #f0f0f0;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
}
#markdown-preview pre {
    background: #f0f0f0;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
}
#markdown-preview blockquote {
    border-left: 4px solid #667eea;
    padding-left: 15px;
    margin-left: 0;
    color: #666;
}
#markdown-preview ul, #markdown-preview ol {
    padding-left: 25px;
}
#markdown-preview a {
    color: #667eea;
    text-decoration: none;
}
#markdown-preview a:hover {
    text-decoration: underline;
}
#markdown-preview img {
    max-width: 100%;
    height: auto;
}
#markdown-preview table {
    border-collapse: collapse;
    width: 100%;
}
#markdown-preview table td, #markdown-preview table th {
    border: 1px solid #ddd;
    padding: 8px;
}
#markdown-preview table th {
    background-color: #f0f0f0;
    font-weight: bold;
}

/* Checklist */
.checklist-container {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    max-height: 300px;
    overflow-y: auto;
}

.checklist-item {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
}

.checklist-item input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.checklist-item input[type="text"] {
    flex: 1;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.checklist-item.checked input[type="text"] {
    text-decoration: line-through;
    opacity: 0.6;
}

.checklist-item button {
    padding: 5px 10px;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-add-checklist {
    margin-top: 10px;
    padding: 8px 15px;
    background: #28a745;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
}

.btn-add-checklist:hover {
    background: #218838;
}

/* Ficheiros */
.files-container {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
}

.file-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 10px;
}

.file-item span {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.file-item div {
    display: flex;
    gap: 5px;
}

/* Botões */
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}
</style>

<!-- Modal HTML -->
<div id="task-editor-overlay">
    <div id="task-editor-modal">
        <div class="task-editor-header">
            <h3>✏️ Editar Task</h3>
            <button class="task-editor-close" onclick="closeTaskEditor()">&times;</button>
        </div>
        
        <div class="task-editor-body">
            <form id="task-editor-form">
                <input type="hidden" id="edit_todo_id" name="todo_id">
                
                <!-- Título -->
                <div class="form-group">
                    <label for="edit_titulo">📝 Título *</label>
                    <input type="text" id="edit_titulo" name="titulo" class="form-control" required>
                </div>
                
                <!-- Linha com Data e Responsável -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label for="edit_data_limite">📅 Data Limite</label>
                        <input type="date" id="edit_data_limite" name="data_limite" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_responsavel">👤 Responsável</label>
                        <select id="edit_responsavel" name="responsavel" class="form-control">
                            <option value="">Sem responsável</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Estado -->
                <div class="form-group">
                    <label for="edit_estado">🎯 Estado</label>
                    <select id="edit_estado" name="estado" class="form-control">
                        <option value="aberta">Aberta</option>
                        <option value="em execução">Em Execução</option>
                        <option value="suspensa">Suspensa</option>
                        <option value="concluída">Concluída</option>
                    </select>
                </div>
                
                <!-- Descrição com Markdown -->
                <div class="form-group">
                    <div class="markdown-header">
                        <label for="edit_descritivo">📄 Descrição (Markdown)</label>
                        <div class="markdown-toggle">
                            <input type="checkbox" id="edit-mode-toggle" onchange="toggleEditMode()">
                            <label for="edit-mode-toggle" style="margin: 0; font-weight: normal; cursor: pointer;">
                                ✏️ Modo Edição
                            </label>
                        </div>
                    </div>
                    
                    <div id="markdown-toolbar" class="markdown-toolbar" style="display: none;">
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('**', '**')"><b>B</b></button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('*', '*')"><i>I</i></button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('# ', '')">H1</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('## ', '')">H2</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('### ', '')">H3</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('- ', '')">• Lista</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('1. ', '')">1. Lista</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('[', '](url)')">🔗 Link</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('`', '`')">Code</button>
                        <button type="button" class="markdown-btn" onclick="insertMarkdown('> ', '')">💬 Quote</button>
                    </div>
                    
                    <textarea id="edit_descritivo" name="descritivo" class="form-control" style="display: none;" oninput="updatePreview()"></textarea>
                    <div id="markdown-preview"></div>
                </div>
                
                <!-- Checklist -->
                <div class="form-group">
                    <label>✅ Checklist de Itens</label>
                    <div class="checklist-container" id="checklist-container">
                        <!-- Items serão adicionados aqui -->
                    </div>
                    <button type="button" class="btn-add-checklist" onclick="addChecklistItem()">+ Adicionar Item</button>
                </div>
                
                <!-- Upload de Ficheiros -->
                <div class="form-group">
                    <label>📎 Ficheiros Anexados</label>
                    <div class="files-container">
                        <input type="file" id="file-upload" style="display:none" onchange="uploadFile()">
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('file-upload').click()">
                            📤 Escolher Ficheiro
                        </button>
                        <div id="files-list" style="margin-top: 15px;">
                            <!-- Ficheiros serão listados aqui -->
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="task-editor-footer">
            <button type="button" class="btn btn-secondary" onclick="closeTaskEditor()">Cancelar</button>
            <button type="button" class="btn btn-primary" onclick="saveTask()">💾 Guardar</button>
        </div>
    </div>
</div>

<script>
// Variáveis globais
let checklistItems = [];
let taskFiles = [];
let isEditMode = false;

// Abrir editor
function openTaskEditor(taskId) {
    fetch(`api/get_task_full.php?id=${taskId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Preencher formulário
                document.getElementById('edit_todo_id').value = data.task.id;
                document.getElementById('edit_titulo').value = data.task.titulo || '';
                document.getElementById('edit_descritivo').value = data.task.descritivo || '';
                document.getElementById('edit_data_limite').value = data.task.data_limite || '';
                document.getElementById('edit_responsavel').value = data.task.responsavel || '';
                document.getElementById('edit_estado').value = data.task.estado || 'aberta';
                
                // Carregar checklist
                checklistItems = data.checklist || [];
                renderChecklist();
                
                // Carregar ficheiros
                taskFiles = data.files || [];
                renderFiles();
                
                // Iniciar em modo preview
                isEditMode = false;
                document.getElementById('edit-mode-toggle').checked = false;
                updatePreview();
                toggleEditMode();
                
                // Mostrar modal
                document.getElementById('task-editor-overlay').style.display = 'block';
            } else {
                alert('Erro ao carregar task: ' + data.error);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Erro ao carregar task');
        });
}

// Fechar editor
function closeTaskEditor() {
    document.getElementById('task-editor-overlay').style.display = 'none';
}

// Alternar entre modo preview e edição
function toggleEditMode() {
    isEditMode = document.getElementById('edit-mode-toggle').checked;
    const textarea = document.getElementById('edit_descritivo');
    const preview = document.getElementById('markdown-preview');
    const toolbar = document.getElementById('markdown-toolbar');
    
    if (isEditMode) {
        // Modo Edição
        textarea.style.display = 'block';
        preview.style.display = 'none';
        toolbar.style.display = 'flex';
    } else {
        // Modo Preview
        textarea.style.display = 'none';
        preview.style.display = 'block';
        toolbar.style.display = 'none';
        updatePreview();
    }
}

// Atualizar preview do Markdown
function updatePreview() {
    const textarea = document.getElementById('edit_descritivo');
    const preview = document.getElementById('markdown-preview');
    const markdown = textarea.value;
    
    if (markdown.trim() === '') {
        preview.innerHTML = '<em style="color: #999;">Sem descrição</em>';
    } else {
        // Usar marked.js para renderizar Markdown
        preview.innerHTML = marked.parse(markdown);
    }
}

// Inserir markdown
function insertMarkdown(before, after) {
    const textarea = document.getElementById('edit_descritivo');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const selectedText = text.substring(start, end);
    
    const newText = text.substring(0, start) + before + selectedText + after + text.substring(end);
    textarea.value = newText;
    textarea.focus();
    textarea.setSelectionRange(start + before.length, end + before.length);
    updatePreview();
}

// Checklist functions
function addChecklistItem() {
    checklistItems.push({ text: '', checked: false });
    renderChecklist();
}

function renderChecklist() {
    const container = document.getElementById('checklist-container');
    container.innerHTML = '';
    
    checklistItems.forEach((item, index) => {
        const div = document.createElement('div');
        div.className = 'checklist-item' + (item.checked ? ' checked' : '');
        div.innerHTML = `
            <input type="checkbox" ${item.checked ? 'checked' : ''} onchange="toggleChecklistItem(${index})">
            <input type="text" value="${item.text}" onchange="updateChecklistItem(${index}, this.value)" placeholder="Descrição do item...">
            <button type="button" onclick="removeChecklistItem(${index})">🗑️</button>
        `;
        container.appendChild(div);
    });
}

function toggleChecklistItem(index) {
    checklistItems[index].checked = !checklistItems[index].checked;
    renderChecklist();
}

function updateChecklistItem(index, text) {
    checklistItems[index].text = text;
}

function removeChecklistItem(index) {
    checklistItems.splice(index, 1);
    renderChecklist();
}

// Upload de ficheiro
function uploadFile() {
    const fileInput = document.getElementById('file-upload');
    const file = fileInput.files[0];
    if (!file) return;
    
    const formData = new FormData();
    formData.append('file', file);
    formData.append('todo_id', document.getElementById('edit_todo_id').value);
    formData.append('action', 'upload_file');
    
    fetch('edit_task.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            taskFiles.push(data);
            renderFiles();
            fileInput.value = '';
        } else {
            alert('Erro no upload: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Erro no upload');
    });
}

// Renderizar ficheiros
function renderFiles() {
    const container = document.getElementById('files-list');
    container.innerHTML = '';
    
    taskFiles.forEach(file => {
        const div = document.createElement('div');
        div.className = 'file-item';
        div.innerHTML = `
            <span>📄 ${file.file_name}</span>
            <div>
                <a href="${file.file_path}" target="_blank" class="btn btn-sm btn-primary">Ver</a>
                <button type="button" class="btn btn-sm btn-danger" onclick="deleteFile(${file.file_id})">🗑️</button>
            </div>
        `;
        container.appendChild(div);
    });
}

// Eliminar ficheiro
function deleteFile(fileId) {
    if (!confirm('Eliminar este ficheiro?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_file');
    formData.append('file_id', fileId);
    
    fetch('edit_task.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            taskFiles = taskFiles.filter(f => f.file_id != fileId);
            renderFiles();
        } else {
            alert('Erro ao eliminar: ' + data.error);
        }
    });
}

// Guardar task
function saveTask() {
    const formData = new FormData();
    formData.append('action', 'save_task');
    formData.append('todo_id', document.getElementById('edit_todo_id').value);
    formData.append('titulo', document.getElementById('edit_titulo').value);
    formData.append('descritivo', document.getElementById('edit_descritivo').value);
    formData.append('data_limite', document.getElementById('edit_data_limite').value);
    formData.append('responsavel', document.getElementById('edit_responsavel').value);
    formData.append('estado', document.getElementById('edit_estado').value);
    
    // Adicionar checklist
    checklistItems.forEach((item, index) => {
        formData.append(`checklist[${index}][text]`, item.text);
        formData.append(`checklist[${index}][checked]`, item.checked ? '1' : '0');
    });
    
    fetch('edit_task.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Task guardada com sucesso!');
            closeTaskEditor();
            location.reload();
        } else {
            alert('Erro ao guardar: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Erro ao guardar task');
    });
}

// Fechar ao clicar fora
document.addEventListener('click', function(e) {
    if (e.target.id === 'task-editor-overlay') {
        closeTaskEditor();
    }
});
</script>