import { cx } from '../../utils/cx';
import styles from './Contracts.module.scss';

/** Grupo de opciones como tarjetas (radio nativo, accesible con teclado). */
export function OptionCards({ legend, name, value, onChange, options, error, columns = 2 }) {
  return (
    <fieldset className={styles.cards} style={{ '--columns': columns }} aria-describedby={error ? `${name}-error` : undefined}>
      <legend className="visually-hidden">{legend}</legend>
      {options.map((option) => (
        <label key={option.value} className={cx(styles.card, value === option.value && styles.cardSelected)}>
          <input type="radio" name={name} value={option.value} checked={value === option.value} onChange={() => onChange(option.value)} />
          <span className={styles.cardTitle}>{option.label}</span>
          {option.description && <span className={styles.cardText}>{option.description}</span>}
        </label>
      ))}
      {error && (
        <p id={`${name}-error`} role="alert" className={styles.fieldError}>
          {error}
        </p>
      )}
    </fieldset>
  );
}
