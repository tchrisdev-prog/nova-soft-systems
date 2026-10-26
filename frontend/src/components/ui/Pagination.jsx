import { Button } from './Button';
import styles from './Pagination.module.scss';

/** Paginación a partir del bloque "meta" de Laravel. */
export function Pagination({ meta, onPageChange, label = 'resultados' }) {
  if (!meta || meta.total === 0) return null;

  const { current_page: page, last_page: last, from, to, total } = meta;

  return (
    <nav className={styles.pagination} aria-label="Paginación">
      <p className={styles.summary}>
        <span className="tabular">
          {from}–{to}
        </span>{' '}
        de <span className="tabular">{total}</span> {label}
      </p>
      {last > 1 && (
        <div className={styles.controls}>
          <Button variant="secondary" size="sm" icon="chevron-left" onClick={() => onPageChange(page - 1)} disabled={page <= 1} aria-label="Página anterior" />
          <span className={styles.page}>
            Página <span className="tabular">{page}</span> de <span className="tabular">{last}</span>
          </span>
          <Button variant="secondary" size="sm" icon="chevron-right" onClick={() => onPageChange(page + 1)} disabled={page >= last} aria-label="Página siguiente" />
        </div>
      )}
    </nav>
  );
}
