import { useState } from 'react';
import { useNavigate } from 'react-router';
import { ModuleTabs } from '../components/business/ModuleTabs';
import { ContractsTable } from '../components/contracts/ContractsTable';
import { ContractWizard } from '../components/contracts/ContractWizard';
import { Button } from '../components/ui/Button';
import { Checkbox, Select } from '../components/ui/Field';
import { PageHeader } from '../components/ui/PageHeader';
import { Pagination } from '../components/ui/Pagination';
import { SearchInput } from '../components/ui/SearchInput';
import { EmptyState, ErrorState } from '../components/ui/StateMessage';
import { useApiResource } from '../hooks/useApiResource';
import { useAuth } from '../hooks/useAuth';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useReferenceData } from '../hooks/useReferenceData';
import { useToast } from '../hooks/useToast';
import { contractsService } from '../services/contractsService';
import styles from './AdminPage.module.scss';

export default function ContractsPage() {
  useDocumentTitle('Contratos');
  const { can } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const reference = useReferenceData();
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState({ status: 'active', service_line: '', billing_model: '', coverage: '' });
  const [onlyAlerts, setOnlyAlerts] = useState(false);
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [exporting, setExporting] = useState(false);
  const debouncedSearch = useDebouncedValue(search.trim());
  const query = { search: debouncedSearch, ...filters };

  // "Solo con alertas" trae una página grande y filtra aquí: las alertas se
  // calculan al vuelo y no son una columna por la que la base pueda filtrar.
  const { data, error, loading, reload } = useApiResource(
    (signal) => contractsService.list({ ...query, page: onlyAlerts ? 1 : page, per_page: onlyAlerts ? 100 : 15 }, { signal }),
    [debouncedSearch, filters, page, onlyAlerts],
  );

  const all = data?.data ?? [];
  const contracts = onlyAlerts ? all.filter((c) => c.alerts?.length) : all;
  const filtered = Boolean(debouncedSearch || filters.service_line || filters.billing_model || filters.coverage || filters.status !== 'active' || onlyAlerts);

  function setFilter(key) {
    return (e) => {
      setFilters((f) => ({ ...f, [key]: e.target.value }));
      setPage(1);
    };
  }

  async function exportCsv() {
    setExporting(true);
    try {
      await contractsService.exportCsv(query);
      toast.success('Exportación lista. Ábrela con Excel.');
    } catch (err) {
      toast.error(err.message);
    } finally {
      setExporting(false);
    }
  }

  return (
    <>
      <PageHeader
        title="Clientes y contratos"
        description="Contratos de las cuatro líneas de servicio con su consumo en tiempo real. Las alertas avisan bolsas por agotarse, excedentes del mes y contratos por vencer."
        actions={
          <>
            <Button variant="secondary" icon="download" onClick={exportCsv} loading={exporting}>
              Exportar a Excel
            </Button>
            {can('clients.create') && (
              <Button icon="plus" onClick={() => setCreating(true)}>
                Nuevo contrato
              </Button>
            )}
          </>
        }
      />
      <ModuleTabs />

      <section className={styles.panel} aria-label="Listado de contratos">
        <div className={styles.toolbar}>
          <SearchInput
            label="Buscar contratos"
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Buscar por nombre o código"
          />
          <Select label="Estado" className={styles.filter} value={filters.status} onChange={setFilter('status')} options={reference.options('contract_statuses', 'Todos los estados')} />
          <Select label="Línea de servicio" className={styles.filter} value={filters.service_line} onChange={setFilter('service_line')} options={reference.options('service_lines', 'Todas las líneas')} />
          <Select label="Forma de cobro" className={styles.filter} value={filters.billing_model} onChange={setFilter('billing_model')} options={reference.options('billing_models', 'Todas las formas de cobro')} />
          <Select label="Cobertura" className={styles.filter} value={filters.coverage} onChange={setFilter('coverage')} options={reference.options('coverages', 'Todas las coberturas')} />
          <Checkbox checked={onlyAlerts} onChange={(e) => setOnlyAlerts(e.target.checked)} label="Solo con alertas" />
        </div>

        {error ? (
          <ErrorState error={error} onRetry={reload} />
        ) : !loading && contracts.length === 0 ? (
          filtered ? (
            <EmptyState icon="search" title="Sin resultados" message={onlyAlerts ? 'Ningún contrato de esta búsqueda tiene alertas. ¡Todo al día!' : 'Ningún contrato coincide con la búsqueda o los filtros.'} />
          ) : (
            <EmptyState
              icon="briefcase"
              title="Aún no hay contratos"
              message="Registra el primer contrato de un cliente."
              action={
                can('clients.create') && (
                  <Button icon="plus" onClick={() => setCreating(true)}>
                    Nuevo contrato
                  </Button>
                )
              }
            />
          )
        ) : (
          <ContractsTable contracts={contracts} loading={loading && !data} />
        )}

        {!onlyAlerts && <Pagination meta={data?.meta} onPageChange={setPage} label="contratos" />}
      </section>

      <ContractWizard open={creating} onClose={() => setCreating(false)} onCreated={(contract) => navigate(`/app/contracts/${contract.id}`)} />
    </>
  );
}
