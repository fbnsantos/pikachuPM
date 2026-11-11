<?php
// tabs/ai_management.php — Gestão de IA: configuração de provider, delegados, histórico de resumos

ini_set('display_errors', 1);
error_reporting(E_ALL);

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
if (!function_exists('getSetting')) :
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['setting_value'] : $default;
}
endif;
if (!function_exists('setSetting')) :
function setSetting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
        ->execute([$key, $value]);
}
endif;

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

    elseif ($action === 'create_api_key') {
        $keyName = trim($_POST['key_name'] ?? '');
        if ($keyName) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_api_keys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                api_key VARCHAR(64) NOT NULL,
                created_by INT NOT NULL,
                last_used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_key (api_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $newKey = 'pk-' . bin2hex(random_bytes(24));
            $pdo->prepare("INSERT INTO ai_api_keys (name, api_key, created_by) VALUES (?,?,?)")->execute([$keyName, $newKey, $currentUserId]);
            $newKeyName = $keyName; // used below to render prompt panel
        }
    }

    elseif ($action === 'revoke_api_key') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $pdo->prepare("DELETE FROM ai_api_keys WHERE id = ?")->execute([$kid]);
        $flash = '<div class="alert alert-warning"><i class="bi bi-trash"></i> Chave revogada.</div>';
    }

    elseif ($action === 'show_prompts') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $st = $pdo->prepare("SELECT name, api_key FROM ai_api_keys WHERE id = ?");
        $st->execute([$kid]);
        $showPromptsRow = $st->fetch(PDO::FETCH_ASSOC);
        if ($showPromptsRow) {
            $newKey     = $showPromptsRow['api_key'];
            $newKeyName = $showPromptsRow['name'];
        }
    }
}

// Load current config
$cfgProvider  = getSetting($pdo, 'ai_provider', 'claude');
$cfgModel     = getSetting($pdo, 'ai_model', '');
$cfgApiKey    = getSetting($pdo, 'ai_api_key', '');
$cfgOllamaUrl = getSetting($pdo, 'ai_ollama_url', 'http://localhost:11434');
$hasKey       = !empty($cfgApiKey);

