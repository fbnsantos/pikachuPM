<?php
// prototypesv2.php - VERSÃO SIMPLES com apenas ordenação alfabética
// Sem header HTML - para include no index.php
?>
<style>
    .prototypes-container {
        display: flex;
        height: calc(100vh - 200px);
        background: #f8f9fa;
    }

    .left-panel {
        width: 350px;
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
    }

    .search-box {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
    }

    .search-box input {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #e1e8ed;
        border-radius: 6px;
        font-size: 14px;
    }

    /* Checkbox de ordenação */
    .sort-box {
        padding: 15px 20px;
        background: #f8f9fa;
        border-bottom: 1px solid #e1e8ed;
    }

    .sort-box label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        cursor: pointer;
        user-select: none;
    }

    .sort-box input[type="checkbox"] {
        width: 16px;
        height: 16px;
        cursor: pointer;
    }

    .prototypes-list {
        flex: 1;
        overflow-y: auto;
        padding: 10px;
    }

    .prototype-item {
        padding: 15px;
        margin-bottom: 8px;
        background: #f8f9fa;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        border: 2px solid transparent;
    }

    .prototype-item:hover {
        background: #e9ecef;
        transform: translateX(4px);
    }

    .prototype-item.active {
        background: #e3f2fd;
        border-color: #3b82f6;
    }

    .prototype-name {
        font-weight: 600;
        font-size: 14px;
    }

    .right-panel {
        flex: 1;
        overflow-y: auto;
        padding: 30px;
        background: white;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #94a3b8;
    }

    .empty-state h3 {
        font-size: 24px;
        margin-bottom: 10px;
    }

    .detail-header {
        display: flex;
        justify-content: space-between;
        align-items: start;
        margin-bottom: 30px;
    }

    .detail-title {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 10px;
    }

    .detail-actions {
        display: flex;
        gap: 10px;
    }

    .section {
        background: white;
        padding: 25px;
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .section h3 {
        font-size: 16px;
        margin-bottom: 15px;
        color: #1e293b;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
        font-weight: 500;
    }

    .btn-primary {
        background: #3b82f6;
        color: white;
    }

    .btn-primary:hover {
        background: #2563eb;
    }

    .btn-small {
        padding: 6px 12px;
        font-size: 13px;
    }

    .btn-danger {
        background: #ef4444;
        color: white;
    }

    .btn-danger:hover {
        background: #dc2626;
    }

    .btn-secondary {
        background: #e5e7eb;
        color: #1f2937;
    }

    .btn-secondary:hover {
        background: #d1d5db;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }

    .info-item {
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
        margin-bottom: 8px;
    }

    .info-value {
        font-size: 14px;
        color: #1a202c;
    }

    .stories-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .story-item {
        padding: 15px;
        background: #f8fafc;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }

    .story-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }

    .story-actions {
        display: flex;
        gap: 8px;
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

    .text-muted {
        color: #9ca3af;
        font-style: italic;
    }

    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
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
        width: 90%;
        max-width: 600px;
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

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        font-size: 14px;
        color: #374151;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
        font-family: inherit;
    }

    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }

    .action-bar {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 20px;
    }
</style>

<div class="prototypes-container">
    <!-- Left Panel -->
    <div class="left-panel">
        <div class="panel-header">
            <h2>Prototypes</h2>
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search prototypes...">
                <button class="btn btn-primary" onclick="createNewPrototype()">+ New</button>
            </div>
        </div>

        <!-- Checkbox de ordenação -->
        <div class="sort-box">
            <label>
                <input type="checkbox" id="sortAlphabetical">
                Ordenar alfabeticamente
            </label>
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

<script>
    const isIncluded = window.location.search.includes('tab=');
    const basePath = isIncluded ? 'tabs/prototypes/' : '';
    window.PROTOTYPES_API_PATH = basePath + 'prototypes_api.php';
</script>
<script src="<?php echo (strpos($_SERVER['REQUEST_URI'], 'tab=') !== false) ? 'tabs/prototypes/' : ''; ?>prototypes.js"></script>