import { useEffect, useState } from 'react';
import { Button } from '../ui/Button';
import { Checkbox } from '../ui/Field';
import { Modal } from '../ui/Modal';
import { useReferenceData } from '../../hooks/useReferenceData';
import { useToast } from '../../hooks/useToast';
import { clientsService } from '../../services/clientsService';
import { validateContact } from '../../utils/validators';
import formStyles from '../ui/Form.module.scss';
import { ContactFields, EMPTY_CONTACT, contactPayload } from './ContactFields';

/** Agregar o editar un contacto de un cliente. */
export function ContactFormModal({ open, clientId, contact, onClose, onSaved }) {
  const editing = Boolean(contact);
  const toast = useToast();
  const reference = useReferenceData();
  const [values, setValues] = useState(EMPTY_CONTACT);
  const [primary, setPrimary] = useState(false);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  useEffect(() => {
    if (!open) return;
    setValues(
      contact
        ? { type: contact.type.value, name: contact.name, position: contact.position ?? '', email: contact.email ?? '', phone: contact.phone ?? '' }
        : { ...EMPTY_CONTACT },
    );
    setPrimary(contact?.is_primary ?? false);
    setErrors({});
    setFormError(null);
  }, [open, contact]);

  async function handleSubmit(event) {
    event.preventDefault();
    const clientErrors = validateContact(values);
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length) return;

    const payload = { ...contactPayload(values), ...(primary ? { is_primary: true } : {}) };
    setSaving(true);
    setFormError(null);
    try {
      const response = editing
        ? await clientsService.updateContact(clientId, contact.id, payload)
        : await clientsService.addContact(clientId, payload);
      toast.success(response.message);
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
      size="lg"
      title={editing ? `Editar contacto` : 'Agregar contacto'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="contact-form" loading={saving}>
            {editing ? 'Guardar cambios' : 'Agregar contacto'}
          </Button>
        </>
      }
    >
      <form id="contact-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        {formError && (
          <p role="alert" className={formStyles.formError}>
            {formError}
          </p>
        )}
        <ContactFields values={values} errors={errors} onChange={(field, value) => setValues((v) => ({ ...v, [field]: value }))} typeOptions={reference.options('contact_types')} />
        <Checkbox
          checked={primary}
          disabled={contact?.is_primary}
          onChange={(e) => setPrimary(e.target.checked)}
          label="Contacto principal"
          description={contact?.is_primary ? 'Para cambiarlo, marca a otro contacto como principal.' : 'Reemplaza al contacto principal actual.'}
        />
      </form>
    </Modal>
  );
}