// Load delegates + API keys (admin only)
$delegates  = [];
$apiKeys    = [];
if ($isAdmin) {
    $delegates = $pdo->query("SELECT ad.user_id, ut.username, ad.created_at FROM ai_delegates ad LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) ut ON ut.user_id = ad.user_id ORDER BY ad.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $allUsers  = $pdo->query("SELECT DISTINCT user_id as id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    $delegateIds = array_column($delegates, 'user_id');
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            api_key VARCHAR(64) NOT NULL,
            created_by INT NOT NULL,
            last_used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_key (api_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $apiKeys = $pdo->query("SELECT id, name, CONCAT(LEFT(api_key,10),'…') as key_preview, last_used_at, created_at FROM ai_api_keys ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Recent summaries
$summaries = $pdo->query("SELECT s.id, s.window_type, s.provider, s.model, LEFT(s.summary, 300) as preview, s.created_at, ut.username
    FROM ai_summaries s LEFT JOIN (SELECT DISTINCT user_id, username FROM user_tokens) ut ON ut.user_id = s.user_id
    ORDER BY s.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

// $user_api_token is set by index.php before including this tab
$userToken = $user_api_token ?? '';
if (!$userToken) {
    $stToken = $pdo->prepare("SELECT token FROM user_tokens WHERE user_id = ? LIMIT 1");
    $stToken->execute([$currentUserId]);
    $userToken = ($stToken->fetch(PDO::FETCH_ASSOC))['token'] ?? '';
}

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
        <div class="d-flex align-items-center gap-2 mb-3">
            <h5 class="mb-0"><i class="bi bi-gear"></i> Configuração do Provider</h5>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="collapse" data-bs-target="#ai-howto" aria-expanded="false">
                <i class="bi bi-question-circle"></i> Como configurar
            </button>
        </div>

        <div class="collapse mb-3" id="ai-howto">
            <div class="card card-body border-0 bg-light" style="font-size:.875rem">
                <ul class="nav nav-tabs nav-tabs-sm mb-3" id="howtoTabs">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#howto-claude">🤖 Claude</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#howto-openai">🟢 OpenAI</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#howto-ollama">🦙 Ollama</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="howto-claude">
                        <ol class="mb-2">
                            <li>Cria conta em <strong>console.anthropic.com</strong></li>
                            <li>Vai a <em>API Keys</em> → <strong>Create Key</strong></li>
                            <li>Copia a chave (<code>sk-ant-…</code>) e cola no campo <em>API Key</em></li>
                            <li>Escolhe o modelo — recomendado: <code>claude-haiku-5-5</code> (rápido/barato) ou <code>claude-sonnet-4-5</code> (melhor qualidade)</li>
                        </ol>
                        <div class="alert alert-warning py-1 px-2 mb-0" style="font-size:.8rem">
                            <i class="bi bi-exclamation-triangle"></i> Requer créditos na conta Anthropic. Os custos são muito baixos para uso ocasional.
                        </div>
                    </div>
                    <div class="tab-pane fade" id="howto-openai">
                        <ol class="mb-2">
                            <li>Cria conta em <strong>platform.openai.com</strong></li>
                            <li>Vai a <em>API Keys</em> → <strong>Create new secret key</strong></li>
                            <li>Copia a chave (<code>sk-…</code>) e cola no campo <em>API Key</em></li>
                            <li>Escolhe o modelo — recomendado: <code>gpt-4o-mini</code> (barato) ou <code>gpt-4o</code> (melhor)</li>
                        </ol>
                        <div class="alert alert-warning py-1 px-2 mb-0" style="font-size:.8rem">
                            <i class="bi bi-exclamation-triangle"></i> Requer créditos na conta OpenAI. Certifica-te que há saldo em <em>Billing</em>.
                        </div>
                    </div>
                    <div class="tab-pane fade" id="howto-ollama">
                        <ol class="mb-2">
                            <li>Instala o Ollama: <code>curl -fsSL https://ollama.com/install.sh | sh</code></li>
                            <li>Descarrega um modelo: <code>ollama pull llama3</code> (ou <code>mistral</code>, <code>phi3</code>)</li>
                            <li>O servidor fica disponível em <code>http://localhost:11434</code></li>
                            <li>Se o Ollama corre noutro servidor, indica o URL completo no campo <em>URL do Ollama</em></li>
                        </ol>
                        <div class="alert alert-info py-1 px-2 mb-0" style="font-size:.8rem">
                            <i class="bi bi-info-circle"></i> Ollama é gratuito e corre localmente. Não envia dados para serviços externos. Requer uma máquina com RAM suficiente (≥8 GB para modelos pequenos).
                        </div>
                    </div>
                </div>
            </div>
        </div>

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

    <!-- API Keys para assistentes externos -->
    <div class="ai-mgmt-card">
        <h5 class="mb-3"><i class="bi bi-key"></i> API Keys — Assistentes Externos</h5>
        <p class="text-muted small mb-3">Permite que um AI assistente externo (Claude Project, Custom GPT, n8n, etc.) aceda aos dados do PikachuPM directamente.</p>

        <?php
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST']
            . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/api/context.php';

        // Show prompt panel immediately after key creation
        if (!empty($newKey)):
        ?>
        <div class="alert alert-success border-0 mb-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-key-fill fs-5"></i>
                <strong>Chave criada: <?= htmlspecialchars($newKeyName) ?></strong>
                <span class="badge bg-warning text-dark ms-auto">Guarda agora — não será mostrada novamente</span>
            </div>
            <div class="input-group input-group-sm mb-3">
                <input type="text" id="new-key-val" class="form-control font-monospace" value="<?= htmlspecialchars($newKey) ?>" readonly>
                <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('new-key-val').value);this.textContent='✓ Copiado'">
                    <i class="bi bi-clipboard"></i> Copiar
                </button>
            </div>

            <div class="fw-semibold mb-2 small">Prompts prontos a usar — clica para copiar:</div>
            <div class="d-flex flex-column gap-2">

                <?php
                $ep = htmlspecialchars($baseUrl);
                $key = htmlspecialchars($newKey);
                $prompts = [
                    'Claude Project — Project Instructions' => [
                        'icon' => '🤖',
                        'nota' => 'Cola em Project Instructions. A chave vai nas definições do MCP/tool, não aqui.',
                        'text' => "Tens acesso em tempo real aos dados do sistema de gestão PikachuPM via um tool HTTP.

Endpoint: GET {$baseUrl}
Parâmetro window: 1h | 24h | 7d | all

Quando te perguntarem sobre o estado da equipa, tarefas, sprints, issues do laboratório, leads ou projetos, consulta sempre este endpoint antes de responder.
Devolve uma análise estruturada com: (1) Crítico — bloqueios, riscos, atrasos; (2) Progresso — trabalho em curso; (3) Recomendações concretas para as próximas horas.",
                    ],
                    'Claude Project — MCP / HTTP tool config' => [
                        'icon' => '🔑',
                        'nota' => 'Configuração do tool/MCP — inclui a chave. Cola na config do conector HTTP.',
                        'text' => "URL: {$baseUrl}?window=24h
Header: X-Api-Key: {$newKey}",
                    ],
                    'Custom GPT — System Prompt' => [
                        'icon' => '🟢',
                        'nota' => 'Cola em Instructions. A chave vai no campo Authentication do GPT Action, não aqui.',
                        'text' => "You have access to real-time data from PikachuPM project management system.

Use the getPikachuContext action to fetch current team data before answering questions about tasks, sprints, lab issues, leads, or projects.

Always call the API with the appropriate window parameter:
- window=1h for recent activity (last hour)
- window=24h for daily overview
- window=7d for weekly summary
- window=all for full history

Provide structured insights: critical issues (blockers, risks, delays) vs non-critical progress, with actionable recommendations.",
                    ],
                    'Custom GPT — Action Authentication' => [
                        'icon' => '🔑',
                        'nota' => 'No GPT Action: Auth Type = API Key, Header = X-Api-Key.',
                        'text' => $newKey,
                    ],
                    'n8n / Make — HTTP Request node' => [
                        'icon' => '⚙️',
                        'nota' => 'Configuração completa do nó HTTP.',
                        'text' => "URL: {$baseUrl}?window=24h
Method: GET
Header: X-Api-Key = {$newKey}

Prompt para o AI Agent node:
Analisa os dados do sistema PikachuPM em JSON e faz um resumo executivo com: (1) Crítico — bloqueios e riscos, (2) Progresso — tarefas em curso, (3) Recomendações para as próximas horas.",
                    ],
                    'cURL / Terminal' => [
                        'icon' => '💻',
                        'nota' => 'Testa o endpoint diretamente.',
                        'text' => "curl \"{$baseUrl}?window=24h\" \\\n  -H \"X-Api-Key: {$newKey}\"",
                    ],
                ];
                foreach ($prompts as $label => $p):
                    $icon     = $p['icon'];
                    $nota     = $p['nota'] ?? '';
                    $text     = $p['text'];
                    $isKeyOnly = ($text === $newKey);
                    $safeText = htmlspecialchars($text);
                    $jsText   = json_encode($text);
                ?>
                <div class="border rounded p-2" style="background:rgba(255,255,255,.5)">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span><?= $icon ?></span>
                        <span class="small fw-semibold"><?= htmlspecialchars($label) ?></span>
                        <button class="btn btn-xs btn-outline-secondary ms-auto"
                                style="font-size:.75rem; padding:1px 8px"
                                onclick="navigator.clipboard.writeText(<?= $jsText ?>);this.textContent='✓ Copiado';setTimeout(()=>this.textContent='Copiar',2000)">Copiar</button>
                    </div>
                    <?php if ($nota): ?><div class="text-muted" style="font-size:.75rem; margin-bottom:.3rem"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($nota) ?></div><?php endif; ?>
                    <pre style="font-size:.75rem; background:rgba(0,0,0,.04); border-radius:4px; padding:.5rem; margin:0; white-space:pre-wrap; max-height:<?= $isKeyOnly ? '40px' : '110px' ?>; overflow:auto"><?= $safeText ?></pre>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="mb-3 p-3 rounded" style="background:var(--bs-tertiary-bg); font-size:.85rem">
            <div class="fw-bold mb-1"><i class="bi bi-link-45deg"></i> Endpoint</div>
            <div class="d-flex gap-2 align-items-center">
                <code id="ctx-endpoint" class="flex-grow-1" style="word-break:break-all"><?= htmlspecialchars($baseUrl) ?>?window=24h</code>
                <button class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('ctx-endpoint').textContent)"><i class="bi bi-clipboard"></i></button>
            </div>
            <div class="mt-2 text-muted">
                Parâmetro <code>window</code>: <code>1h</code> · <code>24h</code> · <code>7d</code> · <code>all</code><br>
                Autenticação: header <code>X-Api-Key: &lt;chave&gt;</code>
            </div>
        </div>

        <!-- Instruções rápidas por assistente -->
        <div class="mb-3">
            <button class="btn btn-sm btn-outline-info" data-bs-toggle="collapse" data-bs-target="#ai-ext-howto">
                <i class="bi bi-question-circle"></i> Como ligar ao meu assistente
            </button>
            <div class="collapse mt-2" id="ai-ext-howto">
                <div class="card card-body border-0 bg-light" style="font-size:.82rem">
                    <ul class="nav nav-tabs nav-tabs-sm mb-3">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ext-claude">Claude Project</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ext-gpt">Custom GPT</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ext-n8n">n8n / Make</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ext-curl">cURL / API</button></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="ext-claude">
                            <ol>
                                <li>Cria um <strong>Claude Project</strong> em claude.ai</li>
                                <li>Em <em>Project Instructions</em>, adiciona:<br>
                                <pre style="font-size:.8rem; background:#eee; padding:.5rem; border-radius:4px">Tens acesso aos dados em tempo real do sistema PikachuPM via:
GET <?= htmlspecialchars($baseUrl) ?>?window=24h
Header: X-Api-Key: &lt;a_tua_chave&gt;

Usa window=1h para a última hora, 24h para o dia, 7d para a semana, all para tudo.
Quando te perguntarem sobre o estado da equipa, tarefas, sprints ou lab, consulta este endpoint primeiro.</pre>
                                </li>
                                <li>Podes usar a ferramenta <strong>Web Search / HTTP tool</strong> ou um MCP server para fazer o fetch</li>
                            </ol>
                        </div>
                        <div class="tab-pane fade" id="ext-gpt">
                            <ol>
                                <li>Em <strong>ChatGPT → My GPTs → Create</strong>, vai a <em>Actions</em></li>
                                <li>Clica <strong>Import from URL</strong> ou adiciona manualmente com schema:</li>
                            </ol>
                            <pre style="font-size:.78rem; background:#eee; padding:.5rem; border-radius:4px; overflow:auto">openapi: 3.0.0
info:
  title: PikachuPM Context
  version: "1.0"
servers:
  - url: <?= htmlspecialchars(dirname($baseUrl)) ?>

paths:
  /context.php:
    get:
      operationId: getPikachuContext
      summary: Get PikachuPM team data
      parameters:
        - name: window
          in: query
          schema:
            type: string
            enum: [1h, 24h, 7d, all]
      responses:
        "200":
          description: Context data
components:
  securitySchemes:
    ApiKeyAuth:
      type: apiKey
      in: header
      name: X-Api-Key
security:
  - ApiKeyAuth: []</pre>
                            <p class="mb-0 mt-2">Cola a chave no campo de autenticação do GPT.</p>
                        </div>
                        <div class="tab-pane fade" id="ext-n8n">
                            <ol>
                                <li>Adiciona um nó <strong>HTTP Request</strong></li>
                                <li>Method: <code>GET</code>, URL: <code><?= htmlspecialchars($baseUrl) ?>?window=24h</code></li>
                                <li>Em <em>Headers</em> adiciona: <code>X-Api-Key</code> → <em>a tua chave</em></li>
                                <li>Liga ao nó de AI Agent como <em>tool</em> ou processa o JSON directamente</li>
                            </ol>
                        </div>
                        <div class="tab-pane fade" id="ext-curl">
                            <pre style="font-size:.82rem; background:#eee; padding:.5rem; border-radius:4px; overflow:auto">curl "<?= htmlspecialchars($baseUrl) ?>?window=24h" \
  -H "X-Api-Key: pk-a_tua_chave"</pre>
                            <p class="mt-2 mb-0">Devolve JSON com todos os dados agregados da janela pedida.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Criar nova chave -->
        <form method="post" class="d-flex gap-2 mb-3">
            <input type="hidden" name="action" value="create_api_key">
            <input type="text" name="key_name" class="form-control form-control-sm" style="max-width:280px" placeholder="Nome da chave (ex: Claude Assistant)" required>
            <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-plus-circle"></i> Criar chave</button>
        </form>

        <?php if (empty($apiKeys)): ?>
        <p class="text-muted small"><i class="bi bi-info-circle"></i> Nenhuma chave criada.</p>
        <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($apiKeys as $k): ?>
            <div class="list-group-item d-flex align-items-center gap-2 px-0">
                <i class="bi bi-key-fill text-warning"></i>
                <span class="flex-grow-1 fw-medium"><?= htmlspecialchars($k['name']) ?></span>
                <code class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($k['key_preview']) ?></code>
                <small class="text-muted"><?= $k['last_used_at'] ? 'usado ' . date('d/m H:i', strtotime($k['last_used_at'])) : 'nunca usado' ?></small>
                <form method="post" class="mb-0">
                    <input type="hidden" name="action" value="show_prompts">
                    <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-code-square"></i> Prompts</button>
                </form>
                <form method="post" class="mb-0">
                    <input type="hidden" name="action" value="revoke_api_key">
                    <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Revogar esta chave?')"><i class="bi bi-trash"></i></button>
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
    // Token from PHP scope; fallback to DOM element set by index.php
    let TOKEN = <?= json_encode($userToken) ?>;
    if (!TOKEN) {
        const el = document.getElementById('pk-token-value');
        if (el) TOKEN = el.textContent.trim();
    }
    const BASE = window.location.href.split('?')[0].replace(/\/[^/]*$/, '/');
    console.log('[AI] TOKEN length:', TOKEN ? TOKEN.length : 0, '| BASE:', BASE);

    window.aiRunSummary = function() {
        const btn = document.getElementById('ai-run-btn');
        const status = document.getElementById('ai-status');
        const output = document.getElementById('ai-output');

        if (!TOKEN || TOKEN === '(token não disponível)') {
            output.style.display = '';
            output.textContent = '❌ Sem token de autenticação. Recarrega a página.';
            return;
        }

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
