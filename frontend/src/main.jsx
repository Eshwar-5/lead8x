import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import './index.css'
import { Toaster } from 'react-hot-toast'

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
    <Toaster
      position="top-right"
      toastOptions={{
        duration: 3500,
        style: {
          background: '#1a1a2e',
          color: '#f0f0ff',
          border: '1px solid #2a2a4a',
          borderRadius: '10px',
          fontSize: '0.875rem',
          fontFamily: 'Inter, sans-serif',
        },
        success: { iconTheme: { primary: '#10b981', secondary: '#1a1a2e' } },
        error:   { iconTheme: { primary: '#ef4444', secondary: '#1a1a2e' } },
      }}
    />
  </React.StrictMode>
)
