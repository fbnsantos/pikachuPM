<?php
/**
 * Links.php - Interface para busca de tópicos no Redmine
 * 
 * Este arquivo permite buscar e listar links para issues no Redmine
 * organizados por categoria ou tópico.
 */

// Carregando configurações (API_KEY, BASE_URL)
include_once __DIR__ . '/../config.php';
global $API_KEY, $BASE_URL;

// Verificar se o usuário está logado
if (!isset($_SESSION['username'])) {
    echo "<p>Acesso não autorizado</p>";
    exit;
}

// Definir categorias padrão para busca
$categorias = [
    'documentacao' => 'Documentação',
    'suporte' => 'Suporte Técnico',
    'desenvolvimento' => 'Desenvolvimento',
    'bugs' => 'Bugs Reportados',
    'requisitos' => 'Requisitos',
    'testes' => 'Testes'
];

// Inicializar variáveis
$resultados = [];
$mensagemErro = "";
$mensagemSucesso = "";
$categoriaSelecionada = isset($_GET['categoria']) ? $_GET['categoria'] : '';
$termoBusca = isset($_GET['termo']) ? $_GET['termo'] : '';
$limitePorPagina = isset($_GET['limite']) ? intval($_GET['limite']) : 15;
$pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;

// Função para buscar issues no Redmine
function buscarRedmine($termo, $categoria, $pagina, $limite) {
    global $API_KEY, $BASE_URL;
    
    // Preparar parâmetros da busca
    $offset = ($pagina - 1) * $limite;
    $url = "{$BASE_URL}/issues.json";
    $parametros = [
        'key' => $API_KEY,
        'limit' => $limite,
        'offset' => $offset,
        'status_id' => 'open', // Padrão: busca apenas issues abertas
        'sort' => 'updated_on:desc' // Ordenar por atualização (mais recentes primeiro)
    ];
    
    // Adicionar termo de busca se fornecido
    if (!empty($termo)) {
        $parametros['subject'] = '~' . $termo; // Busca parcial em assuntos
    }
    
    // Adicionar categoria se fornecida
    if (!empty($categoria) && $categoria !== 'todas') {
        // Aqui você pode mapear a categoria para um tracker_id ou project_id específico no Redmine
        // Por exemplo:
        switch ($categoria) {
            case 'documentacao':
                $parametros['tracker_id'] = 3; // ID do tracker de documentação no Redmine
                break;
            case 'suporte':
                $parametros['tracker_id'] = 2; // ID do tracker de suporte no Redmine
                break;
            case 'bugs':
                $parametros['tracker_id'] = 1; // ID do tracker de bugs no Redmine
                break;
            // Adicione mais casos conforme necessário
        }
    }
    
    // Construir URL com parâmetros
    $queryString = http_build_query($parametros);
    $urlCompleta = $url . '?' . $queryString;
    
    // Inicializar cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $urlCompleta);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Útil para ambientes de desenvolvimento
    
    // Adicionar cabeçalho de autenticação
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Redmine-API-Key: ' . $API_KEY,
        'Content-Type: application/json'
    ]);
    
    // Executar requisição
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Verificar erro de conexão
    if ($response === false) {
        return [
            'erro' => 'Erro de conexão com o Redmine',
            'issues' => [],
            'total' => 0
        ];
    }
    
    // Verificar resposta HTTP
    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'erro' => "Erro na API do Redmine (código HTTP: $httpCode)",
            'issues' => [],
            'total' => 0
        ];
    }
    
    // Decodificar resposta
    $dadosJson = json_decode($response, true);
    
    // Verificar erro de decodificação
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'erro' => 'Erro ao decodificar resposta do Redmine',
            'issues' => [],
            'total' => 0
        ];
    }
    
    // Verificar se há issues na resposta
    if (!isset($dadosJson['issues']) || !is_array($dadosJson['issues'])) {
        return [
            'erro' => 'Formato de resposta inválido',
            'issues' => [],
            'total' => 0
        ];
    }
    
    // Retornar resultados
    return [
        'erro' => null,
        'issues' => $dadosJson['issues'],
        'total' => $dadosJson['total_count'] ?? count($dadosJson['issues'])
    ];
}

// Processar busca se houver termo ou categoria
if (!empty($termoBusca) || !empty($categoriaSelecionada)) {
    $resultado = buscarRedmine($termoBusca, $categoriaSelecionada, $pagina, $limitePorPagina);
    
    if ($resultado['erro']) {
        $mensagemErro = $resultado['erro'];
    } else {
        $resultados = $resultado['issues'];
        $totalResultados = $resultado['total'];
        $totalPaginas = ceil($totalResultados / $limitePorPagina);
        
        if (count($resultados) > 0) {
            $mensagemSucesso = "Foram encontrados {$totalResultados} resultados.";
        } else {
            $mensagemSucesso = "Nenhum resultado encontrado.";
        }
    }
}

