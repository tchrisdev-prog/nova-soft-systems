import { useId } from 'react';
import { Icon } from './Icon';
import styles from './SearchInput.module.scss';

export function SearchInput({ label = 'Buscar', value, onChange, placeholder = 'Buscar…', ...props }) {
  const id = useId();
  return (
    <div className={styles.wrapper}>
      <label htmlFor={id} className="visually-hidden">
        {label}
      </label>
      <Icon name="search" size={18} className={styles.icon} />
      <input
        id={id}
        type="search"
        className={styles.input}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        autoComplete="off"
        {...props}
      />
    </div>
  );
}
