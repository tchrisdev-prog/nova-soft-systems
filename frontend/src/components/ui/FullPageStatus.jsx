import { LogoMark } from '../brand/Logo';
import { Button } from './Button';
import { Spinner } from './Spinner';
import styles from './FullPageStatus.module.scss';

export function FullPageStatus({ label, error, onRetry }) {
  return (
    <div className={styles.page} role={error ? 'alert' : 'status'}>
      <LogoMark size={40} />
      {error ? (
        <>
          <p className={styles.title}>No pudimos conectar con el servidor</p>
          <p className={styles.text}>{error.message}</p>
          {onRetry && (
            <Button variant="secondary" icon="refresh" onClick={onRetry}>
              Reintentar
            </Button>
          )}
        </>
      ) : (
        <p className={styles.loading}>
          <Spinner size={16} />
          {label}
        </p>
      )}
    </div>
  );
}
