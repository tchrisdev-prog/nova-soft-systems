import styles from './PageHeader.module.scss';

export function PageHeader({ title, description, actions, children }) {
  return (
    <header className={styles.header}>
      <div className={styles.text}>
        {children}
        <h1 className={styles.title}>{title}</h1>
        {description && <p className={styles.description}>{description}</p>}
      </div>
      {actions && <div className={styles.actions}>{actions}</div>}
    </header>
  );
}
