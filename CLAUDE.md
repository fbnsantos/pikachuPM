# pikachuPM — Referência para Claude

Projecto PHP 8+ de gestão de projectos/equipa. Sem framework; tudo em PHP puro, PDO/MySQL, Bootstrap 5, vanilla JS.

---

## Estrutura de ficheiros

```
pikachuPM/
├── index.php               # Router principal — carrega tab via include
├── config.php              # Credenciais DB ($db_host, $db_name, $db_user, $db_pass)
├── login.php / logout.php  # Autenticação
├── setup_database.php      # Script de criação de todas as tabelas base
└── tabs/
    ├── rh_imputacao.php    # Alocação RH (~3300 linhas); tabelas criadas inline no topo
    ├── projectos.php       # Gestão de projectos
    ├── todos.php           # Tarefas/Kanban
    ├── sprints.php         # Sprints
    ├── financeiro.php      # Financeiro
    ├── equipa.php          # Equipa
    ├── dashboard.php       # Dashboard
    └── prototypes/
        └── prototypesv2.php  # Protótipos (~6500 linhas); tabelas criadas inline no topo
```

### Como index.php carrega um tab

```php
$tab = $_GET['tab'] ?? 'dashboard';
include __DIR__ . '/tabs/' . $tab . '.php';   // e.g. tabs/prototypes/prototypesv2.php
```

O utilizador está sempre autenticado (sessão PHP); `$_SESSION['user_id']` e `$_SESSION['username']` estão sempre disponíveis.

---

## Padrões de código

### Migrações inline

Cada tab usa `SHOW COLUMNS LIKE` para adicionar colunas quando ainda não existem:

```php
try {
    if (!$pdo->query("SHOW COLUMNS FROM tabela LIKE 'nova_coluna'")->fetch())
        $pdo->exec("ALTER TABLE tabela ADD COLUMN nova_coluna TIPO DEFAULT valor");
} catch (PDOException $e) {}
```

### AJAX — módulo RH (`rh_imputacao.php`)

Gate no topo do ficheiro via `in_array($action, [...])`. Para adicionar uma nova acção AJAX, adicionar o nome ao array:

```php
$is_json = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || in_array($action, ['get_campaign_data','get_resumo_pk','get_resumo_proj', /* + novo */], true)
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
```

### AJAX — módulo Protótipos (`prototypesv2.php`)

POST com campo `_ajax=1`. Em cada `case` do switch:

```php
case 'nome_accao':
    $protoId = (int)($_POST['prototype_id'] ?? 0);
    // ... lógica DB ...
    if (!empty($_POST['_ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => 1, 'milestones' => loadMilestonesJson($pdo, $protoId)]);
        exit;
    }
    header("Location: ?tab=prototypes/prototypesv2&prototype_id=$protoId#roadmap-section");
    exit;
```

Se a acção não precisa de devolver milestones, basta `json_encode(['ok' => 1])`.

### Helper de milestones

```php
loadMilestonesJson($pdo, $protoId)
// devolve array de milestones com ->stories[] e ->projects[] em cada milestone
```

---

## Estados importantes

| Campo | Valores activos | Valores fechados |
|---|---|---|
| `todos.estado` | `aberta`, `em execução`, `suspensa` | `concluída`, `completada`, `fechada`, `concluida` |
| `user_stories.status` | `open` | `closed` |
| `sprints.estado` | `aberta`, `em execução` | `concluída`, `fechada` |
| `prototypes.estado` | `ativo` | `fechado` |
| `financeiro_transacoes.estado` | `pendente` | `pago`, `cancelado` |

---

## Schema da base de dados

### Autenticação e utilizadores

| Tabela | Colunas chave | Notas |
|---|---|---|
| `user_tokens` | `id`, `user_id`, `username`, `email`, `token`, `role`, `active` | Utilizadores do sistema |
| `admin_users` | `user_id` FK | Marca utilizadores como admin |

### Projectos

| Tabela | Colunas chave | Notas |
|---|---|---|
| `projects` | `id`, `title`, `short_name`, `description`, `status` | Projectos |
| `user_project_access` | `user_id`, `project_id` | Controlo de acesso por projecto |

### Tarefas / Todos

