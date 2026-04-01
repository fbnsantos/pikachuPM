<?php
/**
 * export_team_pdf.php
 * Relatório consolidado da equipa para N dias anteriores — optimizado para análise por IA.
 * Abre em nova janela e dispara window.print() automaticamente.
 */

session_start();
if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo 'Acesso não autorizado.';
    exit;
}

include_once __DIR__ . '/config.php';

$days = max(1, min(90, (int)($_GET['days'] ?? 7)));
$date_end   = new DateTime();
$date_start = (new DateTime())->modify("-{$days} days");
$date_end_str   = $date_end->format('Y-m-d');
$date_start_str = $date_start->format('Y-m-d');

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Erro de conexão: ' . htmlspecialchars($e->getMessage()));
}

// ── 1. Daily Reports no período ────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT dr.*, ut.username
    FROM daily_reports dr
    JOIN user_tokens ut ON dr.user_id = ut.user_id
    WHERE dr.report_date BETWEEN ? AND ?
    ORDER BY ut.username ASC, dr.report_date DESC
");
$stmt->execute([$date_start_str, $date_end_str]);
$all_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organizar por pessoa
$by_person = [];
foreach ($all_reports as $r) {
    $by_person[$r['username']][] = $r;
}
ksort($by_person);

// Todos os dias do período (para tabela de participação)
$all_days = [];
$d = clone $date_start;
while ($d <= $date_end) {
    $all_days[] = $d->format('Y-m-d');
    $d->modify('+1 day');
}

