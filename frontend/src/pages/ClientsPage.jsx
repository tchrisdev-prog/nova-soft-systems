import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { ModuleTabs } from '../components/business/ModuleTabs';
import { ClientFormModal } from '../components/clients/ClientFormModal';
import { Badge, StatusBadge } from '../components/ui/Badge';
import { Button } from '../components/ui/Button';
import { Select } from '../components/ui/Field';
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
import { clientsService } from '../services/clientsService';
import { formatCurrency, formatHours } from '../utils/format';
import styles from './AdminPage.module.scss';

const COLUMNS = [
  { key: 'client', label: 'Cliente' },
  { key: 'sector', label: 'Sector y sede' },
  { key: 'contact', label: 'Contacto principal' },
  { key: 'contracts', label: 'Contratos activos', align: 'end' },
  { key: 'monthly', label: 'Mensualidades', align: 'end' },
  { key: 'alerts', label: 'Atención' },
  { key: 'status', label: 'Estado' },
];

const STATUS_OPTIONS = [
  { value: '', label: 'Todos los estados' },
  { value: 'active', label: 'Activos' },
  { value: 'inactive', label: 'Inactivos' },
];

export default function ClientsPage() {
  useDocumentTitle('Clientes');
  const { can } = useAuth();
  const navigate = useNavigate();
  const reference = useReferenceData();
  const [search, setSearch] = useState('');
  const [sector, setSector] = useState('');
  const [site, setSite] = useState('');
  const [status, setStatus] = useState('active');
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const debouncedSearch = useDebouncedValue(search.trim());

  const { data, error, loading, reload } = useApiResource(
    (signal) => clientsService.list({ search: debouncedSearch, sector, site_id: site, status, page, per_page: 15 }, { signal }),
    [debouncedSearch, sector, site, status, page],
  );

  const clients = data?.data ?? [];
  const filtered = Boolean(debouncedSearch || sector || site || status !== 'active');
  const resetPage = (setter) => (e) => {
    setter(e.target.value);
    setPage(1);
  };

  return (
    <>
      <PageHeader
        title="Clientes y contratos"
        description="Cada cliente con sus contactos, el valor de sus mensualidades y el estado de sus bolsas de horas, en un solo lugar para las dos sedes."
        actions={
          can('clients.create') && (
            <Button icon="plus" onClick={() => setCreating(true)}>
              Registrar cliente
            </Button>
          )
        }
      />
      <ModuleTabs />

      <section className={styles.panel} aria-label="Listado de clientes">
        <div className={styles.toolbar}>
          <SearchInput
            label="Buscar clientes"
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Buscar por nombre, código o NIT"
          />
          <Select label="Sector" className={styles.filter} value={sector} onChange={resetPage(setSector)} options={reference.options('client_sectors', 'Todos los sectores')} />
          <Select label="Sede" className={styles.filter} value={site} onChange={resetPage(setSite)} options={reference.options('sites', 'Todas las sedes')} />
          <Select label="Estado" className={styles.filter} value={status} onChange={resetPage(setStatus)} options={STATUS_OPTIONS} />
        </div>

        {error ? (
          <ErrorState error={error} onRetry={reload} />
        ) : !loading && clients.length === 0 ? (
          filtered ? (
            <EmptyState icon="search" title="Sin resultados" message="Ningún cliente coincide con la búsqueda o los filtros." />
          ) : (
            <EmptyState
              icon="briefcase"
              title="Aún no hay clientes"
              message="Registra el primer cliente con sus contactos para empezar a crear contratos."
              action={
                can('clients.create') && (
                  <Button icon="plus" onClick={() => setCreating(true)}>
                    Registrar cliente
                  </Button>
                )
              }
            />
          )
        ) : (
          <Table caption="Clientes" columns={COLUMNS}>
            {loading && !data ? (
              <SkeletonRows columns={COLUMNS.length} />
            ) : (
              clients.map((client) => (
                <tr key={client.id}>
                  <Cell label="Cliente">
                    <Link to={`/app/clients/${client.id}`} className={styles.primaryLink}>
                      {client.name}
                    </Link>
                    <span className={styles.secondary}>
                      {client.code}
                      {client.tax_id && ` · NIT ${client.tax_id}`}
                    </span>
                  </Cell>
                  <Cell label="Sector y sede">
                    <span className={styles.primaryText}>{client.sector.label}</span>
                    <span className={styles.secondary}>{client.site?.name}</span>
                  </Cell>
                  <Cell label="Contacto principal">
                    {client.primary_contact ? (
                      <>
                        <span className={styles.primaryText}>{client.primary_contact.name}</span>
                        <span className={styles.secondary}>{client.primary_contact.position ?? client.primary_contact.type.label}</span>
                      </>
                    ) : (
                      <span className={styles.placeholder}>Sin contactos</span>
                    )}
                  </Cell>
                  <Cell label="Contratos activos" align="end">
                    <span className="tabular">{client.summary.active_contracts}</span>
                    {client.summary.hour_bags > 0 && (
                      <span className={styles.secondary}>{`${formatHours(client.summary.bag_balance_minutes)} en bolsas`}</span>
                    )}
                  </Cell>
                  <Cell label="Mensualidades" align="end">
                    <span className="tabular">{client.summary.monthly_recurring ? formatCurrency(client.summary.monthly_recurring) : '—'}</span>
                  </Cell>
                  <Cell label="Atención">
                    {client.summary.alerts > 0 ? (
                      <Badge tone="warning">{client.summary.alerts === 1 ? '1 alerta' : `${client.summary.alerts} alertas`}</Badge>
                    ) : (
                      <span className={styles.secondary}>Al día</span>
                    )}
                  </Cell>
                  <Cell label="Estado">
                    <StatusBadge active={client.is_active} />
                  </Cell>
                </tr>
              ))
            )}
          </Table>
        )}

        <Pagination meta={data?.meta} onPageChange={setPage} label="clientes" />
      </section>

      <ClientFormModal
        open={creating}
        onClose={() => setCreating(false)}
        onSaved={(client) => {
          setCreating(false);
          navigate(`/app/clients/${client.id}`);
        }}
      />
    </>
  );
}
