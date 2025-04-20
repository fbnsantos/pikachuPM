<?php
include 'config.php';
$BASE_URL = 'http://criis-projects.inesctec.pt';

$url = $BASE_URL . '/issues.json?limit=1';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Redmine-API-Key: ' . $API_KEY
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "<h3>T Resultado da ligação ao Redmine</h3>";

if ($curlError) {
    echo "<p style='color:red;'>Erro cURL: $curlError</p>";
} elseif ($httpCode === 200) {
    echo "<p style='color:green;'>✅ Ligação bem-sucedida! HTTP 200 OK</p>";
    $data = json_decode($response, true);
    echo "<pre>" . print_r($data, true) . "</pre>";
} else {
    echo "<p style='color:red;'>❌ Ligação falhou. Código HTTP: $httpCode</p>";
    echo "<pre>Resposta: " . htmlspecialchars($response) . "</pre>";
}
?>
