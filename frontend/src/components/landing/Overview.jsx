import { Icon } from '../ui/Icon';
import styles from './Overview.module.scss';

const CHAIN = [
  { icon: 'user', title: 'Personas', text: 'Cuentas corporativas de Microsoft 365 autorizadas.' },
  { icon: 'key', title: 'Roles', text: 'Agrupan responsabilidades, por ejemplo “Líder de proyecto”.' },
  { icon: 'check', title: 'Permisos', text: 'Acciones concretas: consultar, crear, actualizar, eliminar, administrar.' },
  { icon: 'grid', title: 'Módulos', text: 'Cada proceso de la empresa en su propio espacio.' },
];

export function Overview() {
  return (
    <section id="plataforma" className={styles.section} aria-labelledby="overview-title">
      <div className={styles.inner}>
        <div className={styles.intro}>
          <h2 id="overview-title" className={styles.title}>
            Un solo lugar para la información que hoy vive dispersa.
          </h2>
          <div className={styles.body}>
            <p>
              Hojas de cálculo, correos y aplicaciones aisladas hacen difícil saber quién cambió qué y con qué autorización.
              Nova Sync Systems centraliza esa gestión: una base de datos, un único inicio de sesión corporativo y reglas de
              acceso que se definen una vez y se aplican en todos los módulos.
            </p>
            <p>El acceso sigue siempre la misma cadena, de la persona al módulo:</p>
          </div>
        </div>

        <ol className={styles.chain}>
          {CHAIN.map((step) => (
            <li key={step.title} className={styles.step}>
              <span className={styles.stepIcon}>
                <Icon name={step.icon} size={20} />
              </span>
              <h3 className={styles.stepTitle}>{step.title}</h3>
              <p className={styles.stepText}>{step.text}</p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}
