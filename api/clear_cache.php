<?php
// Limpar OPCache — visitar este URL uma vez para forçar recompilação dos ficheiros PHP
header('Content-Type: application/json');

$reset = false;
if (function_exists('opcache_reset')) {
    opcache_reset();
    $reset = true;
}

echo json_encode([
    'ok'      => true,
    'opcache' => $reset ? 'limpo' : 'não está ativo',
    'ts'      => date('Y-m-d H:i:s'),
]);
