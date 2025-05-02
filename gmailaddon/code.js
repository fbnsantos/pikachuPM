/**
 * PKMT-Agile Manager - ToDos Gmail Add-on
 * 
 * Este complemento permite visualizar e gerenciar as tarefas (ToDos) do PKMT-Agile Manager
 * diretamente do Gmail, incluindo a criação de tarefas a partir de emails.
 */

// Chaves para armazenamento de propriedades
var TOKEN_KEY = 'TODO_API_TOKEN';
var TOKEN_VISIBLE_KEY = 'TODO_TOKEN_VISIBLE';
var EXPANDED_TODO_KEY = 'EXPANDED_TODO_ID';

// Configurações do sistema
var CONFIG = {
  API_BASE_URL: "http://criis-projects.inesctec.pt/PK/api/",
  APP_BASE_URL: "http://criis-projects.inesctec.pt/PK/index.php"  // URL corrigida incluindo index.php
};

// ====================== FUNÇÕES DE GESTÃO DE TOKEN ======================

/**
 * Recupera o token armazenado nas propriedades do usuário
 */
function getStoredToken() {
  return PropertiesService.getUserProperties().getProperty(TOKEN_KEY);
}

/**
 * Verifica se o token deve ser exibido ou ocultado
 */
function isTokenVisible() {
  var visible = PropertiesService.getUserProperties().getProperty(TOKEN_VISIBLE_KEY);
  return visible === 'true'; // Converter string para boolean
}

/**
 * Obtém o ID da tarefa expandida (se houver)
 */
function getExpandedTodoId() {
  return PropertiesService.getUserProperties().getProperty(EXPANDED_TODO_KEY);
}

/**
 * Define a tarefa expandida
 */
function setExpandedTodoId(todoId) {
  if (todoId) {
    PropertiesService.getUserProperties().setProperty(EXPANDED_TODO_KEY, todoId);
  } else {
    PropertiesService.getUserProperties().deleteProperty(EXPANDED_TODO_KEY);
  }
}

/**
 * Alterna a visibilidade do token
 */
function toggleTokenVisibility(e) {
  var currentVisibility = isTokenVisible();
  PropertiesService.getUserProperties().setProperty(TOKEN_VISIBLE_KEY, (!currentVisibility).toString());
  
  return CardService.newActionResponseBuilder()
    .setNavigation(CardService.newNavigation().popToRoot().updateCard(buildAddOn(e)))
    .build();
}

/**
 * Salva o token nas propriedades do usuário
 */
function saveToken(e) {
  var token = e.formInput.token;
  if (token) {
    PropertiesService.getUserProperties().setProperty(TOKEN_KEY, token);
    // Ocultar o token após salvá-lo
    PropertiesService.getUserProperties().setProperty(TOKEN_VISIBLE_KEY, 'false');
    
    return CardService.newActionResponseBuilder()
      .setNotification(CardService.newNotification().setText("Token salvo com sucesso!"))
      .setNavigation(CardService.newNavigation().popToRoot().updateCard(buildAddOn(e)))
      .build();
  } else {
    return CardService.newActionResponseBuilder()
      .setNotification(CardService.newNotification().setText("Token não pode estar em branco."))
      .build();
  }
}

// ====================== FUNÇÕES DE API ======================

/**
 * Faz uma requisição para a API de ToDos
 */
function makeApiRequest(method, endpoint, data, token) {
  var url = CONFIG.API_BASE_URL + endpoint;
  
  var options = {
    method: method,
    muteHttpExceptions: true,
    headers: {
      'Authorization': 'Bearer ' + token,
      'Content-Type': 'application/json'
    }
  };
  
  if ((method === 'post' || method === 'put') && data) {
    options.payload = JSON.stringify(data);
  }
  
  try {
    var response = UrlFetchApp.fetch(url, options);
    
    return {
      code: response.getResponseCode(),
      content: response.getContentText(),
      success: response.getResponseCode() >= 200 && response.getResponseCode() < 300,
      parsedContent: function() {
        try {
          return JSON.parse(this.content);
        } catch (e) {
          return null;
        }
      }
    };
  } catch (error) {
    Logger.log("Erro na requisição à API: " + error);
    return {
      code: 500,
      content: error.toString(),
      success: false,
      parsedContent: function() { return null; }
    };
  }
}

