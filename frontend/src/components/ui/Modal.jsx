import { useEffect, useId, useRef } from 'react';
import { cx } from '../../utils/cx';
import { Icon } from './Icon';
import styles from './Modal.module.scss';

/**
 * Diálogo modal accesible basado en <dialog> nativo: bloquea el fondo,
 * atrapa el foco, cierra con Escape y devuelve el foco al disparador.
 */
export function Modal({ open, onClose, title, description, children, footer, size = 'md', dismissible = true }) {
  const ref = useRef(null);
  const titleId = useId();
  const descriptionId = useId();
  const returnFocus = useRef(null);

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;

    if (open && !dialog.open) {
      returnFocus.current = document.activeElement;
      dialog.showModal();
    } else if (!open && dialog.open) {
      dialog.close();
      returnFocus.current?.focus?.();
    }
  }, [open]);

  useEffect(() => {
    const dialog = ref.current;
    return () => {
      if (dialog?.open) dialog.close();
    };
  }, []);

  function handleCancel(event) {
    event.preventDefault(); // Escape: cerrar de forma controlada
    if (dismissible) onClose();
  }

  function handleBackdrop(event) {
    if (dismissible && event.target === ref.current) onClose();
  }

  return (
    <dialog
      ref={ref}
      className={cx(styles.dialog, styles[size])}
      aria-labelledby={titleId}
      aria-describedby={description ? descriptionId : undefined}
      onCancel={handleCancel}
      onMouseDown={handleBackdrop}
    >
      {open && (
        <div className={styles.panel}>
          <header className={styles.header}>
            <div>
              <h2 id={titleId} className={styles.title}>
                {title}
              </h2>
              {description && (
                <p id={descriptionId} className={styles.description}>
                  {description}
                </p>
              )}
            </div>
            {dismissible && (
              <button type="button" className={styles.close} onClick={onClose} aria-label="Cerrar">
                <Icon name="x" size={18} />
              </button>
            )}
          </header>
          <div className={styles.body}>{children}</div>
          {footer && <footer className={styles.footer}>{footer}</footer>}
        </div>
      )}
    </dialog>
  );
}
