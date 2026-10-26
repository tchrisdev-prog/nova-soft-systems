import { useMemo, useState } from 'react';
import { ModuleTabs } from '../components/business/ModuleTabs';
import { AllocationModal } from '../components/projects/AllocationModal';
import { LOAD_LABELS, LoadLegend, loadLevel } from '../components/projects/ProjectBits';
import { Button } from '../components/ui/Button';
import { Select, TextField } from '../components/ui/Field';
import { PageHeader } from '../components/ui/PageHeader';
import { SearchInput } from '../components/ui/SearchInput';
import { EmptyState, ErrorState, LoadingState } from '../components/ui/StateMessage';
import { useApiResource } from '../hooks/useApiResource';
import { useAuth } from '../hooks/useAuth';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useReferenceData } from '../hooks/useReferenceData';
import { capacityService } from '../services/capacityService';
import { cx } from '../utils/cx';
import { formatHours, formatPercent, todayInBogota } from '../utils/format';
import adminStyles from './AdminPage.module.scss';
import styles from '../components/business/Business.module.scss';
import cap from '../components/projects/Projects.module.scss';

const shortDate = new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'short' });
const dayName = new Intl.DateTimeFormat('es-CO', { weekday: 'long', day: 'numeric', month: 'long' });
const toDate = (iso) => {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d);
};

const SORTS = [
  { value: 'name', label: 'Nombre' },
  { value: 'load', label: 'Más cargados primero' },
  { value: 'free', label: 'Más libres primero' },
];

function cellTitle(person, week) {
  const lines = [
    `${person.user.name} · semana del ${shortDate.format(toDate(week.start))}`,
    `Asignado: ${formatHours(week.allocated_minutes)} de ${formatHours(week.capacity_minutes)} (${formatPercent(week.percent)})`,
    `Registrado: ${formatHours(week.actual_minutes)}`,
    ...week.projects.map((p) => `• ${p.code} ${p.name} (${p.percent} %)`),
  ];
  return lines.join('\n');
}