/**
 * Busca as tarefas do usuário
 */
function fetchTodos() {
  var token = getStoredToken();
  if (!token) return { success: false, todos: [] };
  
  var response = makeApiRequest('get', 'todos.php', null, token);
  
  if (response.success) {
    var data = response.parsedContent();
    return { 
      success: true, 
      todos: (data && data.todos) ? data.todos : [] 
    };
  }
  
  return { 
    success: false, 
    todos: [],
    error: 'Erro (' + response.code + '): ' + response.content
  };
}

/**
 * Atualiza o estado de uma tarefa
 */
function updateTodoStatus(todoId, newStatus) {
  var token = getStoredToken();
  if (!token) return { success: false, message: "Token não encontrado" };
  
  var data = {
    id: parseInt(todoId),
    estado: newStatus
  };
  
  var response = makeApiRequest('put', 'todos.php', data, token);
  
  return {
    success: response.success,
    message: response.success ? "Estado da tarefa atualizado!" : "Erro ao atualizar estado"
  };
}

/**
 * Cria uma nova tarefa
 */
function createTodoFromEmailData(todoData) {
  var token = getStoredToken();
  if (!token) return { success: false, message: "Token não encontrado" };
  
  var response = makeApiRequest('post', 'todos.php', todoData, token);
  
  return {
    success: response.success,
    message: response.success ? "Tarefa criada com sucesso!" : "Erro ao criar tarefa" 
  };
}

// ====================== AÇÕES DE INTERFACE ======================

/**
 * Expande uma tarefa para mostrar detalhes
 */
function expandTodoAction(e) {
  var todoId = e.parameters.todoId;
  setExpandedTodoId(todoId);
  
  return CardService.newActionResponseBuilder()
    .setNavigation(CardService.newNavigation().popToRoot().updateCard(buildAddOn(e)))
    .build();
}

/**
 * Fecha os detalhes de uma tarefa
 */
function collapseTodoAction(e) {
  setExpandedTodoId(null);
  
  return CardService.newActionResponseBuilder()
    .setNavigation(CardService.newNavigation().popToRoot().updateCard(buildAddOn(e)))
    .build();
}

/**
 * Alterna o estado de uma tarefa entre completa/não completa
 */
function toggleTodoCompletionAction(e) {
  var todoId = e.parameters.todoId;
  var currentState = e.parameters.currentState;
  var newState = currentState === 'completada' ? 'aberta' : 'completada';
  
  var result = updateTodoStatus(todoId, newState);
  
  return CardService.newActionResponseBuilder()
    .setNotification(CardService.newNotification().setText(result.message))
    .setNavigation(CardService.newNavigation().popToRoot().updateCard(buildAddOn(e)))
    .build();
}

/**
 * Mostra formulário para criar nova tarefa (genérica ou de email)
 */
