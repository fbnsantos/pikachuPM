<?php
// api/chat.php — Chat por canal (sprint / protótipo / lead), estilo Teams.
//
// Autenticação:
//   • Web  → sessão PHP ($_SESSION['user_id'])
//   • PWA  → header "Authorization: Bearer <token>" (user_tokens.token)
//
// Canais: channel_type ∈ {sprint, prototype, lead}, channel_id = id da entidade.
// Membros do canal = responsável + membros (sprint_members / prototype_members / lead_members).
// Admins (admin_users) podem ler/escrever em qualquer canal.
// Quando a entidade está fechada o canal fica só de leitura (histórico).
//
// Acções (GET):  channels, unread, messages, poll, members, pinned, files, search, file, export
// Acções (POST): send, edit, delete, react, pin, read, typing

header('X-Content-Type-Options: nosniff');

function chat_bearer_token(): ?string {
    $h = function_exists('getallheaders') ? getallheaders() : [];
    foreach (['Authorization', 'authorization'] as $k) {
        if (!empty($h[$k]) && preg_match('/Bearer\s(\S+)/', $h[$k], $m)) return $m[1];
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $m)) return $m[1];
    return null;
}

$CHAT_TOKEN = chat_bearer_token();

if ($CHAT_TOKEN !== null || ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    // Pedidos da PWA (cross-origin, sem cookies)
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Expose-Headers: Content-Disposition');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function chat_err(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}
function chat_json($data): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../config.php';
date_default_timezone_set('Europe/Lisbon');

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    chat_err(500, 'Erro de ligação à base de dados');
}

