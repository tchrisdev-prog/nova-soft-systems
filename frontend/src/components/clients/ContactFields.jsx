import { Select, TextField } from '../ui/Field';
import styles from './Clients.module.scss';

/** Campos de un contacto; se usan al crear un cliente y en el formulario de contacto. */
export function ContactFields({ values, errors = {}, onChange, typeOptions, idPrefix = 'contact' }) {
  return (
    <div className={styles.grid}>
      <Select
        id={`${idPrefix}-type`}
        label="Tipo de contacto"
        value={values.type}
        onChange={(e) => onChange('type', e.target.value)}
        options={typeOptions}
        error={errors.type}
      />
      <TextField
        id={`${idPrefix}-name`}
        label="Nombre"
        value={values.name}
        onChange={(e) => onChange('name', e.target.value)}
        error={errors.name}
        maxLength={120}
        required
        autoComplete="off"
      />
      <TextField
        id={`${idPrefix}-position`}
        label="Cargo"
        value={values.position}
        onChange={(e) => onChange('position', e.target.value)}
        error={errors.position}
        maxLength={100}
        placeholder="Por ejemplo: Jefe de compras"
        autoComplete="off"
      />
      <TextField
        id={`${idPrefix}-email`}
        label="Correo"
        type="email"
        value={values.email}
        onChange={(e) => onChange('email', e.target.value)}
        error={errors.email}
        maxLength={150}
        autoComplete="off"
      />
      <TextField
        id={`${idPrefix}-phone`}
        label="Teléfono"
        type="tel"
        value={values.phone}
        onChange={(e) => onChange('phone', e.target.value)}
        error={errors.phone}
        maxLength={30}
        placeholder="+57 300 000 0000"
        autoComplete="off"
      />
    </div>
  );
}

export const EMPTY_CONTACT = { type: 'commercial', name: '', position: '', email: '', phone: '' };

/** Quita espacios y convierte vacíos en null antes de enviar. */
export function contactPayload(values) {
  const clean = (v) => (v?.trim() ? v.trim() : null);
  return { type: values.type, name: values.name.trim(), position: clean(values.position), email: clean(values.email), phone: clean(values.phone) };
}
