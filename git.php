<?php

$currentUser = get_current_user();
echo "<p>Current User: " . $currentUser . "</p>";

$output = shell_exec("git config --global --add safe.directory /var/www/html/pikachu/pikachuPM 2>&1");
echo "<pre>$output</pre>";


// Executar o comando 'git pull'
$output = shell_exec('git pull 2>&1');

// Exibir a saída do comando
echo "<pre>$output</pre>";
?>