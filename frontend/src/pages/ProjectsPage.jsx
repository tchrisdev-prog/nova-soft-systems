import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { ModuleTabs } from '../components/business/ModuleTabs';
import { BudgetBar, HealthFlags, ProjectStatusBadge } from '../components/projects/ProjectBits';
import { ProjectFormModal } from '../components/projects/ProjectFormModal';
import { Button } from '../components/ui/Button';
import { Checkbox, Select } from '../components/ui/Field';
import { PageHeader } from '../components/ui/PageHeader';
import { Pagination } from '../components/ui/Pagination';
import { SearchInput } from '../components/ui/SearchInput';
import { EmptyState, ErrorState } from '../components/ui/StateMessage';
import { Cell, SkeletonRows, Table } from '../components/ui/Table';
import { useApiResource } from '../hooks/useApiResource';
import { useAuth } from '../hooks/useAuth';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useReferenceData } from '../hooks/useReferenceData';
import { projectsService } from '../services/projectsService';
import styles from './AdminPage.module.scss';

const COLUMNS = [
  { key: 'project', label: 'Proyecto' },
  { key: 'leader', label: 'Líder' },
  { key: 'status', label: 'Estado' },
  { key: 'budget', label: 'Consumo', width: '13rem' },
  { key: 'health', label: 'Salud' },
  { key: 'team', label: 'Equipo', align: 'end' },
];

export default function ProjectsPage() {
  useDocumentTitle('Proyectos');
  const { can } = useAuth();
  const navigate = useNavigate();
  const reference = useReferenceData();
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState({ state: 'open', kind: '', site_id: '' });
  // Quien ve todos los proyectos puede filtrar los suyos; un líder sin
  // projects.view solo recibe los suyos desde la API, así que no lo necesita.
  const [mine, setMine] = useState(false);
  const [onlyFlags, setOnlyFlags] = useState(false);
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const debouncedSearch = useDebouncedValue(search.trim());
  const seesAll = can('projects.view');

  const query = {
    search: debouncedSearch,
    open: filters.state === 'open' ? 1 : undefined,
    status: filters.state !== 'open' ? filters.state : undefined,
    kind: filters.kind,
    site_id: filters.site_id,
    mine: mine ? 1 : undefined,
  };

  // Las señales de salud se calculan al vuelo; "solo con alertas" trae una
  // página grande y filtra aquí, igual que en contratos.
  const { data, error, loading, reload } = useApiResource(
    (signal) => projectsService.list({ ...query, page: onlyFlags ? 1 : page, per_page: onlyFlags ? 100 : 15 }, { signal }),
    [debouncedSearch, filters, mine, page, onlyFlags],
  );

  const all = data?.data ?? [];
  const projects = onlyFlags ? all.filter((p) => p.health?.flags.length) : all;
  const filtered = Boolean(debouncedSearch || filters.kind || filters.site_id || filters.state !== 'open' || mine || onlyFlags);

  function setFilter(key) {
    return (e) => {
      setFilters((f) => ({ ...f, [key]: e.target.value }));
      setPage(1);
    };
  }

  return (
    <>
      <PageHeader
        title="Proyectos y capacidad"
        description={
          seesAll
            ? 'Proyectos y servicios continuos con su consumo, su equipo y señales de riesgo calculadas con las horas registradas.'
            : 'Los proyectos que lideras: su equipo, su consumo y las señales de riesgo.'
        }
        actions={
          can('projects.create') && (
            <Button icon="plus" onClick={() => setCreating(true)}>
              Nuevo proyecto
            </Button>
          )
        }
      />
      <ModuleTabs module="projects" />

      <section className={styles.panel} aria-label="Listado de proyectos">
        <div className={styles.toolbar}>
          <SearchInput
            label="Buscar proyectos"
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Buscar por nombre o código"
          />
          <Select
            label="Estado"
            className={styles.filter}
            value={filters.state}
            onChange={setFilter('state')}
            options={[{ value: 'open', label: 'Abiertos' }, ...reference.options('project_statuses'), { value: '', label: 'Todos' }]}
          />
          <Select label="Tipo" className={styles.filter} value={filters.kind} onChange={setFilter('kind')} options={reference.options('project_kinds', 'Todos los tipos')} />
          <Select label="Sede" className={styles.filter} value={filters.site_id} onChange={setFilter('site_id')} options={reference.options('sites', 'Todas las sedes')} />
          {seesAll && can('projects.lead') && (
            <Checkbox
              checked={mine}
              onChange={(e) => {
                setMine(e.target.checked);
                setPage(1);
              }}
              label="Mis proyectos"
            />
          )}
          <Checkbox checked={onlyFlags} onChange={(e) => setOnlyFlags(e.target.checked)} label="Solo con alertas" />
        </div>

        {error ? (
          <ErrorState error={error} onRetry={reload} />
        ) : !loading && projects.length === 0 ? (
          filtered ? (
            <EmptyState icon="search" title="Sin resultados" message={onlyFlags ? 'Ningún proyecto de esta búsqueda tiene alertas.' : 'Ningún proyecto coincide con la búsqueda o los filtros.'} />
          ) : (
            <EmptyState
              icon="folder"
              title={seesAll ? 'Aún no hay proyectos' : 'No lideras proyectos todavía'}
              message={seesAll ? 'Crea el primer proyecto sobre un contrato activo o en borrador.' : 'Cuando te asignen como líder de un proyecto aparecerá aquí.'}
              action={
                can('projects.create') && (
                  <Button icon="plus" onClick={() => setCreating(true)}>
                    Nuevo proyecto
                  </Button>
                )
              }
            />
          )
        ) : (
          <Table caption="Proyectos" columns={COLUMNS}>
            {loading && !data ? (
              <SkeletonRows columns={COLUMNS.length} />
            ) : (
              projects.map((p) => (
                <tr key={p.id}>
                  <Cell label="Proyecto">
                    <Link to={`/app/projects/${p.id}`} className={styles.primaryLink}>
                      {p.name}
                    </Link>
                    <span className={styles.secondary}>
                      {p.code} · {p.contract?.client?.name} · {p.kind.label}
                    </span>
                  </Cell>
                  <Cell label="Líder">{p.leader?.name ?? <span className={styles.placeholder}>Sin líder</span>}</Cell>
                  <Cell label="Estado">
                    <ProjectStatusBadge status={p.status} />
                  </Cell>
                  <Cell label="Consumo">
                    <BudgetBar health={p.health} />
                  </Cell>
                  <Cell label="Salud">
                    <HealthFlags health={p.health} />
                  </Cell>
                  <Cell label="Equipo" align="end">
                    <span className="tabular">{p.health?.team_size ?? 0}</span>
                  </Cell>
                </tr>
              ))
            )}
          </Table>
        )}

        {!onlyFlags && <Pagination meta={data?.meta} onPageChange={setPage} label="proyectos" />}
      </section>

      <ProjectFormModal open={creating} onClose={() => setCreating(false)} onSaved={(project) => navigate(`/app/projects/${project.id}`)} />
    </>
  );
}
