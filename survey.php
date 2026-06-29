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
    SELECT ps.*, p.short_name, p.title, p.vision, p.product_description
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

$submitted = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['respondent_name'] ?? '');
    $email = trim($_POST['respondent_email'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $rawYt = $_POST['youtube_links'] ?? '';

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email inválido.';
    if (empty($description)) $errors[] = 'A descrição é obrigatória.';

    // Processar links YouTube (um por linha)
    $ytLinks = [];
    foreach (explode("\n", $rawYt) as $line) {
        $line = trim($line);
        if (!empty($line)) {
            // Aceitar URLs youtube válidos
            if (preg_match('/youtu(\.be|be\.com)\//i', $line)) {
                $ytLinks[] = $line;
            }
        }
    }

    if (empty($errors)) {
        try {
            $ins = $pdo->prepare("
                INSERT INTO survey_responses (survey_id, respondent_name, respondent_email, description, youtube_links, submitted_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([
                $survey['id'],
                $name,
                $email,
                $description,
                json_encode($ytLinks)
            ]);
            $submitted = true;
        } catch (PDOException $e) {
            $errors[] = 'Erro ao guardar a resposta. Por favor, tente novamente.';
        }
    }
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
        .survey-wrapper { max-width: 760px; margin: 40px auto; padding: 0 16px 60px; }
        .survey-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,.08); overflow: hidden; }
        .survey-header { background: linear-gradient(135deg, #1d4ed8 0%, #0f766e 100%); color: #fff; padding: 32px 32px 24px; }
        .survey-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0 0 8px; }
        .survey-header p { opacity: .85; margin: 0; font-size: .95rem; }
        .survey-body { padding: 32px; }
        .form-label { font-weight: 600; color: #374151; }
        .yt-hint { font-size: 12px; color: #6b7280; margin-top: 4px; }
        .success-state { text-align: center; padding: 48px 32px; }
        .success-state i { font-size: 3rem; color: #10b981; }
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
            <p><?= htmlspecialchars($survey['title_full'] ?? $survey['title'] ?? '') ?></p>
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

            <?php if (!empty($survey['vision']) || !empty($survey['product_description'])): ?>
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

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" action="survey.php?token=<?= htmlspecialchars($token) ?>">
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label">Nome</label>
                        <input type="text" name="respondent_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['respondent_name'] ?? '') ?>"
                               placeholder="O seu nome (opcional)">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="respondent_email" class="form-control"
                               value="<?= htmlspecialchars($_POST['respondent_email'] ?? '') ?>"
                               placeholder="email@exemplo.com (opcional)">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Descrição / Necessidades <span class="text-danger">*</span></label>
                    <textarea name="description" class="form-control" rows="6"
                              placeholder="Descreva as suas necessidades, sugestões ou funcionalidades que gostaria de ver no protótipo..." required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    <div class="yt-hint">Seja o mais detalhado possível — descreva cenários de uso, problemas que enfrenta, ou o que seria mais valioso para si.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label"><i class="bi bi-youtube text-danger"></i> Links de Vídeo YouTube (opcional)</label>
                    <textarea name="youtube_links" class="form-control" rows="3"
                              placeholder="https://www.youtube.com/watch?v=...&#10;https://youtu.be/..."><?= htmlspecialchars($_POST['youtube_links'] ?? '') ?></textarea>
                    <div class="yt-hint">Um link por linha. Pode adicionar vídeos de referência, exemplos de produtos similares, ou demonstrações que ilustrem o que pretende.</div>
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