function showCreateTodoForm(e) {
  // Verificar se temos um messageId válido
  var messageId = e && e.parameters && e.parameters.messageId ? e.parameters.messageId : '';
  var subject = "";
  var descricao = "";
  
  // Se temos um messageId válido, obter dados do email
  if (messageId) {
    try {
      var message = GmailApp.getMessageById(messageId);
      if (message) {
        subject = message.getSubject();
        var sender = message.getFrom();
        descricao = "Email de: " + sender + "\n\nID: " + messageId;
      }
    } catch (err) {
      Logger.log("Erro ao obter email: " + err);
      // Continuar com formulário vazio
    }
  }
  
  // Criar card de formulário
  var formCard = CardService.newCardBuilder();
  var formSection = CardService.newCardSection()
    .setHeader("Nova Tarefa" + (messageId ? " do Email" : ""));
  
  // Campo de título
  formSection.addWidget(CardService.newTextInput()
    .setFieldName("titulo")
    .setTitle("Título")
    .setValue(subject));
  
  // Campo de descrição
  formSection.addWidget(CardService.newTextInput()
    .setFieldName("descritivo")
    .setTitle("Descrição")
    .setMultiline(true)
    .setValue(descricao));
  
  // Data limite (uma semana a partir de hoje)
  var dataLimite = new Date();
  dataLimite.setDate(dataLimite.getDate() + 7);
  var dataFormatada = Utilities.formatDate(dataLimite, "GMT", "yyyy-MM-dd");
  
  // Usando TextInput para a data
  formSection.addWidget(CardService.newTextInput()
    .setFieldName("data_limite")
    .setTitle("Data Limite (AAAA-MM-DD)")
    .setValue(dataFormatada));
  
  // Estado
  formSection.addWidget(CardService.newSelectionInput()
    .setFieldName("estado")
    .setTitle("Estado")
    .setType(CardService.SelectionInputType.DROPDOWN)
    .addItem("Aberta", "aberta", true)
    .addItem("Em Execução", "em execução", false));
  
  // Botões
  formSection.addWidget(CardService.newTextButton()
    .setText("Cancelar")
    .setOnClickAction(CardService.newAction()
      .setFunctionName("cancelCreateTodo")));
  
  formSection.addWidget(CardService.newTextButton()
    .setText("Criar Tarefa")
    .setOnClickAction(CardService.newAction()
      .setFunctionName("submitCreateTodo")
      .setParameters({messageId: messageId})));
  
  formCard.addSection(formSection);
  
  return CardService.newActionResponseBuilder()
    .setNavigation(CardService.newNavigation().pushCard(formCard.build()))
    .build();
}

/**
 * Cancela a criação de uma tarefa
 */
function cancelCreateTodo(e) {
  return CardService.newActionResponseBuilder()
    .setNavigation(CardService.newNavigation().popCard())
    .build();
}

/**
 * Submete o formulário para criar uma tarefa
 */
function submitCreateTodo(e) {
  var formInputs = e.formInput;
  var messageId = e.parameters && e.parameters.messageId ? e.parameters.messageId : '';
  
  var todoData = {
    titulo: formInputs.titulo,
    descritivo: formInputs.descritivo,
    data_limite: formInputs.data_limite,
    estado: formInputs.estado
  };
  
  // Adicionar referência ao email apenas se for um messageId válido
  if (messageId && messageId.trim() !== '') {
    todoData.todo_issue = "Email ID: " + messageId;
  }
  
  var result = createTodoFromEmailData(todoData);
  
  return CardService.newActionResponseBuilder()
    .setNotification(CardService.newNotification().setText(result.message))
    .setNavigation(CardService.newNavigation().popToRoot().updateCard(buildAddOn(e)))
    .build();
}

// ====================== CONSTRUÇÃO DA INTERFACE ======================

/**
 * Constrói o card principal do complemento
 */