// Definir links favoritos
$linksFavoritos = [
    ['titulo' => 'Documentação do Sistema', 'url' => $BASE_URL . '/projects/docs', 'descricao' => 'Documentação completa do sistema'],
    ['titulo' => 'Repositório de Código', 'url' => $BASE_URL . '/projects/repo', 'descricao' => 'Repositório principal de código fonte'],
    ['titulo' => 'Wiki do Projeto', 'url' => $BASE_URL . '/projects/wiki', 'descricao' => 'Wiki colaborativa do projeto']
];

// Função para formatar o status com cores
function formatarStatus($status) {
    $statusClasses = [
        'New' => 'bg-info',
        'In Progress' => 'bg-primary',
        'Resolved' => 'bg-success',
        'Feedback' => 'bg-warning',
        'Closed' => 'bg-secondary',
        'Rejected' => 'bg-danger'
    ];
    
    $classe = $statusClasses[$status] ?? 'bg-secondary';
    return "<span class='badge {$classe}'>{$status}</span>";
}

// Função para formatar a prioridade com cores
function formatarPrioridade($prioridade) {
    $prioridadeClasses = [
        'Low' => 'text-muted',
        'Normal' => 'text-primary',
        'High' => 'text-warning',
        'Urgent' => 'text-danger',
        'Immediate' => 'fw-bold text-danger'
    ];
    
    $classe = $prioridadeClasses[$prioridade] ?? 'text-primary';
    return "<span class='{$classe}'>{$prioridade}</span>";
}
?>

