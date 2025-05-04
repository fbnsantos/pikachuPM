import React, { useState } from 'react';
import ReactDOM from 'react-dom/client';
import App from './App.jsx';

function Root() {
  const [waitingWorker, setWaitingWorker] = useState(null);
  const [showUpdate, setShowUpdate] = useState(false);

  const updateServiceWorker = () => {
    if (waitingWorker) {
      waitingWorker.postMessage({ type: 'SKIP_WAITING' });
      window.location.reload();
    }
  };

  React.useEffect(() => {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/pikachu/PKMT/sw.js').then(registration => {
        console.log('SW registado:', registration);

        registration.onupdatefound = () => {
          const newWorker = registration.installing;
          newWorker.onstatechange = () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
              setWaitingWorker(newWorker);
              setShowUpdate(true);
            }
          };
        };
      }).catch(console.error);
    }

    navigator.serviceWorker?.addEventListener('controllerchange', () => {
      window.location.reload();
    });
  }, []);

  return (
    <div>
      <App />
      {showUpdate && (
        <div style={{
          position: 'fixed',
          bottom: 20,
          left: 20,
          background: '#333',
          color: '#fff',
          padding: '10px 20px',
          borderRadius: 8,
          zIndex: 1000
        }}>
          Nova versão disponível.
          <button
            onClick={updateServiceWorker}
            style={{
              marginLeft: 10,
              background: '#4caf50',
              border: 'none',
              color: 'white',
              padding: '6px 12px',
              borderRadius: 4,
              cursor: 'pointer'
            }}
          >
            Atualizar agora
          </button>
        </div>
      )}
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <Root />
  </React.StrictMode>
);