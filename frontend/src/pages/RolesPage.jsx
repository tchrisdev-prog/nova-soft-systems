import { useState } from 'react';
import { Link } from 'react-router';
import { RoleFormModal } from '../components/roles/RoleFormModal';
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
import { useAuth } from '../hooks/useAuth';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useToast } from '../hooks/useToast';
import { rolesService } from '../services/rolesService';
import { countUsers } from '../utils/format';
import styles from './AdminPage.module.scss';

const COLUMNS = [
  { key: 'name', label: 'Rol' },
  { key: 'description', label: 'Descripción' },
  { key: 'permissions', label: 'Permisos', align: 'end' },
  { key: 'users', label: 'Usuarios', align: 'end' },
  { key: 'status', label: 'Estado' },
  { key: 'actions', label: 'Acciones', hidden: true, align: 'end' },
];

const STATUS_OPTIONS = [
  { value: '', label: 'Todos los estados' },
  { value: 'active', label: 'Activos' },
  { value: 'inactive', label: 'Inactivos' },
];

export default function RolesPage() {
  useDocumentTitle('Roles y permisos');
  const { can } = useAuth();
  const toast = useToast();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState(null); // null | 'new' | role
  const [toggling, setToggling] = useState(null);
  const [busy, setBusy] = useState(false);
  const debouncedSearch = useDebouncedValue(search.trim());

  const { data, error, loading, reload } = useApiResource(
    (signal) => rolesService.list({ search: debouncedSearch, status, page, per_page: 15 }, { signal }),
    [debouncedSearch, status, page],
  );

  const roles = data?.data ?? [];
  const filtered = Boolean(debouncedSearch || status);

  async function confirmToggle() {
    setBusy(true);
    try {
      const response = await rolesService.setStatus(toggling.id, !toggling.is_active);
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
        title="Roles y permisos"
        description="Cada rol agrupa permisos por módulo. Asigna roles a los usuarios para definir qué pueden consultar, crear o administrar."
        actions={
          can('access_control.create') && (
            <Button icon="plus" onClick={() => setEditing('new')}>
              Crear rol
            </Button>
          )
        }
      />

      <section className={styles.panel} aria-label="Listado de roles">
        <div className={styles.toolbar}>
          <SearchInput
            label="Buscar roles"
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Buscar por nombre o descripción"
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
        </div>

        {error ? (
          <ErrorState error={error} onRetry={reload} />
        ) : !loading && roles.length === 0 ? (
          filtered ? (
            <EmptyState icon="search" title="Sin resultados" message="Ningún rol coincide con la búsqueda o el filtro. Prueba con otro término." />
          ) : (
            <EmptyState
              icon="shield"
              title="Aún no hay roles personalizados"
              message="Crea el primer rol para agrupar permisos y asignarlo a los usuarios."
              action={
                can('access_control.create') && (
                  <Button icon="plus" onClick={() => setEditing('new')}>
                    Crear rol
                  </Button>
                )
              }
            />
          )
        ) : (
          <Table caption="Roles del sistema" columns={COLUMNS}>
            {loading && !data ? (
              <SkeletonRows columns={COLUMNS.length} />
            ) : (
              roles.map((role) => (
                <tr key={role.id}>
                  <Cell label="Rol">
                    <Link to={`/app/roles/${role.id}`} className={styles.primaryLink}>
                      {role.name}
                    </Link>
                    <span className={styles.secondary}>
                      {role.slug}
                      {role.is_system && (
                        <Badge tone="accent" className={styles.inlineBadge}>
                          Sistema
                        </Badge>
                      )}
                    </span>
                  </Cell>
                  <Cell label="Descripción" className={styles.muted}>
                    {role.description || <span className={styles.placeholder}>Sin descripción</span>}
                  </Cell>
                  <Cell label="Permisos" align="end">
                    <span className="tabular">{role.is_system ? 'Todos' : role.permissions_count}</span>
                  </Cell>
                  <Cell label="Usuarios" align="end">
                    <span className="tabular">{role.users_count}</span>
                  </Cell>
                  <Cell label="Estado">
                    <StatusBadge active={role.is_active} />
                  </Cell>
                  <Cell label="" align="end">
                    <div className={styles.rowActions}>
                      <Button to={`/app/roles/${role.id}`} variant="ghost" size="sm" icon="key">
                        Permisos
                      </Button>
                      {!role.is_system && can('access_control.update') && (
                        <>
                          <Button variant="ghost" size="sm" icon="edit" onClick={() => setEditing(role)} aria-label={`Editar ${role.name}`} />
                          <Button
                            variant={role.is_active ? 'dangerGhost' : 'ghost'}
                            size="sm"
                            icon="power"
                            onClick={() => setToggling(role)}
                            aria-label={`${role.is_active ? 'Desactivar' : 'Activar'} ${role.name}`}
                          />
                        </>
                      )}
                    </div>
                  </Cell>
                </tr>
              ))
            )}
          </Table>
        )}

        <Pagination meta={data?.meta} onPageChange={setPage} label="roles" />
      </section>

      <RoleFormModal
        open={editing !== null}
        role={editing === 'new' ? null : editing}
        onClose={() => setEditing(null)}
        onSaved={() => {
          setEditing(null);
          reload();
        }}
      />

      <ConfirmDialog
        open={Boolean(toggling)}
        title={toggling?.is_active ? 'Desactivar rol' : 'Activar rol'}
        message={
          toggling?.is_active
            ? toggling?.users_count
              ? `${countUsers(toggling.users_count)} con el rol “${toggling.name}” perderá${toggling.users_count === 1 ? '' : 'n'} estos permisos de inmediato. Podrás activarlo de nuevo cuando quieras.`
              : `El rol “${toggling?.name}” no tiene usuarios asignados. No se podrá asignar mientras esté inactivo.`
            : `Quienes tengan el rol “${toggling?.name}” recuperarán sus permisos de inmediato.`
        }
        confirmLabel={toggling?.is_active ? 'Desactivar rol' : 'Activar rol'}
        tone={toggling?.is_active ? 'danger' : 'primary'}
        loading={busy}
        onConfirm={confirmToggle}
        onCancel={() => setToggling(null)}
      />
    </>
  );
}
