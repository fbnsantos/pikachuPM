<?php
$flagFile = __DIR__ . '/debug.txt';
$debugHtml = __DIR__ . '/debug.html';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents($flagFile, "1");
    // abre nova aba com esta mesma página
    echo "<script>
        window.open('" . basename(__FILE__) . "', '_blank');
        window.location.href = '" . basename(__FILE__) . "';
    </script>";
    exit;
}
?>
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <title>Debug Apache</title>
  <style>
    body { font-family: sans-serif; margin: 0; padding: 0; }
    .toolbar { background: #f8f9fa; padding: 10px; border-bottom: 1px solid #ccc; }
    button {
      background: #007bff;
      color: #fff;
      padding: 6px 14px;
      border: none;
      border-radius: 6px;
      font-size: 0.9rem;
      cursor: pointer;
    }
    button:hover { background: #0056b3; }
    .content { padding: 0; }
  </style>
</head>
<body>
  <div class="toolbar">
    <form method="post" style="display:inline;">
      <button type="submit">Reativar Debug</button>
    </form>
  </div>
  <div class="content">
    <?php
    if (file_exists($debugHtml)) {
        // incluir o HTML gerado pelo script bash sem alterar
        readfile($debugHtml);
    } else {
        echo "<p style='padding:10px;'>Ainda não existe ficheiro de debug.</p>";
    }
    ?>
  </div>
</body>
</html>