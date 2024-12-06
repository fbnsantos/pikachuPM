<?php
shell_exec("git config --global --add safe.directory /var/www/html/pikachu/pikachuPM");

// Executar o comando 'git pull'
$output = shell_exec('git pull 2>&1');

// Exibir a saída do comando
echo "<pre>$output</pre>";
?>