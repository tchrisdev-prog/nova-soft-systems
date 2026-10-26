import { Icon } from '../ui/Icon';
import styles from './SecuritySection.module.scss';

const STEPS = [
  { title: 'Eliges “Ingresar con Microsoft 365”', text: 'Te llevamos a la página oficial de inicio de sesión de Microsoft.' },
  { title: 'Microsoft confirma tu identidad', text: 'Con la contraseña y la verificación en dos pasos que ya usa tu organización.' },
  { title: 'Nova valida tu autorización', text: 'El servidor comprueba la firma, el tenant corporativo y que tu cuenta esté autorizada y activa.' },
  { title: 'Accedes a tus módulos', text: 'Ves únicamente los módulos y acciones que te otorgan tus roles.' },
];

const GUARANTEES = [
  'Nova Sync Systems nunca ve ni almacena tu contraseña de Microsoft.',
  'Solo ingresan cuentas de tu organización que un administrador haya autorizado.',
  'Cada operación se autoriza en el servidor, no solo en la pantalla.',
  'Desactivar una cuenta cierra de inmediato sus sesiones abiertas.',
  'Los cambios de roles, permisos y accesos quedan registrados en la auditoría.',
];

export function SecuritySection() {
  return (
    <section id="seguridad" className={styles.section} aria-labelledby="security-title">
      <div className={styles.inner}>
        <header className={styles.header}>
          <h2 id="security-title" className={styles.title}>
            Entras con tu cuenta de Microsoft 365. Nova decide qué puedes hacer.
          </h2>
          <p className={styles.lead}>
            La autenticación la realiza Microsoft Entra ID; la autorización por roles y permisos, Nova Sync Systems. Son dos
            responsabilidades separadas.
          </p>
        </header>

        <div className={styles.grid}>
          <ol className={styles.steps}>
            {STEPS.map((step, index) => (
              <li key={step.title} className={styles.step}>
                <span className={styles.number} aria-hidden="true">
                  {index + 1}
                </span>
                <div>
                  <h3 className={styles.stepTitle}>{step.title}</h3>
                  <p className={styles.stepText}>{step.text}</p>
                </div>
              </li>
            ))}
          </ol>

          <div className={styles.guarantees}>
            <h3 className={styles.guaranteesTitle}>
              <Icon name="lock" size={20} />
              Lo que protege tu acceso
            </h3>
            <ul className={styles.guaranteeList}>
              {GUARANTEES.map((item) => (
                <li key={item}>
                  <Icon name="check" size={18} className={styles.check} />
                  {item}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </section>
  );
}
