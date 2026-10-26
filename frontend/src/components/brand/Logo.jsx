import markUrl from '../../assets/brand/nova-sync-mark.png';
import { cx } from '../../utils/cx';
import styles from './Logo.module.scss';

/** Isotipo de Nova Sync Systems (circuito con llaves y estrella sobre fondo azul noche). */
export function LogoMark({ size = 32, className }) {
  return <img src={markUrl} width={size} height={size} alt="" className={cx(styles.mark, className)} decoding="async" />;
}

/**
 * Logotipo completo: isotipo + nombre. El nombre se compone en HTML (no en
 * imagen) para que sea nítido a cualquier tamaño y se adapte a fondos claros
 * u oscuros. tone: "dark" (texto oscuro, fondos claros) | "light" (fondos oscuros).
 */
export function Logo({ tone = 'dark', size = 32, className }) {
  return (
    <span className={cx(styles.logo, styles[tone], className)}>
      <LogoMark size={size} />
      <span className={styles.wordmark}>
        <span className={styles.nova}>Nova</span> <span className={styles.sync}>Sync</span>{' '}
        <span className={styles.systems}>Systems</span>
      </span>
    </span>
  );
}
