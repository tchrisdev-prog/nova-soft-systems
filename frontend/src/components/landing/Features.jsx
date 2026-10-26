import { Icon } from '../ui/Icon';
import styles from './Features.module.scss';

const FEATURES = [
  {
    icon: 'layers',
    title: 'Modularidad',
    text: 'Cada proceso vive en su propio módulo. Los nuevos se incorporan por etapas sin rehacer lo que ya funciona.',
  },
  {
    icon: 'shield',
    title: 'Seguridad',
    text: 'Cada operación se valida en el servidor, la sesión viaja en una cookie protegida y los cambios sensibles quedan registrados.',
  },
  {
    icon: 'users',
    title: 'Gestión de usuarios',
    text: 'Autoriza cuentas corporativas, consulta su estado y desactívalas cuando sea necesario; sus sesiones abiertas se cierran de inmediato.',
  },
  {
    icon: 'key',
    title: 'Control de acceso',
    text: 'Permisos por módulo y por acción. Un administrador no puede otorgar permisos que él mismo no tiene.',
  },
  {
    icon: 'building',
    title: 'Escalabilidad',
    text: 'API REST en Laravel y una interfaz en React preparadas para sumar módulos, usuarios y equipos de trabajo.',
  },
];

export function Features() {
  return (
    <section className={styles.section} aria-labelledby="features-title">
      <div className={styles.inner}>
        <div className={styles.heading}>
          <h2 id="features-title" className={styles.title}>
            Bases sólidas desde el primer módulo.
          </h2>
          <p className={styles.lead}>
            Antes de sumar contratos, proyectos y registro de horas, la plataforma resuelve lo que todos los módulos necesitan: quién
            entra, qué puede hacer y qué cambió.
          </p>
        </div>

        <ul className={styles.list}>
          {FEATURES.map((feature) => (
            <li key={feature.title} className={styles.item}>
              <span className={styles.icon}>
                <Icon name={feature.icon} size={22} />
              </span>
              <div>
                <h3 className={styles.itemTitle}>{feature.title}</h3>
                <p className={styles.itemText}>{feature.text}</p>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