| Tabela | Colunas chave | Notas |
|---|---|---|
| `todos` | `id`, `title`, `description`, `estado`, `project_id`, `assigned_to`, `created_by`, `due_date`, `priority` | Tarefa |
| `project_todos` | `project_id`, `todo_id` | Associação tarefa ↔ projecto |
| `task_checklist` | `id`, `todo_id`, `item_text`, `is_checked`, `position` | Checklist dentro de uma tarefa |
| `task_files` | `id`, `todo_id`, `file_name`, `file_path`, `file_size`, `uploaded_by` | Ficheiros anexos a tarefas |

### User Stories

| Tabela | Colunas chave | Notas |
|---|---|---|
| `user_stories` | `id`, `story_text`, `moscow_priority`, `status`, `completion_percentage`, `story_type` (Story/Bug/Feature), `created_by`, `closed_at` | Story. `status` = open/closed |
| `project_stories` | `project_id`, `story_id` | Associação story ↔ projecto |
| `user_story_tasks` | `story_id`, `task_id` | Associação story ↔ todo (via `todos.id`) |
| `story_attachments` | `id`, `story_id`, `file_name`, `file_path`, `file_size`, `uploaded_by` | Ficheiros nas stories |

### Sprints

| Tabela | Colunas chave | Notas |
|---|---|---|
| `sprints` | `id`, `nome`, `estado`, `responsavel_id`, `start_date`, `end_date` | Sprint |
| `sprint_stories` | `sprint_id`, `story_id` | Associação sprint ↔ story |
| `user_story_sprints` | `story_id`, `sprint_id` | Criado inline em prototypesv2.php; mesmo fim que sprint_stories |

### Protótipos

| Tabela | Colunas chave | Notas |
|---|---|---|
| `prototypes` | `id`, `name`, `parent_id`, `responsavel_id`, `estado` (ativo/fechado), `prototype_type` | Protótipo |
| `prototype_members` | `prototype_id`, `user_id`, `role` | Equipa do protótipo |
| `prototype_media` | `id`, `prototype_id`, `tipo` (link/ficheiro/imagem), `url`, `label` | Recursos anexados |
| `prototype_notes` | `id`, `prototype_id`, `user_id`, `note_text` | Notas livres |
| `prototype_note_images` | `id`, `note_id`, `file_path` | Imagens dentro de notas |
| `prototype_versions` | `id`, `prototype_id`, `version_name`, `released_at`, `notes` | Versões de release |
| `version_stories` | `version_id`, `story_id` | Stories incluídas numa versão |
| `story_tasks` | `story_id`, `todo_id` | Associação story ↔ tarefa (contexto protótipo) |

#### Roadmap de protótipos

| Tabela | Colunas chave | Notas |
|---|---|---|
| `prototype_milestones` | `id`, `prototype_id`, `title`, `description`, `target_date`, `color`, `completed` | Milestone no roadmap |
| `milestone_stories` | `milestone_id`, `story_id` | Stories associadas ao milestone |
| `milestone_projects` | `milestone_id`, `project_id` | Projectos associados ao milestone |

#### Inquéritos externos (parceiros)

| Tabela | Colunas chave | Notas |
|---|---|---|
| `prototype_surveys` | `id`, `prototype_id`, `token`, `title`, `intro_text`, `video_links`, `questions`, `is_active` | Inquérito público por token |
| `survey_responses` | `id`, `survey_id`, `respondent_name`, `respondent_email`, `answers`, `converted_to_story`, `story_id` | Resposta ao inquérito |

#### GitLab (integração opcional)

| Tabela | Colunas chave | Notas |
|---|---|---|
| `prototype_gitlab_tokens` | `user_id`, `prototype_id`, `encrypted_token`, `gitlab_base_url` | Token GitLab cifrado |
| `gitlab_cache` | `user_id`, `prototype_id`, `cache_key`, `data`, `expires_at` | Cache da API GitLab |
| `gitlab_project_paths` | `user_id`, `prototype_id`, `project_path` | Path do projecto no GitLab |

### RH / Imputação (tabelas criadas inline em `rh_imputacao.php`)

