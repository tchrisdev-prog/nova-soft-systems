import { Link } from 'react-router';
import { Logo } from '../brand/Logo';
import styles from './Footer.module.scss';

export function Footer() {
  const year = new Date().getFullYear();

  return (
    <footer className={styles.footer}>
      <div className={styles.inner}>
        <div className={styles.brand}>
          <Logo />
          <p className={styles.tagline}>
            Plataforma ERP modular para la gestión centralizada de los procesos empresariales.
          </p>
        </div>

        <nav aria-label="Pie de página">
          <ul className={styles.links}>
            <li>
              <a href="#plataforma">Plataforma</a>
            </li>
            <li>
              <a href="#modulos">Módulos</a>
            </li>
            <li>
              <a href="#seguridad">Seguridad y acceso</a>
            </li>
            <li>
              <Link to="/login">Ingresar al sistema</Link>
            </li>
          </ul>
        </nav>
      </div>
      <div className={styles.bottom}>
        <p>© {year} Nova Sync Systems. Todos los derechos reservados.</p>
        <p>Microsoft 365 y Microsoft Entra ID son marcas de Microsoft Corporation.</p>
      </div>
    </footer>
  );
}