// ── 2. Utilizadores e lista de todos os colaboradores ─────────────────────
$all_users_res = $pdo->query("SELECT user_id, username FROM user_tokens ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Estatísticas de tarefas por pessoa ─────────────────────────────────
$task_stats = [];
$check_todos = $pdo->query("SHOW TABLES LIKE 'todos'")->fetch();
if ($check_todos) {
    $stmt = $pdo->prepare("
        SELECT
            ut.username,
            COUNT(*)                                                  AS total,
            SUM(t.estado IN ('aberta'))                               AS abertas,
            SUM(t.estado IN ('em execução'))                          AS em_execucao,
            SUM(t.estado IN ('concluída', 'fechada'))                 AS concluidas,
            SUM(t.estado = 'suspensa')                                AS suspensas,
            SUM(t.data_limite < CURDATE() AND t.estado NOT IN ('concluída','fechada')) AS atrasadas,
            SUM(t.updated_at >= ? AND t.updated_at <= ?)              AS alteradas_periodo
        FROM todos t
        JOIN user_tokens ut ON (t.responsavel = ut.user_id OR (t.responsavel IS NULL AND t.autor = ut.user_id))
        GROUP BY ut.user_id, ut.username
        ORDER BY ut.username
    ");
    $stmt->execute([$date_start_str . ' 00:00:00', $date_end_str . ' 23:59:59']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $task_stats[$row['username']] = $row;
    }
}

// ── 4. Tarefas concluídas no período por pessoa ────────────────────────────
$completed_tasks = [];
if ($check_todos) {
    $stmt = $pdo->prepare("
        SELECT ut.username, t.titulo, t.estado, t.data_limite, t.updated_at
        FROM todos t
        JOIN user_tokens ut ON (t.responsavel = ut.user_id OR (t.responsavel IS NULL AND t.autor = ut.user_id))
        WHERE t.estado IN ('concluída','fechada')
          AND t.updated_at BETWEEN ? AND ?
        ORDER BY ut.username, t.updated_at DESC
    ");
    $stmt->execute([$date_start_str . ' 00:00:00', $date_end_str . ' 23:59:59']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $completed_tasks[$row['username']][] = $row;
    }
}

// ── 5. Tarefas atrasadas por pessoa ───────────────────────────────────────
$overdue_tasks = [];
if ($check_todos) {
    $stmt = $pdo->query("
        SELECT ut.username, t.titulo, t.data_limite,
               DATEDIFF(CURDATE(), t.data_limite) AS dias_atraso
        FROM todos t
        JOIN user_tokens ut ON (t.responsavel = ut.user_id OR (t.responsavel IS NULL AND t.autor = ut.user_id))
        WHERE t.data_limite < CURDATE()
          AND t.estado NOT IN ('concluída','fechada')
        ORDER BY ut.username, t.data_limite ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $overdue_tasks[$row['username']][] = $row;
    }
}

// ── 6. Métricas gerais ────────────────────────────────────────────────────
$total_reports   = count($all_reports);
$total_reporters = count($by_person);
$total_users     = count($all_users_res);
$report_coverage = $total_users > 0 ? round(($total_reporters / $total_users) * 100) : 0;

// Mapa rápido: username → reports por data
$report_map = [];
foreach ($all_reports as $r) {
    $report_map[$r['username']][$r['report_date']] = $r;
}

$generated_at = (new DateTime())->format('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Relatório Equipa <?= $date_start->format('d/m/Y') ?> – <?= $date_end->format('d/m/Y') ?></title>
<style>
/* ── Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #1a1a1a; background: white; }
h1 { font-size: 20pt; }
h2 { font-size: 15pt; margin-top: 28px; margin-bottom: 10px; border-bottom: 2px solid #333; padding-bottom: 4px; }
h3 { font-size: 13pt; margin-top: 18px; margin-bottom: 6px; color: #2c5282; }
h4 { font-size: 11pt; margin-top: 12px; margin-bottom: 4px; color: #444; }
p  { margin-bottom: 6px; line-height: 1.5; }
pre { white-space: pre-wrap; font-family: inherit; font-size: 10pt; background: #f7f7f7; padding: 8px; border-left: 3px solid #ccc; margin: 4px 0; }

/* ── Cover ── */
.cover { text-align: center; padding: 60px 40px 40px; border-bottom: 3px solid #2c5282; margin-bottom: 30px; }
.cover h1 { color: #2c5282; margin-bottom: 8px; }
.cover .subtitle { font-size: 13pt; color: #555; margin-bottom: 6px; }
.cover .meta { font-size: 10pt; color: #888; margin-top: 16px; }

/* ── Summary boxes ── */
.summary-grid { display: flex; gap: 14px; flex-wrap: wrap; margin: 14px 0 20px; }
.summary-box { flex: 1; min-width: 110px; border: 1px solid #ccc; border-radius: 6px; padding: 12px; text-align: center; }
.summary-box .val { font-size: 22pt; font-weight: bold; color: #2c5282; }
.summary-box .lbl { font-size: 9pt; color: #666; margin-top: 2px; }
.summary-box.warn .val { color: #c0392b; }
.summary-box.ok   .val { color: #27ae60; }

/* ── Participation table ── */
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9.5pt; }
th { background: #2c5282; color: white; padding: 6px 8px; text-align: left; }
td { padding: 5px 8px; border-bottom: 1px solid #e0e0e0; vertical-align: top; }
tr:nth-child(even) td { background: #f9f9f9; }
.cell-ok   { text-align: center; color: #27ae60; font-weight: bold; }
.cell-miss { text-align: center; color: #ccc; }
.cell-num  { text-align: right; }
.cell-warn { color: #c0392b; font-weight: bold; }

/* ── Person section ── */
.person-section { border: 1px solid #d0d0d0; border-radius: 6px; margin-bottom: 22px; page-break-inside: avoid; }
.person-header { background: #2c5282; color: white; padding: 10px 14px; border-radius: 5px 5px 0 0; }
.person-header h3 { color: white; margin: 0; font-size: 13pt; }
.person-header .person-meta { font-size: 9pt; opacity: 0.85; margin-top: 2px; }
.person-body { padding: 12px 14px; }
.day-block { border-left: 3px solid #2c5282; padding: 8px 10px; margin: 8px 0; background: #f8faff; }
.day-block .day-date { font-weight: bold; font-size: 10pt; color: #2c5282; margin-bottom: 6px; }
.field-label { font-weight: bold; font-size: 9pt; color: #555; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
.no-report-day { color: #bbb; font-style: italic; font-size: 9.5pt; padding: 4px 0; }

/* ── Task section ── */
.task-ok   { color: #27ae60; }
.task-warn { color: #c0392b; }

/* ── Dividers ── */
.section-divider { border: none; border-top: 1px solid #ddd; margin: 20px 0; }

/* ── Print ── */
@media print {
    body { font-size: 10pt; }
    .no-print { display: none !important; }
    .person-section { page-break-inside: avoid; }
    h2 { page-break-before: auto; }
    .page-break { page-break-before: always; }
    @page { margin: 18mm 15mm; }
}
@media screen {
    body { max-width: 900px; margin: 0 auto; padding: 20px; }
    .print-btn { position: fixed; top: 16px; right: 16px; z-index: 100; background: #2c5282; color: white;
                 border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 12pt; }
    .print-btn:hover { background: #1a365d; }
}
</style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">⎙ Guardar como PDF</button>

<!-- ══════════════════════════════════════════════════════════════
     CAPA
══════════════════════════════════════════════════════════════ -->
<div class="cover">
    <h1>Relatório Consolidado da Equipa</h1>
    <div class="subtitle">
        Período: <?= $date_start->format('d/m/Y') ?> → <?= $date_end->format('d/m/Y') ?>
        (últimos <?= $days ?> dia<?= $days > 1 ? 's' : '' ?>)
    </div>
    <div class="meta">
        Gerado em <?= $generated_at ?> · <?= $total_users ?> colaborador<?= $total_users != 1 ? 'es' : '' ?> na equipa
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     SECÇÃO 1 — SUMÁRIO EXECUTIVO
══════════════════════════════════════════════════════════════ -->
<h2>1. Sumário Executivo</h2>

<div class="summary-grid">
    <div class="summary-box">
        <div class="val"><?= $days ?></div>
        <div class="lbl">Dias analisados</div>
    </div>
    <div class="summary-box">
        <div class="val"><?= $total_users ?></div>
        <div class="lbl">Colaboradores</div>
    </div>
    <div class="summary-box <?= $total_reporters < $total_users ? 'warn' : 'ok' ?>">
        <div class="val"><?= $total_reporters ?></div>
        <div class="lbl">Com reports</div>
    </div>
    <div class="summary-box">
        <div class="val"><?= $total_reports ?></div>
        <div class="lbl">Total de reports</div>
    </div>
    <div class="summary-box <?= $report_coverage < 70 ? 'warn' : 'ok' ?>">
        <div class="val"><?= $report_coverage ?>%</div>
        <div class="lbl">Cobertura equipa</div>
    </div>
    <?php
    $total_tasks_all   = array_sum(array_column($task_stats, 'total'));
    $total_concluidas  = array_sum(array_column($task_stats, 'concluidas'));
    $total_atrasadas   = array_sum(array_column($task_stats, 'atrasadas'));
    $total_em_exec     = array_sum(array_column($task_stats, 'em_execucao'));
    ?>
    <div class="summary-box ok">
        <div class="val"><?= $total_concluidas ?></div>
        <div class="lbl">Tarefas concluídas</div>
    </div>
    <div class="summary-box <?= $total_atrasadas > 0 ? 'warn' : 'ok' ?>">
        <div class="val"><?= $total_atrasadas ?></div>
        <div class="lbl">Tarefas atrasadas</div>
    </div>
</div>

<!-- Colaboradores sem report -->
<?php
$no_report_users = array_filter(array_column($all_users_res, 'username'), fn($u) => !isset($by_person[$u]));
if (!empty($no_report_users)): ?>
<p><strong>Sem qualquer report no período:</strong>
<?= implode(', ', array_map('htmlspecialchars', $no_report_users)) ?></p>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     SECÇÃO 2 — TABELA DE PARTICIPAÇÃO
══════════════════════════════════════════════════════════════ -->
<h2>2. Participação por Dia</h2>
<p>✔ = report entregue &nbsp;·&nbsp; — = sem report nesse dia</p>

<table>
    <thead>
        <tr>
            <th>Colaborador</th>
            <?php foreach ($all_days as $day): ?>
                <th style="text-align:center; font-size:8.5pt; white-space:nowrap;">
                    <?= date('d/m', strtotime($day)) ?><br>
                    <span style="font-weight:normal; opacity:0.8;"><?= date('D', strtotime($day)) ?></span>
                </th>
            <?php endforeach; ?>
            <th style="text-align:right;">Total</th>
            <th style="text-align:right;">% dias</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($all_users_res as $user):
        $uname = $user['username'];
        $user_days_with_report = 0;
    ?>
        <tr>
            <td><?= htmlspecialchars($uname) ?></td>
            <?php foreach ($all_days as $day):
                $has = isset($report_map[$uname][$day]);
                if ($has) $user_days_with_report++;
            ?>
                <td class="<?= $has ? 'cell-ok' : 'cell-miss' ?>">
                    <?= $has ? '✔' : '—' ?>
                </td>
            <?php endforeach; ?>
            <td class="cell-num"><?= $user_days_with_report ?></td>
            <td class="cell-num"><?= $days > 0 ? round($user_days_with_report / $days * 100) : 0 ?>%</td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- ══════════════════════════════════════════════════════════════
     SECÇÃO 3 — ESTATÍSTICAS DE TAREFAS POR COLABORADOR
══════════════════════════════════════════════════════════════ -->
<h2>3. Estatísticas de Tarefas por Colaborador</h2>

<?php if (empty($task_stats)): ?>
<p><em>Módulo de tarefas não disponível.</em></p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>Colaborador</th>
            <th class="cell-num">Total</th>
            <th class="cell-num">Abertas</th>
            <th class="cell-num">Em execução</th>
            <th class="cell-num">Concluídas</th>
            <th class="cell-num">Suspensas</th>
            <th class="cell-num">Atrasadas</th>
            <th class="cell-num">Alteradas no período</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($all_users_res as $user):
        $uname = $user['username'];
        $s = $task_stats[$uname] ?? null;
    ?>
        <tr>
            <td><?= htmlspecialchars($uname) ?></td>
            <?php if ($s): ?>
            <td class="cell-num"><?= (int)$s['total'] ?></td>
            <td class="cell-num"><?= (int)$s['abertas'] ?></td>
            <td class="cell-num"><?= (int)$s['em_execucao'] ?></td>
            <td class="cell-num task-ok"><?= (int)$s['concluidas'] ?></td>
            <td class="cell-num"><?= (int)$s['suspensas'] ?></td>
            <td class="cell-num <?= (int)$s['atrasadas'] > 0 ? 'task-warn' : '' ?>"><?= (int)$s['atrasadas'] ?></td>
            <td class="cell-num"><?= (int)$s['alteradas_periodo'] ?></td>
            <?php else: ?>
            <td colspan="7" style="color:#bbb; font-style:italic;">sem tarefas</td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     SECÇÃO 4 — TAREFAS ATRASADAS
══════════════════════════════════════════════════════════════ -->
<?php if (!empty($overdue_tasks)): ?>
<h2>4. Tarefas Atrasadas (por resolver)</h2>
<table>
    <thead>
        <tr>
            <th>Colaborador</th>
            <th>Tarefa</th>
            <th>Data Limite</th>
            <th class="cell-num">Dias Atraso</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($overdue_tasks as $uname => $tasks): ?>
        <?php foreach ($tasks as $i => $t): ?>
        <tr>
            <td><?= $i === 0 ? htmlspecialchars($uname) : '' ?></td>
            <td><?= htmlspecialchars($t['titulo']) ?></td>
            <td><?= $t['data_limite'] ? date('d/m/Y', strtotime($t['data_limite'])) : '—' ?></td>
            <td class="cell-num task-warn"><?= (int)$t['dias_atraso'] ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     SECÇÃO 5 — DAILY REPORTS POR COLABORADOR
══════════════════════════════════════════════════════════════ -->
<h2>5. Daily Reports por Colaborador</h2>

<?php if (empty($by_person)): ?>
<p><em>Nenhum Daily Report encontrado no período selecionado.</em></p>
<?php else: ?>

<?php foreach ($by_person as $uname => $reports): ?>
<?php
    $s = $task_stats[$uname] ?? null;
    $n_reports = count($reports);
    $coverage_pct = round($n_reports / $days * 100);
    // Index por data para lookup rápido
    $rmap = [];
    foreach ($reports as $r) { $rmap[$r['report_date']] = $r; }
?>
<div class="person-section">
    <div class="person-header">
        <h3><?= htmlspecialchars($uname) ?></h3>
        <div class="person-meta">
            <?= $n_reports ?> report<?= $n_reports != 1 ? 's' : '' ?> em <?= $days ?> dia<?= $days != 1 ? 's' : '' ?>
            (<?= $coverage_pct ?>% de cobertura)
            <?php if ($s): ?>
            &nbsp;·&nbsp; <?= (int)$s['total'] ?> tarefas no total
            &nbsp;·&nbsp; <span style="<?= (int)$s['atrasadas'] > 0 ? 'color:#ffaaaa;' : '' ?>"><?= (int)$s['atrasadas'] ?> atrasada<?= (int)$s['atrasadas'] != 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="person-body">

    <?php foreach (array_reverse($all_days) as $day): ?>
        <?php if (isset($rmap[$day])): $r = $rmap[$day]; ?>
        <div class="day-block">
            <div class="day-date">
                <?= date('l, d/m/Y', strtotime($day)) ?>
                <span style="font-weight:normal; font-size:9pt; color:#666;">
                    (guardado às <?= date('H:i', strtotime($r['atualizado_em'])) ?>)
                </span>
            </div>

            <?php if (!empty(trim($r['tarefas_alteradas']))): ?>
            <div class="field-label">Tarefas com alterações (últimas 24h)</div>
            <pre><?= htmlspecialchars($r['tarefas_alteradas']) ?></pre>
            <?php endif; ?>

            <?php if (!empty(trim($r['tarefas_em_execucao']))): ?>
            <div class="field-label">Tarefas em execução</div>
            <pre><?= htmlspecialchars($r['tarefas_em_execucao']) ?></pre>
            <?php endif; ?>

            <?php if (!empty(trim($r['correu_bem']))): ?>
            <div class="field-label">O que correu bem</div>
            <pre><?= htmlspecialchars($r['correu_bem']) ?></pre>
            <?php endif; ?>

            <?php if (!empty(trim($r['correu_mal']))): ?>
            <div class="field-label">O que correu menos bem</div>
            <pre><?= htmlspecialchars($r['correu_mal']) ?></pre>
            <?php endif; ?>

            <?php if (!empty(trim($r['plano_proximas_horas']))): ?>
            <div class="field-label">Plano próximas horas</div>
            <pre><?= htmlspecialchars($r['plano_proximas_horas']) ?></pre>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <p class="no-report-day">
            <?= date('l, d/m/Y', strtotime($day)) ?> — sem report
        </p>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if (!empty($completed_tasks[$uname])): ?>
    <h4>Tarefas concluídas neste período (<?= count($completed_tasks[$uname]) ?>)</h4>
    <table>
        <thead><tr><th>Tarefa</th><th>Data limite</th><th>Concluída em</th></tr></thead>
        <tbody>
        <?php foreach ($completed_tasks[$uname] as $t): ?>
        <tr>
            <td><?= htmlspecialchars($t['titulo']) ?></td>
            <td><?= $t['data_limite'] ? date('d/m/Y', strtotime($t['data_limite'])) : '—' ?></td>
            <td><?= date('d/m/Y H:i', strtotime($t['updated_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($overdue_tasks[$uname])): ?>
    <h4 class="task-warn">Tarefas atrasadas (<?= count($overdue_tasks[$uname]) ?>)</h4>
    <table>
        <thead><tr><th>Tarefa</th><th>Data limite</th><th>Dias atraso</th></tr></thead>
        <tbody>
        <?php foreach ($overdue_tasks[$uname] as $t): ?>
        <tr>
            <td><?= htmlspecialchars($t['titulo']) ?></td>
            <td><?= date('d/m/Y', strtotime($t['data_limite'])) ?></td>
            <td class="cell-num task-warn"><?= (int)$t['dias_atraso'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    </div><!-- /person-body -->
</div><!-- /person-section -->
<?php endforeach; ?>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     SECÇÃO 6 — NOTAS PARA ANÁLISE IA
══════════════════════════════════════════════════════════════ -->
<h2>6. Contexto para Análise</h2>
<table>
    <tbody>
        <tr><td><strong>Período</strong></td><td><?= $date_start->format('d/m/Y') ?> a <?= $date_end->format('d/m/Y') ?> (<?= $days ?> dias)</td></tr>
        <tr><td><strong>Total colaboradores</strong></td><td><?= $total_users ?></td></tr>
        <tr><td><strong>Colaboradores com reports</strong></td><td><?= $total_reporters ?> (<?= $report_coverage ?>%)</td></tr>
        <tr><td><strong>Total de reports</strong></td><td><?= $total_reports ?></td></tr>
        <tr><td><strong>Total de tarefas (equipa)</strong></td><td><?= $total_tasks_all ?></td></tr>
        <tr><td><strong>Tarefas concluídas</strong></td><td><?= $total_concluidas ?></td></tr>
        <tr><td><strong>Tarefas em execução</strong></td><td><?= $total_em_exec ?></td></tr>
        <tr><td><strong>Tarefas atrasadas</strong></td><td><?= $total_atrasadas ?></td></tr>
        <?php if (!empty($no_report_users)): ?>
        <tr><td><strong>Sem reports no período</strong></td><td><?= implode(', ', array_map('htmlspecialchars', $no_report_users)) ?></td></tr>
        <?php endif; ?>
        <tr><td><strong>Gerado em</strong></td><td><?= $generated_at ?></td></tr>
    </tbody>
</table>

<hr class="section-divider">
<p style="font-size:9pt; color:#888; text-align:center;">
    Relatório gerado automaticamente · PikachuPM · <?= $generated_at ?>
</p>

<script>
// Auto-print quando aberto directamente como exportação
if (window.opener || window.name === 'pdf_export') {
    window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 600);
    });
}
</script>
</body>
</html>