<div class="container-fluid">
    <h2 class="mb-4">Links e Recursos</h2>
    
    <!-- Formulário de Busca -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <i class="bi bi-search"></i> Buscar no Redmine
        </div>
        <div class="card-body">
            <form method="get" action="" class="row g-3">
                <input type="hidden" name="tab" value="links">
                
                <div class="col-md-6">
                    <label for="termo" class="form-label">Termo de Busca</label>
                    <input type="text" class="form-control" id="termo" name="termo" value="<?= htmlspecialchars($termoBusca) ?>" placeholder="Digite palavras-chave...">
                </div>
                
                <div class="col-md-3">
                    <label for="categoria" class="form-label">Categoria</label>
                    <select class="form-select" id="categoria" name="categoria">
                        <option value="todas" <?= $categoriaSelecionada === 'todas' ? 'selected' : '' ?>>Todas as Categorias</option>
                        <?php foreach ($categorias as $valor => $nome): ?>
                            <option value="<?= $valor ?>" <?= $categoriaSelecionada === $valor ? 'selected' : '' ?>>
                                <?= htmlspecialchars($nome) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="limite" class="form-label">Resultados por Página</label>
                    <select class="form-select" id="limite" name="limite">
                        <option value="10" <?= $limitePorPagina === 10 ? 'selected' : '' ?>>10</option>
                        <option value="15" <?= $limitePorPagina === 15 ? 'selected' : '' ?>>15</option>
                        <option value="25" <?= $limitePorPagina === 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $limitePorPagina === 50 ? 'selected' : '' ?>>50</option>
                    </select>
                </div>
                
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Buscar
                    </button>
                    <a href="?tab=links" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i> Limpar
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Resultados da Busca -->
    <?php if (!empty($termoBusca) || !empty($categoriaSelecionada)): ?>
        <div class="card mb-4">
            <div class="card-header bg-light">
                <i class="bi bi-list-ul"></i> Resultados da Busca
                <?php if (!empty($mensagemSucesso)): ?>
                    <span class="badge bg-success ms-2"><?= $mensagemSucesso ?></span>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($mensagemErro)): ?>
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle"></i> <?= $mensagemErro ?>
                </div>
            <?php elseif (empty($resultados)): ?>
                <div class="card-body">
                    <p class="text-muted">Nenhum resultado encontrado para sua busca.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th width="40%">Título</th>
                                <th>Status</th>
                                <th>Prioridade</th>
                                <th>Atualizado</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $issue): ?>
                                <tr>
                                    <td>#<?= $issue['id'] ?></td>
                                    <td>
                                        <a href="<?= $BASE_URL ?>/issues/<?= $issue['id'] ?>" target="_blank" 
                                           class="d-block fw-semibold text-truncate" style="max-width: 400px;" 
                                           title="<?= htmlspecialchars($issue['subject']) ?>">
                                            <?= htmlspecialchars($issue['subject']) ?>
                                        </a>
                                        <?php if (isset($issue['project'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($issue['project']['name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatarStatus($issue['status']['name']) ?></td>
                                    <td><?= formatarPrioridade($issue['priority']['name']) ?></td>
                                    <td>
                                        <small>
                                            <?= date('d/m/Y H:i', strtotime($issue['updated_on'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <a href="<?= $BASE_URL ?>/issues/<?= $issue['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="bi bi-eye"></i> Ver
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginação -->
                <?php if (isset($totalPaginas) && $totalPaginas > 1): ?>
                    <div class="card-footer">
                        <nav aria-label="Navegação de resultados">
                            <ul class="pagination justify-content-center mb-0">
                                <?php if ($pagina > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?tab=links&termo=<?= urlencode($termoBusca) ?>&categoria=<?= $categoriaSelecionada ?>&limite=<?= $limitePorPagina ?>&pagina=<?= $pagina - 1 ?>">
                                            <i class="bi bi-chevron-left"></i> Anterior
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="bi bi-chevron-left"></i> Anterior</span>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                // Determinar quais páginas mostrar
                                $startPage = max(1, $pagina - 2);
                                $endPage = min($totalPaginas, $pagina + 2);
                                
                                // Garantir que pelo menos 5 páginas sejam mostradas
                                if ($endPage - $startPage < 4) {
                                    if ($startPage == 1) {
                                        $endPage = min($totalPaginas, $startPage + 4);
                                    } elseif ($endPage == $totalPaginas) {
                                        $startPage = max(1, $endPage - 4);
                                    }
                                }
                                
                                // Mostrar links para a primeira página
                                if ($startPage > 1) {
                                    echo '<li class="page-item"><a class="page-link" href="?tab=links&termo=' . urlencode($termoBusca) . 
                                         '&categoria=' . $categoriaSelecionada . '&limite=' . $limitePorPagina . '&pagina=1">1</a></li>';
                                    
                                    if ($startPage > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                }
                                
                                // Links das páginas principais
                                for ($i = $startPage; $i <= $endPage; $i++) {
                                    if ($i == $pagina) {
                                        echo '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
                                    } else {
                                        echo '<li class="page-item"><a class="page-link" href="?tab=links&termo=' . urlencode($termoBusca) . 
                                             '&categoria=' . $categoriaSelecionada . '&limite=' . $limitePorPagina . '&pagina=' . $i . '">' . $i . '</a></li>';
                                    }
                                }
                                
                                // Mostrar links para a última página
                                if ($endPage < $totalPaginas) {
                                    if ($endPage < $totalPaginas - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    
                                    echo '<li class="page-item"><a class="page-link" href="?tab=links&termo=' . urlencode($termoBusca) . 
                                         '&categoria=' . $categoriaSelecionada . '&limite=' . $limitePorPagina . '&pagina=' . $totalPaginas . '">' . 
                                         $totalPaginas . '</a></li>';
                                }
                                ?>
                                
                                <?php if ($pagina < $totalPaginas): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?tab=links&termo=<?= urlencode($termoBusca) ?>&categoria=<?= $categoriaSelecionada ?>&limite=<?= $limitePorPagina ?>&pagina=<?= $pagina + 1 ?>">
                                            Próxima <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">Próxima <i class="bi bi-chevron-right"></i></span>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Links Favoritos -->
    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-bookmark-star"></i> Links Favoritos
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($linksFavoritos as $link): ?>
                        <a href="<?= $link['url'] ?>" class="list-group-item list-group-item-action" target="_blank">
                            <div class="d-flex w-100 justify-content-between">
                                <h5 class="mb-1"><?= htmlspecialchars($link['titulo']) ?></h5>
                                <small><i class="bi bi-box-arrow-up-right"></i></small>
                            </div>
                            <p class="mb-1"><?= htmlspecialchars($link['descricao']) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <!-- Dicas Rápidas -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-lightbulb"></i> Dicas Rápidas
                </div>
                <div class="card-body">
                    <h5 class="card-title">Como usar esta página</h5>
                    <ul class="mb-0">
                        <li>Use o campo de busca para encontrar issues no Redmine</li>
                        <li>Filtre por categoria para resultados mais específicos</li>
                        <li>Clique no título de uma issue para visualizá-la no Redmine</li>
                        <li>Links favoritos fornecem acesso rápido a recursos comuns</li>
                    </ul>
                </div>
            </div>
            
            <!-- Estatísticas Rápidas -->
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-graph-up"></i> Estatísticas
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <h3 class="text-primary">
                                <i class="bi bi-bug"></i>
                            </h3>
                            <h5>12</h5>
                            <p class="text-muted">Bugs Ativos</p>
                        </div>
                        <div class="col-4">
                            <h3 class="text-success">
                                <i class="bi bi-check-circle"></i>
                            </h3>
                            <h5>24</h5>
                            <p class="text-muted">Tarefas Concluídas</p>
                        </div>
                        <div class="col-4">
                            <h3 class="text-warning">
                                <i class="bi bi-hourglass-split"></i>
                            </h3>
                            <h5>8</h5>
                            <p class="text-muted">Em Progresso</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>