function buildAddOn(e) {
  var card = CardService.newCardBuilder();
  
  // === SEÇÃO DE CONFIGURAÇÃO ===
  var configSection = CardService.newCardSection()
    .setHeader("Configuração");
  
  var storedToken = getStoredToken();
  var tokenVisible = isTokenVisible();
  
  // Mostrar interface baseada no estado do token e sua visibilidade
  if (storedToken && !tokenVisible) {
    // Token está armazenado mas ocultado - mostrar asteriscos
    configSection.addWidget(CardService.newTextParagraph()
      .setText("Token configurado: ••••••••••••••••"));
    
    // Botão para mostrar o token
    configSection.addWidget(CardService.newTextButton()
      .setText("Editar Token")
      .setOnClickAction(CardService.newAction().setFunctionName("toggleTokenVisibility")));
    
  } else {
    // Token não configurado ou visível para edição
    var placeholder = storedToken ? "Token atual" : "Cole seu token da página de ToDos";
    
    // Campo de entrada de token
    var tokenInput = CardService.newTextInput()
      .setFieldName("token")
      .setTitle("Token de Autenticação")
      .setValue(storedToken || "")
      .setHint(placeholder);
    
    // Botão para salvar o token
    var saveButton = CardService.newTextButton()
      .setText("Salvar Token")
      .setOnClickAction(CardService.newAction().setFunctionName("saveToken"));
    
    configSection.addWidget(tokenInput);
    configSection.addWidget(saveButton);
    
    // Botão para esconder se o token já existe e está visível
    if (storedToken && tokenVisible) {
      configSection.addWidget(CardService.newTextButton()
        .setText("Cancelar edição")
        .setOnClickAction(CardService.newAction().setFunctionName("toggleTokenVisibility")));
    }
  }
  
  // === SEÇÃO DE TAREFAS ===
  var todosSection = CardService.newCardSection()
    .setHeader("Suas Tarefas");
  
  // Adicionar botão para criar nova tarefa no topo da seção
  if (storedToken) {
    todosSection.addWidget(CardService.newTextButton()
      .setText("➕ Nova Tarefa")
      .setTextButtonStyle(CardService.TextButtonStyle.FILLED)
      .setBackgroundColor("#3a763a")
      .setOnClickAction(CardService.newAction()
        .setFunctionName("showCreateTodoForm")));
  }
  
  // Se não tem token, mostrar mensagem informativa
  if (!storedToken) {
    todosSection.addWidget(CardService.newTextParagraph()
      .setText("Por favor, configure seu token de autenticação."));
    
    todosSection.addWidget(CardService.newTextButton()
      .setText("Abrir PKMT-Agile Manager")
      .setOpenLink(CardService.newOpenLink()
        .setUrl(CONFIG.APP_BASE_URL + "?tab=todos")
        .setOpenAs(CardService.OpenAs.OVERLAY)));
  } 
  else {
    // Buscar as tarefas
    var result = fetchTodos();
    
    if (result.success) {
      var todos = result.todos;
      
      // Filtrar tarefas não completadas
      var activeTodos = [];
      for (var i = 0; i < todos.length; i++) {
        if (todos[i].estado !== 'completada') {
          activeTodos.push(todos[i]);
        }
      }
      
      if (activeTodos.length === 0) {
        todosSection.addWidget(CardService.newTextParagraph()
          .setText("Você não tem tarefas ativas no momento."));
      } else {
        // Contador
        todosSection.addWidget(CardService.newTextParagraph()
          .setText("Total: " + activeTodos.length + " tarefa(s)"));
        
        // ID da tarefa expandida (se houver)
        var expandedTodoId = getExpandedTodoId();
        
        // Listar tarefas ativas
        for (var j = 0; j < activeTodos.length; j++) {
          var todo = activeTodos[j];
          var todoId = todo.id.toString();
          var isExpanded = (expandedTodoId === todoId);
          
          // Criar uma linha com checkbox e título da tarefa
          var todoRow = CardService.newDecoratedText()
            .setText(todo.titulo)
            .setWrapText(true)
            .setBottomLabel(todo.estado)
            .setOnClickAction(CardService.newAction()
              .setFunctionName(isExpanded ? "collapseTodoAction" : "expandTodoAction")
              .setParameters({todoId: todoId}));
          
          // Adicionar checkbox para marcar como concluída
          todoRow.setSwitchControl(CardService.newSwitch()
            .setFieldName("complete_" + todoId)
            .setValue("true")
            .setSelected(todo.estado === 'completada')
            .setOnChangeAction(CardService.newAction()
              .setFunctionName("toggleTodoCompletionAction")
              .setParameters({
                todoId: todoId,
                currentState: todo.estado
              })));
          
          todosSection.addWidget(todoRow);
          
          // Se esta tarefa está expandida, mostrar detalhes
          if (isExpanded) {
            // Formatação da data
            var dataLimite = "Sem prazo definido";
            if (todo.data_limite) {
              var date = new Date(todo.data_limite);
              dataLimite = Utilities.formatDate(date, "GMT", "dd/MM/yyyy");
            }
            
            // Extrair email ID se disponível
            var emailId = null;
            if (todo.todo_issue && todo.todo_issue.indexOf("Email ID:") !== -1) {
              emailId = todo.todo_issue.replace("Email ID:", "").trim();
            }
            
            // Detalhes da tarefa
            var detailsText = "<b>Detalhes da Tarefa</b>\n" +
                            "Data limite: " + dataLimite + "\n" +
                            "Responsável: " + (todo.responsavel_nome || "Não atribuído") + "\n" +
                            "Descrição: " + (todo.descritivo || "Sem descrição");
            
            todosSection.addWidget(CardService.newTextParagraph()
              .setText(detailsText));
            
            // Botão para abrir na plataforma
            todosSection.addWidget(CardService.newTextButton()
              .setText("Ver no PKMT-Agile Manager")
              .setOpenLink(CardService.newOpenLink()
                .setUrl(CONFIG.APP_BASE_URL + "?tab=todos&todo=" + todoId)
                .setOpenAs(CardService.OpenAs.OVERLAY)));
            
            // Botão para visualizar email original (se aplicável)
            if (emailId) {
              try {
                // Verificar se o email existe
                var message = GmailApp.getMessageById(emailId);
                if (message) {
                  todosSection.addWidget(CardService.newTextButton()
                    .setText("Ver Email Original")
                    .setOpenLink(CardService.newOpenLink()
                      // URL corrigida para abrir o email diretamente
                      .setUrl("https://mail.google.com/mail/#inbox/" + emailId)
                      .setOpenAs(CardService.OpenAs.FULL_SIZE)));
                }
              } catch (err) {
                // Ignora o botão se o email não puder ser encontrado
                Logger.log("Email não encontrado: " + emailId);
              }
            }
            
            // Adicionar um separador após os detalhes
            todosSection.addWidget(CardService.newDivider());
          }
        }
        
        // Removida a seção que mostrava tarefas completadas
      }
    } else {
      // Erro ao buscar tarefas
      todosSection.addWidget(CardService.newTextParagraph()
        .setText("Erro ao buscar tarefas. Verifique seu token."));
    }
  }
  
  // Adicionar botão para criar tarefa do email atual (apenas se visualizando um email)
  if (e && e.messageMetadata && e.messageMetadata.messageId) {
    todosSection.addWidget(CardService.newDivider());
    todosSection.addWidget(CardService.newTextButton()
      .setText("Criar ToDo deste email")
      .setOnClickAction(CardService.newAction()
        .setFunctionName("showCreateTodoForm")
        .setParameters({messageId: e.messageMetadata.messageId})));
  }
  
  // Botão para abrir sistema completo
  todosSection.addWidget(CardService.newTextButton()
    .setText("Abrir PKMT-Agile Manager")
    .setOpenLink(CardService.newOpenLink()
      .setUrl(CONFIG.APP_BASE_URL + "?tab=todos")
      .setOpenAs(CardService.OpenAs.FULL_SIZE)));
  
  // Adicionar seções ao card
  card.addSection(configSection);
  card.addSection(todosSection);
  
  return card.build();
}

// ====================== PONTOS DE ENTRADA ======================

/**
 * Função chamada quando o add-on é aberto na homepage do Gmail
 */
function onHomepage(e) {
  return buildAddOn(e);
}

/**
 * Função chamada quando o add-on é aberto no contexto de uma mensagem
 */
function onGmailMessage(e) {
  return buildAddOn(e);
}