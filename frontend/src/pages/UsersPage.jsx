import { useState } from 'react';
import { useSearchParams } from 'react-router';
import { AuthorizeUserModal } from '../components/users/AuthorizeUserModal';
import { UserRolesModal } from '../components/users/UserRolesModal';
import { Badge, StatusBadge } from '../components/ui/Badge';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { Select } from '../components/ui/Field';
import { PageHeader } from '../components/ui/PageHeader';
import { Pagination } from '../components/ui/Pagination';
import { SearchInput } from '../components/ui/SearchInput';
import { EmptyState, ErrorState } from '../components/ui/StateMessage';
import { Cell, SkeletonRows, Table } from '../components/ui/Table';
import { useApiResource } from '../hooks/useApiResource';
import { useAssignableRoles } from '../hooks/useAssignableRoles';
import { useAuth } from '../hooks/useAuth';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useToast } from '../hooks/useToast';
import { usersService } from '../services/usersService';
import { formatRelative } from '../utils/format';
import styles from './AdminPage.module.scss';

const COLUMNS = [
  { key: 'user', label: 'Usuario' },
  { key: 'roles', label: 'Roles' },
  { key: 'status', label: 'Estado' },
  { key: 'login', label: 'Último ingreso' },
  { key: 'actions', label: 'Acciones', hidden: true, align: 'end' },
];

const STATUS_OPTIONS = [
  { value: '', label: 'Todos los estados' },
  { value: 'active', label: 'Activos' },
  { value: 'inactive', label: 'Inactivos' },
];

