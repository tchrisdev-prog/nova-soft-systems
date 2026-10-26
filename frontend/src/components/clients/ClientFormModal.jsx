import { useEffect, useState } from 'react';
import { Button } from '../ui/Button';
import { Select, TextField } from '../ui/Field';
import { Modal } from '../ui/Modal';
import { useReferenceData } from '../../hooks/useReferenceData';
import { useToast } from '../../hooks/useToast';
import { clientsService } from '../../services/clientsService';
import { validateClient, validateContact } from '../../utils/validators';
import formStyles from '../ui/Form.module.scss';
import { ContactFields, EMPTY_CONTACT, contactPayload } from './ContactFields';
import styles from './Clients.module.scss';

/**
 * Registrar o editar un cliente. Al registrarlo se pueden agregar de una vez
 * sus contactos; el primero queda como contacto principal. El código lo
 * genera el sistema.
 */
export function ClientFormModal({ open, client, onClose, onSaved }) {
  const editing = Boolean(client);
  const toast = useToast();
  const reference = useReferenceData();
  const [values, setValues] = useState({});
  const [contacts, setContacts] = useState([]);
  const [errors, setErrors] = useState({});
  const [contactErrors, setContactErrors] = useState([]);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  useEffect(() => {
    if (!open) return;
    setValues({
      name: client?.name ?? '',
      tax_id: client?.tax_id ?? '',
      sector: client?.sector?.value ?? '',
      site_id: client?.site ? String(client.site.id) : '',
    });
    setContacts(editing ? [] : [{ ...EMPTY_CONTACT }]);
    setErrors({});
    setContactErrors([]);
    setFormError(null);
  }, [open, client, editing]);

  const update = (field, value) => setValues((v) => ({ ...v, [field]: value }));
  const updateContact = (index, field, value) => setContacts((list) => list.map((c, i) => (i === index ? { ...c, [field]: value } : c)));

  async function handleSubmit(event) {
    event.preventDefault();
    const clientErrors = validateClient(values);
    // Un contacto vacío (sin nombre ni datos) simplemente no se envía.
    const filled = contacts.filter((c) => c.name.trim() || c.email.trim() || c.phone.trim());
    const perContact = filled.map(validateContact);
    setErrors(clientErrors);
    setContactErrors(perContact);
    if (Object.keys(clientErrors).length || perContact.some((e) => Object.keys(e).length)) return;

    const payload = {
      name: values.name.trim(),
      tax_id: values.tax_id.trim() || null,
      sector: values.sector,
      site_id: Number(values.site_id),
      ...(editing ? {} : { contacts: filled.map(contactPayload) }),
    };

    setSaving(true);
    setFormError(null);
    try {
      const response = editing ? await clientsService.update(client.id, payload) : await clientsService.create(payload);
      toast.success(response.message);
      onSaved(response.data);
    } catch (error) {
      if (error.code === 'validation_failed') {
        setErrors(error.fieldErrors);
        setContactErrors(filled.map((_, i) => Object.fromEntries(
          Object.entries(error.fieldErrors).filter(([k]) => k.startsWith(`contacts.${i}.`)).map(([k, v]) => [k.split('.')[2], v]),
        )));
      } else setFormError(error.message);
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
      title={editing ? `Editar ${client.name}` : 'Registrar cliente'}
      description={editing ? `Código ${client.code}. Los contactos se gestionan desde el detalle del cliente.` : 'El código del cliente se genera automáticamente.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="client-form" loading={saving}>
            {editing ? 'Guardar cambios' : 'Registrar cliente'}
          </Button>
        </>
      }
    >
      <form id="client-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        {formError && (
          <p role="alert" className={formStyles.formError}>
            {formError}
          </p>
        )}

        <div className={styles.grid}>
          <TextField
            label="Nombre o razón social"
            className={styles.full}
            value={values.name ?? ''}
            onChange={(e) => update('name', e.target.value)}
            error={errors.name}
            maxLength={150}
            required
            autoFocus
          />
          <TextField
            label="NIT"
            value={values.tax_id ?? ''}
            onChange={(e) => update('tax_id', e.target.value)}
            error={errors.tax_id}
            hint="Con dígito de verificación, por ejemplo 900123456-8. Opcional."
            maxLength={20}
            inputMode="numeric"
          />
          <Select
            label="Sector"
            value={values.sector ?? ''}
            onChange={(e) => update('sector', e.target.value)}
            options={reference.options('client_sectors', 'Elige el sector')}
            error={errors.sector}
          />
          <Select
            label="Sede que atiende la cuenta"
            value={values.site_id ?? ''}
            onChange={(e) => update('site_id', e.target.value)}
            options={reference.options('sites', 'Elige la sede')}
            error={errors.site_id}
          />
        </div>

        {!editing && (
          <fieldset className={formStyles.fieldset}>
            <legend className={formStyles.legend}>
              Contactos
              <span className={formStyles.legendHint}>El primero queda como contacto principal. Puedes agregar más después.</span>
            </legend>
            {contacts.map((contact, index) => (
              <div key={index} className={styles.contactDraft}>
                <div className={styles.contactDraftHeader}>
                  <span>{index === 0 ? 'Contacto principal' : `Contacto ${index + 1}`}</span>
                  {contacts.length > 1 && (
                    <Button variant="ghost" size="sm" icon="x" onClick={() => setContacts((list) => list.filter((_, i) => i !== index))}>
                      Quitar
                    </Button>
                  )}
                </div>
                <ContactFields
                  idPrefix={`new-contact-${index}`}
                  values={contact}
                  errors={contactErrors[index]}
                  onChange={(field, value) => updateContact(index, field, value)}
                  typeOptions={reference.options('contact_types')}
                />
              </div>
            ))}
            {contacts.length < 10 && (
              <Button variant="secondary" size="sm" icon="plus" onClick={() => setContacts((list) => [...list, { ...EMPTY_CONTACT, type: 'technical' }])}>
                Agregar otro contacto
              </Button>
            )}
          </fieldset>
        )}
      </form>
    </Modal>
  );
}
