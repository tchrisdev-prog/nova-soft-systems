import { cx } from '../../utils/cx';
import styles from './Table.module.scss';

/**
 * Tabla administrativa. En pantallas pequeñas cada fila se presenta como
 * bloque apilado usando el atributo data-label de cada celda.
 */
export function Table({ caption, columns, children, className }) {
  return (
    <div className={cx(styles.wrapper, className)}>
      <table className={styles.table}>
        {caption && <caption className="visually-hidden">{caption}</caption>}
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key} scope="col" className={cx(column.align === 'end' && styles.end)} style={column.width ? { width: column.width } : undefined}>
                {column.hidden ? <span className="visually-hidden">{column.label}</span> : column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}

export function Cell({ label, align, children, className }) {
  return (
    <td data-label={label} className={cx(align === 'end' && styles.end, className)}>
      <div className={styles.cellContent}>{children}</div>
    </td>
  );
}

export function SkeletonRows({ columns, rows = 5 }) {
  return Array.from({ length: rows }, (_, rowIndex) => (
    <tr key={rowIndex} aria-hidden="true">
      {Array.from({ length: columns }, (_, columnIndex) => (
        <td key={columnIndex}>
          <span className={styles.skeleton} style={{ width: `${45 + ((rowIndex * 7 + columnIndex * 13) % 45)}%` }} />
        </td>
      ))}
    </tr>
  ));
}
