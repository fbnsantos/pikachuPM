<?php
// tabs/ai_management.php — Gestão de IA: configuração de provider, delegados, histórico de resumos

require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Erro de ligação à base de dados.</div>';
    return;
}

$currentUserId = $_SESSION['user_id'] ?? null;

// Ensure tables exist
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, username VARCHAR(100) NOT NULL,
    added_by INT NULL, added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS ai_delegates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, granted_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS ai_summaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL, window_type VARCHAR(10) NOT NULL,
    provider VARCHAR(30) NOT NULL, model VARCHAR(100) NOT NULL,
    summary TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Check access: admin or delegate
$stAdm = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
$stAdm->execute([$currentUserId]);
$isAdmin = (bool)$stAdm->fetch();

$stDel = $pdo->prepare("SELECT id FROM ai_delegates WHERE user_id = ?");
$stDel->execute([$currentUserId]);
$isDelegate = (bool)$stDel->fetch();

if (!$isAdmin && !$isDelegate) {
    echo '<div class="alert alert-danger"><i class="bi bi-lock"></i> <strong>Acesso Negado</strong><br>Esta área é restrita a administradores e delegados.</div>';
    return;
}

// Helper
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['setting_value'] : $default;
}
function setSetting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
        ->execute([$key, $value]);
}

// --- Process POST (admin only for config changes) ---
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        setSetting($pdo, 'ai_provider',    $_POST['ai_provider'] ?? 'claude');
        setSetting($pdo, 'ai_model',       trim($_POST['ai_model'] ?? ''));
        setSetting($pdo, 'ai_ollama_url',  trim($_POST['ai_ollama_url'] ?? 'http://localhost:11434'));
        if (!empty($_POST['ai_api_key']) && $_POST['ai_api_key'] !== '••••••••') {
            setSetting($pdo, 'ai_api_key', trim($_POST['ai_api_key']));
        }
        $flash = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle"></i> Configuração guardada.</div>';
    }

    elseif ($action === 'add_delegate') {
        $uid = (int)($_POST['delegate_user_id'] ?? 0);
        if ($uid > 0 && $uid != $currentUserId) {
            try {
                $pdo->prepare("INSERT IGNORE INTO ai_delegates (user_id, granted_by) VALUES (?,?)")->execute([$uid, $currentUserId]);
                $flash = '<div class="alert alert-success"><i class="bi bi-person-check"></i> Delegado adicionado.</div>';
            } catch (Exception $e) {}
        }
    }

    elseif ($action === 'remove_delegate') {
        $uid = (int)($_POST['delegate_user_id'] ?? 0);
        $pdo->prepare("DELETE FROM ai_delegates WHERE user_id = ?")->execute([$uid]);
        $flash = '<div class="alert alert-warning"><i class="bi bi-person-dash"></i> Delegado removido.</div>';
    }
}

// Load current config
$cfgProvider  = getSetting($pdo, 'ai_provider', 'claude');
$cfgModel     = getSetting($pdo, 'ai_model', '');
$cfgApiKey    = getSetting($pdo, 'ai_api_key', '');
$cfgOllamaUrl = getSetting($pdo, 'ai_ollama_url', 'http://localhost:11434');
$hasKey       = !empty($cfgApiKey);