export default function UsersPage() {
  useDocumentTitle('Usuarios');
  const { can, user: me } = useAuth();
  const toast = useToast();
  const [params, setParams] = useSearchParams();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [authorizing, setAuthorizing] = useState(false);
  const [managingRoles, setManagingRoles] = useState(null);
  const [toggling, setToggling] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [busy, setBusy] = useState(false);
  const roleFilter = params.get('role_id') ?? '';
  const debouncedSearch = useDebouncedValue(search.trim());
  const roleOptions = useAssignableRoles(can('access_control.view'));

  const { data, error, loading, reload } = useApiResource(
    (signal) => usersService.list({ search: debouncedSearch, status, role_id: roleFilter, page, per_page: 15 }, { signal }),
    [debouncedSearch, status, roleFilter, page],
  );

  const users = data?.data ?? [];
  const filtered = Boolean(debouncedSearch || status || roleFilter);

  function setRoleFilter(value) {
    setPage(1);
    setParams(value ? { role_id: value } : {}, { replace: true });
  }

  async function confirmDelete() {
    setBusy(true);
    try {
      const response = await usersService.remove(deleting.id);
      toast.success(response.message);
      setDeleting(null);
      // Si era el último de la página, vuelve a la anterior.
      if (users.length === 1 && page > 1) setPage(page - 1);
      else reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function confirmToggle() {
    setBusy(true);
    try {
      const response = await usersService.setStatus(toggling.id, !toggling.is_active);
      toast.success(response.message);
      setToggling(null);
      reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader
        title="Usuarios"
        description="Cuentas corporativas autorizadas para ingresar con Microsoft 365, su estado y los roles que tienen asignados."
        actions={
          can('users.create') && (
            <Button icon="plus" onClick={() => setAuthorizing(true)}>
              Autorizar cuenta
            </Button>
          )
        }
      />

      <section className={styles.panel} aria-label="Listado de usuarios">
        <div className={styles.toolbar}>
          <SearchInput
            label="Buscar usuarios"
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Buscar por nombre o correo"
          />
          <Select
            label="Estado"
            className={styles.filter}
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            options={STATUS_OPTIONS}
          />
          {can('access_control.view') && (
            <Select
              label="Rol"
              className={styles.filter}
              value={roleFilter}
              onChange={(e) => setRoleFilter(e.target.value)}
              options={[{ value: '', label: 'Todos los roles' }, ...(roleOptions.data ?? []).map((r) => ({ value: String(r.id), label: r.name }))]}
            />
          )}
        </div>

        {error ? (
          <ErrorState error={error} onRetry={reload} />
        ) : !loading && users.length === 0 ? (
          filtered ? (
            <EmptyState icon="search" title="Sin resultados" message="Ningún usuario coincide con la búsqueda o los filtros." />
          ) : (
            <EmptyState
              icon="users"
              title="Aún no hay cuentas autorizadas"
              message="Autoriza la primera cuenta corporativa para que pueda ingresar con Microsoft 365."
            />
          )
        ) : (
          <Table caption="Usuarios autorizados" columns={COLUMNS}>
            {loading && !data ? (
              <SkeletonRows columns={COLUMNS.length} />
            ) : (
              users.map((user) => {
                const isMe = user.id === me.id;
                return (
                  <tr key={user.id}>
                    <Cell label="Usuario">
                      <span className={styles.primaryText}>
                        {user.name}
                        {isMe && <span className={styles.secondary}>Tu cuenta</span>}
                      </span>
                      <span className={styles.secondary}>{user.email}</span>
                    </Cell>
                    <Cell label="Roles">
                      {user.roles.length === 0 ? (
                        <span className={styles.placeholder}>Sin roles</span>
                      ) : (
                        <span className={styles.tags}>
                          {user.roles.map((role) => (
                            <Badge key={role.id} tone={role.is_system ? 'accent' : role.is_active ? 'primary' : 'neutral'}>
                              {role.name}
                              {!role.is_active && ' (inactivo)'}
                            </Badge>
                          ))}
                        </span>
                      )}
                    </Cell>
                    <Cell label="Estado">
                      <StatusBadge active={user.is_active} />
                    </Cell>
                    <Cell label="Último ingreso">
                      <span className={styles.nowrap}>
                        {user.last_login_at ? formatRelative(user.last_login_at) : <span className={styles.placeholder}>Pendiente de primer ingreso</span>}
                      </span>
                    </Cell>
                    <Cell label="">
                      {isMe ? (
                        <span className={styles.secondary}>No puedes modificar tu propia cuenta</span>
                      ) : (
                        <div className={styles.rowActions}>
                          {can('users.manage') && (
                            <Button variant="ghost" size="sm" icon="key" onClick={() => setManagingRoles(user)}>
                              Roles
                            </Button>
                          )}
                          {can('users.update') && (
                            <Button variant={user.is_active ? 'dangerGhost' : 'ghost'} size="sm" icon="power" onClick={() => setToggling(user)}>
                              {user.is_active ? 'Desactivar' : 'Activar'}
                            </Button>
                          )}
                          {can('users.delete') && (
                            <Button variant="dangerGhost" size="sm" icon="trash" onClick={() => setDeleting(user)}>
                              Eliminar
                            </Button>
                          )}
                        </div>
                      )}
                    </Cell>
                  </tr>
                );
              })
            )}
          </Table>
        )}

        <Pagination meta={data?.meta} onPageChange={setPage} label="usuarios" />
      </section>

      <AuthorizeUserModal
        open={authorizing}
        onClose={() => setAuthorizing(false)}
        onSaved={() => {
          setAuthorizing(false);
          reload();
        }}
      />

      <UserRolesModal
        user={managingRoles}
        onClose={() => setManagingRoles(null)}
        onSaved={() => {
          setManagingRoles(null);
          reload();
        }}
      />

      <ConfirmDialog
        open={Boolean(toggling)}
        title={toggling?.is_active ? 'Desactivar usuario' : 'Activar usuario'}
        message={
          toggling?.is_active
            ? `${toggling?.name} no podrá ingresar y sus sesiones abiertas se cerrarán de inmediato. Sus roles se conservan para cuando lo actives de nuevo.`
            : `${toggling?.name} podrá volver a ingresar con su cuenta de Microsoft 365 y recuperará los permisos de sus roles.`
        }
        confirmLabel={toggling?.is_active ? 'Desactivar usuario' : 'Activar usuario'}
        tone={toggling?.is_active ? 'danger' : 'primary'}
        loading={busy}
        onConfirm={confirmToggle}
        onCancel={() => setToggling(null)}
      />

      <ConfirmDialog
        open={Boolean(deleting)}
        title="Eliminar cuenta"
        message={`¿Eliminar la cuenta de ${deleting?.name}? Dejará de aparecer en la lista y perderá el acceso de inmediato.`}
        details={
          <ul className={styles.consequences}>
            <li>Se cierran sus sesiones abiertas y se retiran sus roles.</li>
            <li>Sus asignaciones a proyectos terminan hoy.</li>
            <li>Su historial (auditoría, horas, contratos) se conserva con su nombre.</li>
            <li>Si vuelve a la empresa, autoriza de nuevo su correo y se recupera la misma cuenta.</li>
          </ul>
        }
        confirmLabel="Eliminar cuenta"
        tone="danger"
        loading={busy}
        onConfirm={confirmDelete}
        onCancel={() => setDeleting(null)}
      />
    </>
  );
}
