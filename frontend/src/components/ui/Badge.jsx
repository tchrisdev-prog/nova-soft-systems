import { cx } from '../../utils/cx';
import styles from './Badge.module.scss';

/** tone: neutral | primary | success | danger | warning | accent */
export function Badge({ tone = 'neutral', dot = false, children, className }) {
  return (
    <span className={cx(styles.badge, styles[tone], className)}>
      {dot && <span className={styles.dot} aria-hidden="true" />}
      {children}
    </span>
  );
}

export function StatusBadge({ active, activeLabel = 'Activo', inactiveLabel = 'Inactivo' }) {
  return (
    <Badge tone={active ? 'success' : 'neutral'} dot>
      {active ? activeLabel : inactiveLabel}
    </Badge>
  );
}
