import { NavLink, useLocation } from 'react-router';
import { Logo } from '../brand/Logo';
import { Icon } from '../ui/Icon';
import { MODULE_ICONS } from '../../config/modules';
import { ADMIN_MODULES, MODULE_EXTRA_PATHS, MODULE_ROUTES } from '../../config/navigation';
import { useAuth } from '../../hooks/useAuth';
import { cx } from '../../utils/cx';
import styles from './Sidebar.module.scss';

function Item({ to, icon, children, end, alsoActiveOn = [] }) {
  const { pathname } = useLocation();
  const extra = alsoActiveOn.some((path) => pathname.startsWith(path));
  return (
    <li>
      <NavLink to={to} end={end} className={({ isActive }) => cx(styles.link, (isActive || extra) && styles.active)}>
        <Icon name={icon} size={18} />
        <span>{children}</span>
      </NavLink>
    </li>
  );
}

export function Sidebar({ open, onClose }) {
  const { modules } = useAuth();
  const available = modules.filter((m) => m.can_access && MODULE_ROUTES[m.code]);
  const business = available.filter((m) => !ADMIN_MODULES.has(m.code));
  const admin = available.filter((m) => ADMIN_MODULES.has(m.code));
  const planned = modules.filter((m) => m.status === 'planned');

  return (
    <>
      <div className={cx(styles.scrim, open && styles.scrimVisible)} onClick={onClose} aria-hidden="true" />
      <aside id="admin-sidebar" className={cx(styles.sidebar, open && styles.open)} aria-label="Navegación del panel">
        <div className={styles.brand}>
          <Logo tone="light" size={28} />
          <button type="button" className={styles.close} onClick={onClose} aria-label="Cerrar menú">
            <Icon name="x" size={20} />
          </button>
        </div>

        <nav className={styles.nav}>
          <ul className={styles.list}>
            <Item to="/app" icon="home" end>
              Panel
            </Item>
            <Item to="/app/modules" icon="grid">
              Módulos
            </Item>
          </ul>

          {business.length > 0 && (
            <div className={styles.group}>
              <p className={styles.groupTitle} id="nav-business">
                Operación
              </p>
              <ul className={styles.list} aria-labelledby="nav-business">
                {business.map((module) => (
                  <Item key={module.code} to={MODULE_ROUTES[module.code]} icon={MODULE_ICONS[module.code] ?? 'grid'} alsoActiveOn={MODULE_EXTRA_PATHS[module.code]}>
                    {module.name}
                  </Item>
                ))}
              </ul>
            </div>
          )}

          {admin.length > 0 && (
            <div className={styles.group}>
              <p className={styles.groupTitle} id="nav-admin">
                Administración
              </p>
              <ul className={styles.list} aria-labelledby="nav-admin">
                {admin.map((module) => (
                  <Item key={module.code} to={MODULE_ROUTES[module.code]} icon={MODULE_ICONS[module.code] ?? 'grid'}>
                    {module.name}
                  </Item>
                ))}
              </ul>
            </div>
          )}

          {planned.length > 0 && (
            <div className={styles.group}>
              <p className={styles.groupTitle} id="nav-planned">
                En desarrollo
              </p>
              <ul className={styles.list} aria-labelledby="nav-planned">
                {planned.map((module) => (
                  <li key={module.code} className={styles.planned}>
                    <Icon name={MODULE_ICONS[module.code] ?? 'grid'} size={18} />
                    <span>{module.name}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </nav>
      </aside>
    </>
  );
}
