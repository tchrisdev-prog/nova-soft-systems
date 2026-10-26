import { useEffect, useState } from 'react';
import { Button } from '../ui/Button';
import { TextField } from '../ui/Field';
import { Modal } from '../ui/Modal';
import { RoleChecklist } from './RoleChecklist';
import { useAssignableRoles } from '../../hooks/useAssignableRoles';
import { useAuth } from '../../hooks/useAuth';
import { useToast } from '../../hooks/useToast';
import { usersService } from '../../services/usersService';
import { validateUser } from '../../utils/validators';
import formStyles from '../ui/Form.module.scss';

/**
 * Autoriza una cuenta corporativa. No se pide contraseña: la persona
 * ingresará con su cuenta de Microsoft 365 y su identidad se vinculará
 * en ese primer ingreso.
 */
export function AuthorizeUserModal({ open, onClose, onSaved }) {
  const toast = useToast();
  const { can } = useAuth();
  const canAssign = can('users.manage');
  const { roles, loading, error } = useAssignableRoles(open && canAssign);
  const [values, setValues] = useState({ name: '', email: '' });
  const [roleIds, setRoleIds] = useState(new Set());
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open) {
      setValues({ name: '', email: '' });
      setRoleIds(new Set());
      setErrors({});
      setFormError(null);
    }
  }, [open]);

  async function handleSubmit(event) {
    event.preventDefault();
    const clientErrors = validateUser(values);
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length) return;

    setSaving(true);
    setFormError(null);
    try {
      const payload = { name: values.name.trim(), email: values.email.trim() };
      if (canAssign && roleIds.size) payload.role_ids = [...roleIds];
      const response = await usersService.authorize(payload);
      toast.success(response.message);
      onSaved(response.data);
    } catch (err) {
      if (err.code === 'validation_failed') setErrors(err.fieldErrors);
      else setFormError(err.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      title="Autorizar cuenta corporativa"
      description="La persona podrá ingresar con su cuenta de Microsoft 365. No se crea ni se solicita ninguna contraseña."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="authorize-user-form" loading={saving}>
            Autorizar cuenta
          </Button>
        </>
      }
    >
      <form id="authorize-user-form" className={formStyles.form} onSubmit={handleSubmit} noValidate>
        {formError && (
          <p role="alert" className={formStyles.formError}>
            {formError}
          </p>
        )}
        <TextField
          label="Nombre completo"
          value={values.name}
          onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))}
          error={errors.name}
          required
          autoFocus
          maxLength={120}
          autoComplete="off"
        />
        <TextField
          label="Correo corporativo"
          type="email"
          value={values.email}
          onChange={(e) => setValues((v) => ({ ...v, email: e.target.value }))}
          error={errors.email}
          hint="Debe coincidir con el usuario de Microsoft 365 (UPN) con el que ingresará."
          required
          maxLength={190}
          autoComplete="off"
          inputMode="email"
        />
        {canAssign && (
          <RoleChecklist
            roles={roles}
            selected={roleIds}
            onChange={setRoleIds}
            loading={loading}
            error={error}
            legend="Roles iniciales"
            hint="Opcional. Puedes asignarlos más tarde."
          />
        )}
      </form>
    </Modal>
  );
}
