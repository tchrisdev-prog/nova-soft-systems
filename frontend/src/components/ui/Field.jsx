import { useId } from 'react';
import { cx } from '../../utils/cx';
import styles from './Field.module.scss';

/** Envoltorio accesible: etiqueta, ayuda y error asociados al control. */
function FieldShell({ id, label, hint, error, required, children, className }) {
  return (
    <div className={cx(styles.field, className)}>
      {label && (
        <label htmlFor={id} className={styles.label}>
          {label}
          {required && <span className={styles.required} aria-hidden="true"> *</span>}
        </label>
      )}
      {children}
      {error ? (
        <p id={`${id}-error`} className={styles.error} role="alert">
          {error}
        </p>
      ) : (
        hint && (
          <p id={`${id}-hint`} className={styles.hint}>
            {hint}
          </p>
        )
      )}
    </div>
  );
}

function describedBy(id, error, hint) {
  return error ? `${id}-error` : hint ? `${id}-hint` : undefined;
}

export function TextField({ label, hint, error, required, className, id: idProp, ...props }) {
  const autoId = useId();
  const id = idProp ?? autoId;
  return (
    <FieldShell id={id} label={label} hint={hint} error={error} required={required} className={className}>
      <input
        id={id}
        className={cx(styles.control, error && styles.invalid)}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, error, hint)}
        required={required}
        {...props}
      />
    </FieldShell>
  );
}

export function TextArea({ label, hint, error, required, className, id: idProp, rows = 3, ...props }) {
  const autoId = useId();
  const id = idProp ?? autoId;
  return (
    <FieldShell id={id} label={label} hint={hint} error={error} required={required} className={className}>
      <textarea
        id={id}
        rows={rows}
        className={cx(styles.control, styles.textarea, error && styles.invalid)}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, error, hint)}
        required={required}
        {...props}
      />
    </FieldShell>
  );
}

export function Select({ label, hint, error, className, id: idProp, options, ...props }) {
  const autoId = useId();
  const id = idProp ?? autoId;
  return (
    <FieldShell id={id} label={label} hint={hint} error={error} className={className}>
      <select id={id} className={cx(styles.control, styles.select)} aria-describedby={describedBy(id, error, hint)} {...props}>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </FieldShell>
  );
}

export function Checkbox({ label, description, className, ...props }) {
  return (
    <label className={cx(styles.checkbox, props.disabled && styles.checkboxDisabled, className)}>
      <input type="checkbox" className={styles.checkboxInput} {...props} />
      <span className={styles.checkboxBox} aria-hidden="true">
        <svg viewBox="0 0 16 16" width="12" height="12">
          <path d="m3.5 8.5 3 3 6-7" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </span>
      {(label || description) && (
        <span className={styles.checkboxText}>
          {label && <span className={styles.checkboxLabel}>{label}</span>}
          {description && <span className={styles.checkboxDescription}>{description}</span>}
        </span>
      )}
    </label>
  );
}
