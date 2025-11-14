import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { LoadingScreen } from './LoadingScreen';

type ProtectedRouteProps = {
  children?: ReactNode;
};

export function ProtectedRoute({ children }: ProtectedRouteProps) {
  const location = useLocation();
  const { user, fetchUser, loading, error } = useAuth();
  const [checking, setChecking] = useState(true);

  useEffect(() => {
    let isMounted = true;

    const verify = async () => {
      if (!user) {
        await fetchUser();
      }

      if (isMounted) {
        setChecking(false);
      }
    };

    void verify();

    return () => {
      isMounted = false;
    };
  }, [user, fetchUser]);

  if (loading || checking) {
    return <LoadingScreen />;
  }

  if (error) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  if (children) {
    return <>{children}</>;
  }

  return <Outlet />;
}
