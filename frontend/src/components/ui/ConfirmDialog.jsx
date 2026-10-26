import { Button } from './Button';
import { Modal } from './Modal';
import styles from './ConfirmDialog.module.scss';

/**
 * Confirmación para operaciones sensibles. El texto del botón describe la
 * acción exacta ("Desactivar rol"), nunca un genérico "Aceptar".
 */
export function ConfirmDialog({ open, title, message, details, confirmLabel, tone = 'danger', loading = false, onConfirm, onCancel }) {
  return (
    <Modal
      open={open}
      onClose={loading ? () => {} : onCancel}
      title={title}
      size="sm"
      dismissible={!loading}
      footer={
        <>
          <Button variant="secondary" onClick={onCancel} disabled={loading}>
            Cancelar
          </Button>
          <Button variant={tone === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} loading={loading}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p className={styles.message}>{message}</p>
      {details && <div className={styles.details}>{details}</div>}
    </Modal>
  );
}
