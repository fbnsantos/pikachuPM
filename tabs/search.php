<?php
/**
 * search.php - Interface para busca de tópicos e arquivos no Redmine
 * 
 * Este arquivo permite buscar e listar links para issues e arquivos no Redmine
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
$resultadosArquivos = [];
$mensagemErro = "";
$mensagemSucesso = "";
$categoriaSelecionada = isset($_GET['categoria']) ? $_GET['categoria'] : '';
$termoBusca = isset($_GET['termo']) ? $_GET['termo'] : '';
$limitePorPagina = isset($_GET['limite']) ? intval($_GET['limite']) : 15;
$pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$tipoBusca = isset($_GET['tipo_busca']) ? $_GET['tipo_busca'] : 'issues';

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

// Função para buscar arquivos no Redmine
function buscarArquivosRedmine($termo, $categoria, $pagina, $limite) {
    global $API_KEY, $BASE_URL;
    
    // Preparar parâmetros da busca
    $offset = ($pagina - 1) * $limite;
    
    // No Redmine, arquivos estão geralmente associados a projetos, issues ou documentos
    // Vamos buscar diretamente pela API de documentos ou anexos do projeto
    $url = "{$BASE_URL}/documents.json";
    $parametros = [
        'key' => $API_KEY,
        'limit' => $limite,
        'offset' => $offset
    ];
    
    // Adicionar filtragem por projeto se a categoria for especificada
    if (!empty($categoria) && $categoria !== 'todas') {
        // Mapear categoria para project_id específico 
        // Este é um exemplo - você precisa ajustar para seu ambiente Redmine
        switch ($categoria) {
            case 'documentacao':
                $parametros['project_id'] = 'docs'; // ID do projeto de documentação
                break;
            case 'desenvolvimento':
                $parametros['project_id'] = 'dev'; // ID do projeto de desenvolvimento
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Adicionar cabeçalho de autenticação
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Redmine-API-Key: ' . $API_KEY,
        'Content-Type: application/json'
    ]);
    
    // Executar requisição
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Verificar erro de conexão ou resposta
    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return [
            'erro' => 'Erro ao conectar com a API de documentos do Redmine',
            'documentos' => [],
            'total' => 0
        ];
    }
    
    // Decodificar resposta
    $dadosJson = json_decode($response, true);
    
    // Verificar erro de decodificação
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'erro' => 'Erro ao decodificar resposta do Redmine',
            'documentos' => [],
            'total' => 0
        ];
    }
    
    // Verificar se há documentos na resposta
    if (!isset($dadosJson['documents']) || !is_array($dadosJson['documents'])) {
        return [
            'erro' => 'Formato de resposta inválido',
            'documentos' => [],
            'total' => 0
        ];
    }
    
    // Filtrar por termo de busca se fornecido
    $documentosFiltrados = $dadosJson['documents'];
    if (!empty($termo)) {
        $documentosFiltrados = array_filter($dadosJson['documents'], function($doc) use ($termo) {
            // Busca no título ou descrição do documento
            return (stripos($doc['title'], $termo) !== false || 
                   (isset($doc['description']) && stripos($doc['description'], $termo) !== false));
        });
    }
    
    // Retornar resultados
    return [
        'erro' => null,
        'documentos' => array_values($documentosFiltrados), // Reindexar array após filtragem
        'total' => count($documentosFiltrados)
    ];
}

// Processar busca conforme o tipo selecionado
if (!empty($termoBusca) || !empty($categoriaSelecionada)) {
    if ($tipoBusca === 'issues' || $tipoBusca === 'ambos') {
        $resultado = buscarRedmine($termoBusca, $categoriaSelecionada, $pagina, $limitePorPagina);
        
        if ($resultado['erro']) {
            $mensagemErro = $resultado['erro'];
        } else {
            $resultados = $resultado['issues'];
            $totalResultados = $resultado['total'];
            $totalPaginas = ceil($totalResultados / $limitePorPagina);
            
            if (count($resultados) > 0) {
                $mensagemSucesso = "Foram encontrados {$totalResultados} resultados para issues.";
            } else {
                $mensagemSucesso = "Nenhum resultado encontrado para issues.";
            }
        }
    }
    
    if ($tipoBusca === 'arquivos' || $tipoBusca === 'ambos') {
        $resultadoArquivos = buscarArquivosRedmine($termoBusca, $categoriaSelecionada, $pagina, $limitePorPagina);
        
        if ($resultadoArquivos['erro']) {
            if (empty($mensagemErro)) {
                $mensagemErro = $resultadoArquivos['erro'];
            } else {
                $mensagemErro .= " | " . $resultadoArquivos['erro'];
            }
        } else {
            $resultadosArquivos = $resultadoArquivos['documentos'];
            $totalResultadosArquivos = $resultadoArquivos['total'];
            
            if (count($resultadosArquivos) > 0) {
                if (empty($mensagemSucesso)) {
                    $mensagemSucesso = "Foram encontrados {$totalResultadosArquivos} arquivos.";
                } else {
                    $mensagemSucesso .= " | Foram encontrados {$totalResultadosArquivos} arquivos.";
                }
            } else if (empty($mensagemSucesso)) {
                $mensagemSucesso = "Nenhum arquivo encontrado.";
            }
        }
    }
}

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

// Função para formatar o tamanho do arquivo
function formatarTamanhoArquivo($bytes) {
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    } else {
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

// Função para obter a extensão do arquivo
function obterExtensaoArquivo($nome) {
    $extensao = pathinfo($nome, PATHINFO_EXTENSION);
    return strtolower($extensao);
}

// Função para determinar o ícone do arquivo baseado na extensão
function obterIconeArquivo($extensao) {
    switch ($extensao) {
        case 'pdf':
            return 'bi-file-earmark-pdf';
        case 'doc':
        case 'docx':
            return 'bi-file-earmark-word';
        case 'xls':
        case 'xlsx':
            return 'bi-file-earmark-excel';
        case 'ppt':
        case 'pptx':
            return 'bi-file-earmark-ppt';
        case 'txt':
            return 'bi-file-earmark-text';
        case 'zip':
        case 'rar':
        case '7z':
            return 'bi-file-earmark-zip';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'bmp':
            return 'bi-file-earmark-image';
        default:
            return 'bi-file-earmark';
    }
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
                <input type="hidden" name="tab" value="search">
                
                <div class="col-md-5">
                    <label for="termo" class="form-label">Termo de Busca</label>
                    <input type="text" class="form-control" id="termo" name="termo" value="<?= htmlspecialchars($termoBusca) ?>" placeholder="Digite palavras-chave...">
                </div>
                
                <div class="col-md-2">
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
                
                <div class="col-md-2">
                    <label for="tipo_busca" class="form-label">Tipo de Busca</label>
                    <select class="form-select" id="tipo_busca" name="tipo_busca">
                        <option value="issues" <?= $tipoBusca === 'issues' ? 'selected' : '' ?>>Issues</option>
                        <option value="arquivos" <?= $tipoBusca === 'arquivos' ? 'selected' : '' ?>>Arquivos</option>
                        <option value="ambos" <?= $tipoBusca === 'ambos' ? 'selected' : '' ?>>Ambos</option>
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
                    <a href="?tab=search" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i> Limpar
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Resultados da Busca -->
    <?php if (!empty($termoBusca) || !empty($categoriaSelecionada)): ?>
        <!-- Tabs para alternar entre resultados -->
        <ul class="nav nav-tabs mb-3" id="resultadosTabs" role="tablist">
            <?php if ($tipoBusca === 'issues' || $tipoBusca === 'ambos'): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="issues-tab" data-bs-toggle="tab" data-bs-target="#issues" type="button" role="tab" aria-controls="issues" aria-selected="true">
                        <i class="bi bi-card-list"></i> Issues <?php if (isset($totalResultados)): ?><span class="badge bg-secondary"><?= $totalResultados ?></span><?php endif; ?>
                    </button>
                </li>
            <?php endif; ?>
            
            <?php if ($tipoBusca === 'arquivos' || $tipoBusca === 'ambos'): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $tipoBusca === 'arquivos' ? 'active' : '' ?>" id="arquivos-tab" data-bs-toggle="tab" data-bs-target="#arquivos" type="button" role="tab" aria-controls="arquivos" aria-selected="<?= $tipoBusca === 'arquivos' ? 'true' : 'false' ?>">
                        <i class="bi bi-file-earmark"></i> Arquivos <?php if (isset($totalResultadosArquivos)): ?><span class="badge bg-secondary"><?= $totalResultadosArquivos ?></span><?php endif; ?>
                    </button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content" id="resultadosTabsContent">
            <!-- Tab para Issues -->
            <?php if ($tipoBusca === 'issues' || $tipoBusca === 'ambos'): ?>
                <div class="tab-pane fade show active" id="issues" role="tabpanel" aria-labelledby="issues-tab">
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <i class="bi bi-list-ul"></i> Resultados da Busca - Issues
                            <?php if (!empty($mensagemSucesso) && $tipoBusca === 'issues'): ?>
                                <span class="badge bg-success ms-2"><?= $mensagemSucesso ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($mensagemErro) && $tipoBusca === 'issues'): ?>
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
                            
                            <!-- Paginação para Issues -->
                            <?php if (isset($totalPaginas) && $totalPaginas > 1): ?>
                                <div class="card-footer">
                                    <nav aria-label="Navegação de resultados">
                                        <ul class="pagination justify-content-center mb-0">
                                            <?php if ($pagina > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?tab=search&termo=<?= urlencode($termoBusca) ?>&categoria=<?= $categoriaSelecionada ?>&tipo_busca=<?= $tipoBusca ?>&limite=<?= $limitePorPagina ?>&pagina=<?= $pagina - 1 ?>">
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
                                                echo '<li class="page-item"><a class="page-link" href="?tab=search&termo=' . urlencode($termoBusca) . 
                                                     '&categoria=' . $categoriaSelecionada . '&tipo_busca=' . $tipoBusca . '&limite=' . $limitePorPagina . '&pagina=1">1</a></li>';
                                                
                                                if ($startPage > 2) {
                                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                                }
                                            }
                                            
                                            // Links das páginas principais
                                            for ($i = $startPage; $i <= $endPage; $i++) {
                                                if ($i == $pagina) {
                                                    echo '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
                                                } else {
                                                    echo '<li class="page-item"><a class="page-link" href="?tab=search&termo=' . urlencode($termoBusca) . 
                                                         '&categoria=' . $categoriaSelecionada . '&tipo_busca=' . $tipoBusca . '&limite=' . $limitePorPagina . '&pagina=' . $i . '">' . $i . '</a></li>';
                                                }
                                            }
                                            
                                            // Mostrar links para a última página
                                            if ($endPage < $totalPaginas) {
                                                if ($endPage < $totalPaginas - 1) {
                                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                                }
                                                
                                                echo '<li class="page-item"><a class="page-link" href="?tab=search&termo=' . urlencode($termoBusca) . 
                                                     '&categoria=' . $categoriaSelecionada . '&tipo_busca=' . $tipoBusca . '&limite=' . $limitePorPagina . '&pagina=' . $totalPaginas . '">' . 
                                                     $totalPaginas . '</a></li>';
                                            }
                                            ?>
                                            
                                            <?php if ($pagina < $totalPaginas): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?tab=search&termo=<?= urlencode($termoBusca) ?>&categoria=<?= $categoriaSelecionada ?>&tipo_busca=<?= $tipoBusca ?>&limite=<?= $limitePorPagina ?>&pagina=<?= $pagina + 1 ?>">
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
                </div>
            <?php endif; ?>
            
            <!-- Tab para Arquivos -->
            <?php if ($tipoBusca === 'arquivos' || $tipoBusca === 'ambos'): ?>
                <div class="tab-pane fade <?= $tipoBusca === 'arquivos' ? 'show active' : '' ?>" id="arquivos" role="tabpanel" aria-labelledby="arquivos-tab">
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <i class="bi bi-file-earmark"></i> Resultados da Busca - Arquivos
                            <?php if (!empty($mensagemSucesso) && $tipoBusca === 'arquivos'): ?>
                                <span class="badge bg-success ms-2"><?= $mensagemSucesso ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($mensagemErro) && $tipoBusca === 'arquivos'): ?>
                            <div class="alert alert-danger m-3">
                                <i class="bi bi-exclamation-triangle"></i> <?= $mensagemErro ?>
                            </div>
                        <?php elseif (empty($resultadosArquivos)): ?>
                            <div class="card-body">
                                <p class="text-muted">Nenhum arquivo encontrado para sua busca.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th width="5%">Tipo</th>
                                            <th width="40%">Nome do Arquivo</th>
                                            <th>Projeto</th>
                                            <th>Tamanho</th>
                                            <th>Data</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($resultadosArquivos as $documento): ?>
                                            <?php 
                                                // Assumindo que os documentos possuem anexos ou links para arquivos
                                                $nomeArquivo = $documento['title'];
                                                $extensao = obterExtensaoArquivo($nomeArquivo);
                                                $icone = obterIconeArquivo($extensao);
                                                
                                                // Estas informações podem variar de acordo com a estrutura da API do Redmine
                                                $tamanho = isset($documento['filesize']) ? formatarTamanhoArquivo($documento['filesize']) : 'N/A';
                                                $projeto = isset($documento['project']) ? $documento['project']['name'] : 'N/A';
                                                $data = isset($documento['created_on']) ? date('d/m/Y H:i', strtotime($documento['created_on'])) : 'N/A';
                                                $url = isset($documento['content_url']) ? $documento['content_url'] : "{$BASE_URL}/documents/{$documento['id']}";
                                            ?>
                                            <tr>
                                                <td class="text-center">
                                                    <i class="bi <?= $icone ?> fs-4"></i>
                                                </td>
                                                <td>
                                                    <a href="<?= $url ?>" target="_blank" 
                                                       class="d-block fw-semibold text-truncate" style="max-width: 400px;" 
                                                       title="<?= htmlspecialchars($nomeArquivo) ?>">
                                                        <?= htmlspecialchars($nomeArquivo) ?>
                                                    </a>
                                                    <?php if (isset($documento['description'])): ?>
                                                        <small class="text-muted"><?= htmlspecialchars(substr($documento['description'], 0, 100)) ?><?= strlen($documento['description']) > 100 ? '...' : '' ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($projeto) ?></td>
                                                <td><?= $tamanho ?></td>
                                                <td>
                                                    <small><?= $data ?></small>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="<?= $url ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                            <i class="bi bi-eye"></i> Ver
                                                        </a>
                                                        <a href="<?= $url ?>" class="btn btn-sm btn-outline-success" download>
                                                            <i class="bi bi-download"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Paginação para Arquivos -->
                            <?php if (isset($totalResultadosArquivos) && ceil($totalResultadosArquivos / $limitePorPagina) > 1): ?>
                                <div class="card-footer">
                                    <nav aria-label="Navegação de resultados">
                                        <ul class="pagination justify-content-center mb-0">
                                            <!-- Similar à paginação de issues, ajustada para arquivos -->
                                            <!-- Implementar conforme necessário -->
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Dicas Rápidas -->
    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            <i class="bi bi-lightbulb"></i> Dicas Rápidas
        </div>
        <div class="card-body">
            <h5 class="card-title">Como usar esta página</h5>
            <ul class="mb-0">
                <li>Use o campo de busca para encontrar issues e arquivos no Redmine</li>
                <li>Selecione o tipo de busca: issues, arquivos ou ambos</li>
                <li>Filtre por categoria para resultados mais específicos</li>
                <li>Clique no título de uma issue ou arquivo para visualizá-lo no Redmine</li>
                <li>Use o botão de download para baixar arquivos diretamente</li>
            </ul>
        </div>
    </div>
</div>