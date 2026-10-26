import { createContext, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { onUnauthorized } from '../services/apiClient';
import { authService } from '../services/authService';

export const AuthContext = createContext(null);

const GUEST = { status: 'guest', user: null, permissions: [], modules: [], isSuperAdmin: false, error: null };

/**
 * Estado de la sesión del usuario. La sesión se carga bajo demanda (cuando
 * una ruta la necesita), no en la landing pública.
 *
 * Importante: estos permisos solo adaptan la interfaz. El backend vuelve a
 * autorizar cada operación de forma independiente.
 */
export function AuthProvider({ children }) {
  const [state, setState] = useState({ ...GUEST, status: 'idle' });
  const inflight = useRef(null);

  const load = useCallback(async () => {
    if (inflight.current) return inflight.current;

    setState((prev) => ({ ...prev, status: prev.status === 'authenticated' ? 'authenticated' : 'loading', error: null }));

    inflight.current = authService
      .me()
      .then((data) => {
        setState({
          status: 'authenticated',
          user: data.user,
          permissions: data.permissions,
          modules: data.modules,
          isSuperAdmin: data.is_super_admin,
          error: null,
        });
        return data;
      })
      .catch((error) => {
        if (error.status === 401) setState(GUEST);
        else if (error.code === 'account_inactive') setState({ ...GUEST, error });
        else setState((prev) => ({ ...prev, status: 'error', error }));
        return null;
      })
      .finally(() => {
        inflight.current = null;
      });

    return inflight.current;
  }, []);

  const logout = useCallback(async () => {
    try {
      await authService.logout();
    } finally {
      // ProtectedRoute redirige a /login?logged_out=1
      setState({ ...GUEST, loggedOut: true });
    }
  }, []);

  // Sesión expirada en cualquier llamada → estado invitado (ProtectedRoute redirige).
  useEffect(() => {
    onUnauthorized(() => setState((prev) => (prev.status === 'authenticated' ? { ...GUEST, expired: true } : prev)));
    return () => onUnauthorized(null);
  }, []);

  const value = useMemo(() => {
    const permissionSet = new Set(state.permissions);
    return {
      ...state,
      load,
      logout,
      can: (code) => state.isSuperAdmin || permissionSet.has(code),
      canAny: (codes) => state.isSuperAdmin || codes.some((code) => permissionSet.has(code)),
      canAccessModule: (code) => state.modules.some((m) => m.code === code && m.can_access),
    };
  }, [state, load, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
