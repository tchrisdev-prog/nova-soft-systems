import { useState } from 'react';
import { PUBLIC_MODULES, STATUS_LABELS } from '../../config/modules';
import { cx } from '../../utils/cx';
import styles from './ModuleBoard.module.scss';

/**
 * Mapa de módulos del ERP: los disponibles en sólido y los que están en
 * desarrollo con trazo discontinuo. El núcleo de acceso ocupa el centro de
 * la primera fila y la animación se enciende desde ahí hacia afuera.
 * Cada casilla es navegable con teclado y muestra su descripción.
 */
export function ModuleBoard() {
  const [selected, setSelected] = useState('access_control');
  const current = PUBLIC_MODULES.find((m) => m.code === selected);

  return (
    <figure className={styles.board} aria-labelledby="module-board-caption">
      <div className={styles.grid} role="group" aria-label="Módulos de Nova Sync Systems">
        {PUBLIC_MODULES.map((module, index) => (
          <button
            key={module.code}
            type="button"
            className={cx(styles.tile, styles[module.status], module.core && styles.core, selected === module.code && styles.selected)}
            style={{ '--delay': `${[1, 0, 1, 2, 2, 2][index] * 110}ms` }}
            aria-pressed={selected === module.code}
            onClick={() => setSelected(module.code)}
            onMouseEnter={() => setSelected(module.code)}
            onFocus={() => setSelected(module.code)}
          >
            <span className={styles.symbol} aria-hidden="true">
              {module.symbol}
            </span>
            <span className={styles.name}>{module.name}</span>
            <span className="visually-hidden">, {STATUS_LABELS[module.status]}</span>
          </button>
        ))}
      </div>

      <figcaption id="module-board-caption" className={styles.caption} aria-live="polite">
        <span className={cx(styles.status, styles[`status-${current.status}`])}>{STATUS_LABELS[current.status]}</span>
        <strong className={styles.captionTitle}>{current.name}</strong>
        <span className={styles.captionText}>{current.description}</span>
      </figcaption>
    </figure>
  );
}
