<?php

include __DIR__ . '/milestone_functions.php'
/**
 * Função para obter milestones filtradas por usuário responsável
 * 
 * @param int|null $user_id ID do usuário para filtrar (null = todas as milestones)
 * @return array Array de milestones filtradas ou erro
 */
function getMilestonesByUser($user_id = null) {
    // Obter todas as milestones primeiro
    $milestones = getMilestones();
    
    if (isset($milestones['error'])) {
        return $milestones;
    }
    
    // Se não há filtro de usuário, retornar todas
    if ($user_id === null) {
        return $milestones;
    }
    
    // Filtrar milestones pelo usuário responsável
    $filtered_milestones = [];
    
    foreach ($milestones as $milestone) {
        // Verificar se a milestone tem um responsável atribuído
        if (isset($milestone['assigned_to']) && isset($milestone['assigned_to']['id'])) {
            // Comparar com o ID do usuário do Redmine
            if ($milestone['assigned_to']['id'] == $user_id) {
                $filtered_milestones[] = $milestone;
            }
        }
    }
    
    return $filtered_milestones;
}

/**
 * Função para mapear usuários locais para usuários do Redmine
 * 
 * @param mysqli $db Conexão com o banco de dados
 * @return array Array de mapeamento [local_user_id => redmine_user_id]
 */
function getUserMapping($db) {
    $mapping = [];
    
    try {
        // Obter usuários do Redmine
        $redmine_users = getUsers();
        
        if (isset($redmine_users['error']) || empty($redmine_users)) {
            error_log("Erro ao obter usuários do Redmine: " . (isset($redmine_users['error']) ? $redmine_users['error'] : 'Lista vazia'));
            return $mapping;
        }
        
        // Obter usuários locais
        $stmt = $db->prepare('SELECT user_id, username FROM user_tokens');
        $stmt->execute();
        $result = $stmt->get_result();
        
        $local_users = [];
        while ($row = $result->fetch_assoc()) {
            $local_users[strtolower($row['username'])] = $row['user_id'];
        }
        $stmt->close();
        
        // Criar mapeamento baseado no username
        foreach ($redmine_users as $redmine_user) {
            $redmine_username = '';
            
            // Tentar diferentes campos para o username
            if (isset($redmine_user['login'])) {
                $redmine_username = strtolower($redmine_user['login']);
            } elseif (isset($redmine_user['name'])) {
                $redmine_username = strtolower($redmine_user['name']);
            } elseif (isset($redmine_user['firstname']) && isset($redmine_user['lastname'])) {
                $redmine_username = strtolower($redmine_user['firstname'] . '.' . $redmine_user['lastname']);
            }
            
            // Se encontrou correspondência
            if (!empty($redmine_username) && isset($local_users[$redmine_username])) {
                $mapping[$local_users[$redmine_username]] = $redmine_user['id'];
                error_log("Mapeamento criado: usuário local {$local_users[$redmine_username]} -> Redmine {$redmine_user['id']} ($redmine_username)");
            }
        }
        
    } catch (Exception $e) {
        error_log("Erro ao criar mapeamento de usuários: " . $e->getMessage());
    }
    
    return $mapping;
}

/**
 * Função melhorada para sincronizar milestones com filtro de usuário
 * 
 * @param mysqli $db Conexão com o banco de dados
 * @param int $current_user_id ID do usuário atual (autor das sincronizações)
 * @param int|null $filter_user_id ID do usuário para filtrar milestones (null = todas)
 * @return int|false Número de milestones sincronizadas ou false em caso de erro
 */
