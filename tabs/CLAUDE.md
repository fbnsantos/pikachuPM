# Tabs — referência rápida

Ver `../CLAUDE.md` (ao lado de `index.php`) para schema completo, padrões AJAX e resumo de módulos.

## Ficheiros desta pasta

| Ficheiro | Módulo | Notas |
|---|---|---|
| `rh_imputacao.php` | Alocação RH | ~3300 linhas; AJAX via GET `?action=`; gate em `$is_json` in_array |
| `projectos.php` | Gestão de projectos | Deliverables, milestones, roadmap próprio |
| `prototypes/prototypesv2.php` | Protótipos | ~6500 linhas; AJAX via POST `_ajax=1` |
| `todos.php` | Tarefas/Kanban | Estados: aberta/em execução/suspensa/concluída/completada/fechada |
| `sprints.php` | Sprints | Lifecycle: planning→execution→review→retrospective |
| `dashboard.php` | Dashboard | Agregações de métricas |
| `financeiro.php` | Financeiro | Transacções, orçamentos, empréstimos |
| `equipa.php` | Equipa | Membros, funções, disponibilidade |

## Referências rápidas

### Adicionar acção AJAX GET ao módulo RH
Localizar o `in_array` em `rh_imputacao.php` perto do topo e adicionar o novo nome de acção.

### Adicionar acção AJAX POST a prototypesv2.php
No `switch ($_POST['action'])`, adicionar ao final do case:
```php
if (!empty($_POST['_ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => 1]);
    exit;
}
header("Location: ?tab=prototypes/prototypesv2&prototype_id=$protoId");
exit;
```

### DB helper para milestones (prototypesv2.php)
```php
loadMilestonesJson($pdo, $protoId)
// devolve array de milestones com ->stories[] e ->projects[] para cada uma
```

### Estados de tarefas (todos.estado)
Activos: `aberta`, `em execução`, `suspensa`
Fechados: `concluída`, `completada`, `fechada`

### Verificar se story está fechada (user_stories.status)
`status = 'closed'` (só dois valores: `open` / `closed`)

### Padrão de migração de coluna
```php
try {
    if (!$pdo->query("SHOW COLUMNS FROM tabela LIKE 'nova_coluna'")->fetch())
        $pdo->exec("ALTER TABLE tabela ADD COLUMN nova_coluna TIPO DEFAULT valor");
} catch (PDOException $e) {}
```
Colocar no bloco de setup no topo do ficheiro, antes de qualquer HTML.
