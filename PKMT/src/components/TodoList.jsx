import { useEffect, useState } from 'react';
import { fetchTodos } from '../services/api';

export default function TodoList({ token }) {
  const [todos, setTodos] = useState([]);

  useEffect(() => {
    fetchTodos(token).then(data => setTodos(data.todos || []));
  }, [token]);

  return (
    <div>
      <h2>Minhas Tarefas</h2>
      <ul>
        {todos.map(todo => (
          <li key={todo.id}>
            {todo.titulo} - <em>{todo.estado}</em>
          </li>
        ))}
      </ul>
    </div>
  );
}