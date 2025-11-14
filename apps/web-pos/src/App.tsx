import type { ReactNode } from 'react';
import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom';
import { LoginPage } from './pages/Login';
import SellPage from './pages/Sell';
import CartPage from './pages/Cart';
import ProfilePage from './pages/Profile';
import ReceiptPage from './pages/Receipt';
import { SalesPage } from './pages/Sales';
import { ZReportPage } from './pages/ZReport';
import { useAuth } from './hooks/useAuth';
import { ProtectedRoute } from './components/ProtectedRoute';
import { LoadingScreen } from './components/LoadingScreen';
import { BottomNav } from './components/pos/BottomNav';

function PublicOnlyRoute({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();

  if (loading) {
    return <LoadingScreen />;
  }

  if (user) {
    return <Navigate to="/pos/sell" replace />;
  }

  return <>{children}</>;
}

function PosLayout() {
  return (
    <div className="min-h-screen bg-[color:var(--pos-bg)] text-[color:var(--pos-text)]">
      <main className="mx-auto flex w-full max-w-5xl flex-col gap-8 px-4 pb-28 pt-8">
        <Outlet />
      </main>
      <BottomNav />
    </div>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route
          path="/login"
          element={
            <PublicOnlyRoute>
              <LoginPage />
            </PublicOnlyRoute>
          }
        />

        <Route
          path="/pos"
          element={
            <ProtectedRoute>
              <PosLayout />
            </ProtectedRoute>
          }
        >
          <Route index element={<Navigate to="sell" replace />} />
          <Route path="sell" element={<SellPage />} />
          <Route path="cart" element={<CartPage />} />
          <Route path="profile" element={<ProfilePage />} />
        </Route>

        <Route
          path="/receipt/:id"
          element={
            <ProtectedRoute>
              <ReceiptPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/sales"
          element={
            <ProtectedRoute>
              <SalesPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/z-report/:id"
          element={
            <ProtectedRoute>
              <ZReportPage />
            </ProtectedRoute>
          }
        />

        <Route path="*" element={<Navigate to="/pos/sell" replace />} />
      </Routes>
    </BrowserRouter>
  );
}

