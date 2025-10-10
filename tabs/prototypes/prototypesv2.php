<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prototypes Management - PikachuPM</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fa;
            color: #1a202c;
        }

        .container {
            display: flex;
            height: 100vh;
            max-width: 100%;
        }

        /* Left Panel - Lista de Protótipos */
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
            margin-top: 10px;
        }

        .search-box input {
            flex: 1;
            padding: 10px;
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
            font-weight: 600;
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
            background: #64748b;
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 13px;
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

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
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

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-header h3,
        .section-header h4 {
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
            word-wrap: break-word;
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

        /* Edit Button */
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

        /* Links styling */
        .info-value a {
            color: #3b82f6;
            text-decoration: underline;
            transition: color 0.2s;
        }

        .info-value a:hover {
            color: #2563eb;
        }

        /* Form Group */
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
        .form-group textarea,
        .form-group select {
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

        /* Action Bar */
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
            margin-bottom: 10px;
            border-radius: 4px;
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

        .story-item.won\'t {
            border-left-color: #94a3b8;
        }

        .story-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .story-priority {
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-must {
            background: #fee2e2;
            color: #991b1b;
        }

        .priority-should {
            background: #fef3c7;
            color: #92400e;
        }

        .priority-could {
            background: #dbeafe;
            color: #1e40af;
        }

        .priority-won\'t {
            background: #f3f4f6;
            color: #6b7280;
        }

        .story-actions {
            display: flex;
            gap: 5px;
        }

        .story-text {
            font-size: 14px;
            color: #1a202c;
            line-height: 1.6;
        }

        /* Modal */
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
            margin: 0;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #94a3b8;
        }

        .close-modal:hover {
            color: #1a202c;
        }

        /* Task List */
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
            margin-left: 8px;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Participants Table Styles */
        #participantsTable table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        #participantsTable th {
            background: #f9fafb;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e5e7eb;
        }

        #participantsTable td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        #participantsTable tr:hover {
            background: #f9fafb;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }

            .left-panel {
                width: 100%;
                height: 300px;
            }

            .info-grid,
            .vision-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Left Panel -->
        <div class="left-panel">
            <div class="panel-header">
                <h2>📋 Prototypes</h2>
                <div class="search-box">
                    <label for="searchInput" style="display:none;">Search prototypes</label>
                    <input type="text" id="searchInput" placeholder="Search prototypes..." aria-label="Search prototypes">
                    <button class="btn btn-primary" onclick="createNewPrototype()" aria-label="Create new prototype">+ New</button>
                </div>
            </div>
            <div class="prototypes-list" id="prototypesList" role="list" aria-label="Prototypes list">
                <div class="empty-state">
                    <h3>No prototypes yet</h3>
                    <p>Create your first prototype to get started</p>
                </div>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="right-panel" id="detailPanel" role="main" aria-label="Prototype details">
            <div class="empty-state">
                <h3>Select a prototype</h3>
                <p>Choose a prototype from the list to view details</p>
            </div>
        </div>
    </div>

    <script>
        // Configurar caminho da API dinamicamente
        const isIncluded = window.location.search.includes('tab=');
        const basePath = isIncluded ? 'tabs/prototypes/' : '';
        window.PROTOTYPES_API_PATH = basePath + 'prototypes_api.php';
        
        console.log('API Path configured:', window.PROTOTYPES_API_PATH);
        console.log('Script location:', window.location.href);
    </script>
    
    <!-- Carregar o JavaScript -->
    <script src="<?php echo (strpos($_SERVER['REQUEST_URI'], 'tab=') !== false) ? 'tabs/prototypes/' : ''; ?>prototypes.js"></script>


    

</body>
</html>