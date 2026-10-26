import { useEffect, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router';
import { Sidebar } from '../components/admin/Sidebar';
import { Topbar } from '../components/admin/Topbar';
import { NEXT_PATH_KEY } from '../config/app';
import styles from './AdminLayout.module.scss';

export default function AdminLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();

  // Tras volver de Microsoft, retoma la ruta que el usuario intentaba abrir.
  useEffect(() => {
    try {
      const next = sessionStorage.getItem(NEXT_PATH_KEY);
      if (next) {
        sessionStorage.removeItem(NEXT_PATH_KEY);
        if (next.startsWith('/app/')) navigate(next, { replace: true });
      }
    } catch {
      // Sin acceso a sessionStorage: se permanece en el panel.
    }
  }, [navigate]);

  useEffect(() => setSidebarOpen(false), [location.pathname]);

  useEffect(() => {
    if (!sidebarOpen) return undefined;
    const onKey = (event) => event.key === 'Escape' && setSidebarOpen(false);
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [sidebarOpen]);

  return (
    <div className={styles.shell}>
      <a href="#panel-content" className="skip-link">
        Saltar al contenido
      </a>
      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
      <div className={styles.main}>
        <Topbar onMenu={() => setSidebarOpen(true)} sidebarOpen={sidebarOpen} />
        <main id="panel-content" className={styles.content} tabIndex={-1}>
          <Outlet />
        </main>
      </div>
    </div>
  );
}
