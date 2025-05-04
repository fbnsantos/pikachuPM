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
  // Se todo já for um objeto, usá-lo diretamente
  const todoData = typeof todo === 'string' 
    ? {
        titulo: todo,
        descritivo: descritivo,
        data_limite: "2025-05-30",
        estado: "aberta"
      }
    : todo;

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
    
    // Verificar se a resposta foi bem-sucedida
    if (!res.ok) {
      const errorData = await res.json();
      console.error("Erro da API:", errorData);
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