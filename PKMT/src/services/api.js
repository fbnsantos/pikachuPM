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
  
  // Se não houver data_limite definida ou for null, remova o campo completamente
  // em vez de enviar null ou string vazia
  if (!todoData.data_limite) {
    delete todoData.data_limite;
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