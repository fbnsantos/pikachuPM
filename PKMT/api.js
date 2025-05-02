const API_URL = 'https://criis-projects.inesctec.pt/PK/api/todos.php';

export async function fetchTodos(token) {
  const res = await fetch(API_URL, {
    headers: {
      Authorization: `Bearer ${token}`
    }
  });
  return await res.json();
}

// Outras funções: createTodo, updateTodo, deleteTodo...