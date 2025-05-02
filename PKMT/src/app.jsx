import { useState } from 'react';
import TodoList from './components/TodoList';

export default function App() {
  const [token, setToken] = useState(localStorage.getItem('token') || '');

  const handleLogin = () => {
    const inputToken = prompt('Insira seu token:');
    localStorage.setItem('token', inputToken);
    setToken(inputToken);
  };

  return (
    <div>
      <h1>ToDo PWA</h1>
      {!token ? (
        <button onClick={handleLogin}>Login com Token</button>
      ) : (
        <TodoList token={token} />
      )}
    </div>
  );
}