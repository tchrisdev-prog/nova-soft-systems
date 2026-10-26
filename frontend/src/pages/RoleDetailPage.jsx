import { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router';
import { PermissionMatrix } from '../components/roles/PermissionMatrix';
import { RoleFormModal } from '../components/roles/RoleFormModal';
import { Badge, StatusBadge } from '../components/ui/Badge';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { Icon } from '../components/ui/Icon';
import { PageHeader } from '../components/ui/PageHeader';
import { ErrorState, LoadingState } from '../components/ui/StateMessage';
import { actionLabel } from '../config/modules';
import { useApiResource } from '../hooks/useApiResource';
import { useAuth } from '../hooks/useAuth';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useToast } from '../hooks/useToast';
import { catalogService } from '../services/catalogService';
import { rolesService } from '../services/rolesService';
import { countUsers, formatDateTime } from '../utils/format';
import styles from './RoleDetailPage.module.scss';

export default function RoleDetailPage() {
  const { roleId } = useParams();
  const { can, isSuperAdmin, user } = useAuth();
  const toast = useToast();

  const role = useApiResource((signal) => rolesService.get(roleId, { signal }), [roleId]);
  const catalog = useApiResource((signal) => catalogService.permissions({ signal }), []);
  useDocumentTitle(role.data ? `Rol ${role.data.name}` : 'Rol');

  const [selected, setSelected] = useState(new Set());
  const [editing, setEditing] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (role.data) setSelected(new Set(role.data.permission_ids ?? []));
  }, [role.data]);

  const original = useMemo(() => new Set(role.data?.permission_ids ?? []), [role.data]);
  const permissionIndex = useMemo(() => {
    const index = new Map();
    catalog.data?.forEach((module) => module.permissions.forEach((p) => index.set(p.id, { ...p, moduleName: module.name })));
    return index;
  }, [catalog.data]);

  const added = [...selected].filter((id) => !original.has(id));
  const removed = [...original].filter((id) => !selected.has(id));
  const dirty = added.length > 0 || removed.length > 0;

  if (role.error || catalog.error) {
    const error = role.error ?? catalog.error;
    return (
      <>
        <BackLink />
        <ErrorState
          error={error}
          title={error.status === 404 ? 'Este rol no existe' : 'No se pudo cargar el rol'}
          onRetry={error.status === 404 ? undefined : () => (role.error ? role.reload() : catalog.reload())}
        />
      </>
    );
  }

  if (!role.data || !catalog.data) return <LoadingState label="Cargando rol y permisos…" />;

  const current = role.data;
  const isSystem = current.is_system;
  const ownsRole = user.roles?.some((r) => r.id === current.id);
  const canManage = can('access_control.manage') && !isSystem && (isSuperAdmin || !ownsRole);
  const canEdit = can('access_control.update') && !isSystem && (isSuperAdmin || !ownsRole);

  function toggle(id) {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  function toggleModule(ids, value) {
    setSelected((prev) => {
      const next = new Set(prev);
      ids.forEach((id) => (value ? next.add(id) : next.delete(id)));
      return next;
    });
  }

  async function save() {
    setSaving(true);
    try {
      const response = await rolesService.syncPermissions(current.id, [...selected]);
      toast.success(response.message);
      setConfirming(false);
      role.setData(response.data);
    } catch (error) {
      toast.error(error.message);
    } finally {
      setSaving(false);
    }
  }

  const describe = (id) => {
    const p = permissionIndex.get(id);
    return p ? `${p.moduleName}: ${actionLabel(p.action)}` : `#${id}`;
  };

  return (
    <>
      <PageHeader
        title={current.name}
        description={current.description || 'Sin descripción.'}
        actions={
          canEdit && (
            <Button variant="secondary" icon="edit" onClick={() => setEditing(true)}>
              Editar rol
            </Button>
          )
        }
      >
        <BackLink />
      </PageHeader>

      <dl className={styles.facts}>
        <div>
          <dt>Estado</dt>
          <dd>
            <StatusBadge active={current.is_active} />
          </dd>
        </div>
        <div>
          <dt>Identificador</dt>
          <dd className={styles.code}>{current.slug}</dd>
        </div>
        <div>
          <dt>Usuarios</dt>
          <dd>
            {can('users.view') ? (
              <Link to={`/app/users?role_id=${current.id}`} className={styles.link}>
                {countUsers(current.users_count)}
              </Link>
            ) : (
              current.users_count
            )}
          </dd>
        </div>
        <div>
          <dt>Actualizado</dt>
          <dd>{formatDateTime(current.updated_at)}</dd>
        </div>
      </dl>

      <section className={styles.panel} aria-labelledby="permissions-title">
        <header className={styles.panelHeader}>
          <div>
            <h2 id="permissions-title" className={styles.panelTitle}>
              Permisos del rol
            </h2>
            <p className={styles.panelText}>
              {isSystem
                ? 'El Super Admin es un rol de sistema con acceso total. Sus permisos no se editan y siempre incluye los módulos nuevos.'
                : canManage
                  ? 'Marca las acciones permitidas en cada módulo. Los cambios se aplican al guardar.'
                  : ownsRole && !isSuperAdmin
                    ? 'No puedes modificar los permisos de un rol que tienes asignado.'
                    : 'Consulta de permisos. Para modificarlos necesitas el permiso “Administrar” en Roles y permisos.'}
            </p>
          </div>
          {isSystem && (
            <Badge tone="accent">
              <Icon name="lock" size={12} /> Rol protegido
            </Badge>
          )}
        </header>

        {isSystem ? (
          <PermissionMatrix modules={catalog.data} selected={new Set(permissionIndex.keys())} readOnly />
        ) : (
          <PermissionMatrix modules={catalog.data} selected={selected} onToggle={toggle} onToggleModule={toggleModule} readOnly={!canManage} />
        )}

        {canManage && (
          <footer className={styles.saveBar} aria-live="polite">
            <p className={styles.saveText}>
              {dirty ? (
                <>
                  <strong>Cambios sin guardar:</strong> {added.length} por agregar, {removed.length} por retirar.
                </>
              ) : (
                `${selected.size} permisos asignados.`
              )}
            </p>
            <div className={styles.saveActions}>
              <Button variant="secondary" onClick={() => setSelected(new Set(original))} disabled={!dirty || saving}>
                Descartar
              </Button>
              <Button icon="check" onClick={() => setConfirming(true)} disabled={!dirty}>
                Guardar permisos
              </Button>
            </div>
          </footer>
        )}
      </section>

      <RoleFormModal
        open={editing}
        role={current}
        onClose={() => setEditing(false)}
        onSaved={(updated) => {
          setEditing(false);
          role.setData((prev) => ({ ...prev, ...updated }));
        }}
      />

      <ConfirmDialog
        open={confirming}
        title="Guardar permisos del rol"
        message={
          current.users_count
            ? `${countUsers(current.users_count)} con el rol “${current.name}” recibirá${current.users_count === 1 ? '' : 'n'} estos cambios de inmediato.`
            : `Ningún usuario tiene asignado el rol “${current.name}” todavía. Los permisos se aplicarán a quienes lo reciban.`
        }
        details={
          <div className={styles.diff}>
            {added.length > 0 && (
              <div>
                <p className={styles.diffTitle}>Se agregarán</p>
                <ul>{added.map((id) => <li key={id}>{describe(id)}</li>)}</ul>
              </div>
            )}
            {removed.length > 0 && (
              <div>
                <p className={styles.diffTitle}>Se retirarán</p>
                <ul>{removed.map((id) => <li key={id}>{describe(id)}</li>)}</ul>
              </div>
            )}
          </div>
        }
        confirmLabel="Guardar permisos"
        tone="primary"
        loading={saving}
        onConfirm={save}
        onCancel={() => setConfirming(false)}
      />
    </>
  );
}

function BackLink() {
  return (
    <Link to="/app/roles" className={styles.back}>
      <Icon name="chevron-left" size={16} />
      Roles y permisos
    </Link>
  );
}
