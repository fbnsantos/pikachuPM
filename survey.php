<?php
// survey.php — Página pública de inquérito para parceiros externos (sem login)

$token = trim($_GET['token'] ?? '');
if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:2rem;">Inquérito não encontrado ou link inválido.</p>');
}

include_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die('<p style="font-family:sans-serif;padding:2rem;">Erro de ligação à base de dados.</p>');
}

// Carregar survey e protótipo
$stmt = $pdo->prepare("
    SELECT ps.*, p.short_name, p.title as proto_title, p.vision, p.product_description
    FROM prototype_surveys ps
    JOIN prototypes p ON ps.prototype_id = p.id
    WHERE ps.token = ? AND ps.is_active = 1
    LIMIT 1
");
$stmt->execute([$token]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$survey) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:2rem;max-width:600px;margin:auto;">Este inquérito não está disponível de momento. Por favor, contacte o responsável pelo projeto.</p>');
}

$questions   = json_decode($survey['questions']  ?? '[]', true) ?: [];
$videoLinks  = json_decode($survey['video_links'] ?? '[]', true) ?: [];

$submitted = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['respondent_name']  ?? '');
    $email       = trim($_POST['respondent_email'] ?? '');
    $description = trim($_POST['description']      ?? '');
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email inválido.';
    }

    // Validar questões obrigatórias
    foreach ($questions as $q) {
        if (!empty($q['required'])) {
            $val = trim($_POST['q_' . $q['id']] ?? '');
            if ($val === '') $errors[] = 'A questão "' . htmlspecialchars($q['label']) . '" é obrigatória.';
        }
    }

    $ytLinks = [];

    // Recolher respostas às questões
    $answers = [];
    foreach ($questions as $q) {
        $val = trim($_POST['q_' . $q['id']] ?? '');
        if ($val !== '') {
            $answers[] = ['id' => $q['id'], 'label' => $q['label'], 'type' => $q['type'], 'value' => $val];
        }
    }

    if (empty($errors)) {
        try {
            $ins = $pdo->prepare("
                INSERT INTO survey_responses
                    (survey_id, respondent_name, respondent_email, description, youtube_links, answers, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([
                $survey['id'],
                $name,
                $email,
                $description,
                json_encode($ytLinks),
                json_encode($answers),
            ]);
            $submitted = true;
        } catch (PDOException $e) {
            $errors[] = 'Erro ao guardar a resposta. Por favor, tente novamente.';
        }
    }
}

// Converter Markdown simples para HTML (sem dependências externas)
function renderMarkdown(string $md): string {
    $html = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
    // Headings
    $html = preg_replace('/^### (.+)$/m', '<h6 class="mt-3 mb-1">$1</h6>', $html);
    $html = preg_replace('/^## (.+)$/m',  '<h5 class="mt-3 mb-1">$1</h5>', $html);
    $html = preg_replace('/^# (.+)$/m',   '<h4 class="mt-3 mb-1">$1</h4>', $html);
    // Bold / italic / code
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/',     '<em>$1</em>',         $html);
    $html = preg_replace('/`(.+?)`/',       '<code>$1</code>',     $html);
    // Unordered lists
    $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>(?:(?!<\/li>).)+<\/li>\n?)+/', '<ul class="mb-2">$0</ul>', $html);
    // Line breaks & paragraphs
    $html = preg_replace('/\n\n/', '</p><p class="mb-2">', $html);
    $html = preg_replace('/\n/', '<br>', $html);
    return '<p class="mb-2">' . $html . '</p>';
}

