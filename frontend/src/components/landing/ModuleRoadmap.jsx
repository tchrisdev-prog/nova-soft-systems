import { PUBLIC_MODULES } from '../../config/modules';
import { cx } from '../../utils/cx';
import styles from './ModuleRoadmap.module.scss';

const available = PUBLIC_MODULES.filter((m) => m.status === 'active').sort((a, b) => Number(Boolean(b.core)) - Number(Boolean(a.core)));
const planned = PUBLIC_MODULES.filter((m) => m.status === 'planned');

function ModuleRow({ module }) {
  return (
    <li className={styles.row}>
      <span className={cx(styles.symbol, styles[module.status], module.core && styles.core)} aria-hidden="true">
        {module.symbol}
      </span>
      <div>
        <h4 className={styles.name}>{module.name}</h4>
        <p className={styles.description}>{module.description}</p>
      </div>
    </li>
  );
}

export function ModuleRoadmap() {
  return (
    <section id="modulos" className={styles.section} aria-labelledby="modules-title">
      <div className={styles.inner}>
        <header className={styles.header}>
          <h2 id="modules-title" className={styles.title}>
            Arquitectura modular: se crece por etapas.
          </h2>
          <p className={styles.lead}>
            Cada módulo tiene un código, un estado y sus propios permisos. Agregar uno nuevo no exige cambiar la forma en que se
            autoriza el acceso.
          </p>
        </header>

        <div className={styles.columns}>
          <div className={styles.group}>
            <h3 className={styles.groupTitle}>
              <span className={cx(styles.marker, styles.markerActive)} aria-hidden="true" />
              Disponible hoy
            </h3>
            <ul className={styles.list}>
              {available.map((module) => (
                <ModuleRow key={module.code} module={module} />
              ))}
            </ul>
          </div>

          <div className={styles.group}>
            <h3 className={styles.groupTitle}>
              <span className={cx(styles.marker, styles.markerPlanned)} aria-hidden="true" />
              En desarrollo
            </h3>
            <ul className={styles.list}>
              {planned.map((module) => (
                <ModuleRow key={module.code} module={module} />
              ))}
            </ul>
            <p className={styles.footnote}>
              Estos módulos ya forman parte de la arquitectura y del modelo de permisos; se habilitarán a medida que se publiquen.
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
