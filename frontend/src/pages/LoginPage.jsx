import { useEffect, useState } from 'react';
import { Link, Navigate, useSearchParams } from 'react-router';
import { Logo } from '../components/brand/Logo';
import { Button } from '../components/ui/Button';
import { Icon } from '../components/ui/Icon';
import { Spinner } from '../components/ui/Spinner';
import { MICROSOFT_LOGIN_PATH, NEXT_PATH_KEY } from '../config/app';
import { useAuth } from '../hooks/useAuth';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { authService } from '../services/authService';
import { authErrorMessage } from '../utils/authMessages';
import styles from './LoginPage.module.scss';

/** Solo se aceptan rutas internas del panel (evita redirecciones abiertas). */
function safeNext(value) {
  return value && /^\/app(\/[\w\-/?=&%.]*)?$/.test(value) ? value : null;
}

export default function LoginPage() {
  useDocumentTitle('Ingresar');
  const auth = useAuth();
  const [params] = useSearchParams();
  const [config, setConfig] = useState({ loading: true, configured: null, error: null });
  const [redirecting, setRedirecting] = useState(false);

  const errorCode = params.get('error');
  const loggedOut = params.get('logged_out') === '1';
  const next = safeNext(params.get('next'));

  useEffect(() => {
    if (auth.status === 'idle') auth.load();
  }, [auth]);

  useEffect(() => {
    let active = true;
    authService
      .config()
      .then((data) => active && setConfig({ loading: false, configured: data.configured, error: null }))
      .catch((error) => active && setConfig({ loading: false, configured: null, error }));
    return () => {
      active = false;
    };
  }, []);

  if (auth.status === 'authenticated') {
    return <Navigate to={next ?? '/app'} replace />;
  }

  function startLogin() {
    setRedirecting(true);
    try {
      if (next) sessionStorage.setItem(NEXT_PATH_KEY, next);
    } catch {
      // Almacenamiento no disponible: se ingresa al panel principal.
    }
    // Navegación completa: Laravel inicia el flujo OAuth 2.0 / OpenID Connect.
    window.location.assign(MICROSOFT_LOGIN_PATH);
  }

  const message = authErrorMessage(errorCode);
  const unavailable = config.configured === false || Boolean(config.error);

  return (
    <div className={styles.page}>
      <header className={styles.top}>
        <Link to="/" className={styles.brand} aria-label="Volver al inicio de Nova Sync Systems">
          <Logo />
        </Link>
        <Link to="/" className={styles.back}>
          <Icon name="chevron-left" size={16} />
          Volver al inicio
        </Link>
      </header>

      <main className={styles.main}>
        <section className={styles.card} aria-labelledby="login-title">
          <h1 id="login-title" className={styles.title}>
            Ingresa a Nova Sync Systems
          </h1>
          <p className={styles.text}>
            Usa tu cuenta corporativa de Microsoft 365. Te llevaremos a la página oficial de Microsoft: tu contraseña nunca se
            escribe ni se guarda en este sistema.
          </p>

          {message && (
            <div className={styles.alert} role="alert">
              <Icon name="alert" size={18} />
              <p>{message}</p>
            </div>
          )}

          {loggedOut && !message && (
            <div className={styles.notice} role="status">
              <Icon name="check" size={18} />
              <p>Cerraste sesión correctamente.</p>
            </div>
          )}

          {config.configured === false && errorCode !== 'not_configured' && (
            <div className={styles.warning} role="status">
              <Icon name="info" size={18} />
              <p>
                El inicio de sesión con Microsoft 365 aún no está configurado en el servidor. Consulta la guía de configuración de
                Microsoft Entra ID del proyecto.
              </p>
            </div>
          )}

          {config.error && (
            <div className={styles.warning} role="status">
              <Icon name="info" size={18} />
              <p>{config.error.message}</p>
            </div>
          )}

          <Button
            size="lg"
            variant="secondary"
            block
            icon="microsoft"
            className={styles.microsoft}
            onClick={startLogin}
            loading={redirecting}
            disabled={config.loading || unavailable}
          >
            {redirecting ? 'Redirigiendo a Microsoft…' : 'Ingresar con Microsoft 365'}
          </Button>

          {config.loading && (
            <p className={styles.checking}>
              <Spinner size={14} /> Comprobando la configuración de acceso…
            </p>
          )}

          <p className={styles.help}>
            ¿No tienes acceso? Solicita al administrador del sistema que autorice tu cuenta corporativa.
          </p>
        </section>
      </main>
    </div>
  );
}
