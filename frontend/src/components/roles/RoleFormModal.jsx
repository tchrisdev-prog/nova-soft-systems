import { useEffect, useState } from 'react';
import { Button } from '../ui/Button';
import { TextArea, TextField } from '../ui/Field';
import { Modal } from '../ui/Modal';
import { useToast } from '../../hooks/useToast';
import { rolesService } from '../../services/rolesService';
import { validateRole } from '../../utils/validators';
import formStyles from '../ui/Form.module.scss';

/** Crear o editar un rol (nombre y descripción). */
export function RoleFormModal({ open, role, onClose, onSaved }) {
  const editing = Boolean(role);
  const toast = useToast();
  const [values, setValues] = useState({ name: '', description: '' });
  const [errors, setErrors] = useState({});
  const [touched, setTouched] = useState({});
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  useEffect(() => {
    if (open) {
      setValues({ name: role?.name ?? '', description: role?.description ?? '' });
      setErrors({});
      setTouched({});
      setFormError(null);
    }
  }, [open, role]);

  function update(field, value) {
    const next = { ...values, [field]: value };
    setValues(next);
    if (touched[field]) setErrors(validateRole(next));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    const clientErrors = validateRole(values);
    setTouched({ name: true, description: true });
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length) return;

    setSaving(true);
    setFormError(null);
    const payload = { name: values.name.trim(), description: values.description.trim() || null };

    try {
      const response = editing ? await rolesService.update(role.id, payload) : await rolesService.create(payload);
      toast.success(response.message ?? (editing ? 'Rol actualizado.' : 'Rol creado.'));
      onSaved(response.data);
    } catch (error) {
      if (error.code === 'validation_failed') setErrors(error.fieldErrors);
      else setFormError(error.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      title={editing ? 'Editar rol' : 'Crear rol'}
      description={
        editing
          ? 'El identificador del rol no cambia al renombrarlo, así que sus asignaciones se conservan.'
          : 'Después de crearlo podrás asignarle permisos y usuarios.'
      }
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="role-form" loading={saving}>
            {editing ? 'Guardar cambios' : 'Crear rol'}
          </Button>
        </>
      }
    >
      <form id="role-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        {formError && (
          <p role="alert" className={formStyles.formError}>
            {formError}
          </p>
        )}
        <TextField
          label="Nombre"
          name="name"
          value={values.name}
          onChange={(e) => update('name', e.target.value)}
          onBlur={() => {
            setTouched((t) => ({ ...t, name: true }));
            setErrors(validateRole(values));
          }}
          error={touched.name ? errors.name : undefined}
          maxLength={80}
          required
          autoFocus
          placeholder="Por ejemplo: Coordinación de proyectos"
        />
        <TextArea
          label="Descripción"
          name="description"
          value={values.description}
          onChange={(e) => update('description', e.target.value)}
          error={touched.description ? errors.description : undefined}
          hint={`${values.description.length}/500 caracteres. Explica qué responsabilidades cubre el rol.`}
          maxLength={500}
          rows={4}
        />
      </form>
    </Modal>
  );
}
