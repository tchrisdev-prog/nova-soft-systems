import { useEffect } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router';
import { useAuth } from '../hooks/useAuth';
import { FullPageStatus } from '../components/ui/FullPageStatus';

/**
 * Exige una sesión válida (verificada con /api/v1/me). Si no existe,
 * redirige a /login conservando la ruta solicitada.
 */
export function ProtectedRoute() {
  const auth = useAuth();
  const location = useLocation();

  useEffect(() => {
    if (auth.status === 'idle') auth.load();
  }, [auth]);

  if (auth.status === 'idle' || auth.status === 'loading') {
    return <FullPageStatus label="Verificando tu sesión…" />;
  }

  if (auth.status === 'error') {
    return <FullPageStatus error={auth.error} onRetry={auth.load} />;
  }

  if (auth.status !== 'authenticated') {
    const params = new URLSearchParams();
    if (auth.loggedOut) params.set('logged_out', '1');
    else {
      if (auth.expired) params.set('error', 'session_expired');
      if (auth.error?.code === 'account_inactive') params.set('error', 'account_inactive');
      if (location.pathname !== '/app') params.set('next', location.pathname + location.search);
    }
    const query = params.toString();
    return <Navigate to={`/login${query ? `?${query}` : ''}`} replace />;
  }

  return <Outlet />;
}
