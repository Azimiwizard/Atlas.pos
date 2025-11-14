import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { AuthProvider } from './hooks/useAuth'
import { StoreProvider } from './hooks/useStore'
import { CartProvider } from './hooks/useCart'
import { ThemeProvider } from './hooks/useTheme'
import { ToastProvider } from './components/ToastProvider'
import { QueryClientProvider } from '@tanstack/react-query'
import { queryClient } from './lib/queryClient'
import { ReactQueryDevtools } from '@tanstack/react-query-devtools'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <AuthProvider>
          <StoreProvider>
            <CartProvider>
              <ThemeProvider>
                <App />
              </ThemeProvider>
            </CartProvider>
          </StoreProvider>
        </AuthProvider>
      </ToastProvider>
      {import.meta.env.DEV ? <ReactQueryDevtools initialIsOpen={false} /> : null}
    </QueryClientProvider>
  </StrictMode>,
)
