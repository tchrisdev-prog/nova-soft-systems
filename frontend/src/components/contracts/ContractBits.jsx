import { Badge } from '../ui/Badge';
import { cx } from '../../utils/cx';
import { formatHours, formatPercent } from '../../utils/format';
import styles from '../business/Business.module.scss';

const STATUS_TONES = { draft: 'neutral', active: 'success', suspended: 'warning', finished: 'primary', cancelled: 'danger' };

export function ContractStatusBadge({ status }) {
  if (!status) return null;
  return (
    <Badge tone={STATUS_TONES[status.value] ?? 'neutral'} dot>
      {status.label}
    </Badge>
  );
}

/** Alertas calculadas por el servidor (bolsa por agotarse, excedente, vencimiento...). */
export function AlertList({ alerts, emptyText }) {
  if (!alerts?.length) return emptyText ? <span className={styles.noAlerts}>{emptyText}</span> : null;
  return (
    <span className={styles.alerts}>
      {alerts.map((alert) => (
        <Badge key={alert.code} tone={alert.level === 'danger' ? 'danger' : 'warning'}>
          {alert.message}
        </Badge>
      ))}
    </span>
  );
}

function level(ratio) {
  if (ratio === null || ratio === undefined) return null;
  if (ratio > 1) return 'danger';
  if (ratio >= 0.8) return 'warning';
  return null;
}

/**
 * Consumo de un contrato en una barra: bolsa (aprobado de lo comprado) o
 * mensualidad (aprobado este mes de lo incluido). Lo pendiente de aprobar se
 * muestra en un tono claro detrás, para ver lo que viene en camino.
 */
export function ConsumptionBar({ consumption }) {
  if (!consumption) return null;

  const isBag = consumption.type === 'hour_bag';
  const total = isBag ? consumption.purchased_minutes : consumption.included_minutes;
  const approved = consumption.approved_minutes;
  const pending = consumption.pending_minutes ?? 0;

  if (!total) {
    return (
      <span className={styles.consumptionText}>
        <span>
          <strong>{formatHours(approved)}</strong> este mes
        </span>
        <span>solo disponibilidad</span>
      </span>
    );
  }

  const approvedWidth = Math.min(100, (approved / total) * 100);
  const pendingWidth = Math.min(100, ((approved + pending) / total) * 100);
  const ratio = consumption.consumed_ratio;

  return (
    <div className={cx(styles.consumption, styles[level(ratio)])}>
      <div
        className={styles.track}
        role="meter"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={Math.round((ratio ?? 0) * 100)}
        aria-label={isBag ? 'Consumo de la bolsa de horas' : 'Consumo de horas de este mes'}
      >
        <span className={styles.pending} style={{ width: `${pendingWidth}%` }} />
        <span className={styles.approved} style={{ width: `${approvedWidth}%` }} />
      </div>
      <span className={styles.consumptionText}>
        <span>
          <strong>{formatHours(approved, { unit: false })}</strong> de {formatHours(total)}
          {!isBag && ' este mes'}
        </span>
        <span>{formatPercent(ratio)}</span>
      </span>
    </div>
  );
}

export function Metric({ label, value, detail }) {
  return (
    <div className={styles.metric}>
      <dt>{label}</dt>
      <dd>
        <span className="tabular">{value}</span>
        {detail && <span className={styles.metricDetail}>{detail}</span>}
      </dd>
    </div>
  );
}
