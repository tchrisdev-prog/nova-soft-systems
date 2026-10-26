import { Link, NavLink } from 'react-router';
import { useAuth } from '../../hooks/useAuth';
import { cx } from '../../utils/cx';
import styles from './Business.module.scss';

// Pestañas de cada módulo. Una pestaña con `anyOf` solo aparece si la
// persona tiene alguno de esos permisos (la API igual lo vuelve a revisar).
const MODULE_TABS = {
  clients: [
    { to: '/app/clients', label: 'Clientes' },
    { to: '/app/contracts', label: 'Contratos' },
  ],
  projects: [
    { to: '/app/projects', label: 'Proyectos', anyOf: ['projects.view', 'projects.lead'] },
    { to: '/app/capacity', label: 'Capacidad', anyOf: ['projects.capacity'] },
    { to: '/app/staff', label: 'Perfiles', anyOf: ['projects.staff'] },
  ],
};

export function ModuleTabs({ module = 'clients' }) {
  const { canAny } = useAuth();
  const tabs = MODULE_TABS[module].filter((tab) => !tab.anyOf || canAny(tab.anyOf));

  if (tabs.length < 2) return null;

  return (
    <nav className={styles.tabs} aria-label="Secciones del módulo">
      {tabs.map((tab) => (
        <NavLink key={tab.to} to={tab.to} className={({ isActive }) => cx(styles.tab, isActive && styles.tabActive)}>
          {tab.label}
        </NavLink>
      ))}
    </nav>
  );
}

export function BackLink({ to, children }) {
  return (
    <Link to={to} className={styles.back}>
      <span aria-hidden="true">‹</span> {children}
    </Link>
  );
}
