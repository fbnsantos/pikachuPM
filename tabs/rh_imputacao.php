<?php
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
include_once __DIR__ . '/../config.php';

$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cur_uid = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id FROM admin_users WHERE user_id = ?");
$stmt->execute([$cur_uid]);
$is_admin = (bool)$stmt->fetch();

// ── Tables ──────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS rh_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_name VARCHAR(255) NOT NULL,
    type ENUM('contratados','bolseiros') NOT NULL,
    months_json TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    UNIQUE KEY uq_plan_type (plan_name, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS rh_persons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    rh_code VARCHAR(50) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    tipo_ligacao VARCHAR(150),
    user_token_id INT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    INDEX idx_camp (campaign_id),
    FOREIGN KEY (campaign_id) REFERENCES rh_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Add user_token_id column to existing tables (migration)
if (!$pdo->query("SHOW COLUMNS FROM rh_persons LIKE 'user_token_id'")->fetch()) {
    $pdo->exec("ALTER TABLE rh_persons ADD COLUMN user_token_id INT DEFAULT NULL AFTER tipo_ligacao");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS rh_person_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    project_code VARCHAR(100) NOT NULL DEFAULT '',
    project_name VARCHAR(255) NOT NULL DEFAULT '',
    pm_orc DECIMAL(8,2),
    pm_exe DECIMAL(8,2),
    project_id INT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    INDEX idx_per (person_id),
    FOREIGN KEY (person_id) REFERENCES rh_persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Add project_id column to existing tables (migration)
if (!$pdo->query("SHOW COLUMNS FROM rh_person_projects LIKE 'project_id'")->fetch()) {
    $pdo->exec("ALTER TABLE rh_person_projects ADD COLUMN project_id INT DEFAULT NULL AFTER pm_exe");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS rh_monthly_alloc (
    person_project_id INT NOT NULL,
    year SMALLINT NOT NULL,
    month TINYINT NOT NULL,
    percentage DECIMAL(6,2),
    PRIMARY KEY (person_project_id, year, month),
    FOREIGN KEY (person_project_id) REFERENCES rh_person_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Helpers ──────────────────────────────────────────────────────────────────
// Name normalization: lowercase, remove accents roughly, keep only letters/spaces
function rhNormName(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
                     'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
                     'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    return preg_replace('/[^a-z ]/', '', $s);
}

function rhMatchUser(string $fullName, array $users): ?int {
    $normFull = rhNormName($fullName);
    $nameWords = array_filter(explode(' ', $normFull), fn($w) => strlen($w) > 2);
    $best = ['score'=>0, 'uid'=>null];
    foreach ($users as $u) {
        $normUser = rhNormName($u['username']);
        $score = 0;
        foreach ($nameWords as $w) {
            if (str_contains($normUser, $w) || str_contains($normFull, $normUser)) $score++;
        }
        if ($score > $best['score']) { $best = ['score'=>$score, 'uid'=>(int)$u['id']]; }
    }
    return ($best['score'] >= 2) ? $best['uid'] : null;
}

// ── AJAX / JSON handlers ─────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$is_json = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || ($action === 'get_campaign_data')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($action && $is_json) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    switch ($action) {

        // ── Import full campaign from SheetJS-parsed JSON ─────────────────
        case 'import_rh': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $body = json_decode(file_get_contents('php://input'), true);
            $plan_name = trim($body['plan_name'] ?? '');
            $type      = $body['type'] ?? '';
            $months    = $body['months'] ?? [];
            $persons   = $body['persons'] ?? [];
            if (!$plan_name || !in_array($type, ['contratados','bolseiros'], true)) {
                echo json_encode(['error'=>'plan_name e type são obrigatórios']); exit;
            }
            // Pre-load projects for auto-match (short_name → id)
            $projLookup = [];
            foreach ($pdo->query("SELECT id, short_name FROM projects")->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                $projLookup[strtolower(trim($pr['short_name']))] = (int)$pr['id'];
            }
            // Pre-load users for name-based suggestion (best-effort)
            $userLookup = $pdo->query("SELECT id, user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM rh_campaigns WHERE plan_name=? AND type=?")
                    ->execute([$plan_name, $type]);
                $pdo->prepare("INSERT INTO rh_campaigns (plan_name,type,months_json,created_by) VALUES (?,?,?,?)")
                    ->execute([$plan_name, $type, json_encode($months), $cur_uid]);
                $cid = (int)$pdo->lastInsertId();
                $sortP = 0;
                foreach ($persons as $p) {
                    // Best-effort person→user match by word overlap
                    $matchedUid = rhMatchUser($p['full_name'], $userLookup);
                    $pdo->prepare("INSERT INTO rh_persons (campaign_id,rh_code,full_name,tipo_ligacao,user_token_id,sort_order) VALUES (?,?,?,?,?,?)")
                        ->execute([$cid, $p['rh_code'], $p['full_name'], $p['tipo_ligacao'] ?? null, $matchedUid, $sortP++]);
                    $pid = (int)$pdo->lastInsertId();
                    $sortPP = 0;
                    foreach ($p['projects'] as $pp) {
                        // Auto-match project by short_name
                        $projId = $projLookup[strtolower(trim($pp['name']))] ?? null;
                        $pdo->prepare("INSERT INTO rh_person_projects (person_id,project_code,project_name,pm_orc,pm_exe,project_id,sort_order) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$pid, $pp['code'], $pp['name'], $pp['pm_orc'] ?? null, $pp['pm_exe'] ?? null, $projId, $sortPP++]);
                        $ppid = (int)$pdo->lastInsertId();
                        foreach ($pp['allocations'] as $ym => $pct) {
                            if ($pct === null || $pct === '') continue;
                            [$y, $m] = explode('-', $ym);
                            $pdo->prepare("INSERT INTO rh_monthly_alloc (person_project_id,year,month,percentage) VALUES (?,?,?,?)")
                                ->execute([$ppid, (int)$y, (int)$m, (float)$pct]);
                        }
                    }
                }
                $pdo->commit();
                echo json_encode(['ok'=>true,'campaign_id'=>$cid]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['error'=>$e->getMessage()]);
            }
            exit;
        }

        // ── Fetch full campaign data (for grid render + export) ───────────
        case 'get_campaign_data': {
            $cid = (int)($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? 0);
            if (!$cid) { echo json_encode(['error'=>'campaign_id requerido']); exit; }
            $camp = $pdo->prepare("SELECT id,plan_name,type,months_json FROM rh_campaigns WHERE id=?");
            $camp->execute([$cid]);
            $campaign = $camp->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) { echo json_encode(['error'=>'Não encontrado']); exit; }
            $campaign['months'] = json_decode($campaign['months_json'], true);
            unset($campaign['months_json']);

            $ps = $pdo->prepare(
                "SELECT p.*, ut.username as linked_username
                 FROM rh_persons p
                 LEFT JOIN user_tokens ut ON p.user_token_id = ut.id
                 WHERE p.campaign_id=? ORDER BY p.sort_order, p.id");
            $ps->execute([$cid]);
            $persons = $ps->fetchAll(PDO::FETCH_ASSOC);
            foreach ($persons as &$person) {
                $pps = $pdo->prepare(
                    "SELECT pp.*, pr.short_name as linked_short_name, pr.title as linked_title,
                            pr.data_inicio, pr.data_fim
                     FROM rh_person_projects pp
                     LEFT JOIN projects pr ON pp.project_id = pr.id
                     WHERE pp.person_id=? ORDER BY pp.sort_order, pp.id");
                $pps->execute([$person['id']]);
                $person['projects'] = $pps->fetchAll(PDO::FETCH_ASSOC);
                foreach ($person['projects'] as &$pp) {
                    $al = $pdo->prepare("SELECT year,month,percentage FROM rh_monthly_alloc WHERE person_project_id=?");
                    $al->execute([$pp['id']]);
                    $pp['allocations'] = [];
                    foreach ($al->fetchAll(PDO::FETCH_ASSOC) as $a) {
                        $pp['allocations'][sprintf('%04d-%02d', $a['year'], $a['month'])] = (float)$a['percentage'];
                    }
                }
                unset($pp);
            }
            unset($person);
            $campaign['persons'] = $persons;
            echo json_encode($campaign);
            exit;
        }

        // ── Save one allocation cell ──────────────────────────────────────
        case 'save_alloc': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $ppid  = (int)($b['pp_id'] ?? 0);
            $year  = (int)($b['year']  ?? 0);
            $month = (int)($b['month'] ?? 0);
            $pct   = ($b['pct'] === '' || $b['pct'] === null) ? null : (float)$b['pct'];
            if (!$ppid || !$year || !$month) { echo json_encode(['error'=>'Dados incompletos']); exit; }
            // Validate against linked project date range
            $projDates = $pdo->prepare(
                "SELECT pr.data_inicio, pr.data_fim FROM rh_person_projects pp
                 LEFT JOIN projects pr ON pp.project_id=pr.id WHERE pp.id=?"
            );
            $projDates->execute([$ppid]);
            if ($pd = $projDates->fetch(PDO::FETCH_ASSOC)) {
                $ymCell = sprintf('%04d-%02d', $year, $month);
                if ($pd['data_inicio'] && $ymCell < substr($pd['data_inicio'], 0, 7))
                    { echo json_encode(['error'=>'Mês anterior ao início do projeto ('.substr($pd['data_inicio'],0,7).')']); exit; }
                if ($pd['data_fim'] && $ymCell > substr($pd['data_fim'], 0, 7))
                    { echo json_encode(['error'=>'Mês posterior ao fim do projeto ('.substr($pd['data_fim'],0,7).')']); exit; }
            }
            if ($pct === null) {
                $pdo->prepare("DELETE FROM rh_monthly_alloc WHERE person_project_id=? AND year=? AND month=?")
                    ->execute([$ppid, $year, $month]);
            } else {
                $pdo->prepare("INSERT INTO rh_monthly_alloc (person_project_id,year,month,percentage) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE percentage=VALUES(percentage)")
                    ->execute([$ppid, $year, $month, $pct]);
            }
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Save PM field ─────────────────────────────────────────────────
        case 'save_pm': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $ppid  = (int)($b['pp_id'] ?? 0);
            $field = $b['field'] ?? '';
            $val   = ($b['value'] === '' || $b['value'] === null) ? null : (float)$b['value'];
            if (!$ppid || !in_array($field, ['pm_orc','pm_exe'], true)) { echo json_encode(['error'=>'Inválido']); exit; }
            $pdo->prepare("UPDATE rh_person_projects SET $field=? WHERE id=?")->execute([$val, $ppid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Save person name/tipo ─────────────────────────────────────────
        case 'save_person': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $pid  = (int)($b['person_id'] ?? 0);
            $name = trim($b['full_name'] ?? '');
            $tipo = trim($b['tipo_ligacao'] ?? '');
            $code = trim($b['rh_code'] ?? '');
            if (!$pid) { echo json_encode(['error'=>'person_id requerido']); exit; }
            $pdo->prepare("UPDATE rh_persons SET full_name=?, tipo_ligacao=?, rh_code=? WHERE id=?")
                ->execute([$name, $tipo ?: null, $code, $pid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Save project label ────────────────────────────────────────────
        case 'save_project': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $ppid = (int)($b['pp_id'] ?? 0);
            $code = trim($b['project_code'] ?? '');
            $name = trim($b['project_name'] ?? '');
            if (!$ppid) { echo json_encode(['error'=>'pp_id requerido']); exit; }
            $pdo->prepare("UPDATE rh_person_projects SET project_code=?, project_name=? WHERE id=?")
                ->execute([$code, $name, $ppid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Add person ────────────────────────────────────────────────────
        case 'add_person': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $cid   = (int)($b['campaign_id'] ?? 0);
            $name  = trim($b['full_name'] ?? '');
            $code  = trim($b['rh_code'] ?? '');
            $tipo  = trim($b['tipo_ligacao'] ?? '');
            $utid = isset($b['user_token_id']) ? ($b['user_token_id'] === null ? null : (int)$b['user_token_id']) : null;
            if (!$cid || !$name) { echo json_encode(['error'=>'Dados incompletos']); exit; }
            $maxS = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM rh_persons WHERE campaign_id=?");
            $maxS->execute([$cid]);
            $sort = (int)$maxS->fetchColumn();
            $pdo->prepare("INSERT INTO rh_persons (campaign_id,rh_code,full_name,tipo_ligacao,user_token_id,sort_order) VALUES (?,?,?,?,?,?)")
                ->execute([$cid, $code, $name, $tipo ?: null, $utid, $sort]);
            $pid = (int)$pdo->lastInsertId();
            echo json_encode(['ok'=>true,'person_id'=>$pid]);
            exit;
        }

        // ── Adjust campaign months (add/remove a year) ───────────────────
        case 'adjust_months': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b   = json_decode(file_get_contents('php://input'), true);
            $plan = trim($b['plan_name'] ?? '');
            $dir  = $b['direction'] ?? '+';
            if (!$plan) { echo json_encode(['error'=>'Plano requerido']); exit; }

            $camps = $pdo->prepare("SELECT id, months_json FROM rh_campaigns WHERE plan_name=?");
            $camps->execute([$plan]);
            foreach ($camps->fetchAll(PDO::FETCH_ASSOC) as $camp) {
                $months = json_decode($camp['months_json'], true);
                sort($months);
                if ($dir === '+') {
                    [$y, $m] = explode('-', end($months));
                    $y = (int)$y; $m = (int)$m;
                    for ($i = 0; $i < 12; $i++) {
                        if (++$m > 12) { $m = 1; $y++; }
                        $months[] = sprintf('%04d-%02d', $y, $m);
                    }
                } else {
                    if (count($months) <= 12) { echo json_encode(['error'=>'Mínimo de 12 meses']); exit; }
                    foreach (array_slice($months, -12) as $ym) {
                        [$y, $mo] = explode('-', $ym);
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM rh_monthly_alloc a
                            JOIN rh_person_projects pp ON a.person_project_id=pp.id
                            JOIN rh_persons p ON pp.person_id=p.id
                            WHERE p.campaign_id=? AND a.year=? AND a.month=? AND a.percentage>0");
                        $chk->execute([$camp['id'], (int)$y, (int)$mo]);
                        if ($chk->fetchColumn() > 0) { echo json_encode(['error'=>'Existem imputações no último ano, não é possível remover']); exit; }
                    }
                    $months = array_slice($months, 0, -12);
                }
                $pdo->prepare("UPDATE rh_campaigns SET months_json=? WHERE id=?")->execute([json_encode($months), $camp['id']]);
            }
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Add project row to person ─────────────────────────────────────
        case 'add_person_project': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $pid  = (int)($b['person_id'] ?? 0);
            $code = trim($b['project_code'] ?? '');
            $name = trim($b['project_name'] ?? '');
            if (!$pid) { echo json_encode(['error'=>'person_id requerido']); exit; }
            $projId = array_key_exists('project_id', $b) ? ($b['project_id'] === null ? null : (int)$b['project_id']) : null;
            $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM rh_person_projects WHERE person_id=$pid")->fetchColumn();
            $pdo->prepare("INSERT INTO rh_person_projects (person_id,project_code,project_name,project_id,sort_order) VALUES (?,?,?,?,?)")
                ->execute([$pid, $code, $name, $projId, $sort]);
            echo json_encode(['ok'=>true,'pp_id'=>(int)$pdo->lastInsertId()]);
            exit;
        }

        // ── Delete project row ─────────────────────────────────────────────
        case 'delete_person_project': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $ppid = (int)($b['pp_id'] ?? 0);
            if (!$ppid) { echo json_encode(['error'=>'pp_id requerido']); exit; }
            $pdo->prepare("DELETE FROM rh_person_projects WHERE id=?")->execute([$ppid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Delete person (and all their project rows) ─────────────────────
        case 'delete_person': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $pid = (int)($b['person_id'] ?? 0);
            if (!$pid) { echo json_encode(['error'=>'person_id requerido']); exit; }
            $pdo->prepare("DELETE FROM rh_persons WHERE id=?")->execute([$pid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Delete plan ───────────────────────────────────────────────────
        case 'delete_plan': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $plan = $b['plan_name'] ?? '';
            if (!$plan) { echo json_encode(['error'=>'plan_name requerido']); exit; }
            $pdo->prepare("DELETE FROM rh_campaigns WHERE plan_name=?")->execute([$plan]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Link person to user_token ─────────────────────────────────────
        case 'link_person': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $pid = (int)($b['person_id'] ?? 0);
            $utid = isset($b['user_token_id']) ? ($b['user_token_id'] === '' || $b['user_token_id'] === null ? null : (int)$b['user_token_id']) : false;
            if (!$pid || $utid === false) { echo json_encode(['error'=>'Dados incompletos']); exit; }
            $pdo->prepare("UPDATE rh_persons SET user_token_id=? WHERE id=?")->execute([$utid, $pid]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        // ── Resumo global por projeto ─────────────────────────────────────
        case 'get_resumo_proj': {
            $campsAll = $pdo->query("SELECT months_json FROM rh_campaigns")->fetchAll(PDO::FETCH_COLUMN);
            $allMonths = [];
            foreach ($campsAll as $mj) foreach (json_decode($mj, true) ?: [] as $ym) $allMonths[$ym] = true;
            ksort($allMonths);
            $months = array_keys($allMonths);

            $rows = $pdo->query("
                SELECT pr.id as proj_id, pr.short_name, pr.title, pr.data_inicio, pr.data_fim,
                       p.id as person_id, p.full_name, p.rh_code,
                       ut.username as linked_username,
                       a.year, a.month, SUM(a.percentage) as pct
                FROM rh_monthly_alloc a
                JOIN rh_person_projects pp ON a.person_project_id = pp.id
                JOIN rh_persons p ON pp.person_id = p.id
                JOIN projects pr ON pp.project_id = pr.id
                LEFT JOIN user_tokens ut ON p.user_token_id = ut.id
                GROUP BY pr.id, p.id, a.year, a.month
                ORDER BY pr.short_name, p.full_name, a.year, a.month
            ")->fetchAll(PDO::FETCH_ASSOC);

            $projects = [];
            foreach ($rows as $r) {
                $pid = $r['proj_id']; $persId = $r['person_id'];
                if (!isset($projects[$pid])) $projects[$pid] = [
                    'proj_id'=>$pid,'short_name'=>$r['short_name'],'title'=>$r['title'],
                    'data_inicio'=>$r['data_inicio'],'data_fim'=>$r['data_fim'],'persons'=>[]
                ];
                if (!isset($projects[$pid]['persons'][$persId])) $projects[$pid]['persons'][$persId] = [
                    'person_id'=>$persId,'full_name'=>$r['full_name'],'rh_code'=>$r['rh_code'],
                    'linked_username'=>$r['linked_username'],'allocs'=>[]
                ];
                $ym = sprintf('%04d-%02d', $r['year'], $r['month']);
                $projects[$pid]['persons'][$persId]['allocs'][$ym] = (float)$r['pct'];
            }
            $result = [];
            foreach ($projects as $proj) { $proj['persons'] = array_values($proj['persons']); $result[] = $proj; }
            echo json_encode(['months'=>$months,'projects'=>$result]);
            exit;
        }

        // ── Resumo global por utilizador PK ───────────────────────────────
        case 'get_resumo_pk': {
            $campsAll = $pdo->query("SELECT months_json FROM rh_campaigns")->fetchAll(PDO::FETCH_COLUMN);
            $allMonths = [];
            foreach ($campsAll as $mj) {
                foreach (json_decode($mj, true) ?: [] as $ym) $allMonths[$ym] = true;
            }
            ksort($allMonths);
            $months = array_keys($allMonths);

            $users = $pdo->query("SELECT id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

            $rows = $pdo->query("
                SELECT ut.id as ut_id, a.year, a.month, SUM(a.percentage) as total
                FROM rh_monthly_alloc a
                JOIN rh_person_projects pp ON a.person_project_id = pp.id
                JOIN rh_persons p ON pp.person_id = p.id
                JOIN user_tokens ut ON p.user_token_id = ut.id
                GROUP BY ut.id, a.year, a.month
            ")->fetchAll(PDO::FETCH_ASSOC);

            $allocs = [];
            foreach ($rows as $r) {
                $ym = $r['year'].'-'.str_pad($r['month'], 2, '0', STR_PAD_LEFT);
                $allocs[$r['ut_id']][$ym] = (float)$r['total'];
            }

            echo json_encode(['months' => $months, 'users' => $users, 'allocs' => $allocs]);
            exit;
        }

        // ── Get users list (for person-linking UI) ────────────────────────
        case 'get_users': {
            $users = $pdo->query("SELECT id, user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($users);
            exit;
        }

        // ── Link project row to pikachuPM project ─────────────────────────
        case 'link_project': {
            if (!$is_admin) { echo json_encode(['error'=>'Sem permissão']); exit; }
            $b = json_decode(file_get_contents('php://input'), true);
            $ppid   = (int)($b['pp_id'] ?? 0);
            $projId = array_key_exists('project_id', $b) ? ($b['project_id'] === null ? null : (int)$b['project_id']) : false;
            $code   = trim($b['project_code'] ?? '');
            $name   = trim($b['project_name'] ?? '');
            if (!$ppid || $projId === false) { echo json_encode(['error'=>'Dados incompletos']); exit; }

            // Read original name before update (used as propagation key)
            $origName = $pdo->prepare("SELECT project_name FROM rh_person_projects WHERE id=?");
            $origName->execute([$ppid]);
            $origName = (string)$origName->fetchColumn();

            // Update the target row
            $pdo->prepare("UPDATE rh_person_projects SET project_id=?, project_code=?, project_name=? WHERE id=?")
                ->execute([$projId, $code, $name, $ppid]);

            // Propagate project_id to all rows with the same project_name (case-insensitive)
            $propagated = 0;
            if ($origName !== '') {
                $stmt = $pdo->prepare(
                    "UPDATE rh_person_projects SET project_id=? WHERE LOWER(TRIM(project_name))=LOWER(TRIM(?)) AND id!=?"
                );
                $stmt->execute([$projId, $origName, $ppid]);
                $propagated = $stmt->rowCount();
            }
            echo json_encode(['ok'=>true, 'propagated'=>$propagated]);
            exit;
        }
    }

    echo json_encode(['error'=>'Ação desconhecida']);
    exit;
}

// ── Page data ────────────────────────────────────────────────────────────────
$plans = $pdo->query(
    "SELECT plan_name, MIN(created_at) as created_at FROM rh_campaigns GROUP BY plan_name ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$campaigns = $pdo->query(
    "SELECT id, plan_name, type FROM rh_campaigns ORDER BY plan_name, type"
)->fetchAll(PDO::FETCH_ASSOC);

$plan_map = [];
foreach ($campaigns as $c) {
    $plan_map[$c['plan_name']][$c['type']] = (int)$c['id'];
}

// Users for person-link selector
$rh_users = $pdo->query("SELECT id, user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Projects for project-link selector
$rh_projects = $pdo->query("SELECT id, short_name, title FROM projects ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
.rh-wrap { padding: 0 0 60px; }
.rh-topbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.rh-topbar h5 { margin:0; font-size:15px; font-weight:700; }
.rh-plan-sel { min-width:200px; max-width:320px; font-size:13px; }

/* Tabs */
.rh-tabs { display:flex; gap:0; border-bottom:2px solid #dee2e6; margin-bottom:0; }
.rh-tab { background:none; border:none; padding:8px 20px; font-size:13px; font-weight:500; color:#6c757d; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .15s; }
.rh-tab.active { color:#0d6efd; border-bottom-color:#0d6efd; }
.rh-tab:hover:not(.active) { color:#495057; background:#f8f9fa; border-radius:6px 6px 0 0; }

/* Grid outer */
.rh-grid-outer { position:relative; overflow-x:auto; border:1px solid #dee2e6; border-radius:0 8px 8px 8px; background:#fff; }

/* Table */
.rh-table { border-collapse:separate; border-spacing:0; font-size:12px; white-space:nowrap; min-width:100%; }
.rh-table th, .rh-table td { border-right:1px solid #e9ecef; border-bottom:1px solid #e9ecef; padding:0; }
.rh-table th { position:sticky; top:0; z-index:3; background:#343a40; color:#fff; font-weight:600; text-align:center; padding:4px 6px; }
.rh-table th.rh-sticky { z-index:5; }
.rh-table td.rh-sticky { position:sticky; z-index:2; background:#fff; }

/* Sticky column widths */
.rh-col-proj  { width:200px; min-width:200px; left:0; text-align:left !important; }
.rh-col-tipo  { width:170px; min-width:170px; left:200px; font-size:11px; color:#6c757d; }
.rh-col-pmorc { width:68px;  min-width:68px;  left:370px; text-align:center !important; }
.rh-col-pmexe { width:68px;  min-width:68px;  left:438px; text-align:center !important; }
.rh-sticky-border { border-right:2px solid #adb5bd !important; }

/* Header year/month rows */
.rh-year-th { background:#1a2433 !important; font-size:11px; letter-spacing:.5px; }
.rh-month-th { background:#343a40 !important; font-size:11px; padding:3px 2px !important; min-width:42px; width:42px; }

/* Person header row */
.rh-person-hdr td { background:#e9ecef; font-weight:600; font-size:12px; padding:5px 10px; cursor:pointer; user-select:none; border-bottom:1px solid #ced4da; }
.rh-person-hdr td:hover { background:#dee2e6; }
.rh-person-hdr .rh-ph-name { font-size:13px; }
.rh-person-hdr .rh-ph-meta { font-size:11px; font-weight:400; color:#6c757d; margin-left:8px; }
.rh-person-hdr .rh-ph-tipo { font-size:11px; font-weight:400; color:#0d6efd; margin-left:6px; }

/* Project rows */
.rh-proj-row td { padding:2px 4px; vertical-align:middle; }
.rh-proj-row:hover td { background:#f8f9fa; }
.rh-proj-row td.rh-sticky { background:#fff; }
.rh-proj-row:hover td.rh-sticky { background:#f8f9fa; }
.rh-proj-label { padding:2px 6px !important; }
.rh-proj-code { font-size:10px; color:#0d6efd; font-weight:600; }
.rh-proj-name { font-size:11px; color:#212529; }

/* SUM row */
.rh-sum-row td { background:#f1f3f5; font-size:11px; font-weight:600; padding:2px 4px; border-top:1px solid #ced4da !important; border-bottom:2px solid #adb5bd !important; }
.rh-sum-row td.rh-sticky { background:#f1f3f5; }

/* Allocation cells */
.rh-cell { width:42px; min-width:42px; text-align:center; padding:1px 2px !important; cursor:default; }
.rh-cell.rh-editable { cursor:pointer; }
.rh-cell.rh-editable:hover { outline:2px solid #0d6efd; outline-offset:-2px; }
.rh-cell-empty  { color:#adb5bd; }
.rh-cell-low    { background:#fff9e6; }
.rh-cell-full   { background:#d1e7dd; font-weight:700; color:#0f5132; }
.rh-cell-over   { background:#f8d7da; color:#721c24; font-weight:700; }
.rh-cell-locked { background:repeating-linear-gradient(45deg,#f1f3f5,#f1f3f5 3px,#e9ecef 3px,#e9ecef 6px); cursor:not-allowed!important; }
.rh-pm-auto { background:#f0fdf4; color:#166534; font-weight:600; cursor:default!important; font-size:11px; text-align:center; }
.rh-cell-locked:hover { outline:none!important; }
.rh-sum-empty   { color:#adb5bd; }
.rh-sum-ok      { background:#d1e7dd; color:#0f5132; }
.rh-sum-partial { background:#fff3cd; color:#664d03; }
.rh-sum-over    { background:#f8d7da; color:#721c24; }

/* PM cells */
.rh-pm-cell { text-align:center; font-size:11px; color:#495057; }
.rh-pm-cell.rh-editable { cursor:pointer; }
.rh-pm-cell.rh-editable:hover { background:#e8f4fd; outline:2px solid #0d6efd; outline-offset:-2px; }

/* Floating edit input */
#rh-edit-input {
    position:fixed; z-index:9999; display:none;
    width:52px; padding:2px 4px; font-size:12px; font-weight:600;
    text-align:center; border:2px solid #0d6efd; border-radius:4px;
    background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.2);
}

/* Empty state */
.rh-empty { padding:40px; text-align:center; color:#adb5bd; font-size:14px; }

/* Collapsed rows */
.rh-person-hidden { display:none; }

/* Admin action buttons on row */
.rh-row-actions { display:none; gap:4px; }
.rh-proj-row:hover .rh-row-actions { display:flex; }
.rh-btn-del { background:none; border:none; color:#dc3545; cursor:pointer; font-size:13px; padding:0 2px; line-height:1; }

/* Add person/project forms */
.rh-add-bar { display:flex; align-items:center; gap:8px; padding:6px 10px; background:#f8f9fa; border-top:1px dashed #dee2e6; }
.rh-add-bar input { font-size:12px; padding:3px 8px; border:1px solid #ced4da; border-radius:4px; }
.rh-add-bar .btn { font-size:12px; padding:3px 10px; }

/* Legend */
.rh-legend { display:flex; gap:14px; flex-wrap:wrap; font-size:11px; color:#6c757d; padding:6px 10px; background:#f8f9fa; border-top:1px solid #dee2e6; border-radius:0 0 8px 8px; }
.rh-legend-item { display:flex; align-items:center; gap:4px; }
.rh-legend-swatch { width:14px; height:14px; border-radius:3px; border:1px solid #dee2e6; }

/* User link badges */
.rh-user-badge { display:inline-flex; align-items:center; background:#dbeafe; color:#1d4ed8; font-size:10px; font-weight:600; padding:1px 7px; border-radius:10px; margin-left:8px; }
.rh-user-unlinked { background:#f3f4f6; color:#9ca3af; }

/* Project linked indicator */
.rh-proj-linked .rh-proj-code { color:#198754; }
.rh-btn-link { background:none;border:none;cursor:pointer;font-size:12px;padding:0 3px;opacity:.6; }
.rh-btn-link:hover { opacity:1; }
.rh-proj-pick { padding:5px 8px;cursor:pointer;border-bottom:1px solid #f1f3f5;font-size:12px; }
.rh-proj-pick:hover { background:#f8f9fa; }
.rh-proj-pick-sel { background:#dbeafe!important; }
.rh-link-dot { color:#198754; font-size:8px; vertical-align:middle; }
</style>

<div class="rh-wrap">

<!-- Top bar -->
<div class="rh-topbar">
  <h5>📋 Imputação RH</h5>

  <select class="form-select form-select-sm rh-plan-sel" id="rh-plan-sel" onchange="rhSelectPlan(this.value)">
    <option value="">— Seleciona um plano —</option>
    <?php foreach ($plans as $pl): ?>
    <option value="<?= htmlspecialchars($pl['plan_name']) ?>">
      <?= htmlspecialchars($pl['plan_name']) ?>
    </option>
    <?php endforeach; ?>
  </select>

  <?php if ($is_admin): ?>
  <button class="btn btn-sm btn-outline-success" onclick="document.getElementById('rh-file-input').click()">
    <i class="bi bi-upload"></i> Importar XLSX
  </button>
  <input type="file" id="rh-file-input" accept=".xlsx" style="display:none" onchange="rhImportFile(this)">

  <button class="btn btn-sm btn-outline-primary" onclick="rhExportXlsx()" id="rh-export-btn" disabled>
    <i class="bi bi-download"></i> Exportar XLSX
  </button>

  <button class="btn btn-sm btn-outline-secondary" onclick="rhNewPlanModal()" title="Criar plano manualmente">
    <i class="bi bi-plus-circle"></i> Novo plano
  </button>

  <div class="btn-group" id="rh-year-btns" style="display:none">
    <button class="btn btn-sm btn-outline-secondary" onclick="rhAdjustMonths('-')" title="Remover último ano (se vazio)">− ano</button>
    <button class="btn btn-sm btn-outline-secondary" onclick="rhAdjustMonths('+')" title="Adicionar mais um ano">+ ano</button>
  </div>

  <button class="btn btn-sm btn-outline-danger" onclick="rhDeletePlan()" id="rh-delete-btn" disabled>
    <i class="bi bi-trash3"></i> Eliminar plano
  </button>
  <?php else: ?>
  <button class="btn btn-sm btn-outline-primary" onclick="rhExportXlsx()" id="rh-export-btn" disabled>
    <i class="bi bi-download"></i> Exportar XLSX
  </button>
  <?php endif; ?>

  <span id="rh-status" class="text-muted" style="font-size:12px"></span>
</div>

<!-- Tabs -->
<div class="rh-tabs">
  <button class="rh-tab active" data-type="contratados" onclick="rhSwitchTab('contratados')">
    👤 Contratados
  </button>
  <button class="rh-tab" data-type="bolseiros" onclick="rhSwitchTab('bolseiros')">
    🎓 Bolseiros
  </button>
  <button class="rh-tab" data-type="resumo" onclick="rhSwitchTab('resumo')">
    📊 Resumo PK
  </button>
  <button class="rh-tab" data-type="resumo-proj" onclick="rhSwitchTab('resumo-proj')">
    📁 Resumo Projetos
  </button>
</div>

<!-- Grid containers -->
<div id="rh-grid-contratados" class="rh-grid-outer">
  <div class="rh-empty">Seleciona um plano para visualizar os dados</div>
</div>
<div id="rh-grid-bolseiros" class="rh-grid-outer" style="display:none">
  <div class="rh-empty">Seleciona um plano para visualizar os dados</div>
</div>
<div id="rh-grid-resumo" class="rh-grid-outer" style="display:none">
  <div class="rh-empty">A carregar…</div>
</div>
<div id="rh-grid-resumo-proj" class="rh-grid-outer" style="display:none">
  <div class="rh-empty">A carregar…</div>
</div>

<!-- Floating edit input -->
<input id="rh-edit-input" type="number" min="0" max="999" step="1"
       onkeydown="rhInputKey(event)" onblur="rhInputBlur()">

</div><!-- .rh-wrap -->

<!-- New Plan Modal -->
<div class="modal fade" id="rh-new-plan-modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Novo Plano Manual</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-bold">Nome do plano</label>
          <input type="text" class="form-control" id="rh-new-plan-name" placeholder="ex: CRIIS 2026-2029">
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Período inicial</label>
          <input type="month" class="form-control" id="rh-new-plan-start" value="2026-01">
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Período final</label>
          <input type="month" class="form-control" id="rh-new-plan-end" value="2029-12">
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Tipo a criar</label>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="rh-np-cont" checked>
            <label class="form-check-label" for="rh-np-cont">Contratados</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="rh-np-bols" checked>
            <label class="form-check-label" for="rh-np-bols">Bolseiros</label></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary btn-sm" onclick="rhCreateEmptyPlan()">Criar</button>
      </div>
    </div>
  </div>
</div>

<!-- Add person modal -->
<div class="modal fade" id="rh-add-person-modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0">➕ Adicionar pessoa</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="rh-ap-campaign-id">
        <div class="row g-2 mb-2">
          <div class="col-8">
            <label class="form-label fw-bold mb-0" style="font-size:11px">Nome completo *</label>
            <input type="text" class="form-control form-control-sm" id="rh-ap-name"
                   placeholder="Nome da pessoa" oninput="rhUpdateApUserSuggestions(this.value)">
          </div>
          <div class="col-4">
            <label class="form-label fw-bold mb-0" style="font-size:11px">Código RH</label>
            <input type="text" class="form-control form-control-sm" id="rh-ap-code" placeholder="R12345">
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-bold mb-0" style="font-size:11px">Tipo de ligação</label>
          <input type="text" class="form-control form-control-sm" id="rh-ap-tipo" placeholder="ex: Colaborador, Bolseiro…">
        </div>
        <label class="form-label fw-bold mb-1" style="font-size:11px">Utilizador pikachuPM</label>
        <select class="form-select form-select-sm" id="rh-ap-user-sel"></select>
        <p class="text-muted mt-1 mb-0" style="font-size:11px">★ = sugestão por nome. Opcional.</p>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success btn-sm" onclick="rhSaveAddPerson()">Adicionar</button>
      </div>
    </div>
  </div>
</div>

<!-- Link project modal -->
<div class="modal fade" id="rh-link-proj-modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0">🔗 Associar projeto pikachuPM</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding-bottom:8px">
        <input type="hidden" id="rh-lp-ppid">
        <input type="hidden" id="rh-lp-person-id">
        <input type="hidden" id="rh-lp-type">
        <input type="hidden" id="rh-lp-selected-proj-id">
        <input type="text" class="form-control form-control-sm mb-2" id="rh-lp-search"
               placeholder="Pesquisar projeto…" oninput="rhFilterProjList(this.value)">
        <div id="rh-lp-list" style="max-height:220px;overflow-y:auto;border:1px solid #dee2e6;border-radius:4px;margin-bottom:10px"></div>
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label fw-bold mb-0" style="font-size:11px">Código RH</label>
            <input type="text" class="form-control form-control-sm" id="rh-lp-code" placeholder="ex: PG07206">
          </div>
          <div class="col-8">
            <label class="form-label fw-bold mb-0" style="font-size:11px">Nome no ficheiro RH</label>
            <input type="text" class="form-control form-control-sm" id="rh-lp-name" placeholder="Nome curto">
          </div>
        </div>
        <p class="text-muted mt-2 mb-0" style="font-size:11px">Seleciona um projeto da lista para associar. O código e nome podem ser editados independentemente.</p>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="rhClearProjLink()">Sem ligação</button>
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary btn-sm" onclick="rhSaveLinkProject()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- Link user modal -->
<div class="modal fade" id="rh-link-user-modal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0">🔗 Ligar ao utilizador</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-2" style="font-size:12px"><strong id="rh-lu-person-name"></strong></p>
        <input type="hidden" id="rh-lu-person-id">
        <label class="form-label fw-bold" style="font-size:12px">Utilizador pikachuPM</label>
        <select class="form-select form-select-sm" id="rh-lu-user-sel"></select>
        <p class="text-muted mt-2 mb-0" style="font-size:11px">★ = sugestão automática por nome</p>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary btn-sm" onclick="rhSaveLinkUser()">Guardar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
<script>
// ── State ────────────────────────────────────────────────────────────────────
const RH_PLAN_MAP   = <?= json_encode($plan_map, JSON_UNESCAPED_UNICODE) ?>;
const RH_IS_ADMIN   = <?= $is_admin ? 'true' : 'false' ?>;
const RH_USERS      = <?= json_encode($rh_users, JSON_UNESCAPED_UNICODE) ?>; // [{id, user_id, username}]
const RH_PROJECTS   = <?= json_encode($rh_projects, JSON_UNESCAPED_UNICODE) ?>; // [{id, short_name, title}]
let rhCurrentPlan   = null;
let rhCurrentType   = 'contratados';
let rhCampaignData  = { contratados: null, bolseiros: null };
let rhEditTarget    = null; // { el, ppid, year, month, field }

// ── PT month labels ──────────────────────────────────────────────────────────
const PT_MONTHS_SHORT = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
const PT_MONTHS_ABBR  = { 'jan':'01','fev':'02','mar':'03','abr':'04','mai':'05','jun':'06',
                            'jul':'07','ago':'08','set':'09','out':'10','nov':'11','dez':'12' };

function rhYMLabel(ym) {
    const [y, m] = ym.split('-');
    return PT_MONTHS_SHORT[parseInt(m)-1] + ' ' + y.slice(2);
}

// ── Tab switching ─────────────────────────────────────────────────────────────
let rhResumoLoaded = false;
let rhResumoProjLoaded = false;
let rhResumoProjData = null;
let rhResumoPkData = null;
function rhSwitchTab(type) {
    rhCurrentType = type;
    document.querySelectorAll('.rh-tab').forEach(t => t.classList.toggle('active', t.dataset.type === type));
    document.getElementById('rh-grid-contratados').style.display  = type === 'contratados'  ? '' : 'none';
    document.getElementById('rh-grid-bolseiros').style.display    = type === 'bolseiros'    ? '' : 'none';
    document.getElementById('rh-grid-resumo').style.display       = type === 'resumo'       ? '' : 'none';
    document.getElementById('rh-grid-resumo-proj').style.display  = type === 'resumo-proj'  ? '' : 'none';
    if (type === 'resumo'      && !rhResumoLoaded)     rhLoadResumo();
    if (type === 'resumo-proj' && !rhResumoProjLoaded) rhLoadResumoProj();
}

async function rhLoadResumo() {
    const el = document.getElementById('rh-grid-resumo');
    el.innerHTML = '<div class="rh-empty">A carregar…</div>';
    const r = await fetch('?tab=rh_imputacao&action=get_resumo_pk', { headers:{'X-Requested-With':'XMLHttpRequest'} });
    rhResumoPkData = await r.json();
    rhResumoLoaded = true;
    rhRenderResumo(rhResumoPkData, el);
}

async function rhLoadResumoProj() {
    const el = document.getElementById('rh-grid-resumo-proj');
    el.innerHTML = '<div class="rh-empty">A carregar…</div>';
    const r = await fetch('?tab=rh_imputacao&action=get_resumo_proj', { headers:{'X-Requested-With':'XMLHttpRequest'} });
    rhResumoProjData = await r.json();
    rhResumoProjLoaded = true;
    rhRenderResumoProj(rhResumoProjData, el);
}

function rhRenderResumoProj(data, el) {
    const { months, projects } = data;
    if (!months.length || !projects.length) {
        el.innerHTML = '<div class="rh-empty">Sem dados de imputação por projeto</div>'; return;
    }
    const PT_ABBRs = { '01':'jan','02':'fev','03':'mar','04':'abr','05':'mai','06':'jun',
                       '07':'jul','08':'ago','09':'set','10':'out','11':'nov','12':'dez' };
    const yearGroups = {};
    months.forEach(ym => { const y = ym.split('-')[0]; yearGroups[y] = (yearGroups[y]||0)+1; });

    let html = '<div class="rh-grid-inner"><table class="rh-table">';

    // Header row 1: year spans
    html += '<tr>'
          + '<th class="rh-sticky" style="left:0;min-width:200px;text-align:left;padding-left:8px">Projeto / Pessoa</th>'
          + '<th class="rh-sticky" style="left:200px;min-width:130px;text-align:left">Utilizador PK</th>';
    Object.entries(yearGroups).forEach(([y, cnt]) => {
        html += '<th colspan="'+cnt+'" style="text-align:center;border-left:2px solid #555">'+y+'</th>';
    });
    html += '</tr>';

    // Header row 2: months
    html += '<tr>'
          + '<th class="rh-sticky" style="left:0;background:#343a40"></th>'
          + '<th class="rh-sticky" style="left:200px;background:#343a40"></th>';
    months.forEach(ym => {
        const m = ym.split('-')[1];
        html += '<th style="min-width:44px;'+(m==='01'?'border-left:2px solid #555':'')+'">'
              + PT_ABBRs[m]+'</th>';
    });
    html += '</tr>';

    projects.forEach(proj => {
        const ps = proj.data_inicio ? proj.data_inicio.substring(0,7) : null;
        const pe = proj.data_fim    ? proj.data_fim.substring(0,7)    : null;

        // Project total row
        const projTotals = {};
        months.forEach(ym => projTotals[ym] = proj.persons.reduce((s,p) => s+(p.allocs[ym]||0), 0));

        html += '<tr>';
        html += '<td class="rh-sticky" style="left:0;background:#dbeafe;font-weight:700;font-size:12px;padding:4px 8px;color:#1e40af">'
              + rhEsc(proj.short_name) + ' — ' + rhEsc(proj.title)
              + (ps ? ' <span style="font-size:10px;font-weight:400;color:#64748b">('+ps+' → '+(pe||'…')+')</span>' : '')
              + '</td>';
        html += '<td class="rh-sticky" style="left:200px;background:#dbeafe;font-size:11px;color:#64748b;padding:4px 8px">'
              + proj.persons.length + ' pessoa'+(proj.persons.length!==1?'s':'')+'</td>';
        months.forEach(ym => {
            const v = projTotals[ym];
            const m = ym.split('-')[1];
            let bg = 'background:#dbeafe;';
            if (v > 0) bg = 'background:#bfdbfe;font-weight:700;';
            html += '<td style="text-align:center;font-size:11px;'+(m==='01'?'border-left:2px solid #93c5fd;':'')+bg+'">'
                  + (v ? v : '<span style="color:#93c5fd">—</span>')+'</td>';
        });
        html += '</tr>';

        // Person rows
        proj.persons.forEach(person => {
            html += '<tr>';
            html += '<td class="rh-sticky" style="left:0;padding:2px 8px 2px 20px;background:#fff;font-size:11px">'
                  + rhEsc((person.rh_code ? person.rh_code+' | ' : '')+person.full_name)+'</td>';
            html += '<td class="rh-sticky" style="left:200px;padding:2px 8px;background:#fff;font-size:11px;color:#1d4ed8">'
                  + rhEsc(person.linked_username||'—')+'</td>';
            months.forEach(ym => {
                const locked = (ps && ym < ps) || (pe && ym > pe);
                const v = person.allocs[ym] ?? 0;
                const m = ym.split('-')[1];
                let style = m==='01'?'border-left:2px solid #dee2e6;':'';
                if (locked) style += 'background:repeating-linear-gradient(45deg,#f1f3f5,#f1f3f5 2px,#e9ecef 2px,#e9ecef 4px);';
                else if (v>=100 && v===100) style += 'background:#d4edda;font-weight:700;color:#0f5132;';
                else if (v>100) style += 'background:#f8d7da;font-weight:700;color:#721c24;';
                else if (v>0)   style += 'background:#fff9c4;';
                html += '<td style="text-align:center;font-size:11px;'+style+'">'
                      + (locked?'':(v?v:'<span style="color:#ced4da">—</span>'))+'</td>';
            });
            html += '</tr>';
        });
    });

    html += '</table></div>';
    el.innerHTML = html;
}

function rhRenderResumo(data, el) {
    const { months, users, allocs } = data;
    if (!months.length) { el.innerHTML = '<div class="rh-empty">Sem dados de imputação disponíveis</div>'; return; }

    const PT_ABBRs = { '01':'jan','02':'fev','03':'mar','04':'abr','05':'mai','06':'jun',
                       '07':'jul','08':'ago','09':'set','10':'out','11':'nov','12':'dez' };

    // Group months by year for header
    const yearGroups = {};
    months.forEach(ym => { const y = ym.split('-')[0]; yearGroups[y] = (yearGroups[y]||0)+1; });

    let html = '<div class="rh-grid-inner"><table class="rh-table">';

    // Header row 1: year spans
    html += '<tr><th class="rh-sticky" style="left:0;min-width:160px;text-align:left;padding-left:8px">Utilizador PK</th>';
    Object.entries(yearGroups).forEach(([y, cnt]) => {
        html += '<th colspan="'+cnt+'" style="text-align:center;border-left:2px solid #555">'+y+'</th>';
    });
    html += '</tr>';

    // Header row 2: month abbreviations
    html += '<tr><th class="rh-sticky" style="left:0;background:#343a40"></th>';
    months.forEach(ym => {
        const [y, m] = ym.split('-');
        html += '<th style="min-width:44px;'+(m === '01' ? 'border-left:2px solid #555':'')+'">'
              + PT_ABBRs[m]+'</th>';
    });
    html += '</tr>';

    // User rows
    users.forEach(u => {
        const ua = allocs[u.id] || {};
        const hasAny = months.some(ym => ua[ym]);
        html += '<tr>';
        html += '<td class="rh-sticky" style="left:0;padding:3px 8px;background:#fff;font-size:12px;font-weight:'+(hasAny?'600':'400')+';color:'+(hasAny?'#212529':'#adb5bd')+'">'+rhEsc(u.username)+'</td>';
        months.forEach(ym => {
            const v = ua[ym] ?? 0;
            let bg = '';
            if (v === 0)        bg = '';
            else if (v < 100)   bg = 'background:#fff9c4';
            else if (v === 100) bg = 'background:#d4edda';
            else                bg = 'background:#f8d7da';
            const border = ym.endsWith('-01') ? 'border-left:2px solid #dee2e6;' : '';
            html += '<td style="text-align:center;font-size:11px;'+border+bg+'">'
                  + (v ? v : '<span style="color:#ced4da">—</span>')+'</td>';
        });
        html += '</tr>';
    });

    html += '</table></div>';
    el.innerHTML = html;
}

// ── Plan selection ────────────────────────────────────────────────────────────
function rhSelectPlan(planName) {
    rhCurrentPlan = planName || null;
    const expBtn = document.getElementById('rh-export-btn');
    const delBtn = document.getElementById('rh-delete-btn');
    if (expBtn) expBtn.disabled = !planName;
    if (delBtn) delBtn.disabled = !planName;
    const yearBtns = document.getElementById('rh-year-btns');
    if (yearBtns) yearBtns.style.display = planName ? '' : 'none';

    if (!planName) {
        ['contratados','bolseiros'].forEach(t => {
            rhCampaignData[t] = null;
            document.getElementById('rh-grid-'+t).innerHTML =
                '<div class="rh-empty">Seleciona um plano para visualizar os dados</div>';
        });
        return;
    }

    const planCamps = RH_PLAN_MAP[planName] || {};
    ['contratados','bolseiros'].forEach(type => {
        const cid = planCamps[type];
        const el  = document.getElementById('rh-grid-'+type);
        if (!cid) {
            rhCampaignData[type] = null;
            el.innerHTML = '<div class="rh-empty">Sem dados de ' + type + ' neste plano</div>';
            return;
        }
        el.innerHTML = '<div class="rh-empty"><div class="spinner-border spinner-border-sm text-secondary me-2"></div>A carregar…</div>';
        fetch('?tab=rh_imputacao&action=get_campaign_data&campaign_id='+cid, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            rhCampaignData[type] = data;
            rhRenderGrid(data, el, type);
        })
        .catch(e => { el.innerHTML = '<div class="rh-empty text-danger">Erro: ' + e.message + '</div>'; });
    });
}

// ── Grid rendering ────────────────────────────────────────────────────────────
function rhRenderGrid(data, container, type) {
    if (!data || !data.months || !data.persons) {
        container.innerHTML = '<div class="rh-empty">Sem dados</div>';
        return;
    }
    const months = data.months; // ["2026-01", ...]
    const totalCols = 4 + months.length;

    // Group months by year
    const yearGroups = [];
    months.forEach(ym => {
        const y = ym.split('-')[0];
        if (!yearGroups.length || yearGroups[yearGroups.length-1].year !== y)
            yearGroups.push({year: y, count: 0});
        yearGroups[yearGroups.length-1].count++;
    });

    let html = '<table class="rh-table" id="rh-tbl-'+type+'">';

    // ── Header row 1: year groups
    html += '<thead><tr>';
    html += '<th class="rh-sticky rh-col-proj rh-year-th" rowspan="2">Projeto</th>';
    html += '<th class="rh-sticky rh-col-tipo rh-year-th" rowspan="2">Tipo Ligação</th>';
    html += '<th class="rh-sticky rh-col-pmorc rh-year-th" rowspan="2">PM<br>ORC</th>';
    html += '<th class="rh-sticky rh-col-pmexe rh-sticky-border rh-year-th" rowspan="2">PM<br>EXE</th>';
    yearGroups.forEach(yg => {
        html += '<th class="rh-year-th" colspan="'+yg.count+'" style="font-size:13px;padding:5px 0">'+yg.year+'</th>';
    });
    html += '</tr>';

    // ── Header row 2: month names
    html += '<tr>';
    months.forEach(ym => {
        html += '<th class="rh-month-th">'+rhYMLabel(ym)+'</th>';
    });
    html += '</tr></thead><tbody>';

    // ── Person groups
    data.persons.forEach(person => {
        const pid = person.id;
        const projects = person.projects || [];

        // Person header row
        // PM EXE computed from allocations (Σ% / 100), locked months excluded
        function ppComputedPmExe(pp) {
            return months.reduce((s, ym) => {
                const ps = pp.data_inicio ? pp.data_inicio.substring(0,7) : null;
                const pe = pp.data_fim    ? pp.data_fim.substring(0,7)    : null;
                if ((ps && ym < ps) || (pe && ym > pe)) return s;
                return s + parseFloat(pp.allocations?.[ym] || 0);
            }, 0) / 100;
        }
        const pmExeTotal = projects.reduce((s,pp) => s + ppComputedPmExe(pp), 0);
        const linkedUser = person.linked_username;
        html += '<tr class="rh-person-hdr" onclick="rhTogglePerson('+pid+')">';
        html += '<td colspan="'+totalCols+'">';
        html += '<span style="margin-right:6px;font-size:10px;color:#6c757d" id="rh-arrow-'+pid+'">▼</span>';
        html += '<span class="rh-ph-name">'+rhEsc(person.full_name)+'</span>';
        html += '<span class="rh-ph-tipo">'+rhEsc(person.tipo_ligacao||'')+'</span>';
        // User link badge
        if (linkedUser) {
            html += '<span class="rh-user-badge" title="Ligado ao utilizador '+rhEsc(linkedUser)+'">👤 '+rhEsc(linkedUser)+'</span>';
        } else {
            html += '<span class="rh-user-badge rh-user-unlinked" title="Sem utilizador associado">👤 ?</span>';
        }
        html += '<span class="rh-ph-meta">'+projects.length+' projeto'+(projects.length!==1?'s':'');
        if (pmExeTotal) html += ' · PM EXE: '+pmExeTotal.toFixed(1)+' meses';
        html += '</span>';
        html += '<span class="rh-ph-meta" style="margin-left:6px;color:#6c757d;font-size:10px">('+rhEsc(person.rh_code)+')</span>';
        if (RH_IS_ADMIN) {
            html += '<button class="btn btn-xs btn-outline-secondary ms-2" style="font-size:10px;padding:0 6px" '
                  + 'onclick="event.stopPropagation();rhOpenLinkUser('+pid+','+(person.user_token_id||'null')+',\''+rhEsc(person.full_name)+'\')" '
                  + 'title="Ligar ao utilizador pikachuPM">🔗 ligar</button>';
            html += '<button class="btn btn-xs btn-outline-primary ms-1" style="font-size:10px;padding:0 6px" onclick="event.stopPropagation();rhOpenLinkProject(null,'+pid+',\''+type+'\',null,\'\',\'\')">+ projeto</button>';
            html += '<button class="btn btn-xs btn-outline-danger ms-1" style="font-size:10px;padding:0 6px" onclick="event.stopPropagation();rhDeletePerson('+pid+',\''+type+'\')">🗑</button>';
        }
        html += '</td></tr>';

        // Project rows
        projects.forEach(pp => {
            const ppid = pp.id;
            html += '<tr class="rh-proj-row" data-pid="'+pid+'" data-ppid="'+ppid+'">';

            // Project label
            const hasLink = pp.project_id != null;
            html += '<td class="rh-sticky rh-col-proj rh-proj-label'+(hasLink?' rh-proj-linked':'')+'">';
            html += '<div class="rh-proj-code">'+rhEsc(pp.project_code);
            if (hasLink) html += ' <span class="rh-link-dot" title="Ligado: '+rhEsc(pp.linked_short_name||pp.project_name)+'">●</span>';
            html += '</div>';
            html += '<div class="rh-proj-name">'+rhEsc(pp.project_name)+'</div>';
            if (hasLink && pp.linked_title) {
                html += '<div style="font-size:10px;color:#198754;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px" title="'+rhEsc(pp.linked_title)+'">'+rhEsc(pp.linked_title)+'</div>';
            }
            if (RH_IS_ADMIN) {
                const escapedCode = rhEsc(pp.project_code||'');
                const escapedName = rhEsc(pp.project_name||'');
                html += '<div class="rh-row-actions">'
                      + '<button class="rh-btn-link" onclick="event.stopPropagation();rhOpenLinkProject('+ppid+',null,\''+type+'\','+(pp.project_id||'null')+',\''+escapedCode+'\',\''+escapedName+'\')" title="Associar a projeto pikachuPM">🔗</button>'
                      + '<button class="rh-btn-del" onclick="event.stopPropagation();rhDeletePP('+ppid+',\''+type+'\')" title="Remover projeto">✕</button>'
                      + '</div>';
            }
            html += '</td>';

            // Tipo
            html += '<td class="rh-sticky rh-col-tipo" style="font-size:11px;color:#6c757d;padding-left:6px">'+rhEsc(person.tipo_ligacao||'')+'</td>';

            // PM ORC
            const pmOrcVal = pp.pm_orc != null ? parseFloat(pp.pm_orc) : null;
            html += '<td class="rh-sticky rh-col-pmorc rh-pm-cell'+(RH_IS_ADMIN?' rh-editable':'')+'" '
                  + 'data-ppid="'+ppid+'" data-field="pm_orc">'
                  + (pmOrcVal != null ? pmOrcVal : '<span style="color:#ced4da">—</span>')+'</td>';

            // PM EXE — auto-computed from monthly allocations (Σ% / 100)
            const pmExeComputed = ppComputedPmExe(pp);
            const pmExeDisplay  = pmExeComputed > 0 ? (Math.round(pmExeComputed * 100) / 100) : null;
            html += '<td class="rh-sticky rh-col-pmexe rh-sticky-border rh-pm-cell rh-pm-auto" '
                  + 'data-ppid="'+ppid+'" title="Calculado: Σ imputações / 100">'
                  + (pmExeDisplay != null ? pmExeDisplay : '<span style="color:#ced4da">—</span>')+'</td>';

            // Monthly cells — lock months outside linked project's date range
            const projStart = pp.data_inicio ? pp.data_inicio.substring(0,7) : null;
            const projEnd   = pp.data_fim    ? pp.data_fim.substring(0,7)    : null;
            months.forEach(ym => {
                const pct = pp.allocations ? pp.allocations[ym] : undefined;
                html += rhCellHtml(ppid, ym, pct, projStart, projEnd);
            });

            html += '</tr>';
        });

        // SUM row
        html += '<tr class="rh-sum-row" data-pid="'+pid+'">';
        html += '<td class="rh-sticky rh-col-proj" style="padding-left:8px;color:#495057;font-size:11px">Σ total</td>';
        html += '<td class="rh-sticky rh-col-tipo"></td>';
        html += '<td class="rh-sticky rh-col-pmorc"></td>';

        const pmExeTotalFmt = pmExeTotal > 0 ? pmExeTotal.toFixed(1) : '';
        html += '<td class="rh-sticky rh-col-pmexe rh-sticky-border rh-sum-pm" data-pid="'+pid+'" style="text-align:center;font-size:11px;color:#495057">'+pmExeTotalFmt+'</td>';

        months.forEach(ym => {
            const [y, m] = ym.split('-').map(Number);
            const sum = projects.reduce((s, pp) => {
                const v = pp.allocations && pp.allocations[ym];
                return s + (v != null ? parseFloat(v) : 0);
            }, 0);
            html += rhSumCellHtml(pid, ym, sum);
        });
        html += '</tr>';
    });

    html += '</tbody></table>';

    // Legend
    html += '<div class="rh-legend">'
          + '<span class="rh-legend-item"><span class="rh-legend-swatch" style="background:#d1e7dd"></span>100%</span>'
          + '<span class="rh-legend-item"><span class="rh-legend-swatch" style="background:#fff9e6"></span>1–99%</span>'
          + '<span class="rh-legend-item"><span class="rh-legend-swatch" style="background:#f8d7da"></span>&gt;100%</span>'
          + '<span class="rh-legend-item"><span class="rh-legend-swatch" style="background:#fff3cd"></span>soma &lt;100%</span>'
          + '</div>';

    if (RH_IS_ADMIN) {
        html += '<div class="rh-add-bar">'
              + '<button class="btn btn-sm btn-outline-success" onclick="rhAddPersonRow(\''+data.id+'\',\''+type+'\')">+ Adicionar pessoa</button>'
              + '</div>';
    }

    container.innerHTML = html;
}

function rhCellHtml(ppid, ym, pct, projStart, projEnd) {
    const [y, m] = ym.split('-');
    const locked = (projStart && ym < projStart) || (projEnd && ym > projEnd);
    if (locked) {
        const reason = projStart && ym < projStart
            ? 'Antes do início do projeto ('+projStart+')'
            : 'Após o fim do projeto ('+projEnd+')';
        return '<td class="rh-cell rh-cell-locked" data-ppid="'+ppid+'" data-y="'+y+'" data-m="'+m+'" title="'+reason+'"></td>';
    }
    const editable = RH_IS_ADMIN ? ' rh-editable' : '';
    let cls = 'rh-cell'+editable;
    let label = '';
    if (pct != null && pct !== '') {
        const n = parseFloat(pct);
        label = n === 0 ? '<span class="rh-cell-empty">0</span>' : String(n);
        if (n === 0) cls += ' rh-cell-empty';
        else if (n < 100) cls += ' rh-cell-low';
        else if (n === 100) cls += ' rh-cell-full';
        else cls += ' rh-cell-over';
    }
    return '<td class="'+cls+'" data-ppid="'+ppid+'" data-y="'+y+'" data-m="'+m+'">'+label+'</td>';
}

function rhSumCellHtml(pid, ym, sum) {
    let cls = 'rh-cell';
    let label = sum > 0 ? Math.round(sum*100)/100 : '';
    if (sum === 0) cls += ' rh-sum-empty';
    else if (sum < 100) cls += ' rh-sum-partial';
    else if (sum === 100) cls += ' rh-sum-ok';
    else cls += ' rh-sum-over';
    return '<td class="'+cls+'" data-sum-pid="'+pid+'" data-sum-ym="'+ym+'">'+label+'</td>';
}

// ── Toggle person group ───────────────────────────────────────────────────────
function rhTogglePerson(pid) {
    const rows = document.querySelectorAll('[data-pid="'+pid+'"]');
    const arrow = document.getElementById('rh-arrow-'+pid);
    const collapsed = arrow && arrow.textContent === '▶';
    rows.forEach(r => {
        if (collapsed) r.classList.remove('rh-person-hidden');
        else r.classList.add('rh-person-hidden');
    });
    if (arrow) arrow.textContent = collapsed ? '▼' : '▶';
}

// ── Inline cell editing ───────────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    const cell = e.target.closest('.rh-cell.rh-editable');
    if (cell) { rhOpenCellEdit(cell); return; }
    const pm = e.target.closest('.rh-pm-cell.rh-editable');
    if (pm) { rhOpenPmEdit(pm); return; }
    // click outside — commit
    if (!e.target.closest('#rh-edit-input')) rhCommitEdit();
});

function rhOpenCellEdit(cell) {
    rhCommitEdit();
    const rect = cell.getBoundingClientRect();
    const inp  = document.getElementById('rh-edit-input');
    const ppid = parseInt(cell.dataset.ppid);
    const year = parseInt(cell.dataset.y);
    const month= parseInt(cell.dataset.m);
    const cur  = cell.textContent.trim().replace(/[^0-9.]/g,'') || '';

    rhEditTarget = { el: cell, ppid, year, month, field: null, orig: cur };
    inp.value = cur;
    inp.style.left = (rect.left + window.scrollX) + 'px';
    inp.style.top  = (rect.top  + window.scrollY) + 'px';
    inp.style.width= rect.width + 'px';
    inp.style.height= rect.height + 'px';
    inp.style.display = 'block';
    inp.focus(); inp.select();
}

function rhOpenPmEdit(cell) {
    rhCommitEdit();
    const rect = cell.getBoundingClientRect();
    const inp  = document.getElementById('rh-edit-input');
    const ppid = parseInt(cell.dataset.ppid);
    const field= cell.dataset.field;
    const cur  = cell.textContent.trim().replace(/[^0-9.]/g,'') || '';

    rhEditTarget = { el: cell, ppid, year: null, month: null, field, orig: cur };
    inp.value = cur;
    inp.style.left = (rect.left + window.scrollX) + 'px';
    inp.style.top  = (rect.top  + window.scrollY) + 'px';
    inp.style.width= rect.width + 'px';
    inp.style.height= rect.height + 'px';
    inp.style.display = 'block';
    inp.focus(); inp.select();
}

function rhInputKey(e) {
    if (e.key === 'Enter')  { rhCommitEdit(); }
    if (e.key === 'Escape') { rhCancelEdit(); }
    if (e.key === 'Tab') {
        e.preventDefault();
        const t = rhEditTarget;
        rhCommitEdit();
        if (t && t.el) {
            const next = t.el.nextElementSibling;
            if (next && next.classList.contains('rh-editable')) rhOpenCellEdit(next);
        }
    }
}

function rhInputBlur() {
    // Small delay so click events fire first
    setTimeout(rhCommitEdit, 100);
}

function rhCancelEdit() {
    document.getElementById('rh-edit-input').style.display = 'none';
    rhEditTarget = null;
}

function rhCommitEdit() {
    if (!rhEditTarget) return;
    const inp = document.getElementById('rh-edit-input');
    const val = inp.value.trim();
    inp.style.display = 'none';
    const t = rhEditTarget;
    rhEditTarget = null;

    if (val === t.orig) return; // No change

    if (t.field) {
        // PM field
        const numVal = val === '' ? null : parseFloat(val);
        t.el.textContent = numVal != null ? numVal : '';
        fetch('?tab=rh_imputacao&action=save_pm', {
            method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ pp_id: t.ppid, field: t.field, value: val === '' ? null : parseFloat(val) })
        }).catch(console.error);
    } else {
        // Allocation cell
        const numVal = val === '' ? null : parseFloat(val);
        const ym = t.year + '-' + String(t.month).padStart(2,'0');

        // Update cell visuals
        rhUpdateCellVisual(t.el, numVal);

        // Update monthly sum row and PM EXE
        const pid = t.el.closest('tr').dataset.pid;
        if (pid) rhRecomputeSum(parseInt(pid), ym);
        rhRecomputePmExe(t.ppid);

        // Update in-memory data
        if (rhCampaignData[rhCurrentType]) {
            for (const person of rhCampaignData[rhCurrentType].persons) {
                for (const pp of person.projects) {
                    if (pp.id === t.ppid) {
                        if (numVal === null) delete pp.allocations[ym];
                        else pp.allocations[ym] = numVal;
                        break;
                    }
                }
            }
        }

        fetch('?tab=rh_imputacao&action=save_alloc', {
            method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ pp_id: t.ppid, year: t.year, month: t.month, pct: numVal })
        }).catch(console.error);
    }
}

function rhUpdateCellVisual(cell, pct) {
    cell.className = cell.className.replace(/rh-cell-\w+/g,'').trim();
    if (pct === null || pct === undefined) {
        cell.innerHTML = '';
    } else {
        const n = parseFloat(pct);
        if (n === 0) { cell.innerHTML = '<span class="rh-cell-empty">0</span>'; cell.classList.add('rh-cell-empty'); }
        else if (n < 100)  { cell.textContent = n; cell.classList.add('rh-cell-low'); }
        else if (n === 100){ cell.textContent = n; cell.classList.add('rh-cell-full'); }
        else               { cell.textContent = n; cell.classList.add('rh-cell-over'); }
    }
}

function rhRecomputePmExe(ppid) {
    // Sum all non-locked monthly cells for this project row
    const cells = document.querySelectorAll('tr.rh-proj-row[data-ppid="'+ppid+'"] .rh-cell[data-ppid="'+ppid+'"]:not(.rh-cell-locked)');
    let total = 0;
    cells.forEach(c => { const v = parseFloat(c.textContent); if (!isNaN(v)) total += v; });
    const pmExe = Math.round(total * 100) / 10000; // total/100, 2 dp

    // Update PM EXE cell
    const pmExeCell = document.querySelector('.rh-col-pmexe[data-ppid="'+ppid+'"]');
    if (pmExeCell) pmExeCell.textContent = pmExe > 0 ? pmExe : '';

    // Update person SUM row PM EXE
    const tr = document.querySelector('tr.rh-proj-row[data-ppid="'+ppid+'"]');
    if (tr) rhRecomputeSumPmExe(parseInt(tr.dataset.pid));

    // Persist to DB silently
    rhAjax('save_pm', { pp_id: ppid, field: 'pm_exe', value: pmExe > 0 ? pmExe : null });
}

function rhRecomputeSumPmExe(pid) {
    let total = 0;
    document.querySelectorAll('tr.rh-proj-row[data-pid="'+pid+'"] .rh-col-pmexe').forEach(cell => {
        total += parseFloat(cell.textContent) || 0;
    });
    const sumCell = document.querySelector('tr.rh-sum-row[data-pid="'+pid+'"] .rh-sum-pm');
    if (sumCell) sumCell.textContent = total > 0 ? (Math.round(total*100)/100) : '';
}

function rhRecomputeSum(pid, ym) {
    // Collect all project cells for this person+month
    const cells = document.querySelectorAll('.rh-proj-row[data-pid="'+pid+'"] .rh-cell[data-y="'+ym.split('-')[0]+'"][data-m="'+ym.split('-')[1]+'"]');
    let sum = 0;
    cells.forEach(c => { const v = parseFloat(c.textContent); if (!isNaN(v)) sum += v; });
    const sumCell = document.querySelector('.rh-sum-row[data-pid="'+pid+'"] [data-sum-ym="'+ym+'"]');
    if (sumCell) {
        sumCell.className = sumCell.className.replace(/rh-sum-\w+|rh-cell-\w+/g,'').trim() + ' rh-cell';
        if (sum === 0)        { sumCell.textContent=''; sumCell.classList.add('rh-sum-empty'); }
        else if (sum < 100)   { sumCell.textContent=Math.round(sum*100)/100; sumCell.classList.add('rh-sum-partial'); }
        else if (sum === 100) { sumCell.textContent=100; sumCell.classList.add('rh-sum-ok'); }
        else                  { sumCell.textContent=Math.round(sum*100)/100; sumCell.classList.add('rh-sum-over'); }
    }
}

// ── Admin: add / delete rows ──────────────────────────────────────────────────
function rhAddPersonRow(campaignId, type) {
    document.getElementById('rh-ap-campaign-id').value = campaignId;
    document.getElementById('rh-ap-name').value  = '';
    document.getElementById('rh-ap-code').value  = '';
    document.getElementById('rh-ap-tipo').value  = '';
    rhUpdateApUserSuggestions('');
    new bootstrap.Modal(document.getElementById('rh-add-person-modal')).show();
    setTimeout(() => document.getElementById('rh-ap-name').focus(), 300);
}

function rhUpdateApUserSuggestions(nameVal) {
    const sel = document.getElementById('rh-ap-user-sel');
    const current = sel.value;
    const normWords = rhNormStr(nameVal).split(/\s+/).filter(w => w.length > 1);
    const scored = RH_USERS.map(u => {
        const uWords = rhNormStr(u.username).split(/[\s._-]+/).filter(w => w.length > 1);
        const score = normWords.filter(w => uWords.some(uw => uw.includes(w) || w.includes(uw))).length;
        return { ...u, score };
    }).sort((a, b) => b.score - a.score);
    sel.innerHTML = '<option value="">— Sem utilizador associado —</option>'
        + scored.map(u => '<option value="'+u.id+'"'+(u.id==current?' selected':'')+'>'+
            (u.score >= 2 ? '★ ' : '')+rhEsc(u.username)+'</option>').join('');
    // Auto-select top suggestion if score >= 2
    if (!current && scored[0] && scored[0].score >= 2) sel.value = scored[0].id;
}

async function rhSaveAddPerson() {
    const cid  = parseInt(document.getElementById('rh-ap-campaign-id').value);
    const name = document.getElementById('rh-ap-name').value.trim();
    const code = document.getElementById('rh-ap-code').value.trim();
    const tipo = document.getElementById('rh-ap-tipo').value.trim();
    const utid = document.getElementById('rh-ap-user-sel').value;
    if (!name) { document.getElementById('rh-ap-name').classList.add('is-invalid'); document.getElementById('rh-ap-name').focus(); return; }
    document.getElementById('rh-ap-name').classList.remove('is-invalid');
    await rhAjax('add_person', { campaign_id: cid, full_name: name, rh_code: code, tipo_ligacao: tipo,
        user_token_id: utid ? parseInt(utid) : null });
    bootstrap.Modal.getInstance(document.getElementById('rh-add-person-modal')).hide();
    rhSelectPlan(rhCurrentPlan);
}

async function rhAdjustMonths(dir) {
    if (!rhCurrentPlan) return;
    if (dir === '-' && !confirm('Remover o último ano?\nSó é possível se não houver imputações nesses meses.')) return;
    const res = await rhAjax('adjust_months', { plan_name: rhCurrentPlan, direction: dir });
    if (res && res.error) { alert(res.error); return; }
    rhSelectPlan(rhCurrentPlan);
}

function rhOpenLinkProject(ppid, personId, type, currentProjId, projCode, projName) {
    document.getElementById('rh-lp-ppid').value      = ppid || '';
    document.getElementById('rh-lp-person-id').value = personId || '';
    document.getElementById('rh-lp-type').value      = type || '';
    document.getElementById('rh-lp-selected-proj-id').value = currentProjId || '';
    document.getElementById('rh-lp-code').value = projCode || '';
    document.getElementById('rh-lp-name').value = projName || '';
    document.getElementById('rh-lp-search').value = '';
    rhFilterProjList('', currentProjId);
    new bootstrap.Modal(document.getElementById('rh-link-proj-modal')).show();
}

function rhFilterProjList(q, selectedId) {
    selectedId = selectedId !== undefined ? selectedId : parseInt(document.getElementById('rh-lp-selected-proj-id').value) || null;
    const list = document.getElementById('rh-lp-list');
    const term = (q || '').toLowerCase().trim();
    const filtered = term
        ? RH_PROJECTS.filter(p => (p.short_name+' '+p.title).toLowerCase().includes(term))
        : RH_PROJECTS;
    if (!filtered.length) { list.innerHTML = '<div style="padding:8px;color:#6c757d;font-size:12px">Sem resultados</div>'; return; }
    list.innerHTML = filtered.map(p => {
        const sel = p.id === selectedId;
        return '<div class="rh-proj-pick'+(sel?' rh-proj-pick-sel':'')+'" data-pid="'+p.id+'" data-code="'+rhEsc(p.short_name)+'" data-name="'+rhEsc(p.title)+'" onclick="rhPickProj(this)">'
             + '<strong style="font-size:12px">'+rhEsc(p.short_name)+'</strong> '
             + '<span style="font-size:11px;color:#6c757d">'+rhEsc(p.title)+'</span>'
             + '</div>';
    }).join('');
}

function rhPickProj(el) {
    document.querySelectorAll('#rh-lp-list .rh-proj-pick').forEach(d => d.classList.remove('rh-proj-pick-sel'));
    el.classList.add('rh-proj-pick-sel');
    document.getElementById('rh-lp-selected-proj-id').value = el.dataset.pid;
    document.getElementById('rh-lp-code').value = el.dataset.code;
    if (!document.getElementById('rh-lp-name').value) document.getElementById('rh-lp-name').value = el.dataset.name;
}

function rhClearProjLink() {
    document.getElementById('rh-lp-selected-proj-id').value = '';
    document.querySelectorAll('#rh-lp-list .rh-proj-pick').forEach(d => d.classList.remove('rh-proj-pick-sel'));
}

async function rhSaveLinkProject() {
    const ppid     = document.getElementById('rh-lp-ppid').value;
    const personId = document.getElementById('rh-lp-person-id').value;
    const type     = document.getElementById('rh-lp-type').value;
    const projId   = document.getElementById('rh-lp-selected-proj-id').value;
    const code     = document.getElementById('rh-lp-code').value.trim();
    const name     = document.getElementById('rh-lp-name').value.trim();
    const payload  = { project_id: projId ? parseInt(projId) : null, project_code: code, project_name: name || code };
    if (ppid) {
        // Link mode: update existing project row (propagates to same project_name)
        const res = await rhAjax('link_project', { pp_id: parseInt(ppid), ...payload });
        bootstrap.Modal.getInstance(document.getElementById('rh-link-proj-modal')).hide();
        if (res && res.propagated > 0)
            rhSetStatus('Projeto ligado + ' + res.propagated + ' linha' + (res.propagated !== 1 ? 's' : '') + ' com o mesmo nome atualizadas automaticamente');
    } else {
        // Add mode: insert new project row
        await rhAjax('add_person_project', { person_id: parseInt(personId), ...payload });
        bootstrap.Modal.getInstance(document.getElementById('rh-link-proj-modal')).hide();
    }
    rhSelectPlan(rhCurrentPlan);
}

function rhDeletePP(ppid, type) {
    if (!confirm('Remover este projeto e todas as alocações mensais?')) return;
    rhAjax('delete_person_project', { pp_id: ppid })
        .then(() => rhSelectPlan(rhCurrentPlan));
}

function rhDeletePerson(pid, type) {
    if (!confirm('Remover esta pessoa e todos os seus projetos/alocações?')) return;
    rhAjax('delete_person', { person_id: pid })
        .then(() => rhSelectPlan(rhCurrentPlan));
}

function rhDeletePlan() {
    if (!rhCurrentPlan) return;
    if (!confirm('Eliminar o plano "'+rhCurrentPlan+'" e todos os dados associados?')) return;
    rhAjax('delete_plan', { plan_name: rhCurrentPlan }).then(() => location.reload());
}

// ── Import XLSX ───────────────────────────────────────────────────────────────
function rhImportFile(input) {
    const file = input.files[0];
    if (!file) return;
    input.value = '';

    const planName = prompt('Nome do plano para esta importação:', file.name.replace(/\.xlsx$/i,'').trim());
    if (!planName) return;

    rhSetStatus('A ler ficheiro…');
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const wb = XLSX.read(e.target.result, { type: 'array', cellDates: true });
            const sheetTypeMap = {
                'Imputacao_RH_contratados': 'contratados',
                'Imputacao_RH_bolseiros':   'bolseiros'
            };
            const promises = [];
            wb.SheetNames.forEach(sname => {
                const type = sheetTypeMap[sname];
                if (!type) return;
                const ws = wb.Sheets[sname];
                const rows = XLSX.utils.sheet_to_json(ws, { header:1, defval:null, raw:true });
                const { months, persons } = rhParseSheet(rows);
                rhSetStatus('A importar '+type+' ('+persons.length+' pessoas)…');
                promises.push(
                    rhAjax('import_rh', { plan_name: planName, type, months, persons })
                );
            });
            Promise.all(promises).then(() => {
                rhSetStatus('Importação concluída!');
                location.reload();
            }).catch(err => { rhSetStatus('Erro: '+err.message); });
        } catch(ex) {
            rhSetStatus('Erro ao ler XLSX: '+ex.message);
        }
    };
    reader.readAsArrayBuffer(file);
}

function rhParseSheet(rows) {
    if (!rows.length) return { months:[], persons:[] };

    // Parse header row → month keys
    const header = rows[0];
    const months = [];
    for (let i = 5; i < header.length; i++) {
        const h = header[i] ? String(header[i]).trim().toLowerCase() : '';
        const match = h.match(/^([a-z]+)\/(\d{2})$/);
        if (match && PT_MONTHS_ABBR[match[1]]) {
            months.push((2000 + parseInt(match[2])) + '-' + PT_MONTHS_ABBR[match[1]]);
        }
    }

    const persons = [];
    let curPerson = null;
    let curName   = null;

    for (let ri = 1; ri < rows.length; ri++) {
        const row = rows[ri];
        const colA = (row[0] ?? '').toString().trim();
        const colB = (row[1] ?? '').toString().trim();
        const colC = (row[2] ?? '').toString().trim();
        const colD = (row[3] ?? '').toString().trim();

        if (!colA) continue;
        // Skip SUM rows
        if (colC === 'SUM' || colD === 'SUM') continue;

        // Parse person
        let rh_code, full_name;
        if (colA.includes('|')) {
            const sep = colA.split(/\s*\|\s*/);
            rh_code   = sep[0].trim();
            full_name = sep.slice(1).join('|').trim();
        } else { rh_code = full_name = colA; }

        if (full_name !== curName) {
            curName = full_name;
            curPerson = { rh_code, full_name, tipo_ligacao: colB || null, projects: [] };
            persons.push(curPerson);
        } else if (colB && !curPerson.tipo_ligacao) {
            curPerson.tipo_ligacao = colB;
        }

        // Parse project
        let proj_code, proj_name;
        if (colC && colC.includes('|')) {
            const parts = colC.split(/\s*\|\s*/);
            proj_code = parts[0].trim(); proj_name = parts.slice(1).join('|').trim();
        } else { proj_code = proj_name = colC || ''; }

        // PM values
        const pm_orc = (row[3] !== null && row[3] !== undefined && String(row[3]).trim() !== 'SUM' && String(row[3]).trim() !== '')
                        ? parseFloat(row[3]) || null : null;
        const pm_exe = (row[4] !== null && row[4] !== undefined && String(row[4]).trim() !== '')
                        ? parseFloat(row[4]) || null : null;

        // Allocations
        const allocations = {};
        months.forEach((ym, i) => {
            const v = row[5 + i];
            if (v !== null && v !== undefined && v !== '') {
                const n = parseFloat(v);
                if (!isNaN(n)) allocations[ym] = n;
            }
        });

        curPerson.projects.push({ code: proj_code, name: proj_name, pm_orc, pm_exe, allocations });
    }

    return { months, persons };
}

// ── Export XLSX ───────────────────────────────────────────────────────────────
async function rhExportXlsx() {
    if (!rhCurrentPlan) return;
    rhSetStatus('A gerar XLSX…');

    const planCamps = RH_PLAN_MAP[rhCurrentPlan] || {};
    const wb = XLSX.utils.book_new();

    const PT_ABBRs = { '01':'jan','02':'fev','03':'mar','04':'abr','05':'mai','06':'jun',
                       '07':'jul','08':'ago','09':'set','10':'out','11':'nov','12':'dez' };

    // Style palette
    const base = (extra) => Object.assign({ font:{sz:10}, alignment:{vertical:'center'} }, extra);
    const XS = {
        hdr:    base({ fill:{fgColor:{rgb:'2D3338'}}, font:{bold:true,color:{rgb:'FFFFFF'},sz:10}, alignment:{horizontal:'center',vertical:'center'} }),
        hdrL:   base({ fill:{fgColor:{rgb:'2D3338'}}, font:{bold:true,color:{rgb:'FFFFFF'},sz:10}, alignment:{horizontal:'left',vertical:'center'} }),
        person: base({ fill:{fgColor:{rgb:'DBEAFE'}}, font:{bold:true,sz:10}, alignment:{horizontal:'left',vertical:'center'} }),
        sum:    base({ fill:{fgColor:{rgb:'E9ECEF'}}, font:{italic:true,sz:10}, alignment:{horizontal:'left',vertical:'center'} }),
        sumNum: base({ fill:{fgColor:{rgb:'E9ECEF'}}, font:{bold:true,sz:10}, alignment:{horizontal:'center',vertical:'center'} }),
        proj:   base({ alignment:{horizontal:'left',vertical:'center'} }),
        num:    base({ alignment:{horizontal:'center',vertical:'center'} }),
        empty:  base({ font:{color:{rgb:'CCCCCC'},sz:10}, alignment:{horizontal:'center',vertical:'center'} }),
        low:    base({ fill:{fgColor:{rgb:'FFF9C4'}}, alignment:{horizontal:'center',vertical:'center'} }),
        full:   base({ fill:{fgColor:{rgb:'D4EDDA'}}, font:{bold:true,color:{rgb:'0F5132'},sz:10}, alignment:{horizontal:'center',vertical:'center'} }),
        over:   base({ fill:{fgColor:{rgb:'F8D7DA'}}, font:{bold:true,color:{rgb:'721C24'},sz:10}, alignment:{horizontal:'center',vertical:'center'} }),
        locked: base({ fill:{fgColor:{rgb:'EEEEEE'},patternType:'solid'}, font:{color:{rgb:'CCCCCC'},sz:10}, alignment:{horizontal:'center',vertical:'center'} }),
    };
    function cellStyle(v, locked) {
        if (locked) return XS.locked;
        if (v == null) return XS.empty;
        const n = parseFloat(v);
        if (isNaN(n) || n === 0) return XS.empty;
        if (n < 100) return XS.low;
        if (n === 100) return XS.full;
        return XS.over;
    }

    const sheetDefs = [
        { type:'contratados', sheetName:'Imputacao_RH_contratados' },
        { type:'bolseiros',   sheetName:'Imputacao_RH_bolseiros'   }
    ];

    for (const sd of sheetDefs) {
        const cid = planCamps[sd.type];
        if (!cid) continue;
        let data = rhCampaignData[sd.type];
        if (!data) {
            const r = await fetch('?tab=rh_imputacao&action=get_campaign_data&campaign_id='+cid, {
                headers:{'X-Requested-With':'XMLHttpRequest'}
            });
            data = await r.json();
        }
        if (!data || !data.persons) continue;

        const months = data.months;
        const aoa = [];
        const styleMap = []; // styleMap[row][col] = style object

        // Header
        const hdr = ['iD RH | Nome RH','Utilizador PK','Tipo Ligação','Código | Nome Projeto RH','Projeto PK','PM ORC','PM EXE'];
        months.forEach(ym => { const [y,m] = ym.split('-'); hdr.push(PT_ABBRs[m]+'/'+y.slice(2)); });
        aoa.push(hdr);
        styleMap.push(hdr.map((_,ci) => ci < 5 ? XS.hdrL : XS.hdr));

        data.persons.forEach(person => {
            const rhLabel = (person.rh_code ? person.rh_code+'  |  ' : '')+person.full_name;
            const pkLabel = person.linked_username || '';
            let sumPmExe = 0;
            const sumAllocs = {};
            months.forEach(ym => sumAllocs[ym] = 0);

            person.projects.forEach(pp => {
                const rhProj = (pp.project_code ? pp.project_code+'  |  ' : '')+pp.project_name;
                const pkProj = pp.linked_short_name ? (pp.linked_short_name+(pp.linked_title?' | '+pp.linked_title:'')) : '';
                const ps = pp.data_inicio ? pp.data_inicio.substring(0,7) : null;
                const pe = pp.data_fim    ? pp.data_fim.substring(0,7)    : null;

                const row = [rhLabel, pkLabel, person.tipo_ligacao||'', rhProj, pkProj, pp.pm_orc!=null?parseFloat(pp.pm_orc):null, pp.pm_exe!=null?parseFloat(pp.pm_exe):null];
                const styles = [XS.person, XS.person, XS.person, XS.proj, XS.proj, XS.num, XS.num];

                months.forEach(ym => {
                    const locked = (ps && ym < ps) || (pe && ym > pe);
                    const v = pp.allocations ? (pp.allocations[ym] ?? null) : null;
                    row.push(locked ? null : (v != null ? parseFloat(v) : null));
                    if (!locked) sumAllocs[ym] += parseFloat(v||0);
                    styles.push(cellStyle(v, locked));
                });

                aoa.push(row);
                styleMap.push(styles);
                sumPmExe += parseFloat(pp.pm_exe||0);
            });

            // SUM row
            const sumRow = [rhLabel, pkLabel, null, 'SUM', null, null, sumPmExe>0?sumPmExe:null];
            const sumStyles = [XS.sum,XS.sum,XS.sum,XS.sum,XS.sum,XS.sum,XS.sumNum];
            months.forEach(ym => {
                const s = sumAllocs[ym];
                sumRow.push(s > 0 ? s : null);
                sumStyles.push(s > 0 ? XS.sumNum : XS.sum);
            });
            aoa.push(sumRow);
            styleMap.push(sumStyles);
        });

        const ws = XLSX.utils.aoa_to_sheet(aoa);

        // Apply styles
        const rng = XLSX.utils.decode_range(ws['!ref']);
        for (let r = rng.s.r; r <= rng.e.r; r++) {
            for (let c = rng.s.c; c <= rng.e.c; c++) {
                const addr = XLSX.utils.encode_cell({r, c});
                if (!ws[addr]) ws[addr] = { t:'z' };
                if (styleMap[r] && styleMap[r][c]) ws[addr].s = styleMap[r][c];
            }
        }

        // Column widths + freeze header row
        const wscols = [{wch:32},{wch:18},{wch:20},{wch:30},{wch:25},{wch:10},{wch:10}];
        months.forEach(() => wscols.push({wch:7}));
        ws['!cols'] = wscols;
        ws['!freeze'] = { xSplit: 0, ySplit: 1 };

        XLSX.utils.book_append_sheet(wb, ws, sd.sheetName);
    }

    // ── Resumo PK sheet ───────────────────────────────────────────────────────
    rhSetStatus('A adicionar resumo PK…');
    let pkData = rhResumoPkData;
    if (!pkData) {
        const r = await fetch('?tab=rh_imputacao&action=get_resumo_pk', { headers:{'X-Requested-With':'XMLHttpRequest'} });
        pkData = await r.json();
        rhResumoPkData = pkData; rhResumoLoaded = true;
    }
    if (pkData && pkData.months && pkData.months.length) {
        const months = pkData.months;
        const aoa = [];
        const styleMap = [];
        const hdr = ['Utilizador PK'];
        months.forEach(ym => { const [y,m] = ym.split('-'); hdr.push(PT_ABBRs[m]+'/'+y.slice(2)); });
        aoa.push(hdr);
        styleMap.push(hdr.map((_,ci) => ci===0 ? XS.hdrL : XS.hdr));

        pkData.users.forEach(u => {
            const ua = pkData.allocs[u.id] || {};
            const row = [u.username];
            const styles = [XS.proj];
            months.forEach(ym => {
                const v = ua[ym] ?? null;
                row.push(v != null ? parseFloat(v) : null);
                styles.push(cellStyle(v, false));
            });
            aoa.push(row);
            styleMap.push(styles);
        });

        const ws2 = XLSX.utils.aoa_to_sheet(aoa);
        const rng2 = XLSX.utils.decode_range(ws2['!ref']);
        for (let r = rng2.s.r; r <= rng2.e.r; r++)
            for (let c = rng2.s.c; c <= rng2.e.c; c++) {
                const addr = XLSX.utils.encode_cell({r,c});
                if (!ws2[addr]) ws2[addr] = {t:'z'};
                if (styleMap[r] && styleMap[r][c]) ws2[addr].s = styleMap[r][c];
            }
        const wscols2 = [{wch:22}];
        months.forEach(() => wscols2.push({wch:7}));
        ws2['!cols'] = wscols2;
        ws2['!freeze'] = {xSplit:0, ySplit:1};
        XLSX.utils.book_append_sheet(wb, ws2, 'Resumo_PK');
    }

    // ── Resumo Projetos sheet ─────────────────────────────────────────────────
    rhSetStatus('A adicionar resumo por projeto…');
    let projData = rhResumoProjData;
    if (!projData) {
        const r = await fetch('?tab=rh_imputacao&action=get_resumo_proj', { headers:{'X-Requested-With':'XMLHttpRequest'} });
        projData = await r.json();
        rhResumoProjData = projData; rhResumoProjLoaded = true;
    }
    if (projData && projData.months && projData.months.length && projData.projects.length) {
        const months = projData.months;
        const aoa = [];
        const styleMap = [];

        const hdr = ['Projeto','Pessoa','Utilizador PK'];
        months.forEach(ym => { const [y,m] = ym.split('-'); hdr.push(PT_ABBRs[m]+'/'+y.slice(2)); });
        aoa.push(hdr);
        styleMap.push(hdr.map((_,ci) => ci<3 ? XS.hdrL : XS.hdr));

        projData.projects.forEach(proj => {
            const ps = proj.data_inicio ? proj.data_inicio.substring(0,7) : null;
            const pe = proj.data_fim    ? proj.data_fim.substring(0,7)    : null;

            // Project total row
            const projLabel = proj.short_name + ' — ' + proj.title;
            const projTotals = {};
            months.forEach(ym => projTotals[ym] = proj.persons.reduce((s,p)=>s+(p.allocs[ym]||0),0));
            const projRow = [projLabel, '', ''];
            const projStyles = [XS.person, XS.person, XS.person];
            months.forEach(ym => {
                const v = projTotals[ym];
                projRow.push(v > 0 ? v : null);
                projStyles.push(v > 0 ? XS.full : XS.empty);
            });
            aoa.push(projRow);
            styleMap.push(projStyles);

            // Person rows
            proj.persons.forEach(person => {
                const row = ['', (person.rh_code?person.rh_code+' | ':'')+person.full_name, person.linked_username||''];
                const styles = [XS.proj, XS.proj, XS.proj];
                months.forEach(ym => {
                    const locked = (ps && ym < ps) || (pe && ym > pe);
                    const v = person.allocs[ym] ?? null;
                    row.push(locked ? null : (v != null ? parseFloat(v) : null));
                    styles.push(cellStyle(v, locked));
                });
                aoa.push(row);
                styleMap.push(styles);
            });
        });

        const ws3 = XLSX.utils.aoa_to_sheet(aoa);
        const rng3 = XLSX.utils.decode_range(ws3['!ref']);
        for (let r = rng3.s.r; r <= rng3.e.r; r++)
            for (let c = rng3.s.c; c <= rng3.e.c; c++) {
                const addr = XLSX.utils.encode_cell({r,c});
                if (!ws3[addr]) ws3[addr] = {t:'z'};
                if (styleMap[r] && styleMap[r][c]) ws3[addr].s = styleMap[r][c];
            }
        const wscols3 = [{wch:35},{wch:30},{wch:18}];
        months.forEach(() => wscols3.push({wch:7}));
        ws3['!cols'] = wscols3;
        ws3['!freeze'] = {xSplit:0, ySplit:1};
        XLSX.utils.book_append_sheet(wb, ws3, 'Resumo_Projetos');
    }

    const filename = (rhCurrentPlan || 'rh_imputacao') + '.xlsx';
    XLSX.writeFile(wb, filename);
    rhSetStatus('Exportado: '+filename);
}

// ── New empty plan ────────────────────────────────────────────────────────────
function rhNewPlanModal() {
    new bootstrap.Modal(document.getElementById('rh-new-plan-modal')).show();
}

async function rhCreateEmptyPlan() {
    const name  = document.getElementById('rh-new-plan-name').value.trim();
    const start = document.getElementById('rh-new-plan-start').value; // "2026-01"
    const end   = document.getElementById('rh-new-plan-end').value;
    if (!name || !start || !end) { alert('Preenche todos os campos'); return; }

    // Generate month array
    const months = [];
    let [sy, sm] = start.split('-').map(Number);
    const [ey, em] = end.split('-').map(Number);
    while (sy < ey || (sy === ey && sm <= em)) {
        months.push(sy + '-' + String(sm).padStart(2,'0'));
        sm++; if (sm > 12) { sm = 1; sy++; }
    }

    const types = [];
    if (document.getElementById('rh-np-cont').checked) types.push('contratados');
    if (document.getElementById('rh-np-bols').checked) types.push('bolseiros');
    if (!types.length) { alert('Seleciona pelo menos um tipo'); return; }

    for (const type of types) {
        await rhAjax('import_rh', { plan_name: name, type, months, persons: [] });
    }
    bootstrap.Modal.getInstance(document.getElementById('rh-new-plan-modal')).hide();
    location.reload();
}

// ── Link person to user ───────────────────────────────────────────────────────
function rhOpenLinkUser(personId, currentUtid, fullName) {
    // Build a simple modal-style prompt using the existing Bootstrap modal
    const modal = document.getElementById('rh-link-user-modal');
    document.getElementById('rh-lu-person-name').textContent = fullName;
    document.getElementById('rh-lu-person-id').value = personId;

    const sel = document.getElementById('rh-lu-user-sel');
    sel.innerHTML = '<option value="">— sem ligação —</option>';

    // Compute suggestions by word overlap
    const normFull = rhNormStr(fullName);
    const scored = RH_USERS.map(u => {
        const normU = rhNormStr(u.username);
        const words = normFull.split(' ').filter(w => w.length > 2);
        let score = words.reduce((s,w) => s + (normU.includes(w) || normFull.includes(normU) ? 1 : 0), 0);
        return { ...u, score };
    }).sort((a,b) => b.score - a.score);

    scored.forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.username + (u.score >= 2 ? ' ★' : '');
        if (u.id === currentUtid) opt.selected = true;
        sel.appendChild(opt);
    });

    if (!currentUtid) sel.selectedIndex = 0;
    new bootstrap.Modal(modal).show();
}

function rhNormStr(s) {
    return s.toLowerCase()
        .replace(/[àáâãä]/g,'a').replace(/[èéêë]/g,'e').replace(/[ìíîï]/g,'i')
        .replace(/[òóôõö]/g,'o').replace(/[ùúûü]/g,'u').replace(/ç/g,'c').replace(/[^a-z ]/g,'');
}

async function rhSaveLinkUser() {
    const pid  = parseInt(document.getElementById('rh-lu-person-id').value);
    const utid = document.getElementById('rh-lu-user-sel').value;
    await rhAjax('link_person', { person_id: pid, user_token_id: utid === '' ? null : parseInt(utid) });
    bootstrap.Modal.getInstance(document.getElementById('rh-link-user-modal')).hide();
    rhSelectPlan(rhCurrentPlan); // Reload
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function rhAjax(action, body) {
    return fetch('?tab=rh_imputacao&action='+action, {
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify(body)
    }).then(r => r.json()).then(d => {
        if (d.error) throw new Error(d.error);
        return d;
    });
}

function rhSetStatus(msg) {
    const el = document.getElementById('rh-status');
    if (el) { el.textContent = msg; setTimeout(() => { if (el.textContent===msg) el.textContent=''; }, 4000); }
}

function rhEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-select first plan if exists
(function() {
    const sel = document.getElementById('rh-plan-sel');
    if (sel && sel.options.length > 1) {
        sel.selectedIndex = 1;
        rhSelectPlan(sel.value);
    }
})();
</script>
