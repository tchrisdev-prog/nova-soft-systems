import { Badge } from '../ui/Badge';
import { cx } from '../../utils/cx';
import { formatHours, formatPercent } from '../../utils/format';
import styles from './Projects.module.scss';

const STATUS_TONES = { planned: 'neutral', active: 'success', on_hold: 'warning', closed: 'primary' };

export function ProjectStatusBadge({ status }) {
  if (!status) return null;
  return (
    <Badge tone={STATUS_TONES[status.value] ?? 'neutral'} dot>
      {status.label}
    </Badge>
  );
}

/** Señales de salud calculadas por el servidor (en riesgo, vencido, sin equipo...). */
export function HealthFlags({ health, emptyText = 'Al día' }) {
  if (!health) return null;
  if (!health.flags.length) return <span className={styles.ok}>{emptyText}</span>;

  return (
    <span className={styles.flags}>
      {health.flags.map((flag) => (
        <Badge key={flag.code} tone={flag.severity === 'danger' ? 'danger' : 'warning'}>
          {flag.label}
        </Badge>
      ))}
    </span>
  );
}

/**
 * Consumo contra el presupuesto: el del proyecto o, si no tiene, la bolsa del
 * contrato (que comparten todos sus proyectos). Lo aprobado va en sólido y lo
 * enviado sin aprobar en claro.
 */
export function BudgetBar({ health }) {
  if (!health) return null;

  if (!health.budget_minutes) {
    return (
      <span className={styles.budgetText}>
        <span>
          <strong>{formatHours(health.consumed_minutes)}</strong> registradas
        </span>
        <span>sin presupuesto</span>
      </span>
    );
  }

  const ratio = health.budget_ratio ?? 0;
  const tone = ratio > 1 ? 'danger' : ratio >= 0.9 ? 'warning' : null;
  const width = Math.min(100, ratio * 100);

  return (
    <div className={cx(styles.budget, tone && styles[tone])}>
      <div className={styles.track} role="meter" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(ratio * 100)} aria-label="Consumo del presupuesto de horas">
        <span className={styles.fill} style={{ width: `${width}%` }} />
      </div>
      <span className={styles.budgetText}>
        <span>
          <strong>{formatHours(health.budget_consumed_minutes, { unit: false })}</strong> de {formatHours(health.budget_minutes)}
          {health.budget_source === 'contract_bag' && ' (bolsa)'}
        </span>
        <span>{formatPercent(health.budget_ratio)}</span>
      </span>
    </div>
  );
}

/**
 * Colores de carga acordados: libre por debajo del 70 %, ocupado del 70 % al
 * 100 % y sobreasignado por encima del 100 %.
 */
export function loadLevel(ratio) {
  if (ratio === null || ratio === undefined) return 'none';
  if (ratio > 1) return 'over';
  if (ratio >= 0.7) return 'busy';
  return 'free';
}

export const LOAD_LABELS = { free: 'Libre', busy: 'Ocupado', over: 'Sobreasignado', none: 'Sin capacidad' };

export function LoadLegend() {
  return (
    <ul className={styles.legend} aria-label="Convenciones de carga">
      <li>
        <span className={cx(styles.swatch, styles.free)} aria-hidden="true" /> Libre (menos de 70 %)
      </li>
      <li>
        <span className={cx(styles.swatch, styles.busy)} aria-hidden="true" /> Ocupado (70 % a 100 %)
      </li>
      <li>
        <span className={cx(styles.swatch, styles.over)} aria-hidden="true" /> Sobreasignado (más de 100 %)
      </li>
    </ul>
  );
}