export default function CapacityPage() {
  useDocumentTitle('Capacidad');
  const { can } = useAuth();
  const reference = useReferenceData();
  const [weeks, setWeeks] = useState('8');
  const [filters, setFilters] = useState({ site_id: '', specialty: '' });
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState('name');
  const debouncedSearch = useDebouncedValue(search.trim());
  const canAssign = can('projects.manage') || can('projects.lead');

  const { data, error, loading, reload } = useApiResource(
    (signal) => capacityService.weekly({ weeks, ...filters, search: debouncedSearch }, { signal }),
    [weeks, filters, debouncedSearch],
  );

  // Búsqueda de disponibilidad: "¿quién tiene 50 % libre del 3 de noviembre al 18 de diciembre?"
  const [finder, setFinder] = useState({ percent: '50', from: todayInBogota(), to: '', specialty: '', site_id: '' });
  const [finderErrors, setFinderErrors] = useState({});
  const [results, setResults] = useState(null);
  const [searching, setSearching] = useState(false);
  const [finderError, setFinderError] = useState(null);
  const [assigning, setAssigning] = useState(null);

  // Objetos estables: el modal reinicia su formulario cuando cambian.
  const presetPerson = useMemo(() => (assigning ? { id: assigning.user.id, name: assigning.user.name, free_percent: assigning.free_percent } : null), [assigning]);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const presetRange = useMemo(() => (assigning ? { from: finder.from, to: finder.to, percent: Number(finder.percent) } : null), [assigning]);

  const people = useMemo(() => {
    const list = [...(data?.people ?? [])];
    const first = (p) => p.weeks[0]?.percent ?? 0;
    if (sort === 'load') list.sort((a, b) => first(b) - first(a));
    if (sort === 'free') list.sort((a, b) => first(a) - first(b));
    return list;
  }, [data, sort]);

  const thisWeek = useMemo(() => {
    const counts = { free: 0, busy: 0, over: 0 };
    (data?.people ?? []).forEach((p) => {
      const level = loadLevel(p.weeks[0]?.percent);
      if (level in counts) counts[level] += 1;
    });
    return counts;
  }, [data]);

  function setFilter(key) {
    return (e) => setFilters((f) => ({ ...f, [key]: e.target.value }));
  }

  function setFinderField(key) {
    return (e) => setFinder((f) => ({ ...f, [key]: e.target.value }));
  }

  async function findAvailable(event) {
    event.preventDefault();
    const next = {};
    if (!finder.from) next.from = 'Indica desde cuándo.';
    if (!finder.to) next.to = 'Indica hasta cuándo.';
    else if (finder.from && finder.to < finder.from) next.to = 'Debe ser posterior a la fecha de inicio.';
    setFinderErrors(next);
    if (Object.keys(next).length) return;

    setSearching(true);
    setFinderError(null);
    try {
      setResults(await capacityService.available(finder));
    } catch (err) {
      if (err.code === 'validation_failed') setFinderErrors(err.fieldErrors);
      else setFinderError(err.message);
    } finally {
      setSearching(false);
    }
  }

  return (
    <>
      <PageHeader
        title="Proyectos y capacidad"
        description="Carga de cada persona semana a semana según sus asignaciones. La capacidad descuenta los festivos de Colombia; los costos por hora nunca se muestran aquí."
      />
      <ModuleTabs module="projects" />

      {data && (
        <dl className={styles.metrics}>
          <div className={styles.metric}>
            <dt>Libres esta semana</dt>
            <dd>
              <span className="tabular">{thisWeek.free}</span>
              <span className={styles.metricDetail}>con menos de 70 % asignado</span>
            </dd>
          </div>
          <div className={styles.metric}>
            <dt>Ocupados</dt>
            <dd>
              <span className="tabular">{thisWeek.busy}</span>
              <span className={styles.metricDetail}>entre 70 % y 100 %</span>
            </dd>
          </div>
          <div className={styles.metric}>
            <dt>Sobreasignados</dt>
            <dd>
              <span className="tabular">{thisWeek.over}</span>
              <span className={styles.metricDetail}>por encima de su jornada</span>
            </dd>
          </div>
          <div className={styles.metric}>
            <dt>Horas libres esta semana</dt>
            <dd>
              <span className="tabular">{formatHours(data.summary.by_site.reduce((sum, s) => sum + s.free_minutes_this_week, 0))}</span>
              <span className={styles.metricDetail}>
                de {formatHours(data.summary.by_site.reduce((sum, s) => sum + s.capacity_minutes_this_week, 0))} de capacidad
              </span>
            </dd>
          </div>
        </dl>
      )}

      <section className={styles.section} aria-labelledby="grid-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="grid-title" className={styles.sectionTitle}>
              Carga semanal
            </h2>
            <p className={styles.sectionText}>Pasa el cursor sobre una semana para ver en qué proyectos está la persona y cuántas horas registró.</p>
          </div>
          <LoadLegend />
        </header>
        <div className={adminStyles.toolbar}>
          <SearchInput label="Buscar personas" value={search} onChange={setSearch} placeholder="Buscar por nombre" />
          <Select label="Horizonte" className={adminStyles.filter} value={weeks} onChange={(e) => setWeeks(e.target.value)} options={(reference.data?.capacity_horizons ?? [4, 8, 12]).map((w) => ({ value: String(w), label: `${w} semanas` }))} />
          <Select label="Sede" className={adminStyles.filter} value={filters.site_id} onChange={setFilter('site_id')} options={reference.options('sites', 'Todas las sedes')} />
          <Select label="Especialidad" className={adminStyles.filter} value={filters.specialty} onChange={setFilter('specialty')} options={reference.options('staff_specialties', 'Todas las especialidades')} />
          <Select label="Ordenar por" className={adminStyles.filter} value={sort} onChange={(e) => setSort(e.target.value)} options={SORTS} />
        </div>

        {error ? (
          <ErrorState error={error} onRetry={reload} compact />
        ) : !data ? (
          <LoadingState label="Calculando capacidad…" />
        ) : people.length === 0 ? (
          <EmptyState compact icon="users" title="Nadie con estos filtros" message="Solo aparecen personas activas con perfil laboral. Configura los perfiles en la pestaña Perfiles." />
        ) : (
          <div className={cap.gridWrap} aria-busy={loading || undefined}>
            <table className={cap.grid}>
              <caption className="visually-hidden">Carga semanal por persona</caption>
              <thead>
                <tr>
                  <th scope="col" className={cap.personCol}>
                    Persona
                  </th>
                  {data.weeks.map((w) => (
                    <th key={w.key} scope="col">
                      <span className={cap.weekHead}>
                        <span className={cap.weekDate}>{shortDate.format(toDate(w.start))}</span>
                        {w.holidays.length > 0 ? (
                          <span className={cap.holiday} title={w.holidays.map((h) => `${dayName.format(toDate(h.date))}: ${h.name}`).join('\n')}>
                            {w.holidays.length === 1 ? 'Festivo' : `${w.holidays.length} festivos`} · {w.working_days} días
                          </span>
                        ) : (
                          <span>{w.working_days} días</span>
                        )}
                      </span>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {people.map((person) => (
                  <tr key={person.user.id}>
                    <th scope="row" className={cap.personCol}>
                      <span className={cap.personName}>{person.user.name}</span>
                      <span className={cap.personMeta}>
                        {person.specialty?.label ?? person.position} · {person.site.name}
                        {person.weekly_capacity_minutes !== 2520 && ` · ${formatHours(person.weekly_capacity_minutes)}/sem`}
                      </span>
                    </th>
                    {person.weeks.map((week, i) => {
                      const level = loadLevel(week.percent);
                      return (
                        <td key={data.weeks[i].key}>
                          <span className={cx(cap.cell, cap[level])} title={cellTitle(person, { ...week, start: data.weeks[i].start })}>
                            <span className={cap.cellPercent}>{week.percent === null ? '—' : formatPercent(week.percent)}</span>
                            <span className={cap.cellHours}>{formatHours(week.free_minutes, { unit: false })} h libres</span>
                            <span className="visually-hidden">{LOAD_LABELS[level]}</span>
                          </span>
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {data && (
        <section className={styles.section} aria-labelledby="summary-title">
          <header className={styles.sectionHeader}>
            <div>
              <h2 id="summary-title" className={styles.sectionTitle}>
                Horas libres esta semana
              </h2>
              <p className={styles.sectionText}>Por especialidad y por sede, con el promedio semanal del horizonte escogido.</p>
            </div>
          </header>
          <dl className={cap.summary}>
            {[...data.summary.by_specialty, ...data.summary.by_site].map((group, i) => (
              <div key={`${i < data.summary.by_specialty.length ? 'sp' : 'site'}-${group.value}`} className={cap.summaryItem}>
                <dt>
                  {i < data.summary.by_specialty.length ? '' : 'Sede '}
                  {group.label}
                </dt>
                <dd>
                  <span className="tabular">{formatHours(group.free_minutes_this_week)}</span>
                  <span className={cap.summaryDetail}>
                    de {formatHours(group.capacity_minutes_this_week)} · {group.people} {group.people === 1 ? 'persona' : 'personas'} · promedio {formatHours(group.average_free_minutes)}/sem
                  </span>
                </dd>
              </div>
            ))}
          </dl>
        </section>
      )}

      <section className={styles.section} aria-labelledby="finder-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="finder-title" className={styles.sectionTitle}>
              Buscar disponibilidad
            </h2>
            <p className={styles.sectionText}>Quién tiene libre el porcentaje pedido durante todo el rango. Se mide con su día más cargado: si un solo día no alcanza, no aparece.</p>
          </div>
        </header>
        <form className={cap.finder} onSubmit={findAvailable} noValidate>
          <Select label="Dedicación libre" value={finder.percent} onChange={setFinderField('percent')} options={[25, 50, 75, 100].map((p) => ({ value: String(p), label: `Al menos ${p} %` }))} />
          <TextField label="Desde" type="date" value={finder.from} onChange={setFinderField('from')} error={finderErrors.from} />
          <TextField label="Hasta" type="date" value={finder.to} onChange={setFinderField('to')} error={finderErrors.to} />
          <Select label="Especialidad" value={finder.specialty} onChange={setFinderField('specialty')} options={reference.options('staff_specialties', 'Cualquiera')} />
          <Select label="Sede" value={finder.site_id} onChange={setFinderField('site_id')} options={reference.options('sites', 'Cualquiera')} />
          <Button type="submit" icon="search" loading={searching}>
            Buscar
          </Button>
        </form>
        {finderError && <ErrorState error={{ message: finderError }} compact />}
        {results && results.length === 0 && <EmptyState compact icon="search" title="Nadie tiene ese espacio" message="Prueba con un porcentaje menor, otro rango de fechas u otra especialidad." />}
        {results && results.length > 0 && (
          <ul className={cap.results} aria-label="Personas disponibles">
            {results.map((person) => (
              <li key={person.user.id} className={cap.result}>
                <span className={cap.resultInfo}>
                  <span className={adminStyles.primaryText}>{person.user.name}</span>
                  <span className={adminStyles.secondary}>
                    {person.position} · {person.site.name}
                    {person.projects.length > 0 ? ` · en ${person.projects.map((p) => p.name).join(', ')}` : ' · sin proyectos en el rango'}
                  </span>
                </span>
                <span className={cap.resultSide}>
                  <span className={cx(cap.freeBadge, cap[person.free_percent >= 30 ? 'free' : 'busy'])}>{person.free_percent} % libre</span>
                  {canAssign && (
                    <Button size="sm" variant="secondary" icon="plus" onClick={() => setAssigning(person)}>
                      Asignar
                    </Button>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <AllocationModal
        open={Boolean(assigning)}
        presetPerson={presetPerson}
        presetRange={presetRange}
        onClose={() => setAssigning(null)}
        onSaved={() => {
          setAssigning(null);
          setResults(null);
          reload();
        }}
      />
    </>
  );
}
