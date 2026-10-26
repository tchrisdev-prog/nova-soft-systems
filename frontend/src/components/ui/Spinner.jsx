import styles from './Spinner.module.scss';

export function Spinner({ size = 16, label }) {
  return (
    <span className={styles.spinner} style={{ width: size, height: size }} role={label ? 'status' : undefined} aria-hidden={label ? undefined : true}>
      {label && <span className="visually-hidden">{label}</span>}
    </span>
  );
}