function ytEmbedId(string $url): string {
    if (preg_match('/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/))([A-Za-z0-9_-]{11})/', $url, $m)) {
        return $m[1];
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($survey['title'] ?? 'Inquérito de Parceiros') ?> — <?= htmlspecialchars($survey['short_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f0f4f8; font-family: 'Segoe UI', sans-serif; }
        .survey-wrapper { max-width: 780px; margin: 40px auto; padding: 0 16px 60px; }
        .survey-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,.08); overflow: hidden; }
        .survey-header { background: linear-gradient(135deg, #1d4ed8 0%, #0f766e 100%); color: #fff; padding: 32px 32px 24px; }
        .survey-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0; }
        .survey-body { padding: 32px; }
        .form-label { font-weight: 600; color: #374151; }
        .hint { font-size: 12px; color: #6b7280; margin-top: 4px; }
        .success-state { text-align: center; padding: 48px 32px; }
        .success-state .bi-check-circle-fill { font-size: 3rem; color: #10b981; }
        .question-block { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px; }
        .star-group label { cursor: pointer; font-size: 1.6rem; color: #d1d5db; transition: color .15s; }
        .star-group input[type=radio] { display: none; }
        .star-group label:hover,
        .star-group label:hover ~ label,
        .star-group input[type=radio]:checked ~ label { color: #f59e0b; }
        .star-group { display: flex; flex-direction: row-reverse; gap: 4px; }
        .video-embed-wrapper { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .video-embed-wrapper iframe { border-radius: 8px; border: none; }
    </style>
</head>
<body>
<div class="survey-wrapper">
    <div class="survey-card">
        <div class="survey-header">
            <div style="font-size:12px; opacity:.7; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">
                <i class="bi bi-puzzle-fill"></i> <?= htmlspecialchars($survey['short_name']) ?>
            </div>
            <h1><?= htmlspecialchars($survey['title'] ?? 'Inquérito de Parceiros') ?></h1>
        </div>

        <?php if ($submitted): ?>
        <div class="success-state">
            <i class="bi bi-check-circle-fill d-block mb-3"></i>
            <h4 class="fw-bold text-success mb-2">Obrigado pela sua contribuição!</h4>
            <p class="text-muted mb-4">A sua resposta foi registada com sucesso. A equipa irá analisá-la e poderá convertê-la em requisitos do protótipo.</p>
            <a href="survey.php?token=<?= htmlspecialchars($token) ?>" class="btn btn-outline-primary">
                <i class="bi bi-plus-circle"></i> Submeter outra resposta
            </a>
        </div>
        <?php else: ?>
        <div class="survey-body">

            <!-- Texto introdutório com Markdown -->
            <?php if (!empty($survey['intro_text'])): ?>
            <div class="mb-4" style="line-height:1.7;">
                <?= renderMarkdown($survey['intro_text']) ?>
            </div>
            <?php elseif (!empty($survey['vision']) || !empty($survey['product_description'])): ?>
            <div class="alert alert-light border mb-4" style="background:#f8fafc;">
                <h6 class="fw-bold mb-2"><i class="bi bi-lightbulb"></i> Sobre o Protótipo</h6>
                <?php if (!empty($survey['vision'])): ?>
                <p class="mb-1" style="font-size:.93rem;"><strong>Visão:</strong> <?= nl2br(htmlspecialchars($survey['vision'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($survey['product_description'])): ?>
                <p class="mb-0" style="font-size:.93rem;"><?= nl2br(htmlspecialchars($survey['product_description'])) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Vídeos do inquérito (definidos pelo editor) -->
            <?php if (!empty($videoLinks)): ?>
            <div class="video-embed-wrapper mb-4">
                <?php foreach ($videoLinks as $vUrl): ?>
                <?php $vid = ytEmbedId($vUrl); ?>
                <?php if ($vid): ?>
                <iframe width="340" height="192" src="https://www.youtube.com/embed/<?= htmlspecialchars($vid) ?>" allowfullscreen></iframe>
                <?php else: ?>
                <a href="<?= htmlspecialchars($vUrl) ?>" target="_blank" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-youtube"></i> <?= htmlspecialchars($vUrl) ?>
                </a>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mb-4">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" action="survey.php?token=<?= htmlspecialchars($token) ?>">

                <!-- Identificação (opcional) -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label">Nome <span class="text-muted fw-normal">(opcional)</span></label>
                        <input type="text" name="respondent_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['respondent_name'] ?? '') ?>"
                               placeholder="O seu nome">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Email <span class="text-muted fw-normal">(opcional)</span></label>
                        <input type="email" name="respondent_email" class="form-control"
                               value="<?= htmlspecialchars($_POST['respondent_email'] ?? '') ?>"
                               placeholder="email@exemplo.com">
                    </div>
                </div>

                <!-- Questões dinâmicas -->
                <?php foreach ($questions as $q):
                    $qId  = 'q_' . $q['id'];
                    $prev = $_POST[$qId] ?? '';
                    $req  = !empty($q['required']);
                ?>
                <div class="question-block mb-3">
                    <label class="form-label mb-2">
                        <?= htmlspecialchars($q['label']) ?>
                        <?php if ($req): ?><span class="text-danger">*</span><?php endif; ?>
                    </label>

                    <?php if ($q['type'] === 'yesno'): ?>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="<?= $qId ?>" id="<?= $qId ?>_yes" value="Sim" <?= $prev==='Sim'?'checked':'' ?> <?= $req?'required':'' ?>>
                            <label class="form-check-label" for="<?= $qId ?>_yes">Sim</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="<?= $qId ?>" id="<?= $qId ?>_no" value="Não" <?= $prev==='Não'?'checked':'' ?>>
                            <label class="form-check-label" for="<?= $qId ?>_no">Não</label>
                        </div>
                    </div>

                    <?php elseif ($q['type'] === 'long'): ?>
                    <textarea name="<?= $qId ?>" class="form-control" rows="4"
                              placeholder="A sua resposta…" <?= $req?'required':'' ?>><?= htmlspecialchars($prev) ?></textarea>

                    <?php elseif ($q['type'] === 'rating'): ?>
                    <?php $max = (int)($q['max'] ?? 5); ?>
                    <div class="star-group" id="stars_<?= $qId ?>">
                        <?php for ($s = $max; $s >= 1; $s--): ?>
                        <input type="radio" name="<?= $qId ?>" id="<?= $qId ?>_s<?= $s ?>" value="<?= $s ?>" <?= $prev==(string)$s?'checked':'' ?> <?= ($req&&$s==1)?'required':'' ?>>
                        <label for="<?= $qId ?>_s<?= $s ?>" title="<?= $s ?>"><i class="bi bi-star-fill"></i></label>
                        <?php endfor; ?>
                    </div>
                    <div class="hint mt-1">1 = mínimo · <?= $max ?> = máximo</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <!-- Descrição livre -->
                <div class="mb-3">
                    <label class="form-label">Comentários / Descrição adicional</label>
                    <textarea name="description" class="form-control" rows="5"
                              placeholder="Descreva as suas necessidades, sugestões ou cenários de uso…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-send-fill"></i> Submeter Resposta
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="text-center mt-4" style="font-size:12px; color:#9ca3af;">
        Powered by PikachuPM
    </div>
</div>
</body>
</html>
