import { useEffect, useState } from 'react';
import { fetchTodos, createTodo, deleteTodo, updateTodo } from '../services/api';
import { FiTrash2, FiCheckCircle, FiCircle } from 'react-icons/fi';

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
    console.log("Vlic:");
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
    <div className="max-w-xl mx-auto p-4">
      <h2 className="text-2xl font-semibold mb-4">Minhas Tarefas</h2>

      <div className="flex gap-2 mb-4">
        <input
          value={newTodo}
          onChange={(e) => setNewTodo(e.target.value)}
          placeholder="Nova tarefa"
          className="flex-1 border border-gray-300 rounded px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
        <button
          onClick={() => {
            console.log("Botão clicado!");
            handleAdd();
          }}
          className="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700"
        >
          Adicionar
        </button>
      </div>

      <ul className="space-y-3">
        {todos.map(todo => (
          <li
            key={todo.id}
            className="flex justify-between items-center bg-white p-4 rounded shadow hover:bg-gray-50 transition"
          >
            <div className="flex items-center gap-3">
              <button onClick={() => toggleEstado(todo)} className="text-blue-600 hover:text-blue-800 text-xl">
                {todo.estado === 'completada' ? <FiCheckCircle /> : <FiCircle />}
              </button>
              <div>
                <h3 className={`font-medium text-lg ${todo.estado === 'completada' ? 'line-through text-gray-400' : ''}`}>
                  {todo.titulo}
                </h3>
                <p className="text-sm text-gray-500">{todo.estado}</p>
              </div>
            </div>
            <button
              onClick={() => handleDelete(todo.id)}
              className="text-red-600 hover:text-red-800 text-xl"
              title="Excluir"
            >
              <FiTrash2 />
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}