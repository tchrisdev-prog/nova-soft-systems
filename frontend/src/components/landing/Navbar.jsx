import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Logo } from '../brand/Logo';
import { Button } from '../ui/Button';
import { Icon } from '../ui/Icon';
import { cx } from '../../utils/cx';
import styles from './Navbar.module.scss';

const LINKS = [
  { href: '#plataforma', label: 'Plataforma' },
  { href: '#modulos', label: 'Módulos' },
  { href: '#seguridad', label: 'Seguridad' },
];

export function Navbar() {
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (event) => event.key === 'Escape' && setOpen(false);
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  return (
    <header className={cx(styles.header, scrolled && styles.scrolled)}>
      <div className={styles.inner}>
        <Link to="/" className={styles.brand} aria-label="Nova Sync Systems, inicio">
          <Logo />
        </Link>

        <nav aria-label="Principal" className={styles.nav}>
          <button
            type="button"
            className={styles.toggle}
            aria-expanded={open}
            aria-controls="landing-menu"
            onClick={() => setOpen((value) => !value)}
          >
            <Icon name={open ? 'x' : 'menu'} size={22} />
            <span className="visually-hidden">{open ? 'Cerrar menú' : 'Abrir menú'}</span>
          </button>

          <div id="landing-menu" className={cx(styles.menu, open && styles.menuOpen)}>
            <ul className={styles.links}>
              {LINKS.map((link) => (
                <li key={link.href}>
                  <a href={link.href} className={styles.link} onClick={() => setOpen(false)}>
                    {link.label}
                  </a>
                </li>
              ))}
            </ul>
            <Button to="/login" size="md" className={styles.cta}>
              Ingresar al sistema
            </Button>
          </div>
        </nav>
      </div>
    </header>
  );
}
