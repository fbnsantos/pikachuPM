import { useEffect, useState } from 'react';
import TodoList from './components/TodoList';

export default function App() {
  const [token, setToken] = useState(localStorage.getItem('token') || '');
  const [deferredPrompt, setDeferredPrompt] = useState(null);
  const [showInstallButton, setShowInstallButton] = useState(false);

  useEffect(() => {
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault(); // impede o banner automático
      setDeferredPrompt(e); // guarda o evento para usar depois
      setShowInstallButton(true); // mostra botão customizado
    });
  }, []);

  const handleInstallClick = () => {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then((choiceResult) => {
        if (choiceResult.outcome === 'accepted') {
          console.log('PWA instalada');
        } else {
          console.log('PWA recusada');
        }
        setDeferredPrompt(null);
        setShowInstallButton(false);
      });
    }
  };

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


      {showInstallButton && (
        <button onClick={handleInstallClick} style={{ marginTop: '10px' }}>
          Instalar App
        </button>
      )}
    </div>
  );
}