<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prototypes Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            color: #2c3e50;
        }

        .container {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* Left Panel - Lista */
        .left-panel {
            width: 380px;
            background: white;
            border-right: 1px solid #e1e8ed;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 20px;
            border-bottom: 1px solid #e1e8ed;
        }

        .panel-header h2 {
            font-size: 24px;
            margin-bottom: 15px;
            color: #1a202c;
        }

        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .search-box input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #e1e8ed;
            border-radius: 6px;
            font-size: 14px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-secondary {
            background: #e1e8ed;
            color: #2c3e50;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .prototypes-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }

        .prototype-item {
            padding: 15px;
            margin-bottom: 10px;
            background: #f9fafb;
            border: 2px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .prototype-item:hover {
            background: #f3f4f6;
            border-color: #e5e7eb;
        }

        .prototype-item.active {
            background: #eff6ff;
            border-color: #3b82f6;
        }

        .prototype-item h3 {
            font-size: 16px;
            margin-bottom: 5px;
            color: #1a202c;
        }

        .prototype-item p {
            font-size: 13px;
            color: #64748b;
        }

        /* Right Panel - Detalhes */
        .right-panel {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }

        .detail-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .detail-section h3 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
            color: #4a5568;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e1e8ed;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .action-bar {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e1e8ed;
        }

        /* User Stories */
        .story-item {
            background: #f9fafb;
            border-left: 4px solid #94a3b8;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 6px;
        }

        .story-item.must {
            border-left-color: #ef4444;
        }

        .story-item.should {
            border-left-color: #f59e0b;
        }

        .story-item.could {
            border-left-color: #3b82f6;
        }

        .story-item.wont {
            border-left-color: #94a3b8;
        }

        .story-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .story-priority {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .priority-must {
            background: #fee2e2;
            color: #991b1b;
        }

        .priority-should {
            background: #fed7aa;
            color: #92400e;
        }

        .priority-could {
            background: #dbeafe;
            color: #1e40af;
        }

        .priority-wont {
            background: #e2e8f0;
            color: #475569;
        }

        .story-text {
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 10px;
        }

        .story-actions {
            display: flex;
            gap: 8px;
        }

        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .filter-bar select {
            padding: 8px 12px;
            border: 1px solid #e1e8ed;
            border-radius: 6px;
            font-size: 14px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 20px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #94a3b8;
        }

        .task-list {
            margin-top: 15px;
        }

        .task-item {
            background: white;
            border: 1px solid #e1e8ed;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        /* Adicionar ao prototypesv2.php dentro da tag <style> */

/* Section Header with Edit Button */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.section-header h3 {
    margin: 0;
}

/* Info Grid (para Basic Information) */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.info-item {
    position: relative;
    padding: 15px;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.info-label {
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.info-value {
    font-size: 16px;
    color: #1a202c;
    min-height: 24px;
}

/* Vision Grid (para Product Vision Board) */
.vision-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 15px;
}

.vision-card {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 15px;
    transition: all 0.2s;
}

.vision-card:hover {
    border-color: #3b82f6;
    box-shadow: 0 4px 6px rgba(59, 130, 246, 0.1);
}

.vision-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e5e7eb;
}

.vision-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin: 0;
}

.vision-content {
    font-size: 14px;
    color: #4b5563;
    line-height: 1.6;
}

.vision-content p {
    margin: 8px 0;
}

.vision-content .list-item {
    padding: 4px 0 4px 16px;
    position: relative;
}

.vision-content .list-item:before {
    content: "•";
    position: absolute;
    left: 0;
    color: #3b82f6;
    font-weight: bold;
}

/* Statement Box */
.statement-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 25px;
    border-radius: 12px;
    font-size: 16px;
    line-height: 1.8;
    font-style: italic;
    box-shadow: 0 4px 6px rgba(102, 126, 234, 0.2);
}

.statement-box p {
    margin: 0;
}

.statement-hint {
    margin-top: 10px;
    padding: 12px;
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
    border-radius: 4px;
}

.statement-hint small {
    color: #92400e;
    font-size: 12px;
}

/* Resources Grid */
.resources-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.resource-card {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 15px;
    transition: all 0.2s;
}

.resource-card:hover {
    border-color: #10b981;
    box-shadow: 0 4px 6px rgba(16, 185, 129, 0.1);
}

.resource-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e5e7eb;
}

.resource-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin: 0;
}

.resource-content {
    font-size: 14px;
}

.link-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid #e5e7eb;
}

.link-item:last-child {
    border-bottom: none;
}

.link-icon {
    font-size: 16px;
    flex-shrink: 0;
}

