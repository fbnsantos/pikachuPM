<?php
// Caminho para o arquivo de log
$logFile = 'error_log';

// Verificar se o arquivo existe e se pode ser lido
if (file_exists($logFile) && is_readable($logFile)) {
    // Ler o conteúdo do arquivo
    $logContents = file_get_contents($logFile);
    
    // Se o arquivo estiver vazio
    if (!$logContents) {
        echo "O arquivo de log está vazio.";
    } else {
        // Exibir o conteúdo do log de erros
        echo "<h1>Conteúdo do arquivo error.log:</h1>";
        echo "<pre>" . htmlspecialchars($logContents) . "</pre>";
    }
} else {
    // Se o arquivo não existir ou não puder ser lido
    echo "Não foi possível acessar o arquivo de log. Verifique o caminho ou as permissões.";
}
?>