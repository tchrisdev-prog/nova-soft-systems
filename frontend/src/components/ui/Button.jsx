import { forwardRef } from 'react';
import { Link } from 'react-router';
import { cx } from '../../utils/cx';
import { Icon } from './Icon';
import { Spinner } from './Spinner';
import styles from './Button.module.scss';

/**
 * Botón reutilizable. Con `to` se renderiza como enlace interno, con `href`
 * como enlace externo/navegación completa; en otro caso como <button>.
 */
export const Button = forwardRef(function Button(
  { variant = 'primary', size = 'md', icon, iconRight, loading = false, block = false, to, href, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  const classes = cx(styles.button, styles[variant], styles[size], block && styles.block, loading && styles.loading, className);
  const content = (
    <>
      {loading ? <Spinner size={size === 'sm' ? 14 : 16} /> : icon && <Icon name={icon} size={size === 'sm' ? 16 : 18} />}
      {children && <span className={styles.label}>{children}</span>}
      {iconRight && !loading && <Icon name={iconRight} size={size === 'sm' ? 16 : 18} />}
    </>
  );

  if (to) {
    return (
      <Link ref={ref} to={to} className={classes} {...rest}>
        {content}
      </Link>
    );
  }

  if (href) {
    return (
      <a ref={ref} href={href} className={classes} {...rest}>
        {content}
      </a>
    );
  }

  return (
    <button ref={ref} type={type} className={classes} disabled={disabled || loading} aria-busy={loading || undefined} {...rest}>
      {content}
    </button>
  );
});
