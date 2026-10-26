import { useEffect, useState } from 'react';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { RoleChecklist } from './RoleChecklist';
import { useAssignableRoles } from '../../hooks/useAssignableRoles';
import { useToast } from '../../hooks/useToast';
import { usersService } from '../../services/usersService';
import formStyles from '../ui/Form.module.scss';

export function UserRolesModal({ user, onClose, onSaved }) {
  const open = Boolean(user);
  const toast = useToast();
  const { roles, loading, error } = useAssignableRoles(open);
  const [selected, setSelected] = useState(new Set());
  const [formError, setFormError] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (user) {
      setSelected(new Set(user.roles.map((r) => r.id)));
      setFormError(null);
    }
  }, [user]);

  // Roles asignados que no se ofrecen (inactivos o de sistema para quien no es Super Admin) se conservan.
  const hiddenAssigned = user ? user.roles.filter((r) => !roles.some((o) => o.id === r.id)) : [];

  async function save() {
    setSaving(true);
    setFormError(null);
    try {
      const ids = new Set([...selected, ...hiddenAssigned.map((r) => r.id)]);
      const response = await usersService.syncRoles(user.id, [...ids]);
      toast.success(response.message);
      onSaved(response.data);
    } catch (err) {
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      title="Gestionar roles"
      description={user ? `${user.name} (${user.email})` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button onClick={save} loading={saving} disabled={loading}>
            Guardar roles
          </Button>
        </>
      }
    >
      <div className={formStyles.form}>
        {formError && (
          <p role="alert" className={formStyles.formError}>
            {formError}
          </p>
        )}
        <RoleChecklist
          roles={roles}
          selected={selected}
          onChange={setSelected}
          loading={loading}
          error={error}
          legend="Roles asignados"
          hint="Los permisos del usuario son la suma de los permisos de sus roles activos."
        />
        {hiddenAssigned.length > 0 && (
          <p className={formStyles.legendHint}>
            También tiene asignados: {hiddenAssigned.map((r) => r.name + (r.is_active ? '' : ' (inactivo)')).join(', ')}. Esta
            asignación se conserva.
          </p>
        )}
      </div>
    </Modal>
  );
}
