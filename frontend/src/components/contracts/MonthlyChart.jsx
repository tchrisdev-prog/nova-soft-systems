import { cx } from '../../utils/cx';
import { formatHours } from '../../utils/format';
import styles from './Contracts.module.scss';

const monthShort = new Intl.DateTimeFormat('es-CO', { month: 'short' });

function label(period) {
  const [y, m] = period.split('-').map(Number);
  return monthShort.format(new Date(y, m - 1, 1)).replace('.', '');
}

/**
 * Horas aprobadas por mes frente a las horas incluidas (línea punteada).
 * Lo pendiente de aprobar se ve en un tono claro encima de lo aprobado.
 */
export function MonthlyChart({ periods }) {
  const max = Math.max(1, ...periods.map((p) => Math.max(p.approved_minutes + p.pending_minutes, p.included_minutes ?? 0))) * 1.1;
  const pct = (minutes) => `${(minutes / max) * 100}%`;

  return (
    <figure className={styles.chart} aria-label="Horas por mes">
      <div className={styles.bars} role="img" aria-label={periods.map((p) => `${label(p.period)}: ${formatHours(p.approved_minutes)} aprobadas${p.included_minutes !== null ? ` de ${formatHours(p.included_minutes)} incluidas` : ''}`).join('; ')}>
        {periods.map((p) => (
          <div key={p.period} className={styles.barColumn} title={`${formatHours(p.approved_minutes)} aprobadas, ${formatHours(p.pending_minutes)} pendientes`}>
            {p.included_minutes !== null && <span className={styles.included} style={{ bottom: pct(p.included_minutes) }} />}
            {p.pending_minutes > 0 && <span className={styles.barPending} style={{ height: pct(p.pending_minutes) }} />}
            <span className={cx(styles.bar, p.overage_minutes > 0 && styles.barOver)} style={{ height: pct(p.approved_minutes) }} />
          </div>
        ))}
      </div>
      <div className={styles.barLabels} aria-hidden="true">
        {periods.map((p) => (
          <span key={p.period}>{label(p.period)}</span>
        ))}
      </div>
      <figcaption className={styles.legend}>
        <span>
          <i className={styles.swatchApproved} /> Horas aprobadas
        </span>
        <span>
          <i className={styles.swatchPending} /> Pendientes de aprobar
        </span>
        {periods.some((p) => p.included_minutes !== null) && (
          <span>
            <i className={styles.swatchIncluded} /> Horas incluidas
          </span>
        )}
      </figcaption>
    </figure>
  );
}