function syncMilestonesFromRedmineByUser($db, $current_user_id, $filter_user_id = null) {
    try {
        // Criar mapeamento de usuários
        $user_mapping = getUserMapping($db);
        
        // Se há filtro de usuário, verificar se temos mapeamento para ele
        $redmine_user_id = null;
        if ($filter_user_id !== null) {
            if (isset($user_mapping[$filter_user_id])) {
                $redmine_user_id = $user_mapping[$filter_user_id];
                error_log("Filtrando milestones para usuário local $filter_user_id (Redmine ID: $redmine_user_id)");
            } else {
                error_log("Não foi possível mapear o usuário local $filter_user_id para um usuário do Redmine");
                // Retornar 0 ao invés de false para indicar que a operação foi bem-sucedida, mas sem resultados
                return 0;
            }
        }
        
        // Buscar milestones filtradas
        $milestones = getMilestonesByUser($redmine_user_id);
        
        if (isset($milestones['error'])) {
            error_log("Erro ao buscar milestones: " . $milestones['error']);
            return false;
        }
        
        $synced_count = 0;
        
        foreach ($milestones as $milestone) {
            // Verificar se já existe como tarefa
            $check_stmt = $db->prepare('SELECT id FROM todos WHERE redmine_milestone_id = ? AND is_milestone = 1');
            $check_stmt->bind_param('i', $milestone['id']);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            // Determinar responsável local
            $local_responsavel = $current_user_id; // Default para o usuário atual
            if (isset($milestone['assigned_to']) && isset($user_mapping[$milestone['assigned_to']['id']])) {
                // Usar o mapeamento reverso
                foreach ($user_mapping as $local_id => $redmine_id) {
                    if ($redmine_id == $milestone['assigned_to']['id']) {
                        $local_responsavel = $local_id;
                        break;
                    }
                }
            }
            
            // Determinar estado baseado no status da milestone
            $estado = 'aberta';
            if (isset($milestone['status'])) {
                switch ($milestone['status']['id']) {
                    case 5: // Fechado
                        $estado = 'completada';
                        break;
                    case 2: // Em progresso
                        $estado = 'em execução';
                        break;
                    case 3: // Resolvido/Pausa
                        $estado = 'suspensa';
                        break;
                    default:
                        $estado = 'aberta';
                }
            }
            
            // Preparar descrição com informações da milestone
            $descricao = "Milestone do Redmine";
            if (isset($milestone['task_stats'])) {
                $stats = $milestone['task_stats'];
                $descricao .= "\n\nProgresso: " . $stats['completion'] . "% concluído";
                $descricao .= "\nTarefas: " . $stats['total'] . " total";
                $descricao .= " (" . $stats['closed']['count'] . " fechadas, ";
                $descricao .= $stats['in_progress']['count'] . " em execução, ";
                $descricao .= $stats['backlog']['count'] . " em backlog)";
            }
            
            // Adicionar informações de deadline
            if (isset($milestone['days_remaining'])) {
                $descricao .= "\n\nDeadline: " . $milestone['deadline_text'];
            }
            
            if (!$existing) {
                // Inserir nova milestone como tarefa
                $insert_stmt = $db->prepare('
                    INSERT INTO todos (
                        titulo, descritivo, data_limite, autor, responsavel, 
                        estado, is_milestone, redmine_milestone_id
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                ');
                
                $insert_stmt->bind_param(
                    'sssiisi',
                    $milestone['subject'],
                    $descricao,
                    $milestone['due_date'] ?? null,
                    $current_user_id,
                    $local_responsavel,
                    $estado,
                    $milestone['id']
                );
                
                if ($insert_stmt->execute()) {
                    $synced_count++;
                    error_log("Milestone inserida: {$milestone['subject']} (ID: {$milestone['id']})");
                } else {
                    error_log("Erro ao inserir milestone {$milestone['id']}: " . $db->error);
                }
                $insert_stmt->close();
            } else {
                // Atualizar milestone existente
                $update_stmt = $db->prepare('
                    UPDATE todos SET 
                        titulo = ?, 
                        descritivo = ?, 
                        data_limite = ?, 
                        responsavel = ?, 
                        estado = ?
                    WHERE redmine_milestone_id = ? AND is_milestone = 1
                ');
                
                $update_stmt->bind_param(
                    'sssisi',
                    $milestone['subject'],
                    $descricao,
                    $milestone['due_date'] ?? null,
                    $local_responsavel,
                    $estado,
                    $milestone['id']
                );
                
                if ($update_stmt->execute()) {
                    $synced_count++;
                    error_log("Milestone atualizada: {$milestone['subject']} (ID: {$milestone['id']})");
                } else {
                    error_log("Erro ao atualizar milestone {$milestone['id']}: " . $db->error);
                }
                $update_stmt->close();
            }
        }
        
        return $synced_count;
        
    } catch (Exception $e) {
        error_log("Erro ao sincronizar milestones: " . $e->getMessage());
        return false;
    }
}

/**
 * Função para obter estatísticas de milestones por usuário
 * 
 * @param mysqli $db Conexão com o banco de dados
 * @param int|null $user_id ID do usuário (null = todos os usuários)
 * @return array Estatísticas das milestones
 */
function getMilestoneStatsByUser($db, $user_id = null) {
    $sql = 'SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = "aberta" THEN 1 ELSE 0 END) as abertas,
                SUM(CASE WHEN estado = "em execução" THEN 1 ELSE 0 END) as em_execucao,
                SUM(CASE WHEN estado = "suspensa" THEN 1 ELSE 0 END) as suspensas,
                SUM(CASE WHEN estado = "completada" THEN 1 ELSE 0 END) as completadas,
                COUNT(CASE WHEN data_limite < CURDATE() AND estado != "completada" THEN 1 END) as vencidas
            FROM todos 
            WHERE is_milestone = 1';
    
    $params = [];
    $types = '';
    
    if ($user_id !== null) {
        $sql .= ' AND responsavel = ?';
        $params[] = $user_id;
        $types .= 'i';
    }
    
    $stmt = $db->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return [
        'total' => (int)$result['total'],
        'abertas' => (int)$result['abertas'],
        'em_execucao' => (int)$result['em_execucao'],
        'suspensas' => (int)$result['suspensas'],
        'completadas' => (int)$result['completadas'],
        'vencidas' => (int)$result['vencidas'],
        'completion_rate' => $result['total'] > 0 ? round(($result['completadas'] / $result['total']) * 100, 1) : 0
    ];
}

/**
 * Função para limpar milestones órfãs (que não existem mais no Redmine)
 * 
 * @param mysqli $db Conexão com o banco de dados
 * @return int Número de milestones removidas
 */
function cleanOrphanMilestones($db) {
    try {
        // Obter todas as milestones do Redmine
        $redmine_milestones = getMilestones();
        
        if (isset($redmine_milestones['error'])) {
            error_log("Erro ao obter milestones do Redmine para limpeza: " . $redmine_milestones['error']);
            return 0;
        }
        
        // Criar array com IDs das milestones existentes no Redmine
        $existing_milestone_ids = [];
        foreach ($redmine_milestones as $milestone) {
            $existing_milestone_ids[] = $milestone['id'];
        }
        
        // Se não há milestones no Redmine, não remover nada (por segurança)
        if (empty($existing_milestone_ids)) {
            error_log("Nenhuma milestone encontrada no Redmine. Não removendo milestones locais por segurança.");
            return 0;
        }
        
        // Obter milestones locais que não existem mais no Redmine
        $placeholders = str_repeat('?,', count($existing_milestone_ids) - 1) . '?';
        $sql = "SELECT id, redmine_milestone_id, titulo FROM todos 
                WHERE is_milestone = 1 
                AND redmine_milestone_id NOT IN ($placeholders)";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($existing_milestone_ids)), ...$existing_milestone_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $orphan_milestones = [];
        while ($row = $result->fetch_assoc()) {
            $orphan_milestones[] = $row;
        }
        $stmt->close();
        
        // Remover milestones órfãs
        $removed_count = 0;
        foreach ($orphan_milestones as $orphan) {
            $delete_stmt = $db->prepare('DELETE FROM todos WHERE id = ?');
            $delete_stmt->bind_param('i', $orphan['id']);
            
            if ($delete_stmt->execute()) {
                $removed_count++;
                error_log("Milestone órfã removida: {$orphan['titulo']} (Local ID: {$orphan['id']}, Redmine ID: {$orphan['redmine_milestone_id']})");
            } else {
                error_log("Erro ao remover milestone órfã ID {$orphan['id']}: " . $db->error);
            }
            $delete_stmt->close();
        }
        
        return $removed_count;
        
    } catch (Exception $e) {
        error_log("Erro ao limpar milestones órfãs: " . $e->getMessage());
        return 0;
    }
}
?>