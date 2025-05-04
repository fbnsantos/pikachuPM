const API_URL = 'https://criis-projects.inesctec.pt/PK/api/todos.php';

export async function fetchTodos(token) {
  const res = await fetch(API_URL, {
    headers: {
      Authorization: `Bearer ${token}`
    }
  });
  return await res.json();
}

export async function createTodo(token, todo) {
  const res = await fetch(API_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`
    },
    body: JSON.stringify(todo)
  });
  return await res.json();
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