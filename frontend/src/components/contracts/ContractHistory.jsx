import { useEffect, useState } from 'react';
import { Button } from '../ui/Button';
import { ErrorState, LoadingState } from '../ui/StateMessage';
import { useReferenceData } from '../../hooks/useReferenceData';
import { contractsService } from '../../services/contractsService';
import { cx } from '../../utils/cx';
import { formatCalendarDate, formatCurrency, formatDateTime, formatHours } from '../../utils/format';
import styles from './Contracts.module.scss';

const FIELD_LABELS = {
  name: 'Nombre',
  code: 'Código',
  service_line: 'Línea de servicio',
  billing_model: 'Forma de cobro',
  coverage: 'Cobertura',
  status: 'Estado',
  start_date: 'Inicio',
  end_date: 'Fin',
  monthly_fee: 'Valor mensual',
  included_minutes_per_month: 'Horas incluidas al mes',
  notes: 'Notas',
  minutes: 'Horas',
  amount: 'Valor',
  period: 'Mes',
  fee_amount: 'Valor del mes',
  included_minutes: 'Horas incluidas',
};

const MONEY = new Set(['monthly_fee', 'amount', 'fee_amount']);
const DURATION = new Set(['minutes', 'included_minutes', 'included_minutes_per_month']);
const DATES = new Set(['start_date', 'end_date']);
const OPTION_LISTS = { status: 'contract_statuses', coverage: 'coverages', service_line: 'service_lines', billing_model: 'billing_models' };
const month = new Intl.DateTimeFormat('es-CO', { month: 'long', year: 'numeric' });

function useFormatter() {
  const reference = useReferenceData();

  return (field, value) => {
    if (value === null || value === undefined || value === '') return field.includes('included') ? 'solo disponibilidad' : '—';
    if (MONEY.has(field)) return formatCurrency(value);
    if (DURATION.has(field)) return formatHours(value);
    if (DATES.has(field)) return formatCalendarDate(value);
    if (field === 'period') {
      const [y, m] = value.split('-').map(Number);
      return month.format(new Date(y, m - 1, 1));
    }
    if (OPTION_LISTS[field]) return reference.label(OPTION_LISTS[field], value);
    return String(value);
  };
}

/**
 * Historial del contrato como línea de tiempo: qué cambió (antes y después),
 * quién lo hizo, cuándo y por qué. Los eventos automáticos (abrir un mes)
 * aparecen como "Sistema".
 */
export function ContractHistory({ contractId, version }) {
  const format = useFormatter();
  const [pages, setPages] = useState({ items: [], meta: null });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  async function load(page = 1) {
    setLoading(true);
    setError(null);
    try {
      const response = await contractsService.history(contractId, { page, per_page: 15 });
      setPages((prev) => ({ items: page === 1 ? response.data : [...prev.items, ...response.data], meta: response.meta }));
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load(1);
    // `version` cambia cuando el contrato se modifica, para traer el evento nuevo.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [contractId, version]);

  if (error) return <ErrorState error={error} onRetry={() => load(1)} compact />;
  if (loading && pages.items.length === 0) return <LoadingState label="Cargando historial…" />;

  const hasMore = pages.meta && pages.meta.current_page < pages.meta.last_page;

  return (
    <>
      <ol className={styles.timeline}>
        {pages.items.map((event) => {
          const changes = Object.entries(event.changes ?? {});
          return (
            <li key={event.id} className={cx(styles.event, !event.actor && styles.eventSystem)}>
              <p className={styles.eventTitle}>
                {event.event_label}
                <span className={styles.eventMeta}>
                  {event.actor?.name ?? 'Sistema'} · {formatDateTime(event.created_at)}
                </span>
              </p>
              {changes.length > 0 && (
                <ul className={styles.changes}>
                  {changes.map(([field, change]) => (
                    <li key={field}>
                      {FIELD_LABELS[field] ?? field}:{' '}
                      {change.old !== null && change.old !== undefined ? (
                        <>
                          <del>{format(field, change.old)}</del> → <ins>{format(field, change.new)}</ins>
                        </>
                      ) : (
                        <ins>{format(field, change.new)}</ins>
                      )}
                    </li>
                  ))}
                </ul>
              )}
              {event.note && <p className={styles.eventNote}>{event.note}</p>}
            </li>
          );
        })}
      </ol>
      {hasMore && (
        <p className={styles.more}>
          <Button variant="secondary" size="sm" onClick={() => load(pages.meta.current_page + 1)} loading={loading}>
            Ver eventos anteriores
          </Button>
        </p>
      )}
    </>
  );
}
