import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { BackLink } from '../components/business/ModuleTabs';
import { AlertList, ConsumptionBar, ContractStatusBadge, Metric } from '../components/contracts/ContractBits';
import { ContractHistory } from '../components/contracts/ContractHistory';
import { ContractEditModal, HourMovementModal, StatusChangeModal } from '../components/contracts/ContractModals';
import { MonthlyChart } from '../components/contracts/MonthlyChart';
import { ProjectFormModal } from '../components/projects/ProjectFormModal';
import { Badge } from '../components/ui/Badge';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { PageHeader } from '../components/ui/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ui/StateMessage';
import { Cell, Table } from '../components/ui/Table';
import { useApiResource } from '../hooks/useApiResource';
import { useAuth } from '../hooks/useAuth';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useToast } from '../hooks/useToast';
import { contractsService } from '../services/contractsService';
import { formatCalendarDate, formatCurrency, formatDateTime, formatHours, formatPercent } from '../utils/format';
import adminStyles from './AdminPage.module.scss';
import styles from '../components/business/Business.module.scss';

const monthLong = new Intl.DateTimeFormat('es-CO', { month: 'long', year: 'numeric' });
const periodName = (p) => {
  const [y, m] = p.split('-').map(Number);
  return monthLong.format(new Date(y, m - 1, 1));
};