.link-item a {
    color: #3b82f6;
    text-decoration: none;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.link-item a:hover {
    color: #2563eb;
    text-decoration: underline;
}

.external-icon {
    font-size: 12px;
    color: #9ca3af;
    flex-shrink: 0;
}

/* Edit Button */
.edit-btn {
    background: transparent;
    border: none;
    font-size: 16px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s;
    opacity: 0.6;
}

.edit-btn:hover {
    background: #e5e7eb;
    opacity: 1;
    transform: scale(1.1);
}

/* Edit Container */
.edit-container {
    width: 100%;
}

.edit-input {
    width: 100%;
    padding: 10px;
    border: 2px solid #3b82f6;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    margin-bottom: 10px;
    transition: border-color 0.2s;
}

.edit-input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

textarea.edit-input {
    resize: vertical;
    min-height: 100px;
}

.edit-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

/* Text Muted */
.text-muted {
    color: #9ca3af;
    font-style: italic;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .info-grid,
    .vision-grid,
    .resources-grid {
        grid-template-columns: 1fr;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}

/* Edit Button for inline editing */
.edit-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 16px;
    color: #94a3b8;
    transition: color 0.2s;
    padding: 5px;
}

.edit-btn:hover {
    color: #3b82f6;
}

/* Vision Card Headers */
.vision-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.vision-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: #4a5568;
    margin: 0;
}

.vision-content {
    font-size: 14px;
    color: #1a202c;
    line-height: 1.6;
    white-space: pre-wrap;
}

/* Links styling */
.info-value a {
    color: #3b82f6;
    text-decoration: underline;
    transition: color 0.2s;
}

.info-value a:hover {
    color: #2563eb;
}

/* Button Sizes */
.btn-small {
    padding: 6px 12px;
    font-size: 13px;
}
    </style>
</head>
<body>
    <div class="container">
        <!-- Left Panel -->
        <div class="left-panel">
            <div class="panel-header">
                <h2>Prototypes</h2>
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search prototypes...">
                    <button class="btn btn-primary" onclick="createNewPrototype()">+ New</button>
                </div>
            </div>
            <div class="prototypes-list" id="prototypesList">
                <div class="empty-state">
                    <h3>No prototypes yet</h3>
                    <p>Create your first prototype to get started</p>
                </div>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="right-panel" id="detailPanel">
            <div class="empty-state">
                <h3>Select a prototype</h3>
                <p>Choose a prototype from the list to view details</p>
            </div>
        </div>
    </div>

    <!-- Modal for User Story -->
    <div class="modal" id="storyModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="storyModalTitle">New User Story</h3>
                <button class="close-modal" onclick="closeStoryModal()">&times;</button>
            </div>
            <div class="form-group">
                <label>Story Text</label>
                <textarea id="storyText" placeholder="As a [user type], I want to [action], so that I [benefit]"></textarea>
            </div>
            <div class="form-group">
                <label>MoSCoW Priority</label>
                <select id="storyPriority">
                    <option value="Must">Must Have</option>
                    <option value="Should" selected>Should Have</option>
                    <option value="Could">Could Have</option>
                    <option value="Won't">Won't Have</option>
                </select>
            </div>
            <div class="action-bar">
                <button class="btn btn-primary" onclick="saveStory()">Save Story</button>
                <button class="btn btn-secondary" onclick="closeStoryModal()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Modal for Create Task -->
    <div class="modal" id="taskModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Task from Story</h3>
                <button class="close-modal" onclick="closeTaskModal()">&times;</button>
            </div>
            <div class="form-group">
                <label>Task Title</label>
                <input type="text" id="taskTitle" placeholder="Task title">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="taskDescription" placeholder="Task description"></textarea>
            </div>
            <div class="form-group">
                <label>Priority</label>
                <select id="taskPriority">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                </select>
            </div>
            <div class="action-bar">
                <button class="btn btn-primary" onclick="createTaskFromStory()">Create Task</button>
                <button class="btn btn-secondary" onclick="closeTaskModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        // Detectar se está sendo incluído ou executado diretamente
        const isIncluded = window.location.search.includes('tab=');
        const basePath = isIncluded ? 'tabs/prototypes/' : '';
        
        // Ajustar caminho da API dinamicamente
        window.PROTOTYPES_API_PATH = basePath + 'prototypes_api.php';
    </script>
    <script src="<?php echo (strpos($_SERVER['REQUEST_URI'], 'tab=') !== false) ? 'tabs/prototypes/' : ''; ?>prototypes.js"></script>
</body>
</html>