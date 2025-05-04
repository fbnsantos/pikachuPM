import { useEffect, useState } from 'react';
import { fetchTodos, createTodo, deleteTodo, updateTodo } from '../services/api';

export default function TodoList({ token }) {
  const [todos, setTodos] = useState([]);
  const [newTodo, setNewTodo] = useState('');

  const loadTodos = () => {
    fetchTodos(token).then(data => setTodos(data.todos || []));
  };

  useEffect(() => {
    loadTodos();
  }, [token]);

  const handleAdd = () => {
    if (!newTodo.trim()) return;
    createTodo(token, { titulo: newTodo }).then(() => {
      setNewTodo('');
      loadTodos();
    });
  };

  const handleDelete = (id) => {
    deleteTodo(token, id).then(() => loadTodos());
  };

  const toggleEstado = (todo) => {
    const nextEstado = todo.estado === 'aberta' ? 'completada' : 'aberta';
    updateTodo(token, { id: todo.id, estado: nextEstado }).then(() => loadTodos());
  };

  return (
    <div>
      <h2>Minhas Tarefas</h2>
      <input
        value={newTodo}
        onChange={(e) => setNewTodo(e.target.value)}
        placeholder="Nova tarefa"
      />
      <button onClick={handleAdd}>Adicionar</button>
      <ul>
        {todos.map(todo => (
          <li key={todo.id}>
            <strong>{todo.titulo}</strong> - <em>{todo.estado}</em>
            <button onClick={() => toggleEstado(todo)}>Toggle</button>
            <button onClick={() => handleDelete(todo.id)}>Excluir</button>
          </li>
        ))}
      </ul>
    </div>
  );
}