import { cx } from '../../utils/cx';
import { Icon } from './Icon';
import styles from './Toast.module.scss';

const ICONS = { success: 'check', error: 'alert', info: 'info' };

/** Notificaciones anunciadas por lectores de pantalla (aria-live). */
export function ToastViewport({ toasts, onDismiss }) {
  return (
    <div className={styles.viewport} aria-live="polite" aria-relevant="additions">
      {toasts.map((toast) => (
        <div key={toast.id} className={cx(styles.toast, styles[toast.tone])} role={toast.tone === 'error' ? 'alert' : 'status'}>
          <Icon name={ICONS[toast.tone]} size={18} className={styles.icon} />
          <p className={styles.message}>{toast.message}</p>
          <button type="button" className={styles.close} onClick={() => onDismiss(toast.id)} aria-label="Cerrar notificación">
            <Icon name="x" size={16} />
          </button>
        </div>
      ))}
    </div>
  );
}
