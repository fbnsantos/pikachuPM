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

// PROCESSAR UPLOAD DE FICHEIROS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_file') {
    header('Content-Type: application/json');
    
    $todo_id = (int)$_POST['todo_id'];
    
    // Verificar permissão
    $stmt = $pdo->prepare('SELECT autor, responsavel FROM todos WHERE id = ?');
    $stmt->execute([$todo_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task || ($task['autor'] != $_SESSION['user_id'] && $task['responsavel'] != $_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Sem permissão']);
        exit;
    }
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/files/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_name = basename($_FILES['file']['name']);
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $new_name = uniqid() . '_' . time() . '.' . $file_ext;
        $file_path = $upload_dir . $new_name;
        
        if (move_uploaded_file($_FILES['file']['tmp_name'], $file_path)) {
            $stmt = $pdo->prepare('INSERT INTO task_files (todo_id, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)');
            $stmt->execute([$todo_id, $file_name, 'files/' . $new_name, $_SESSION['user_id']]);
            
            echo json_encode([
                'success' => true,
                'file_id' => $pdo->lastInsertId(),
                'file_name' => $file_name,
                'file_path' => 'files/' . $new_name
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao mover ficheiro']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Erro no upload']);
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
    
    if (!$file || ($file['autor'] != $_SESSION['user_id'] && $file['responsavel'] != $_SESSION['user_id'])) {
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
    
    if (!$task || ($task['autor'] != $_SESSION['user_id'] && $task['responsavel'] != $_SESSION['user_id'])) {
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
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
}

/* Editor Markdown */
.markdown-toolbar {
    display: flex;
    gap: 5px;
    margin-bottom: 10px;
    padding: 10px;
    background: #f5f5f5;
    border-radius: 8px 8px 0 0;
    border: 2px solid #e0e0e0;
    border-bottom: none;
}

.md-btn {
    background: white;
    border: 1px solid #ddd;
    padding: 8px 12px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.md-btn:hover {
    background: #667eea;
    color: white;
    border-color: #667eea;
}

#descritivo {
    border-radius: 0 0 8px 8px;
    border-top: none;
    min-height: 200px;
    font-family: 'Courier New', monospace;
}

/* Checklist */
.checklist-container {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    background: #fafafa;
}

.checklist-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: white;
    border-radius: 6px;
    margin-bottom: 8px;
    transition: all 0.2s;
}

.checklist-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.checklist-item input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.checklist-item input[type="text"] {
    flex: 1;
    border: none;
    background: transparent;
    padding: 5px;
}

.checklist-item.checked input[type="text"] {
    text-decoration: line-through;
    color: #999;
}

.checklist-item button {
    background: #ff4444;
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 4px;
    cursor: pointer;
}

.btn-add-checklist {
    background: #4CAF50;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    margin-top: 10px;
}

/* Ficheiros */
.files-container {
    border: 2px dashed #e0e0e0;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    background: #fafafa;
}

.file-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: white;
    border-radius: 6px;
    margin-bottom: 8px;
}

.task-editor-footer {
    padding: 20px 30px;
    background: #f8f8f8;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.btn {
    padding: 12px 30px;
    border: none;
    border-radius: 8px;
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
                        <option value="aberta">🟡 Aberta</option>
                        <option value="em execução">🔵 Em Execução</option>
                        <option value="suspensa">🟠 Suspensa</option>
                        <option value="concluída">🟢 Concluída</option>
                    </select>
                </div>
                
                <!-- Editor Markdown -->
                <div class="form-group">
                    <label>📄 Descrição (Markdown)</label>
                    <div class="markdown-toolbar">
                        <button type="button" class="md-btn" onclick="insertMarkdown('**', '**')" title="Negrito"><b>B</b></button>
                        <button type="button" class="md-btn" onclick="insertMarkdown('*', '*')" title="Itálico"><i>I</i></button>
                        <button type="button" class="md-btn" onclick="insertMarkdown('# ', '')" title="Título">H1</button>
                        <button type="button" class="md-btn" onclick="insertMarkdown('## ', '')" title="Subtítulo">H2</button>
                        <button type="button" class="md-btn" onclick="insertMarkdown('- ', '')" title="Lista">• Lista</button>
                        <button type="button" class="md-btn" onclick="insertMarkdown('> ', '')" title="Citação">❝ Citação</button>
                        <button type="button" class="md-btn" onclick="insertMarkdown('`', '`')" title="Código">{'</>'}</button>
                        <button type="button" class="md-btn" onclick="insertMarkdown('[', '](url)')" title="Link">🔗 Link</button>
                    </div>
                    <textarea id="descritivo" name="descritivo" class="form-control" rows="8"></textarea>
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
// Variável global para armazenar a checklist
let checklistItems = [];
let taskFiles = [];

// Abrir editor
function openTaskEditor(taskId) {
    // Buscar dados da task
    fetch(`api/get_task_full.php?id=${taskId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Preencher formulário
                document.getElementById('edit_todo_id').value = data.task.id;
                document.getElementById('edit_titulo').value = data.task.titulo || '';
                document.getElementById('descritivo').value = data.task.descritivo || '';
                document.getElementById('edit_data_limite').value = data.task.data_limite || '';
                document.getElementById('edit_responsavel').value = data.task.responsavel || '';
                document.getElementById('edit_estado').value = data.task.estado || 'aberta';
                
                // Carregar checklist
                checklistItems = data.checklist || [];
                renderChecklist();
                
                // Carregar ficheiros
                taskFiles = data.files || [];
                renderFiles();
                
                // Mostrar modal
                document.getElementById('task-editor-overlay').style.display = 'block';
            } else {
                alert('Erro ao carregar task: ' + data.error);
            }
        });
}

// Fechar editor
function closeTaskEditor() {
    document.getElementById('task-editor-overlay').style.display = 'none';
}

// Inserir markdown
function insertMarkdown(before, after) {
    const textarea = document.getElementById('descritivo');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const selectedText = text.substring(start, end);
    
    const newText = text.substring(0, start) + before + selectedText + after + text.substring(end);
    textarea.value = newText;
    
    // Reposicionar cursor
    const newPos = start + before.length + selectedText.length + after.length;
    textarea.setSelectionRange(newPos, newPos);
    textarea.focus();
}

// Adicionar item à checklist
function addChecklistItem(text = '', checked = false) {
    checklistItems.push({ text, checked });
    renderChecklist();
}

// Renderizar checklist
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
    
    fetch('edit_task.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_file&file_id=${fileId}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            taskFiles = taskFiles.filter(f => f.file_id !== fileId);
            renderFiles();
        }
    });
}

// Guardar task
function saveTask() {
    const formData = new FormData(document.getElementById('task-editor-form'));
    formData.append('action', 'save_task');
    formData.append('checklist', JSON.stringify(checklistItems));
    
    fetch('edit_task.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ Task guardada com sucesso!');
            closeTaskEditor();
            location.reload(); // Recarregar página
        } else {
            alert('Erro: ' + data.error);
        }
    });
}

// Fechar com ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeTaskEditor();
});
</script>