export default function ContractDetailPage() {
  const { contractId } = useParams();
  const navigate = useNavigate();
  const { can } = useAuth();
  const toast = useToast();
  const [version, setVersion] = useState(0); // fuerza recargar consumo e historial tras un cambio

  const contract = useApiResource((signal) => contractsService.get(contractId, { signal }), [contractId, version]);
  const consumption = useApiResource((signal) => contractsService.consumption(contractId, { months: 6 }, { signal }), [contractId, version]);
  const isBag = contract.data?.billing_model.value === 'hour_bag';
  const movements = useApiResource((signal) => (isBag ? contractsService.movements(contractId, { signal }) : Promise.resolve(null)), [contractId, version, isBag]);
  const canSeeProjects = can('projects.view') || can('projects.lead');
  const projects = useApiResource((signal) => (canSeeProjects ? contractsService.projects(contractId, { signal }) : Promise.resolve(null)), [contractId, canSeeProjects]);
  useDocumentTitle(contract.data ? contract.data.name : 'Contrato');

  const [modal, setModal] = useState(null); // 'status' | 'movement' | 'edit' | 'delete' | 'project'
  const [deleting, setDeleting] = useState(false);

  if (contract.error) {
    return (
      <>
        <BackLink to="/app/contracts">Contratos</BackLink>
        <ErrorState error={contract.error} title={contract.error.status === 404 ? 'Este contrato no existe' : 'No se pudo cargar el contrato'} onRetry={contract.error.status === 404 ? undefined : contract.reload} />
      </>
    );
  }

  if (!contract.data) return <LoadingState label="Cargando contrato…" />;

  const current = contract.data;
  const c = current.consumption;
  const money = current.can.view_financials;
  const closed = ['finished', 'cancelled'].includes(current.status.value);
  const saved = () => {
    setModal(null);
    setVersion((v) => v + 1);
  };

  async function remove() {
    setDeleting(true);
    try {
      const response = await contractsService.remove(current.id);
      toast.success(response.message);
      navigate(`/app/clients/${current.client.id}`);
    } catch (error) {
      toast.error(error.message);
      setDeleting(false);
    }
  }

  return (
    <>
      <PageHeader
        title={current.name}
        description={`${current.service_line.label} · ${current.billing_model.label} · cobertura ${current.coverage.label}`}
        actions={
          <>
            {current.can.update && !closed && (
              <Button variant="secondary" icon="edit" onClick={() => setModal('edit')}>
                Editar
              </Button>
            )}
            {current.can.add_hours && !closed && (
              <Button variant="secondary" icon="plus" onClick={() => setModal('movement')}>
                Recargar o ajustar
              </Button>
            )}
            {current.can.change_status && current.next_statuses.length > 0 && (
              <Button icon="refresh" onClick={() => setModal('status')}>
                {current.status.value === 'draft' ? 'Activar o cancelar' : 'Cambiar estado'}
              </Button>
            )}
          </>
        }
      >
        <BackLink to="/app/contracts">Contratos</BackLink>
        <span className={styles.headerBadges}>
          <Badge>{current.code}</Badge>
          <ContractStatusBadge status={current.status} />
          <Link to={`/app/clients/${current.client.id}`} className={adminStyles.primaryLink}>
            {current.client.name}
          </Link>
        </span>
      </PageHeader>

      {current.alerts?.length > 0 && (
        <div className={styles.alertBanner} role="status">
          <AlertList alerts={current.alerts} />
        </div>
      )}

      {current.status.value === 'draft' && (
        <div className={styles.alertBanner} role="status">
          <Badge tone="neutral">Borrador: actívalo cuando el cliente firme para empezar a registrar horas.</Badge>
        </div>
      )}

      <dl className={styles.metrics}>
        {isBag ? (
          <>
            <Metric label="Horas compradas" value={formatHours(c?.purchased_minutes)} detail={`por ${formatCurrency(current.hour_bag?.contract_value)}`} />
            <Metric label="Horas aprobadas" value={formatHours(c?.approved_minutes)} detail={`${formatPercent(c?.consumed_ratio)} de la bolsa`} />
            <Metric label="Pendientes de aprobar" value={formatHours(c?.pending_minutes)} detail="enviadas por los consultores" />
            <Metric label="Saldo" value={formatHours(c?.balance_minutes)} detail={c?.balance_minutes < 0 ? 'bolsa excedida' : 'disponible'} />
          </>
        ) : (
          <>
            <Metric label="Valor del mes" value={formatCurrency(c?.fee_amount)} detail={c?.period ? periodName(c.period) : undefined} />
            <Metric label="Horas incluidas" value={c?.included_minutes === null ? 'Disponibilidad' : formatHours(c?.included_minutes)} detail={c?.included_minutes === null ? 'sin horas incluidas' : 'al mes'} />
            <Metric label="Aprobadas este mes" value={formatHours(c?.approved_minutes)} detail={c?.consumed_ratio !== null ? `${formatPercent(c?.consumed_ratio)} de lo incluido` : undefined} />
            <Metric label="Pendientes de aprobar" value={formatHours(c?.pending_minutes)} detail="este mes" />
          </>
        )}
        {money && current.financials && (
          <Metric label="Margen a la fecha" value={formatCurrency(current.financials.margin)} detail={`ingreso ${formatCurrency(current.financials.revenue_to_date)} · costo ${formatCurrency(current.financials.approved_cost)}`} />
        )}
      </dl>

      <section className={styles.section} aria-labelledby="consumption-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="consumption-title" className={styles.sectionTitle}>
              Consumo
            </h2>
            <p className={styles.sectionText}>Solo cuentan las horas aprobadas. Las enviadas se ven aparte como pendientes.</p>
          </div>
        </header>
        <div className={styles.sectionBody}>
          {consumption.error ? (
            <ErrorState error={consumption.error} onRetry={consumption.reload} compact />
          ) : !consumption.data ? (
            <LoadingState label="Calculando consumo…" />
          ) : isBag ? (
            <ConsumptionBar consumption={c} />
          ) : consumption.data.data.periods.length === 0 ? (
            <EmptyState compact icon="clock" title="Aún no hay meses abiertos" message="El primer mes se abre al activar el contrato." />
          ) : (
            <MonthlyChart periods={consumption.data.data.periods} />
          )}
        </div>
        {!isBag && consumption.data?.data.periods.length > 0 && (
          <Table
            caption="Consumo por mes"
            columns={[
              { key: 'period', label: 'Mes' },
              { key: 'fee', label: 'Valor', align: 'end' },
              { key: 'included', label: 'Incluidas', align: 'end' },
              { key: 'approved', label: 'Aprobadas', align: 'end' },
              { key: 'overage', label: 'Excedente', align: 'end' },
              ...(money ? [{ key: 'cost', label: 'Costo', align: 'end' }, { key: 'margin', label: 'Margen', align: 'end' }] : []),
            ]}
          >
            {[...consumption.data.data.periods].reverse().map((p) => (
              <tr key={p.period}>
                <Cell label="Mes">{periodName(p.period)}</Cell>
                <Cell label="Valor" align="end">
                  <span className="tabular">{formatCurrency(p.fee_amount)}</span>
                </Cell>
                <Cell label="Incluidas" align="end">
                  {p.included_minutes === null ? <span className={adminStyles.secondary}>Disponibilidad</span> : <span className="tabular">{formatHours(p.included_minutes)}</span>}
                </Cell>
                <Cell label="Aprobadas" align="end">
                  <span className="tabular">{formatHours(p.approved_minutes)}</span>
                  {p.pending_minutes > 0 && <span className={adminStyles.secondary}>{`+${formatHours(p.pending_minutes)} pendientes`}</span>}
                </Cell>
                <Cell label="Excedente" align="end">
                  {p.overage_minutes ? <Badge tone="warning">{formatHours(p.overage_minutes)}</Badge> : <span className={adminStyles.secondary}>—</span>}
                </Cell>
                {money && (
                  <>
                    <Cell label="Costo" align="end">
                      <span className="tabular">{formatCurrency(p.cost)}</span>
                    </Cell>
                    <Cell label="Margen" align="end">
                      <span className="tabular">{formatCurrency(p.margin)}</span>
                    </Cell>
                  </>
                )}
              </tr>
            ))}
          </Table>
        )}
      </section>

      {isBag && (
        <section className={styles.section} aria-labelledby="movements-title">
          <header className={styles.sectionHeader}>
            <div>
              <h2 id="movements-title" className={styles.sectionTitle}>
                Movimientos de la bolsa
              </h2>
              <p className={styles.sectionText}>La bolsa nunca se edita: cada compra o corrección queda como una línea nueva.</p>
            </div>
          </header>
          {movements.error ? (
            <ErrorState error={movements.error} onRetry={movements.reload} compact />
          ) : (
            <Table
              caption="Movimientos de la bolsa de horas"
              columns={[
                { key: 'date', label: 'Fecha' },
                { key: 'type', label: 'Movimiento' },
                { key: 'hours', label: 'Horas', align: 'end' },
                { key: 'amount', label: 'Valor', align: 'end' },
                { key: 'note', label: 'Nota' },
                { key: 'author', label: 'Registrado por' },
              ]}
            >
              {(movements.data?.data ?? []).map((m) => (
                <tr key={m.id}>
                  <Cell label="Fecha">{formatCalendarDate(m.reference_date)}</Cell>
                  <Cell label="Movimiento">
                    <Badge tone={m.type.value === 'adjustment' ? 'warning' : 'primary'}>{m.type.label}</Badge>
                  </Cell>
                  <Cell label="Horas" align="end">
                    <span className="tabular">
                      {m.minutes > 0 ? '+' : ''}
                      {formatHours(m.minutes)}
                    </span>
                  </Cell>
                  <Cell label="Valor" align="end">
                    <span className="tabular">{formatCurrency(m.amount)}</span>
                  </Cell>
                  <Cell label="Nota" className={adminStyles.muted}>
                    {m.note ?? <span className={adminStyles.placeholder}>—</span>}
                  </Cell>
                  <Cell label="Registrado por">
                    <span className={adminStyles.primaryText}>{m.author?.name ?? '—'}</span>
                    <span className={adminStyles.secondary}>{formatDateTime(m.created_at)}</span>
                  </Cell>
                </tr>
              ))}
            </Table>
          )}
        </section>
      )}

      {canSeeProjects && (
        <section className={styles.section} aria-labelledby="projects-title">
          <header className={styles.sectionHeader}>
            <h2 id="projects-title" className={styles.sectionTitle}>
              Proyectos
            </h2>
            {can('projects.create') && ['draft', 'active'].includes(current.status.value) && (
              <Button variant="secondary" icon="plus" onClick={() => setModal('project')}>
                Nuevo proyecto
              </Button>
            )}
          </header>
          {projects.data?.data.length === 0 ? (
            <EmptyState compact icon="folder" title="Sin proyectos" message="Este contrato aún no tiene proyectos o servicios asociados." />
          ) : (
            <Table
              caption="Proyectos del contrato"
              columns={[
                { key: 'project', label: 'Proyecto' },
                { key: 'leader', label: 'Líder' },
                { key: 'status', label: 'Estado' },
                { key: 'hours', label: 'Horas aprobadas', align: 'end' },
              ]}
            >
              {(projects.data?.data ?? []).map((p) => (
                <tr key={p.id}>
                  <Cell label="Proyecto">
                    <Link to={`/app/projects/${p.id}`} className={adminStyles.primaryLink}>
                      {p.name}
                    </Link>
                    <span className={adminStyles.secondary}>
                      {p.code} · {p.kind.label} · {p.site?.name}
                    </span>
                  </Cell>
                  <Cell label="Líder">{p.leader?.name ?? <span className={adminStyles.placeholder}>Sin líder</span>}</Cell>
                  <Cell label="Estado">
                    <Badge tone={p.status.value === 'active' ? 'success' : 'neutral'} dot>
                      {p.status.label}
                    </Badge>
                  </Cell>
                  <Cell label="Horas aprobadas" align="end">
                    <span className="tabular">{formatHours(p.health?.approved_minutes ?? 0)}</span>
                    {p.budget_minutes && <span className={adminStyles.secondary}>{`de ${formatHours(p.budget_minutes)} presupuestadas`}</span>}
                  </Cell>
                </tr>
              ))}
            </Table>
          )}
        </section>
      )}

      <section className={styles.section} aria-labelledby="data-title">
        <header className={styles.sectionHeader}>
          <h2 id="data-title" className={styles.sectionTitle}>
            Datos del contrato
          </h2>
        </header>
        <dl className={styles.facts}>
          <div>
            <dt>Vigencia</dt>
            <dd>
              {formatCalendarDate(current.start_date)} – {current.end_date ? formatCalendarDate(current.end_date) : isBag ? 'sin fecha de fin' : 'se renueva mes a mes'}
            </dd>
          </div>
          {!isBag && (
            <div>
              <dt>Condiciones vigentes</dt>
              <dd>
                {formatCurrency(current.monthly_fee)} al mes · {current.included_minutes_per_month === null ? 'solo disponibilidad' : `${formatHours(current.included_minutes_per_month)} incluidas`}
              </dd>
            </div>
          )}
          <div>
            <dt>Notas</dt>
            <dd>{current.notes ?? <span className={adminStyles.placeholder}>Sin notas</span>}</dd>
          </div>
          <div>
            <dt>Registrado</dt>
            <dd>{formatDateTime(current.created_at)}</dd>
          </div>
        </dl>
      </section>

      <section className={styles.section} aria-labelledby="history-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="history-title" className={styles.sectionTitle}>
              Historial de cambios
            </h2>
            <p className={styles.sectionText}>Cada cambio con su valor anterior y el nuevo, quién lo hizo y por qué. No se puede editar ni borrar.</p>
          </div>
        </header>
        <div className={styles.sectionBody}>
          <ContractHistory contractId={current.id} version={version} />
        </div>
      </section>

      {current.can.delete && (
        <p>
          <Button variant="dangerGhost" icon="trash" onClick={() => setModal('delete')}>
            Eliminar borrador
          </Button>
        </p>
      )}

      <ProjectFormModal open={modal === 'project'} presetContractId={current.id} onClose={() => setModal(null)} onSaved={(p) => navigate(`/app/projects/${p.id}`)} />
      <StatusChangeModal open={modal === 'status'} contract={current} onClose={() => setModal(null)} onSaved={saved} />
      <HourMovementModal open={modal === 'movement'} contract={current} onClose={() => setModal(null)} onSaved={saved} />
      <ContractEditModal open={modal === 'edit'} contract={current} onClose={() => setModal(null)} onSaved={saved} />
      <ConfirmDialog
        open={modal === 'delete'}
        title="Eliminar borrador"
        message={`¿Eliminar el borrador ${current.code}? Solo es posible porque nunca se activó. Si ya se firmó, cámbialo a cancelado para conservar su historial.`}
        confirmLabel="Eliminar borrador"
        loading={deleting}
        onCancel={() => setModal(null)}
        onConfirm={remove}
      />
    </>
  );
}