// ── Schema (migração inline) ────────────────────────────────────
function chat_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        channel_type ENUM('sprint','prototype','lead') NOT NULL,
        channel_id INT NOT NULL,
        user_id INT NOT NULL,
        body TEXT,
        reply_to INT NULL,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        edited_at DATETIME(3) NULL,
        deleted_at DATETIME(3) NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        INDEX idx_chan (channel_type, channel_id, id),
        INDEX idx_upd (channel_type, channel_id, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size BIGINT NOT NULL DEFAULT 0,
        mime_type VARCHAR(150) NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        INDEX idx_msg (message_id),
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_reactions (
        message_id INT NOT NULL,
        user_id INT NOT NULL,
        emoji VARCHAR(16) NOT NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (message_id, user_id, emoji),
        FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_reads (
        user_id INT NOT NULL,
        channel_type ENUM('sprint','prototype','lead') NOT NULL,
        channel_id INT NOT NULL,
        last_read_id INT NOT NULL DEFAULT 0,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (user_id, channel_type, channel_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_typing (
        user_id INT NOT NULL,
        channel_type ENUM('sprint','prototype','lead') NOT NULL,
        channel_id INT NOT NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (user_id, channel_type, channel_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
try {
    if (!$pdo->query("SHOW TABLES LIKE 'chat_typing'")->fetch()) chat_ensure_schema($pdo);
    // Migrações incrementais
    // Alargar ENUM para incluir project e lab
    $enumCheck = $pdo->query("SHOW COLUMNS FROM chat_messages LIKE 'channel_type'")->fetch();
    if ($enumCheck && strpos($enumCheck['Type'], 'project') === false) {
        $pdo->exec("ALTER TABLE chat_messages MODIFY channel_type ENUM('sprint','prototype','lead','project','lab') NOT NULL");
        $pdo->exec("ALTER TABLE chat_reads    MODIFY channel_type ENUM('sprint','prototype','lead','project','lab') NOT NULL");
        $pdo->exec("ALTER TABLE chat_typing   MODIFY channel_type ENUM('sprint','prototype','lead','project','lab') NOT NULL");
    }
    // Coluna topic_id para mensagens do canal lab
    if (!$pdo->query("SHOW COLUMNS FROM chat_messages LIKE 'topic_id'")->fetch()) {
        $pdo->exec("ALTER TABLE chat_messages ADD COLUMN topic_id INT NULL DEFAULT NULL AFTER reply_to, ADD INDEX idx_topic (topic_id)");
    }
    if (!$pdo->query("SHOW TABLES LIKE 'chat_push_subs'")->fetch()) {
        // Subscrições Web Push (uma por browser/dispositivo)
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_push_subs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint TEXT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            p256dh VARCHAR(255) NULL,
            auth VARCHAR(255) NULL,
            user_agent VARCHAR(255) NULL,
            pending_test TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used DATETIME NULL,
            UNIQUE KEY uq_endpoint (endpoint_hash),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Par de chaves VAPID do servidor (gerado automaticamente na 1.ª utilização)
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_push_config (
            id TINYINT PRIMARY KEY,
            public_key VARCHAR(255) NOT NULL,
            private_pem TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (PDOException $e) {
    chat_err(500, 'Erro ao preparar tabelas do chat: ' . $e->getMessage());
}

// ── Autenticação ────────────────────────────────────────────────
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$uid = 0;
if ($action === 'push_inbox') {
    // Chamado pelo service worker quando chega um push (sem token/cookies):
    // o endpoint da subscrição — URL secreto — identifica o utilizador.
    header('Access-Control-Allow-Origin: *');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $ep = (string)($in['endpoint'] ?? '');
    if ($ep === '') chat_err(400, 'Endpoint em falta');
    $st = $pdo->prepare('SELECT user_id FROM chat_push_subs WHERE endpoint_hash = ?');
    $st->execute([hash('sha256', $ep)]);
    $uid = (int)($st->fetchColumn() ?: 0);
    if (!$uid) chat_err(404, 'Subscrição desconhecida');
    $pdo->prepare('UPDATE chat_push_subs SET last_used = NOW() WHERE endpoint_hash = ?')->execute([hash('sha256', $ep)]);
} elseif ($CHAT_TOKEN !== null) {
    $st = $pdo->prepare('SELECT user_id FROM user_tokens WHERE token = ?');
    $st->execute([$CHAT_TOKEN]);
    $uid = (int)($st->fetchColumn() ?: 0);
    if (!$uid) chat_err(401, 'Token inválido');
} else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    session_write_close(); // não bloquear outros pedidos da mesma sessão (polling)
    if (!$uid) chat_err(401, 'Sessão expirada');
}

$st = $pdo->prepare('SELECT username FROM user_tokens WHERE user_id = ?');
$st->execute([$uid]);
$myName = (string)($st->fetchColumn() ?: ('user' . $uid));

// ── Helpers de tabelas/canais ───────────────────────────────────
function chat_table_exists(PDO $pdo, string $t): bool {
    static $cache = [];
    if (!array_key_exists($t, $cache)) {
        $cache[$t] = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
    }
    return $cache[$t];
}

function chat_is_admin(PDO $pdo, int $uid): bool {
    if (!chat_table_exists($pdo, 'admin_users')) return false;
    $st = $pdo->prepare('SELECT 1 FROM admin_users WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
}

const CHAT_TYPES = ['sprint', 'prototype', 'lead', 'project', 'lab'];

/** Metadados da entidade dona do canal, ou null se não existir. */
function chat_channel_info(PDO $pdo, string $type, int $id): ?array {
    if ($type === 'sprint' && chat_table_exists($pdo, 'sprints')) {
        $st = $pdo->prepare('SELECT id, nome AS title, estado, responsavel_id FROM sprints WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        $r['closed'] = in_array($r['estado'], ['concluída', 'concluida', 'fechada'], true);
        $r['url'] = '?tab=sprints&sprint_id=' . $id;
        return $r;
    }
    if ($type === 'prototype' && chat_table_exists($pdo, 'prototypes')) {
        $hasEstado = (bool)$pdo->query("SHOW COLUMNS FROM prototypes LIKE 'estado'")->fetch();
        $st = $pdo->prepare('SELECT id, COALESCE(NULLIF(short_name,\'\'), name) AS title, '
            . ($hasEstado ? 'estado' : "'ativo' AS estado") . ', responsavel_id FROM prototypes WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        $r['closed'] = ($r['estado'] === 'fechado');
        $r['url'] = '?tab=prototypes/prototypesv2&prototype_id=' . $id;
        return $r;
    }
    if ($type === 'lead' && chat_table_exists($pdo, 'leads')) {
        $st = $pdo->prepare('SELECT id, titulo AS title, estado, responsavel_id FROM leads WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        $r['closed'] = ($r['estado'] === 'fechada');
        $r['url'] = '?tab=leads&lead_id=' . $id;
        return $r;
    }
    if ($type === 'project' && chat_table_exists($pdo, 'projects')) {
        $st = $pdo->prepare('SELECT id, COALESCE(NULLIF(short_name,\'\'), title) AS title, estado, owner_id AS responsavel_id FROM projects WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        $r['closed'] = in_array($r['estado'], ['fechado', 'concluído', 'arquivado'], true);
        $r['url'] = '?tab=projectos&project_id=' . $id;
        return $r;
    }
    if ($type === 'lab') {
        // Canal único do laboratório (channel_id = 1)
        return ['id' => 1, 'title' => 'Lab Chat', 'estado' => 'ativo', 'responsavel_id' => 0, 'closed' => false, 'url' => '?tab=lab_management'];
    }
    return null;
}

function chat_member_table(string $type): array {
    return [
        'sprint'    => ['sprint_members',    'sprint_id'],
        'prototype' => ['prototype_members', 'prototype_id'],
        'lead'      => ['lead_members',      'lead_id'],
        'project'   => ['project_members',   'project_id'],
        'lab'       => ['lab_authorized_users', null],
    ][$type] ?? ['', ''];
}

/** Lista de membros: responsável + tabela de membros. */
function chat_members(PDO $pdo, string $type, int $id, ?array $info = null): array {
    $info = $info ?? chat_channel_info($pdo, $type, $id);
    if (!$info) return [];
    $members = [];

    if ($type === 'lab') {
        // Lab: membros = todos os utilizadores autorizados
        if (chat_table_exists($pdo, 'lab_authorized_users')) {
            $st = $pdo->query('SELECT user_id FROM lab_authorized_users');
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) {
                $members[(int)$u] = 'membro';
            }
        }
    } else {
        if (!empty($info['responsavel_id'])) {
            $members[(int)$info['responsavel_id']] = 'responsável';
        }
        [$tbl, $col] = chat_member_table($type);
        if ($tbl && chat_table_exists($pdo, $tbl)) {
            if ($type === 'project') {
                $st = $pdo->prepare("SELECT user_id FROM `$tbl` WHERE `$col` = ?");
                $st->execute([$id]);
            } else {
                $st = $pdo->prepare("SELECT user_id FROM `$tbl` WHERE `$col` = ?");
                $st->execute([$id]);
            }
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) {
                if (!isset($members[(int)$u])) $members[(int)$u] = 'membro';
            }
        }
    }

    if (!$members) return [];
    $ids = array_keys($members);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT user_id, username FROM user_tokens WHERE user_id IN ($in)");
    $st->execute($ids);
    $names = [];
    foreach ($st->fetchAll() as $r) $names[(int)$r['user_id']] = $r['username'];
    $out = [];
    foreach ($members as $u => $role) {
        $out[] = ['user_id' => $u, 'username' => $names[$u] ?? ('user' . $u), 'role' => $role];
    }
    usort($out, function ($a, $b) {
        $ra = $a['role'] === 'responsável' ? 0 : 1;
        $rb = $b['role'] === 'responsável' ? 0 : 1;
        return $ra !== $rb ? $ra <=> $rb : strcasecmp($a['username'], $b['username']);
    });
    return $out;
}

/** Valida tipo/id, acesso, e devolve [type, id, info, canWrite]. */
function chat_require_channel(PDO $pdo, int $uid, array $src): array {
    $type = (string)($src['type'] ?? '');
    $id   = (int)($src['id'] ?? 0);
    if (!in_array($type, CHAT_TYPES, true) || $id <= 0) chat_err(400, 'Canal inválido');
    $info = chat_channel_info($pdo, $type, $id);
    if (!$info) chat_err(404, 'Canal não encontrado');
    $isMember = false;
    foreach (chat_members($pdo, $type, $id, $info) as $m) {
        if ($m['user_id'] === $uid) { $isMember = true; break; }
    }
    if (!$isMember && !chat_is_admin($pdo, $uid)) chat_err(403, 'Não és membro deste canal');
    return [$type, $id, $info, !$info['closed']];
}

/** Imagens que o browser mostra inline (SVG fica como ficheiro por segurança). */
function chat_is_inline_image(?string $mime): bool {
    return in_array((string)$mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif'], true);
}

/** Serializa mensagens (com anexos, reacções e resposta). */
function chat_hydrate(PDO $pdo, array $rows, int $uid): array {
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', $ids);

    $atts = [];
    foreach ($pdo->query("SELECT id, message_id, file_name, file_size, mime_type FROM chat_attachments WHERE message_id IN ($in) ORDER BY id") as $a) {
        $atts[(int)$a['message_id']][] = [
            'id'       => (int)$a['id'],
            'name'     => $a['file_name'],
            'size'     => (int)$a['file_size'],
            'mime'     => $a['mime_type'],
            'is_image' => chat_is_inline_image($a['mime_type']),
        ];
    }

    $reacts = [];
    foreach ($pdo->query("SELECT r.message_id, r.emoji, r.user_id, u.username
                          FROM chat_reactions r LEFT JOIN user_tokens u ON u.user_id = r.user_id
                          WHERE r.message_id IN ($in) ORDER BY r.created_at") as $r) {
        $mid = (int)$r['message_id'];
        $e   = $r['emoji'];
        if (!isset($reacts[$mid][$e])) $reacts[$mid][$e] = ['emoji' => $e, 'count' => 0, 'users' => [], 'mine' => false];
        $reacts[$mid][$e]['count']++;
        $reacts[$mid][$e]['users'][] = $r['username'] ?? '?';
        if ((int)$r['user_id'] === $uid) $reacts[$mid][$e]['mine'] = true;
    }

    $replyIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['reply_to'], $rows))));
    $replies  = [];
    if ($replyIds) {
        $rin = implode(',', $replyIds);
        foreach ($pdo->query("SELECT m.id, m.body, m.deleted_at, u.username,
                                     (SELECT COUNT(*) FROM chat_attachments a WHERE a.message_id = m.id) AS n_att
                              FROM chat_messages m LEFT JOIN user_tokens u ON u.user_id = m.user_id
                              WHERE m.id IN ($rin)") as $r) {
            $txt = $r['deleted_at'] ? 'Mensagem eliminada' : mb_strimwidth((string)$r['body'], 0, 160, '…');
            if ($txt === '' && $r['n_att'] > 0) $txt = '📎 Anexo';
            $replies[(int)$r['id']] = ['id' => (int)$r['id'], 'username' => $r['username'] ?? '?', 'body' => $txt];
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $id      = (int)$r['id'];
        $deleted = !empty($r['deleted_at']);
        $out[] = [
            'id'          => $id,
            'user_id'     => (int)$r['user_id'],
            'username'    => $r['username'] ?? ('user' . $r['user_id']),
            'body'        => $deleted ? '' : (string)$r['body'],
            'created_at'  => $r['created_at'],
            'edited'      => !empty($r['edited_at']),
            'deleted'     => $deleted,
            'pinned'      => (bool)$r['is_pinned'],
            'system'      => (bool)$r['is_system'],
            'reply_to'    => $r['reply_to'] ? ($replies[(int)$r['reply_to']] ?? null) : null,
            'topic_id'    => isset($r['topic_id']) ? ($r['topic_id'] !== null ? (int)$r['topic_id'] : null) : null,
            'attachments' => $deleted ? [] : ($atts[$id] ?? []),
            'reactions'   => $deleted ? [] : array_values($reacts[$id] ?? []),
        ];
    }
    return $out;
}

const CHAT_MSG_SELECT = "SELECT m.*, u.username FROM chat_messages m LEFT JOIN user_tokens u ON u.user_id = m.user_id";

function chat_touch(PDO $pdo, int $msgId): void {
    $pdo->prepare('UPDATE chat_messages SET updated_at = NOW(3) WHERE id = ?')->execute([$msgId]);
}

function chat_load_msg(PDO $pdo, int $msgId, string $type, int $id): array {
    $st = $pdo->prepare('SELECT * FROM chat_messages WHERE id = ? AND channel_type = ? AND channel_id = ?');
    $st->execute([$msgId, $type, $id]);
    $m = $st->fetch();
    if (!$m) chat_err(404, 'Mensagem não encontrada');
    return $m;
}

function chat_server_time(PDO $pdo): string {
    return (string)$pdo->query('SELECT DATE_FORMAT(NOW(3), "%Y-%m-%d %H:%i:%s.%f")')->fetchColumn();
}

/** Canais (sprints / protótipos / leads) a que o utilizador pertence. */
function chat_user_channels(PDO $pdo, int $uid): array {
    $parts = [];
    $params = [];
    if (chat_table_exists($pdo, 'sprints')) {
        $mem = chat_table_exists($pdo, 'sprint_members')
            ? 'OR EXISTS (SELECT 1 FROM sprint_members x WHERE x.sprint_id = s.id AND x.user_id = ?)' : '';
        $parts[] = "SELECT 'sprint' AS type, s.id, s.nome AS title,
                           (s.estado IN ('concluída','concluida','fechada')) AS closed
                    FROM sprints s WHERE s.responsavel_id = ? $mem";
        $params[] = $uid; if ($mem) $params[] = $uid;
    }
    if (chat_table_exists($pdo, 'prototypes')) {
        $hasEstado = (bool)$pdo->query("SHOW COLUMNS FROM prototypes LIKE 'estado'")->fetch();
        $mem = chat_table_exists($pdo, 'prototype_members')
            ? 'OR EXISTS (SELECT 1 FROM prototype_members x WHERE x.prototype_id = p.id AND x.user_id = ?)' : '';
        $parts[] = "SELECT 'prototype' AS type, p.id, COALESCE(NULLIF(p.short_name,''), p.name) AS title,
                           " . ($hasEstado ? "(p.estado = 'fechado')" : '0') . " AS closed
                    FROM prototypes p WHERE p.responsavel_id = ? $mem";
        $params[] = $uid; if ($mem) $params[] = $uid;
    }
    if (chat_table_exists($pdo, 'leads')) {
        $mem = chat_table_exists($pdo, 'lead_members')
            ? 'OR EXISTS (SELECT 1 FROM lead_members x WHERE x.lead_id = l.id AND x.user_id = ?)' : '';
        $parts[] = "SELECT 'lead' AS type, l.id, l.titulo AS title, (l.estado = 'fechada') AS closed
                    FROM leads l WHERE l.responsavel_id = ? $mem";
        $params[] = $uid; if ($mem) $params[] = $uid;
    }
    if (chat_table_exists($pdo, 'projects')) {
        $mem = chat_table_exists($pdo, 'project_members')
            ? 'OR EXISTS (SELECT 1 FROM project_members x WHERE x.project_id = pj.id AND x.user_id = ?)' : '';
        $parts[] = "SELECT 'project' AS type, pj.id, COALESCE(NULLIF(pj.short_name,''), pj.title) AS title,
                           (pj.estado IN ('fechado','concluído','arquivado')) AS closed
                    FROM projects pj WHERE pj.owner_id = ? $mem";
        $params[] = $uid; if ($mem) $params[] = $uid;
    }
    // Canal lab: aparece se o utilizador for authorized
    if (chat_table_exists($pdo, 'lab_authorized_users')) {
        $st2 = $pdo->prepare('SELECT 1 FROM lab_authorized_users WHERE user_id = ?');
        $st2->execute([$uid]);
        if ($st2->fetchColumn()) {
            $parts[] = "SELECT 'lab' AS type, 1 AS id, 'Lab Chat' AS title, 0 AS closed FROM DUAL";
        }
    }
    if (!$parts) return [];
    $st = $pdo->prepare(implode(' UNION ALL ', $parts));
    $st->execute($params);
    $chans = [];
    foreach ($st->fetchAll() as $r) {
        $key = $r['type'] . ':' . $r['id'];
        $chans[$key] = [
            'type' => $r['type'], 'id' => (int)$r['id'], 'title' => $r['title'],
            'closed' => (bool)$r['closed'], 'unread' => 0, 'mentions' => 0, 'last' => null,
        ];
    }
    if (!$chans) return [];

    // Estatísticas por canal (unread / menções / última mensagem)
    $st = $pdo->prepare("
        SELECT m.channel_type, m.channel_id, MAX(m.id) AS last_id,
               SUM(m.id > COALESCE(r.last_read_id, 0) AND m.user_id <> ? AND m.deleted_at IS NULL) AS unread,
               SUM(m.id > COALESCE(r.last_read_id, 0) AND m.user_id <> ? AND m.deleted_at IS NULL
                   AND m.body LIKE ?) AS mentions
        FROM chat_messages m
        LEFT JOIN chat_reads r ON r.user_id = ? AND r.channel_type = m.channel_type AND r.channel_id = m.channel_id
        GROUP BY m.channel_type, m.channel_id
    ");
    $mention = '%@' . str_replace(['%', '_'], ['\\%', '\\_'], chat_my_name($pdo, $uid)) . '%';
    $st->execute([$uid, $uid, $mention, $uid]);
    $lastIds = [];
    foreach ($st->fetchAll() as $r) {
        $key = $r['channel_type'] . ':' . $r['channel_id'];
        if (!isset($chans[$key])) continue;
        $chans[$key]['unread']   = (int)$r['unread'];
        $chans[$key]['mentions'] = (int)$r['mentions'];
        $lastIds[(int)$r['last_id']] = $key;
    }
    if ($lastIds) {
        $in = implode(',', array_keys($lastIds));
        foreach ($pdo->query("SELECT m.id, m.body, m.created_at, m.deleted_at, u.username,
                                     (SELECT COUNT(*) FROM chat_attachments a WHERE a.message_id = m.id) AS n_att
                              FROM chat_messages m LEFT JOIN user_tokens u ON u.user_id = m.user_id
                              WHERE m.id IN ($in)") as $r) {
            $txt = $r['deleted_at'] ? 'Mensagem eliminada' : mb_strimwidth(preg_replace('/\s+/', ' ', (string)$r['body']), 0, 90, '…');
            if ($txt === '' && $r['n_att'] > 0) $txt = '📎 Anexo';
            $chans[$lastIds[(int)$r['id']]]['last'] = [
                'id' => (int)$r['id'], 'username' => $r['username'] ?? '?', 'body' => $txt, 'created_at' => $r['created_at'],
            ];
        }
    }
    $list = array_values($chans);
    usort($list, function ($a, $b) {
        if ($a['closed'] !== $b['closed']) return $a['closed'] <=> $b['closed'];
        $ta = $a['last']['created_at'] ?? '';
        $tb = $b['last']['created_at'] ?? '';
        if ($ta !== $tb) return strcmp($tb, $ta);
        return strcasecmp($a['title'], $b['title']);
    });
    return $list;
}

function chat_my_name(PDO $pdo, int $uid): string {
    global $myName;
    return $myName;
}

// ── Ficheiros ───────────────────────────────────────────────────
const CHAT_MAX_FILE   = 20 * 1024 * 1024;
const CHAT_MAX_FILES  = 10;
const CHAT_BLOCKED_EXT = ['php','php3','php4','php5','php7','php8','phtml','phar','pht','phps','cgi','pl','py','sh','asp','aspx','jsp','htaccess','htpasswd','shtml','exe','bat','cmd','com','msi','scr','vbs','ps1'];

function chat_storage_dir(): string {
    $base = __DIR__ . '/../files/chat';
    if (!is_dir($base)) mkdir($base, 0755, true);
    $ht = $base . '/.htaccess';
    if (!file_exists($ht)) {
        // Os ficheiros só são servidos via api/chat.php (com controlo de acesso)
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $dir = $base . '/' . date('Ym');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

/** Normaliza $_FILES['files'] (multiple) para lista. */
function chat_uploaded_files(): array {
    if (empty($_FILES['files'])) return [];
    $f = $_FILES['files'];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

// ── Web Push (VAPID, sem payload) ───────────────────────────────
// O push só "acorda" o service worker; este pede o conteúdo a ?action=push_inbox.
// Assim não é preciso cifrar o payload (RFC 8291) — basta assinar o JWT VAPID (ES256).

function chat_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** Devolve ['public' => b64url(65 bytes), 'pem' => chave privada]. Gera na 1.ª vez. */
function chat_vapid(PDO $pdo): array {
    $r = $pdo->query('SELECT public_key, private_pem FROM chat_push_config WHERE id = 1')->fetch();
    if ($r) return ['public' => $r['public_key'], 'pem' => $r['private_pem']];
    if (!function_exists('openssl_pkey_new')) throw new RuntimeException('Extensão openssl do PHP não disponível');
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$key || !openssl_pkey_export($key, $pem)) throw new RuntimeException('Não foi possível gerar chaves VAPID: ' . openssl_error_string());
    $d   = openssl_pkey_get_details($key);
    $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $pdo->prepare('INSERT IGNORE INTO chat_push_config (id, public_key, private_pem) VALUES (1, ?, ?)')->execute([chat_b64u($pub), $pem]);
    return chat_vapid($pdo); // relê (em caso de corrida ganha a primeira)
}

/** Assinatura DER (openssl) → r||s com 64 bytes (formato JWS ES256). */
function chat_der_to_raw(string $der): string {
    $o = 2;
    if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $o++; $lr = ord($der[$o++]); $r = substr($der, $o, $lr); $o += $lr;
    $o++; $ls = ord($der[$o++]); $s = substr($der, $o, $ls);
    return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}

function chat_vapid_jwt(array $vapid, string $aud): string {
    $host = parse_url((isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'https://localhost'), PHP_URL_HOST);
    $h = chat_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = chat_b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:noreply@' . ($host ?: 'pikachupm.local')]));
    if (!openssl_sign("$h.$c", $der, $vapid['pem'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Falha a assinar JWT VAPID');
    return "$h.$c." . chat_b64u(chat_der_to_raw($der));
}

/** Envia um push (sem payload) a todas as subscrições dos utilizadores indicados. Devolve nº enviados com sucesso. */
function chat_push_users(PDO $pdo, array $userIds): int {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds || !function_exists('curl_multi_init')) return 0;
    $in = implode(',', $userIds);
    $subs = $pdo->query("SELECT id, endpoint FROM chat_push_subs WHERE user_id IN ($in)")->fetchAll();
    if (!$subs) return 0;
    try { $vapid = chat_vapid($pdo); } catch (Throwable $e) { error_log('chat push: ' . $e->getMessage()); return 0; }

    $mh = curl_multi_init();
    $handles = [];
    $jwts = [];
    foreach ($subs as $s) {
        $p = parse_url($s['endpoint']);
        if (empty($p['scheme']) || empty($p['host']) || $p['scheme'] !== 'https') continue;
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        try { $jwts[$aud] = $jwts[$aud] ?? chat_vapid_jwt($vapid, $aud); } catch (Throwable $e) { error_log('chat push: ' . $e->getMessage()); return 0; }
        $ch = curl_init($s['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => [
                'TTL: 86400',
                'Urgency: high',
                'Content-Length: 0',
                'Authorization: vapid t=' . $jwts[$aud] . ', k=' . $vapid['public'],
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$s['id']] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);

    $ok = 0; $gone = [];
    foreach ($handles as $sid => $ch) {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 200 && $code < 300) $ok++;
        elseif ($code === 404 || $code === 410) $gone[] = $sid;           // subscrição expirada/cancelada
        else error_log("chat push: HTTP $code para subscrição $sid: " . substr((string)curl_multi_getcontent($ch), 0, 200));
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if ($gone) $pdo->exec('DELETE FROM chat_push_subs WHERE id IN (' . implode(',', $gone) . ')');
    return $ok;
}

/** Responde ao cliente já e continua a trabalhar (envio dos pushes) sem o fazer esperar. */
function chat_json_then($data, callable $after): void {
    $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    ignore_user_abort(true);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (function_exists('fastcgi_finish_request')) {
        echo $body;
        fastcgi_finish_request();
    } else {
        header('Connection: close');
        header('Content-Length: ' . strlen($body));
        echo $body;
        while (ob_get_level() > 0) ob_end_flush();
        flush();
    }
    try { $after(); } catch (Throwable $e) { error_log('chat after: ' . $e->getMessage()); }
    exit;
}

// ── Router ──────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

$POST_ACTIONS = ['send', 'edit', 'delete', 'react', 'pin', 'read', 'typing', 'push_subscribe', 'push_unsubscribe', 'push_test', 'push_inbox'];
if (in_array($action, $POST_ACTIONS, true) && $method !== 'POST') chat_err(405, 'Use POST');

// Pedido maior que post_max_size → o PHP descarta $_POST/$_FILES sem aviso
if ($method === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    chat_err(413, 'Os ficheiros excedem o limite do servidor (' . ini_get('post_max_size') . ' por mensagem)');
}

// Corpo JSON opcional em POST
$IN = $_POST;
if ($method === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $IN = json_decode(file_get_contents('php://input'), true) ?: [];
}
$SRC = array_merge($_GET, $IN);

try {
switch ($action) {

    // ── Utilizador actual ───────────────────────────────────────
    case 'me':
        chat_json(['user_id' => $uid, 'username' => $myName, 'is_admin' => chat_is_admin($pdo, $uid)]);

    // ── Lista de canais ─────────────────────────────────────────
    case 'channels':
        $list = chat_user_channels($pdo, $uid);
        chat_json([
            'me'           => ['user_id' => $uid, 'username' => $myName],
            'channels'     => $list,
            'unread_total' => array_sum(array_map(fn($c) => $c['unread'], $list)),
            'server_time'  => chat_server_time($pdo),
        ]);

    case 'unread':
        $list = chat_user_channels($pdo, $uid);
        chat_json([
            'unread_total'   => array_sum(array_map(fn($c) => $c['unread'], $list)),
            'mentions_total' => array_sum(array_map(fn($c) => $c['mentions'], $list)),
            'channels'       => array_values(array_map(fn($c) => [
                'type' => $c['type'], 'id' => $c['id'], 'title' => $c['title'],
                'unread' => $c['unread'], 'mentions' => $c['mentions'], 'last' => $c['last'],
            ], array_filter($list, fn($c) => $c['unread'] > 0))),
        ]);

    // ── Tópicos (assuntos abertos do lab) ──────────────────────
    case 'topics': {
        [$type, $id, $info, $canWrite] = chat_require_channel($pdo, $uid, $SRC);
        if ($type !== 'lab') chat_err(400, 'Tópicos só disponíveis no canal Lab');
        $topics = [];
        if (chat_table_exists($pdo, 'lab_issues')) {
            $st = $pdo->query("SELECT id, titulo, status, prioridade FROM lab_issues WHERE status NOT IN ('resolvido','fechado') ORDER BY prioridade DESC, id DESC");
            $topics = $st->fetchAll();
        }
        chat_json(['topics' => $topics]);
    }

    // ── Mensagens (página inicial / mais antigas) ───────────────
    case 'messages': {
        [$type, $id, $info, $canWrite] = chat_require_channel($pdo, $uid, $SRC);
        $limit   = max(1, min(100, (int)($SRC['limit'] ?? 50)));
        $before  = (int)($SRC['before_id'] ?? 0);
        $topicFilter = (int)($SRC['topic_id'] ?? 0);
        $params = [$type, $id];
        $where  = " WHERE m.channel_type = ? AND m.channel_id = ?";
        if ($topicFilter) { $where .= ' AND m.topic_id = ?'; $params[] = $topicFilter; }
        if ($before)      { $where .= ' AND m.id < ?';       $params[] = $before; }
        $sql = CHAT_MSG_SELECT . $where . " ORDER BY m.id DESC LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = array_reverse($st->fetchAll());

        $st = $pdo->prepare('SELECT last_read_id FROM chat_reads WHERE user_id = ? AND channel_type = ? AND channel_id = ?');
        $st->execute([$uid, $type, $id]);
        $lastRead = (int)($st->fetchColumn() ?: 0);

        chat_json([
            'channel'      => ['type' => $type, 'id' => $id, 'title' => $info['title'], 'closed' => $info['closed'],
                               'estado' => $info['estado'], 'url' => $info['url'], 'can_write' => $canWrite],
            'me'           => ['user_id' => $uid, 'username' => $myName, 'is_admin' => chat_is_admin($pdo, $uid)],
            'members'      => $before ? null : chat_members($pdo, $type, $id, $info),
            'messages'     => chat_hydrate($pdo, $rows, $uid),
            'has_more'     => count($rows) === $limit,
            'last_read_id' => $lastRead,
            'server_time'  => chat_server_time($pdo),
        ]);
    }

    // ── Polling: novas + alteradas + "a escrever" + leituras ────
    case 'poll': {
        [$type, $id, $info, $canWrite] = chat_require_channel($pdo, $uid, $SRC);
        $after = (int)($SRC['after_id'] ?? 0);
        $since = (string)($SRC['since'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $since)) $since = '1970-01-01 00:00:00';
        $now = chat_server_time($pdo);

        $st = $pdo->prepare(CHAT_MSG_SELECT . " WHERE m.channel_type = ? AND m.channel_id = ?
                                               AND (m.id > ? OR m.updated_at >= ?) ORDER BY m.id LIMIT 200");
        $st->execute([$type, $id, $after, $since]);
        $rows = $st->fetchAll();

        $st = $pdo->prepare("SELECT u.username FROM chat_typing t JOIN user_tokens u ON u.user_id = t.user_id
                             WHERE t.channel_type = ? AND t.channel_id = ? AND t.user_id <> ?
                               AND t.updated_at > NOW(3) - INTERVAL 6 SECOND");
        $st->execute([$type, $id, $uid]);
        $typing = $st->fetchAll(PDO::FETCH_COLUMN);

        $st = $pdo->prepare("SELECT r.user_id, u.username, r.last_read_id FROM chat_reads r
                             LEFT JOIN user_tokens u ON u.user_id = r.user_id
                             WHERE r.channel_type = ? AND r.channel_id = ?");
        $st->execute([$type, $id]);
        $reads = array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'username' => $r['username'], 'last_read_id' => (int)$r['last_read_id']], $st->fetchAll());

        chat_json([
            'messages'    => chat_hydrate($pdo, $rows, $uid),
            'typing'      => $typing,
            'reads'       => $reads,
            'closed'      => $info['closed'],
            'estado'      => $info['estado'],
            'can_write'   => $canWrite,
            'server_time' => $now,
        ]);
    }

    case 'members': {
        [$type, $id, $info] = chat_require_channel($pdo, $uid, $SRC);
        chat_json(['members' => chat_members($pdo, $type, $id, $info)]);
    }

    case 'pinned': {
        [$type, $id] = chat_require_channel($pdo, $uid, $SRC);
        $st = $pdo->prepare(CHAT_MSG_SELECT . " WHERE m.channel_type = ? AND m.channel_id = ? AND m.is_pinned = 1
                                               AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 100");
        $st->execute([$type, $id]);
        chat_json(['messages' => chat_hydrate($pdo, $st->fetchAll(), $uid)]);
    }

    case 'files': {
        [$type, $id] = chat_require_channel($pdo, $uid, $SRC);
        $st = $pdo->prepare("SELECT a.id, a.file_name AS name, a.file_size AS size, a.mime_type AS mime,
                                    a.created_at, m.id AS message_id, u.username
                             FROM chat_attachments a
                             JOIN chat_messages m ON m.id = a.message_id
                             LEFT JOIN user_tokens u ON u.user_id = m.user_id
                             WHERE m.channel_type = ? AND m.channel_id = ? AND m.deleted_at IS NULL
                             ORDER BY a.id DESC LIMIT 500");
        $st->execute([$type, $id]);
        $files = array_map(fn($f) => [
            'id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'mime' => $f['mime'],
            'created_at' => $f['created_at'], 'message_id' => (int)$f['message_id'], 'username' => $f['username'],
            'is_image' => chat_is_inline_image($f['mime']),
        ], $st->fetchAll());
        chat_json(['files' => $files]);
    }

    case 'search': {
        [$type, $id] = chat_require_channel($pdo, $uid, $SRC);
        $q = trim((string)($SRC['q'] ?? ''));
        if (mb_strlen($q) < 2) chat_json(['messages' => []]);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $st = $pdo->prepare(CHAT_MSG_SELECT . " WHERE m.channel_type = ? AND m.channel_id = ? AND m.deleted_at IS NULL
                                               AND (m.body LIKE ? OR EXISTS (SELECT 1 FROM chat_attachments a
                                                    WHERE a.message_id = m.id AND a.file_name LIKE ?))
                                               ORDER BY m.id DESC LIMIT 100");
        $st->execute([$type, $id, $like, $like]);
        chat_json(['messages' => chat_hydrate($pdo, $st->fetchAll(), $uid)]);
    }

    // ── Enviar mensagem (texto + ficheiros) ─────────────────────
    case 'send': {
        [$type, $id, $info, $canWrite] = chat_require_channel($pdo, $uid, $SRC);
        if (!$canWrite) chat_err(409, 'Canal arquivado — apenas leitura');
        $body    = trim(str_replace("\r\n", "\n", (string)($SRC['body'] ?? '')));
        $replyTo = (int)($SRC['reply_to'] ?? 0) ?: null;
        $topicId = (int)($SRC['topic_id'] ?? 0) ?: null;
        // Canal lab exige topic_id
        if ($type === 'lab' && !$topicId) chat_err(400, 'É obrigatório selecionar um assunto para mensagens do Lab Chat');
        $files   = array_values(array_filter(chat_uploaded_files(), fn($f) => $f['error'] !== UPLOAD_ERR_NO_FILE));
        if ($body === '' && !$files) chat_err(400, 'Mensagem vazia');
        if (mb_strlen($body) > 10000) chat_err(400, 'Mensagem demasiado longa (máx. 10000 caracteres)');
        if (count($files) > CHAT_MAX_FILES) chat_err(400, 'Máximo de ' . CHAT_MAX_FILES . ' ficheiros por mensagem');
        foreach ($files as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK) chat_err(400, 'Erro no upload de "' . $f['name'] . '" (código ' . $f['error'] . ')');
            if ($f['size'] > CHAT_MAX_FILE) chat_err(400, '"' . $f['name'] . '" excede 20 MB');
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, CHAT_BLOCKED_EXT, true)) chat_err(400, 'Tipo de ficheiro não permitido: .' . $ext);
        }
        if ($replyTo) chat_load_msg($pdo, $replyTo, $type, $id); // garante que pertence ao canal

        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO chat_messages (channel_type, channel_id, user_id, body, reply_to, topic_id, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, NOW(3), NOW(3))');
        $st->execute([$type, $id, $uid, $body, $replyTo, $topicId]);
        $msgId = (int)$pdo->lastInsertId();

        $moved = [];
        if ($files) {
            $dir   = chat_storage_dir();
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            $ins   = $pdo->prepare('INSERT INTO chat_attachments (message_id, file_name, file_path, file_size, mime_type) VALUES (?, ?, ?, ?, ?)');
            foreach ($files as $f) {
                $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $name = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . preg_replace('/[^a-z0-9]/', '', $ext) : '');
                $dest = $dir . '/' . $name;
                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    foreach ($moved as $p) @unlink($p);
                    $pdo->rollBack();
                    chat_err(500, 'Erro ao guardar ficheiro');
                }
                $moved[] = $dest;
                $mime = $finfo ? (finfo_file($finfo, $dest) ?: null) : null;
                $mime = $mime ?: ($f['type'] ?: 'application/octet-stream');
                $orig = mb_substr(basename(str_replace('\\', '/', $f['name'])), 0, 255);
                $ins->execute([$msgId, $orig, 'files/chat/' . basename($dir) . '/' . $name, (int)$f['size'], $mime]);
            }
        }
        // Quem envia leu tudo até aqui
        $pdo->prepare('INSERT INTO chat_reads (user_id, channel_type, channel_id, last_read_id, updated_at)
                       VALUES (?, ?, ?, ?, NOW(3))
                       ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id)), updated_at = NOW(3)')
            ->execute([$uid, $type, $id, $msgId]);
        $pdo->prepare('DELETE FROM chat_typing WHERE user_id = ? AND channel_type = ? AND channel_id = ?')->execute([$uid, $type, $id]);
        $pdo->commit();

        $st = $pdo->prepare(CHAT_MSG_SELECT . ' WHERE m.id = ?');
        $st->execute([$msgId]);
        $out = ['ok' => true, 'message' => chat_hydrate($pdo, $st->fetchAll(), $uid)[0]];
        // Notificação push para todos os membros do canal (menos quem enviou)
        $recipients = array_filter(array_map(fn($m) => $m['user_id'], chat_members($pdo, $type, $id, $info)), fn($u) => $u !== $uid);
        chat_json_then($out, fn() => chat_push_users($pdo, $recipients));
    }

    // ── Web Push ────────────────────────────────────────────────
    case 'push_key':
        try { chat_json(['public_key' => chat_vapid($pdo)['public']]); }
        catch (Throwable $e) { chat_err(500, $e->getMessage()); }

    case 'push_subscribe': {
        $ep = (string)($SRC['endpoint'] ?? '');
        if (!preg_match('#^https://#', $ep) || strlen($ep) > 2000) chat_err(400, 'Endpoint inválido');
        $keys = is_array($SRC['keys'] ?? null) ? $SRC['keys'] : [];
        $pdo->prepare('INSERT INTO chat_push_subs (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent, last_used)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth),
                                               user_agent = VALUES(user_agent), last_used = NOW()')
            ->execute([$uid, $ep, hash('sha256', $ep), mb_substr((string)($keys['p256dh'] ?? ''), 0, 255),
                       mb_substr((string)($keys['auth'] ?? ''), 0, 255), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        chat_json(['ok' => true]);
    }

    case 'push_unsubscribe': {
        $ep = (string)($SRC['endpoint'] ?? '');
        $pdo->prepare('DELETE FROM chat_push_subs WHERE endpoint_hash = ? AND user_id = ?')->execute([hash('sha256', $ep), $uid]);
        chat_json(['ok' => true]);
    }

    case 'push_test': {
        $st = $pdo->prepare('SELECT COUNT(*) FROM chat_push_subs WHERE user_id = ?');
        $st->execute([$uid]);
        $n = (int)$st->fetchColumn();
        if (!$n) chat_err(409, 'Este utilizador ainda não tem nenhum dispositivo subscrito');
        $pdo->prepare('UPDATE chat_push_subs SET pending_test = 1 WHERE user_id = ?')->execute([$uid]);
        chat_json(['ok' => true, 'devices' => $n, 'sent' => chat_push_users($pdo, [$uid])]);
    }

    // Chamado pelo service worker ao receber um push: o que mostrar na notificação
    case 'push_inbox': {
        $list = array_values(array_filter(chat_user_channels($pdo, $uid), fn($c) => $c['unread'] > 0 && !$c['closed']));
        usort($list, fn($a, $b) => strcmp($b['last']['created_at'] ?? '', $a['last']['created_at'] ?? ''));
        $epHash = hash('sha256', (string)($SRC['endpoint'] ?? ''));
        $st = $pdo->prepare('SELECT pending_test FROM chat_push_subs WHERE endpoint_hash = ?');
        $st->execute([$epHash]);
        $isTest = (bool)$st->fetchColumn();
        if ($isTest) $pdo->prepare('UPDATE chat_push_subs SET pending_test = 0 WHERE endpoint_hash = ?')->execute([$epHash]);
        chat_json([
            'test'         => $isTest,
            'unread_total' => array_sum(array_map(fn($c) => $c['unread'], $list)),
            'channels'     => array_slice(array_map(fn($c) => [
                'type' => $c['type'], 'id' => $c['id'], 'title' => $c['title'],
                'unread' => $c['unread'], 'mentions' => $c['mentions'], 'last' => $c['last'],
            ], $list), 0, 4),
        ]);
    }

    case 'edit': {
        [$type, $id, , $canWrite] = chat_require_channel($pdo, $uid, $SRC);
        if (!$canWrite) chat_err(409, 'Canal arquivado — apenas leitura');
        $m = chat_load_msg($pdo, (int)($SRC['message_id'] ?? 0), $type, $id);
        if ((int)$m['user_id'] !== $uid) chat_err(403, 'Só podes editar as tuas mensagens');
        if ($m['deleted_at']) chat_err(409, 'Mensagem eliminada');
        $body = trim(str_replace("\r\n", "\n", (string)($SRC['body'] ?? '')));
        if ($body === '') chat_err(400, 'Mensagem vazia');
        if (mb_strlen($body) > 10000) chat_err(400, 'Mensagem demasiado longa');
        $pdo->prepare('UPDATE chat_messages SET body = ?, edited_at = NOW(3), updated_at = NOW(3) WHERE id = ?')
            ->execute([$body, (int)$m['id']]);
        chat_json(['ok' => true]);
    }

    case 'delete': {
        [$type, $id, , $canWrite] = chat_require_channel($pdo, $uid, $SRC);
        $m = chat_load_msg($pdo, (int)($SRC['message_id'] ?? 0), $type, $id);
        $admin = chat_is_admin($pdo, $uid);
        if ((int)$m['user_id'] !== $uid && !$admin) chat_err(403, 'Só podes eliminar as tuas mensagens');
        if (!$canWrite && !$admin) chat_err(409, 'Canal arquivado — apenas leitura');
        // Soft delete: o histórico mantém o registo "Mensagem eliminada"; os ficheiros são removidos do disco
        $st = $pdo->prepare('SELECT file_path FROM chat_attachments WHERE message_id = ?');
        $st->execute([(int)$m['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
            $full = realpath(__DIR__ . '/../' . $p);
            $root = realpath(__DIR__ . '/../files/chat');
            if ($full && $root && str_starts_with($full, $root)) @unlink($full);
        }
        $pdo->prepare('DELETE FROM chat_attachments WHERE message_id = ?')->execute([(int)$m['id']]);
        $pdo->prepare('DELETE FROM chat_reactions WHERE message_id = ?')->execute([(int)$m['id']]);
        $pdo->prepare('UPDATE chat_messages SET body = \'\', is_pinned = 0, deleted_at = NOW(3), updated_at = NOW(3) WHERE id = ?')
            ->execute([(int)$m['id']]);
        chat_json(['ok' => true]);
    }

    case 'react': {
        [$type, $id, , $canWrite] = chat_require_channel($pdo, $uid, $SRC);
        if (!$canWrite) chat_err(409, 'Canal arquivado — apenas leitura');
        $m = chat_load_msg($pdo, (int)($SRC['message_id'] ?? 0), $type, $id);
        if ($m['deleted_at']) chat_err(409, 'Mensagem eliminada');
        $emoji = (string)($SRC['emoji'] ?? '');
        if ($emoji === '' || mb_strlen($emoji) > 8 || strlen($emoji) > 16) chat_err(400, 'Emoji inválido');
        $st = $pdo->prepare('DELETE FROM chat_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?');
        $st->execute([(int)$m['id'], $uid, $emoji]);
        if ($st->rowCount() === 0) {
            $pdo->prepare('INSERT INTO chat_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)')
                ->execute([(int)$m['id'], $uid, $emoji]);
        }
        chat_touch($pdo, (int)$m['id']);
        chat_json(['ok' => true]);
    }

    case 'pin': {
        [$type, $id, , $canWrite] = chat_require_channel($pdo, $uid, $SRC);
        if (!$canWrite) chat_err(409, 'Canal arquivado — apenas leitura');
        $m = chat_load_msg($pdo, (int)($SRC['message_id'] ?? 0), $type, $id);
        if ($m['deleted_at']) chat_err(409, 'Mensagem eliminada');
        $pdo->prepare('UPDATE chat_messages SET is_pinned = 1 - is_pinned, updated_at = NOW(3) WHERE id = ?')
            ->execute([(int)$m['id']]);
        chat_json(['ok' => true]);
    }

    case 'read': {
        [$type, $id] = chat_require_channel($pdo, $uid, $SRC);
        $last = (int)($SRC['last_id'] ?? 0);
        if ($last <= 0) {
            $st = $pdo->prepare('SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE channel_type = ? AND channel_id = ?');
            $st->execute([$type, $id]);
            $last = (int)$st->fetchColumn();
        }
        $pdo->prepare('INSERT INTO chat_reads (user_id, channel_type, channel_id, last_read_id, updated_at)
                       VALUES (?, ?, ?, ?, NOW(3))
                       ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id)), updated_at = NOW(3)')
            ->execute([$uid, $type, $id, $last]);
        chat_json(['ok' => true]);
    }

    case 'typing': {
        [$type, $id, , $canWrite] = chat_require_channel($pdo, $uid, $SRC);
        if ($canWrite) {
            $pdo->prepare('INSERT INTO chat_typing (user_id, channel_type, channel_id, updated_at) VALUES (?, ?, ?, NOW(3))
                           ON DUPLICATE KEY UPDATE updated_at = NOW(3)')->execute([$uid, $type, $id]);
        }
        chat_json(['ok' => true]);
    }

    // ── Download / visualização de anexo ───────────────────────
    case 'file': {
        $fid = (int)($SRC['file_id'] ?? 0);
        $st  = $pdo->prepare('SELECT a.*, m.channel_type, m.channel_id, m.deleted_at FROM chat_attachments a
                              JOIN chat_messages m ON m.id = a.message_id WHERE a.id = ?');
        $st->execute([$fid]);
        $a = $st->fetch();
        if (!$a || $a['deleted_at']) chat_err(404, 'Ficheiro não encontrado');
        chat_require_channel($pdo, $uid, ['type' => $a['channel_type'], 'id' => $a['channel_id']]);
        $full = realpath(__DIR__ . '/../' . $a['file_path']);
        $root = realpath(__DIR__ . '/../files/chat');
        if (!$full || !$root || !str_starts_with($full, $root) || !is_file($full)) chat_err(404, 'Ficheiro em falta no servidor');

        $mime   = $a['mime_type'] ?: 'application/octet-stream';
        $inline = !empty($SRC['inline']) && (chat_is_inline_image($mime) || $mime === 'application/pdf');
        $fname  = str_replace(['"', "\r", "\n"], '', $a['file_name']);
        header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fname . '"; filename*=UTF-8\'\'' . rawurlencode($a['file_name']));
        header('Cache-Control: private, max-age=86400');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        readfile($full);
        exit;
    }

    // ── Exportar histórico (HTML) ───────────────────────────────
    case 'export': {
        [$type, $id, $info] = chat_require_channel($pdo, $uid, $SRC);
        $st = $pdo->prepare(CHAT_MSG_SELECT . ' WHERE m.channel_type = ? AND m.channel_id = ? ORDER BY m.id');
        $st->execute([$type, $id]);
        $msgs  = chat_hydrate($pdo, $st->fetchAll(), $uid);
        $label = ['sprint' => 'Sprint', 'prototype' => 'Protótipo', 'lead' => 'Lead'][$type];
        $h     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $slug  = preg_replace('/[^A-Za-z0-9_-]+/', '_', $info['title']) ?: $type . $id;

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="chat_' . $type . '_' . $id . '_' . $slug . '.html"');
        echo '<!doctype html><html lang="pt"><head><meta charset="utf-8"><title>Chat — ' . $h($label . ': ' . $info['title']) . '</title>';
        echo '<style>body{font-family:Segoe UI,Arial,sans-serif;max-width:860px;margin:24px auto;padding:0 16px;color:#222}
              h1{font-size:20px}.meta{color:#666;font-size:13px;margin-bottom:20px}.day{margin:22px 0 8px;font-weight:600;color:#555;border-bottom:1px solid #ddd}
              .m{margin:8px 0;padding:8px 12px;border-radius:8px;background:#f5f5fa}.m .h{font-size:12px;color:#555}.m .h b{color:#222}
              .m .b{white-space:pre-wrap;margin-top:3px}.q{border-left:3px solid #999;padding-left:8px;color:#666;font-size:12px;margin:4px 0}
              .del{color:#999;font-style:italic}.att{font-size:12px;color:#335}.r{font-size:12px;color:#555}.pin{color:#b45309}</style></head><body>';
        echo '<h1>💬 ' . $h($label . ': ' . $info['title']) . '</h1>';
        echo '<div class="meta">Estado: ' . $h($info['estado']) . ' · ' . count($msgs) . ' mensagens · exportado em ' . date('Y-m-d H:i') . ' por ' . $h($myName) . '</div>';
        $day = '';
        foreach ($msgs as $m) {
            $d = substr($m['created_at'], 0, 10);
            if ($d !== $day) { $day = $d; echo '<div class="day">' . $h($d) . '</div>'; }
            echo '<div class="m"><div class="h"><b>' . $h($m['username']) . '</b> · ' . $h(substr($m['created_at'], 11, 5))
               . ($m['edited'] ? ' · (editada)' : '') . ($m['pinned'] ? ' · <span class="pin">📌 afixada</span>' : '') . '</div>';
            if ($m['reply_to']) echo '<div class="q">↪ ' . $h($m['reply_to']['username']) . ': ' . $h($m['reply_to']['body']) . '</div>';
            echo $m['deleted'] ? '<div class="b del">Mensagem eliminada</div>' : '<div class="b">' . $h($m['body']) . '</div>';
            foreach ($m['attachments'] as $a) echo '<div class="att">📎 ' . $h($a['name']) . ' (' . number_format($a['size'] / 1024, 0, ',', ' ') . ' KB)</div>';
            if ($m['reactions']) echo '<div class="r">' . implode(' ', array_map(fn($r) => $h($r['emoji'] . ' ' . $r['count']), $m['reactions'])) . '</div>';
            echo '</div>';
        }
        echo '</body></html>';
        exit;
    }

    default:
        chat_err(400, 'Acção desconhecida');
}
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    chat_err(500, 'Erro de base de dados: ' . $e->getMessage());
}
