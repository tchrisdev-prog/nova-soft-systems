import { useApiResource } from './useApiResource';
import { useAuth } from './useAuth';
import { rolesService } from '../services/rolesService';

/**
 * Roles activos que pueden ofrecerse para asignar. El rol Super Admin solo
 * se ofrece a un Super Admin. El servidor valida igualmente cada asignación.
 */
export function useAssignableRoles(enabled = true) {
  const { isSuperAdmin } = useAuth();
  const resource = useApiResource(
    (signal) => (enabled ? rolesService.list({ status: 'active', per_page: 100 }, { signal }).then((r) => r.data) : Promise.resolve(null)),
    [enabled],
  );

  return {
    ...resource,
    roles: (resource.data ?? []).filter((role) => isSuperAdmin || !role.is_system),
  };
}