| Tabela | Colunas chave | Notas |
|---|---|---|
| `rh_campaigns` | `id`, `plan_name`, `type` (contratados/bolseiros), `months_json`, `created_by` | Plano de RH; `months_json` é array de strings de mês |
| `rh_persons` | `id`, `campaign_id`, `rh_code`, `full_name`, `tipo_ligacao`, `user_token_id`, `sort_order` | Pessoa no plano |
| `rh_person_projects` | `id`, `person_id`, `project_code`, `project_name`, `pm_orc`, `pm_exe`, `project_id`, `sort_order` | Projecto associado à pessoa |
| `rh_monthly_alloc` | PK(`person_project_id`, `year`, `month`), `percentage` | % de alocação mensal |

### Comercial / CRM

| Tabela | Colunas chave | Notas |
|---|---|---|
| `comercial_contactos` | `id`, `tipo` (cliente/fornecedor/parceiro/outro), `empresa`, `pessoa_contacto`, `email`, `telefone`, `nif`, `estado` (ativo/inativo/potencial) | Contacto CRM |
| `comercial_interacoes` | `id`, `contacto_id`, `tipo` (reuniao/email/telefone/proposta/negociacao/outro), `assunto`, `data_interacao`, `proximo_followup`, `user_id` | Interacção com contacto |

### Financeiro

| Tabela | Colunas chave | Notas |
|---|---|---|
| `financeiro_categorias` | `id`, `nome`, `tipo` (receita/despesa), `cor`, `icone`, `ativa` | Categoria financeira |
| `financeiro_transacoes` | `id`, `tipo`, `categoria_id`, `valor`, `descricao`, `data_transacao`, `estado` (pendente/pago/cancelado), `projeto_id` | Transacção |
| `financeiro_orcamentos` | `id`, `ano`, `categoria_id`, `valor_mensal` | Orçamento mensal por categoria e ano |
| `financeiro_emprestimos` | `id`, `user_id`, `valor`, `descricao`, `data_emprestimo`, `estado` (pendente/reembolsado/parcial), `valor_reembolsado` | Empréstimo a colaborador |
| `financeiro_reembolsos` | `id`, `emprestimo_id`, `valor`, `data_reembolso`, `metodo_pagamento` | Reembolso parcial/total de empréstimo |

---

## Resumo funcional por módulo

### `rh_imputacao.php` — Alocação RH
Gestão de planos de afectação de RH (contratados e bolseiros) a projectos. Importa dados via Excel (SheetJS no cliente), guarda em `rh_campaigns` + `rh_persons` + `rh_person_projects` + `rh_monthly_alloc`. Exibe grelha interactiva de % de alocação mensal. Tem resumos por pessoa e por projecto. AJAX via GET `?action=`.

### `projectos.php` — Gestão de Projectos
CRUD de projectos. Associação de stories, tarefas e membros. Vista de roadmap própria (diferente do roadmap de protótipos).

### `prototypes/prototypesv2.php` — Protótipos
Gestão de protótipos (produtos/iniciativas). Cada protótipo tem:
- Stories (user stories com MoSCoW, tipo, % conclusão)
- Roadmap com milestones (datas, cor, marcação de concluído)
- Notas com imagens
- Media (links/ficheiros)
- Versões de release
- Inquéritos externos (link público por token)
- Integração GitLab (opcional)
AJAX via POST `_ajax=1`. Sem recarregamento de página em operações de milestone/story.

### `todos.php` — Tarefas/Kanban
Vista Kanban por estado. Tarefas com checklist, ficheiros, assignee. `todos.estado` tem valores activos e fechados — ver tabela de estados acima.

### `sprints.php` — Sprints
Lifecycle: planning → execution → review → retrospective. Stories e tarefas associadas a sprints.

### `financeiro.php` — Financeiro
Transacções receita/despesa por categoria. Orçamentos mensais. Gestão de empréstimos a colaboradores e reembolsos.

### `equipa.php` — Equipa
Vista de membros, funções e disponibilidade.

### `dashboard.php` — Dashboard
Agregação de métricas de projectos, sprints e tarefas.

---

## Notas de ambiente

- **PWA / Android Chrome**: nunca usar `window.location.reload()` para forçar update de SW. Usar navegação com `?_sw=timestamp`.
- **PHP sem binário local**: não é possível validar sintaxe PHP com `php -l` na máquina de desenvolvimento.
- **Credenciais**: `config.php` não está no repositório. O schema está em `setup_database.php`.
