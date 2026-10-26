import { useAuth } from '../hooks/useAuth';
import ForbiddenPage from '../pages/ForbiddenPage';

/**
 * Oculta una vista si el usuario no tiene ninguno de los permisos indicados.
 * Es una ayuda de interfaz: la API rechaza igualmente cualquier operación
 * no autorizada (403).
 */
export function RequirePermission({ anyOf, children }) {
  const { canAny } = useAuth();
  return canAny(anyOf) ? children : <ForbiddenPage />;
}
