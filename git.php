<?php

// Executar o comando 'git pull'
$output = shell_exec('git pull 2>&1');

// Exibir a saída do comando
echo "<pre>$output</pre>";
?>