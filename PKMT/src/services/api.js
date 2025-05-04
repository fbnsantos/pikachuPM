const API_URL = 'https://criis-projects.inesctec.pt/PK/api/todos.php';

export async function fetchTodos(token) {
  const res = await fetch(API_URL, {
    headers: {
      Authorization: `Bearer ${token}`
    }
  });
  return await res.json();
}

export async function createTodo(token, todo, descritivo = '') {
  // Se todo for uma string, criar um objeto com título
  // Se todo já for um objeto, usá-lo como base
  let todoData = typeof todo === 'string' 
    ? { titulo: todo, descritivo: descritivo }
    : { ...todo };
  
  // Garantir que estado esteja definido
  if (!todoData.estado) {
    todoData.estado = "aberta";
  }
  
  // Definir uma data padrão de 90 dias no futuro se não houver data_limite
  if (!todoData.data_limite) {
    // Criar data atual
    const dataAtual = new Date();
    // Adicionar 90 dias
    dataAtual.setDate(dataAtual.getDate() + 90);
    // Formatar como YYYY-MM-DD (formato aceito pelo MySQL)
    todoData.data_limite = dataAtual.toISOString().split('T')[0];
  }

  console.log("Enviando para API:", todoData);

  try {
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`
      },
      body: JSON.stringify(todoData)
    });
    
    // Tratamento de erro baseado no status
    if (!res.ok) {
      console.error("Erro HTTP:", res.status, res.statusText);
      const errorData = await res.json().catch(() => ({ 
        error: `Erro ${res.status}: ${res.statusText}` 
      }));
      return errorData;
    }
    
    return await res.json();
  } catch (error) {
    console.error("Erro na requisição:", error);
    return { error: error.message || 'Erro na comunicação com o servidor' };
  }
}

export async function updateTodo(token, todo) {
  const res = await fetch(API_URL, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`
    },
    body: JSON.stringify(todo)
  });
  return await res.json();
}

export async function deleteTodo(token, id) {
  const res = await fetch(`${API_URL}?id=${id}`, {
    method: 'DELETE',
    headers: {
      Authorization: `Bearer ${token}`
    }
  });
  return await res.json();
}