import { cx } from '../../utils/cx';
import { Button } from './Button';
import { Icon } from './Icon';
import { Spinner } from './Spinner';
import styles from './StateMessage.module.scss';

/** Estado vacío: una invitación a actuar, no un callejón sin salida. */
export function EmptyState({ icon = 'layers', title, message, action, compact = false }) {
  return (
    <div className={cx(styles.state, compact && styles.compact)}>
      <span className={styles.icon}>
        <Icon name={icon} size={22} />
      </span>
      <h3 className={styles.title}>{title}</h3>
      {message && <p className={styles.message}>{message}</p>}
      {action && <div className={styles.action}>{action}</div>}
    </div>
  );
}

/** Error con explicación y forma de recuperarse. */
export function ErrorState({ error, title = 'No se pudo cargar la información', onRetry, compact = false }) {
  return (
    <div className={cx(styles.state, styles.error, compact && styles.compact)} role="alert">
      <span className={styles.icon}>
        <Icon name="alert" size={22} />
      </span>
      <h3 className={styles.title}>{title}</h3>
      <p className={styles.message}>{error?.message ?? 'Ocurrió un error inesperado.'}</p>
      {onRetry && (
        <div className={styles.action}>
          <Button variant="secondary" icon="refresh" onClick={onRetry}>
            Reintentar
          </Button>
        </div>
      )}
    </div>
  );
}

export function LoadingState({ label = 'Cargando…' }) {
  return (
    <div className={cx(styles.state, styles.loading)} role="status">
      <Spinner size={22} />
      <p className={styles.message}>{label}</p>
    </div>
  );
}