// Load delegates (admin only)
$delegates = [];
if ($isAdmin) {
    $delegates = $pdo->query("SELECT ad.user_id, u.username, ad.created_at FROM ai_delegates ad LEFT JOIN utilizadores u ON u.id = ad.user_id ORDER BY ad.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $allUsers = $pdo->query("SELECT id, username FROM utilizadores WHERE ativo = 1 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    $delegateIds = array_column($delegates, 'user_id');
}

// Recent summaries
$summaries = $pdo->query("SELECT s.id, s.window_type, s.provider, s.model, LEFT(s.summary, 300) as preview, s.created_at, u.username
    FROM ai_summaries s LEFT JOIN utilizadores u ON u.id = s.user_id
    ORDER BY s.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

// Get user token for JS
$stToken = $pdo->prepare("SELECT token FROM user_tokens WHERE user_id = ? AND expires_at > NOW() ORDER BY expires_at DESC LIMIT 1");
$stToken->execute([$currentUserId]);
$userToken = ($stToken->fetch(PDO::FETCH_ASSOC))['token'] ?? '';

$defaultModels = [
    'claude' => 'claude-haiku-5-5',
    'openai' => 'gpt-4o-mini',
    'ollama' => 'llama3',
];
?>

<style>
.ai-mgmt-card { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
.ai-provider-btn { cursor: pointer; border: 2px solid transparent; border-radius: 8px; padding: .5rem 1rem; transition: border-color .2s; }
.ai-provider-btn.active { border-color: var(--bs-primary); background: rgba(var(--bs-primary-rgb),.08); }
.summary-card { border-left: 3px solid var(--bs-primary); padding: .75rem 1rem; margin-bottom:.75rem; background: var(--bs-tertiary-bg); border-radius: 0 6px 6px 0; }
.summary-preview { white-space: pre-wrap; font-size: .85em; max-height: 120px; overflow: hidden; }
#ai-output { white-space: pre-wrap; background: var(--bs-tertiary-bg); border-radius: 8px; padding: 1rem; min-height: 100px; font-size: .9em; line-height: 1.6; }
</style>

<div class="container-fluid px-0">
    <div class="d-flex align-items-center gap-3 mb-4">
        <h4 class="mb-0"><i class="bi bi-robot"></i> Gestão de Inteligência Artificial</h4>
        <?php if ($isAdmin): ?><span class="badge bg-danger">Admin</span><?php else: ?><span class="badge bg-secondary">Delegado</span><?php endif; ?>
    </div>

    <?= $flash ?>

    <!-- Resumo rápido -->
    <div class="ai-mgmt-card">
        <h5 class="mb-3"><i class="bi bi-lightning-charge text-warning"></i> Resumo IA</h5>
        <p class="text-muted small mb-3">Gera uma análise do estado atual da equipa com base nos dados do sistema.</p>
        <div class="row g-3 align-items-end mb-3">
            <div class="col-sm-auto">
                <label class="form-label small">Janela de dados</label>
                <select id="ai-window" class="form-select form-select-sm">
                    <option value="1h">Última hora</option>
                    <option value="24h" selected>Últimas 24h</option>
                    <option value="7d">Últimos 7 dias</option>
                    <option value="all">Tudo</option>
                </select>
            </div>
            <div class="col">
                <label class="form-label small">Instrução adicional (opcional)</label>
                <input type="text" id="ai-custom-prompt" class="form-control form-control-sm" placeholder="ex: foca-te nos bloqueios do sprint atual">
            </div>
            <div class="col-sm-auto">
                <button class="btn btn-primary btn-sm" id="ai-run-btn" onclick="aiRunSummary()">
                    <i class="bi bi-play-circle"></i> Gerar Resumo
                </button>
            </div>
        </div>
        <div id="ai-status" class="text-muted small mb-2" style="display:none"><i class="bi bi-hourglass-split"></i> A processar…</div>
        <div id="ai-output" style="display:none"></div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Configuração do provider (admin only) -->
    <div class="ai-mgmt-card">
        <h5 class="mb-3"><i class="bi bi-gear"></i> Configuração do Provider</h5>
        <form method="post">
            <input type="hidden" name="action" value="save_config">

            <div class="mb-3">
                <label class="form-label small fw-bold">Provider LLM</label>
                <div class="d-flex gap-2 flex-wrap">
                    <?php foreach (['claude' => '🤖 Claude (Anthropic)', 'openai' => '🟢 OpenAI / ChatGPT', 'ollama' => '🦙 Ollama (local)'] as $pv => $pl): ?>
                    <div class="ai-provider-btn <?= $cfgProvider === $pv ? 'active' : '' ?>"
                         onclick="document.querySelectorAll('.ai-provider-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');document.getElementById('ai_provider').value='<?= $pv ?>';aiUpdateProviderUI()">
                        <?= $pl ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="ai_provider" id="ai_provider" value="<?= htmlspecialchars($cfgProvider) ?>">
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Modelo</label>
                    <input type="text" name="ai_model" id="ai_model" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($cfgModel) ?>"
                           placeholder="<?= htmlspecialchars($defaultModels[$cfgProvider] ?? '') ?>">
                    <div class="form-text" id="ai-model-hint"></div>
                </div>
                <div class="col-md-6" id="ai-apikey-row">
                    <label class="form-label small fw-bold">API Key</label>
                    <input type="password" name="ai_api_key" class="form-control form-control-sm"
                           value="" placeholder="<?= $hasKey ? '••••••••  (guardada)' : 'Insere a chave de API' ?>">
                    <?php if ($hasKey): ?><div class="form-text text-success"><i class="bi bi-check-circle"></i> Chave configurada</div><?php endif; ?>
                </div>
                <div class="col-md-6" id="ai-ollama-row" style="<?= $cfgProvider !== 'ollama' ? 'display:none' : '' ?>">
                    <label class="form-label small fw-bold">URL do Ollama</label>
                    <input type="text" name="ai_ollama_url" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($cfgOllamaUrl) ?>" placeholder="http://localhost:11434">
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Guardar Configuração</button>
            </div>
        </form>
    </div>

    <!-- Gestão de delegados -->
    <div class="ai-mgmt-card">
        <h5 class="mb-3"><i class="bi bi-people"></i> Delegados com Acesso</h5>
        <p class="text-muted small mb-3">Utilizadores autorizados a usar o resumo IA sem serem admins.</p>

        <form method="post" class="d-flex gap-2 mb-3">
            <input type="hidden" name="action" value="add_delegate">
            <select name="delegate_user_id" class="form-select form-select-sm" style="max-width:280px">
                <option value="">— Seleciona utilizador —</option>
                <?php foreach ($allUsers as $u): if (in_array($u['id'], $delegateIds)) continue; ?>
                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-person-plus"></i> Adicionar</button>
        </form>

        <?php if (empty($delegates)): ?>
        <p class="text-muted small"><i class="bi bi-info-circle"></i> Nenhum delegado configurado.</p>
        <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($delegates as $d): ?>
            <div class="list-group-item d-flex align-items-center gap-2 px-0">
                <i class="bi bi-person-badge text-primary"></i>
                <span class="flex-grow-1"><?= htmlspecialchars($d['username'] ?? 'ID:'.$d['user_id']) ?></span>
                <small class="text-muted"><?= date('d/m/Y', strtotime($d['created_at'])) ?></small>
                <form method="post" class="mb-0">
                    <input type="hidden" name="action" value="remove_delegate">
                    <input type="hidden" name="delegate_user_id" value="<?= $d['user_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remover delegado?')"><i class="bi bi-person-dash"></i></button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Histórico de resumos -->
    <div class="ai-mgmt-card">
        <h5 class="mb-3"><i class="bi bi-clock-history"></i> Histórico de Resumos</h5>
        <?php if (empty($summaries)): ?>
        <p class="text-muted small">Nenhum resumo gerado ainda.</p>
        <?php else: ?>
        <?php foreach ($summaries as $s): ?>
        <div class="summary-card">
            <div class="d-flex gap-2 align-items-center mb-1 flex-wrap">
                <small class="text-muted"><?= date('d/m/Y H:i', strtotime($s['created_at'])) ?></small>
                <span class="badge bg-secondary"><?= htmlspecialchars($s['window_type']) ?></span>
                <span class="badge bg-primary"><?= htmlspecialchars($s['provider']) ?></span>
                <?php if ($s['model']): ?><span class="badge bg-light text-dark border"><?= htmlspecialchars($s['model']) ?></span><?php endif; ?>
                <small class="text-muted ms-auto"><?= htmlspecialchars($s['username'] ?? '') ?></small>
            </div>
            <div class="summary-preview"><?= htmlspecialchars($s['preview']) ?><?= strlen($s['preview']) >= 300 ? '…' : '' ?></div>
            <button class="btn btn-link btn-sm p-0 mt-1" onclick="aiLoadFull(<?= $s['id'] ?>)">Ver completo</button>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Full summary modal -->
<div class="modal fade" id="aiFullModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Resumo IA</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><pre id="aiFullText" style="white-space:pre-wrap; font-size:.9em"></pre></div>
        </div>
    </div>
</div>

<script>
(function(){
    const TOKEN = <?= json_encode($userToken) ?>;
    const BASE  = window.location.href.split('?')[0].replace(/\/[^/]*$/, '/');

    window.aiRunSummary = function() {
        const btn = document.getElementById('ai-run-btn');
        const status = document.getElementById('ai-status');
        const output = document.getElementById('ai-output');
        btn.disabled = true;
        status.style.display = '';
        output.style.display = 'none';
        output.textContent = '';

        fetch(BASE + 'api/ai_summary.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + TOKEN },
            body: JSON.stringify({
                window: document.getElementById('ai-window').value,
                prompt: document.getElementById('ai-custom-prompt').value,
            })
        })
        .then(r => r.json())
        .then(d => {
            status.style.display = 'none';
            output.style.display = '';
            if (d.error) {
                output.style.borderLeftColor = 'var(--bs-danger)';
                output.textContent = '❌ Erro: ' + d.error;
            } else {
                output.style.borderLeftColor = 'var(--bs-success)';
                output.textContent = d.summary;
                // Reload page to update history
                setTimeout(() => location.reload(), 3000);
            }
        })
        .catch(e => {
            status.style.display = 'none';
            output.style.display = '';
            output.textContent = '❌ Erro de rede: ' + e.message;
        })
        .finally(() => { btn.disabled = false; });
    };

    window.aiLoadFull = function(id) {
        fetch(BASE + 'api/ai_summary_detail.php?id=' + id, {
            headers: { 'Authorization': 'Bearer ' + TOKEN }
        })
        .then(r => r.json())
        .then(d => {
            document.getElementById('aiFullText').textContent = d.summary || d.error || 'Sem dados';
            new bootstrap.Modal(document.getElementById('aiFullModal')).show();
        });
    };

    // Provider UI
    window.aiUpdateProviderUI = function() {
        const pv = document.getElementById('ai_provider').value;
        const hints = {
            claude: 'Ex: claude-haiku-5-5, claude-sonnet-4-5',
            openai: 'Ex: gpt-4o-mini, gpt-4o',
            ollama: 'Ex: llama3, mistral, phi3',
        };
        const defaults = { claude:'claude-haiku-5-5', openai:'gpt-4o-mini', ollama:'llama3' };
        const hintEl = document.getElementById('ai-model-hint');
        if (hintEl) hintEl.textContent = hints[pv] || '';
        const modelEl = document.getElementById('ai_model');
        if (modelEl && !modelEl.value) modelEl.placeholder = defaults[pv] || '';
        const ollamaRow = document.getElementById('ai-ollama-row');
        if (ollamaRow) ollamaRow.style.display = pv === 'ollama' ? '' : 'none';
        const apikeyRow = document.getElementById('ai-apikey-row');
        if (apikeyRow) apikeyRow.style.display = pv === 'ollama' ? 'none' : '';
    };
    aiUpdateProviderUI();
})();
</script>
