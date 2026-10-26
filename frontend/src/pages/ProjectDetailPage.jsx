import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { BackLink } from '../components/business/ModuleTabs';
import { Metric } from '../components/contracts/ContractBits';
import { AllocationModal } from '../components/projects/AllocationModal';
import { BudgetBar, HealthFlags, ProjectStatusBadge } from '../components/projects/ProjectBits';
import { ProjectFormModal } from '../components/projects/ProjectFormModal';
import { LeaderModal, ProjectStatusModal } from '../components/projects/ProjectModals';
import { Badge } from '../components/ui/Badge';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { Select } from '../components/ui/Field';
import { PageHeader } from '../components/ui/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ui/StateMessage';
import { Cell, Table } from '../components/ui/Table';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useToast } from '../hooks/useToast';
import { projectsService } from '../services/projectsService';
import { formatCalendarDate, formatCurrency, formatDateTime, formatHours, todayInBogota } from '../utils/format';
import adminStyles from './AdminPage.module.scss';
import styles from '../components/business/Business.module.scss';
import projectStyles from '../components/projects/Projects.module.scss';

const ALLOCATION_STATES = { upcoming: ['Próxima', 'neutral'], current: ['Vigente', 'success'], ended: ['Terminó', 'primary'] };
const shortDate = new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'short' });
const weekLabel = (iso) => {
  const [y, m, d] = iso.split('-').map(Number);
  return shortDate.format(new Date(y, m - 1, d));
};

export default function ProjectDetailPage() {
  const { projectId } = useParams();
  const navigate = useNavigate();
  const toast = useToast();
  const [version, setVersion] = useState(0);
  const [weeks, setWeeks] = useState(8);
  const [modal, setModal] = useState(null); // edit | status | leader | assign | delete
  const [editingAllocation, setEditingAllocation] = useState(null);
  const [confirming, setConfirming] = useState(null); // { type: 'end' | 'remove', allocation }
  const [busy, setBusy] = useState(false);

  const project = useApiResource((signal) => projectsService.get(projectId, { signal }), [projectId, version]);
  const team = useApiResource((signal) => projectsService.allocations(projectId, { signal }), [projectId, version]);
  const hours = useApiResource((signal) => projectsService.hours(projectId, weeks, { signal }), [projectId, weeks, version]);
  useDocumentTitle(project.data ? project.data.name : 'Proyecto');

  if (project.error) {
    return (
      <>
        <BackLink to="/app/projects">Proyectos</BackLink>
        <ErrorState
          error={project.error}
          title={project.error.status === 404 ? 'Este proyecto no existe' : project.error.status === 403 ? 'No tienes acceso a este proyecto' : 'No se pudo cargar el proyecto'}
          onRetry={[403, 404].includes(project.error.status) ? undefined : project.reload}
        />
      </>
    );
  }

  if (!project.data) return <LoadingState label="Cargando proyecto…" />;

  const current = project.data;
  const health = current.health;
  const closed = current.status.value === 'closed';
  const canTeam = current.can.manage_allocations && !closed;
  const refresh = () => {
    setModal(null);
    setEditingAllocation(null);
    setConfirming(null);
    setVersion((v) => v + 1);
  };

  async function confirmAction() {
    setBusy(true);
    try {
      const { type, allocation } = confirming;
      const response =
        type === 'end' ? await projectsService.endAllocation(current.id, allocation.id, todayInBogota()) : await projectsService.removeAllocation(current.id, allocation.id);
      toast.success(response.message);
      refresh();
    } catch (error) {
      toast.error(error.message);
    } finally {
      setBusy(false);
    }
  }

  async function removeProject() {
    setBusy(true);
    try {
      const response = await projectsService.remove(current.id);
      toast.success(response.message);
      navigate('/app/projects');
    } catch (error) {
      toast.error(error.message);
      setBusy(false);
      setModal(null);
    }
  }

  return (
    <>
      <PageHeader
        title={current.name}
        description={`${current.kind.label} · ${current.contract?.client?.name} · sede ${current.site?.name}`}
        actions={
          <>
            {current.can.update && !closed && (
              <Button variant="secondary" icon="edit" onClick={() => setModal('edit')}>
                Editar
              </Button>
            )}
            {current.can.change_leader && !closed && (
              <Button variant="secondary" icon="user" onClick={() => setModal('leader')}>
                Cambiar líder
              </Button>
            )}
            {current.can.change_status && current.next_statuses.length > 0 && (
              <Button icon="refresh" onClick={() => setModal('status')}>
                {current.status.value === 'planned' ? 'Iniciar o cerrar' : 'Cambiar estado'}
              </Button>
            )}
          </>
        }
      >
        <BackLink to="/app/projects">Proyectos</BackLink>
        <span className={styles.headerBadges}>
          <Badge>{current.code}</Badge>
          <ProjectStatusBadge status={current.status} />
          {current.contract && (
            <Link to={`/app/contracts/${current.contract.id}`} className={adminStyles.primaryLink}>
              {current.contract.code}
            </Link>
          )}
        </span>
      </PageHeader>

      {health?.flags.length > 0 && (
        <div className={styles.alertBanner} role="status">
          <HealthFlags health={health} />
        </div>
      )}

      <dl className={styles.metrics}>
        <Metric
          label={health?.budget_source === 'contract_bag' ? 'Bolsa del contrato' : 'Presupuesto'}
          value={health?.budget_minutes ? formatHours(health.budget_minutes) : 'Sin presupuesto'}
          detail={health?.budget_source === 'contract_bag' ? 'compartida con los demás proyectos del contrato' : health?.budget_minutes ? 'del proyecto' : 'solo se registran las horas'}
        />
        <Metric label="Horas aprobadas" value={formatHours(health?.approved_minutes)} detail="en este proyecto" />
        <Metric
          label="Pendientes de aprobar"
          value={formatHours(health?.submitted_minutes)}
          detail={health?.draft_minutes ? `y ${formatHours(health.draft_minutes)} en borrador` : 'enviadas por el equipo'}
        />
        <Metric
          label="Ritmo semanal"
          value={formatHours(health?.weekly_burn_minutes)}
          detail={health?.projected_exhaustion_date ? `a este ritmo se agota el ${formatCalendarDate(health.projected_exhaustion_date)}` : 'promedio de las últimas 4 semanas'}
        />
        {current.financials && (
          <Metric
            label="Costo interno aprobado"
            value={formatCurrency(current.financials.approved_cost)}
            detail={
              current.financials.uncosted_minutes > 0
                ? `${formatHours(current.financials.uncosted_minutes)} aprobadas sin costo registrado`
                : current.financials.average_hourly_cost
                  ? `${formatCurrency(current.financials.average_hourly_cost)} por hora en promedio`
                  : undefined
            }
          />
        )}
      </dl>

      {health?.budget_minutes > 0 && (
        <section className={styles.section} aria-labelledby="budget-title">
          <header className={styles.sectionHeader}>
            <div>
              <h2 id="budget-title" className={styles.sectionTitle}>
                Consumo
              </h2>
              <p className={styles.sectionText}>Cuenta lo aprobado y lo enviado sin aprobar: es lo que el equipo ya trabajó.</p>
            </div>
          </header>
          <div className={styles.sectionBody}>
            <BudgetBar health={health} />
          </div>
        </section>
      )}

      <section className={styles.section} aria-labelledby="team-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="team-title" className={styles.sectionTitle}>
              Equipo
            </h2>
            <p className={styles.sectionText}>Terminar conserva las horas y el historial. Eliminar solo corrige un error, cuando la persona no ha registrado horas.</p>
          </div>
          {canTeam && (
            <Button icon="plus" onClick={() => setModal('assign')}>
              Asignar persona
            </Button>
          )}
        </header>
        {team.error ? (
          <ErrorState error={team.error} onRetry={team.reload} compact />
        ) : team.data?.length === 0 ? (
          <EmptyState compact icon="users" title="Sin equipo" message={canTeam ? 'Asigna a las personas que van a trabajar en el proyecto.' : 'Aún no hay personas asignadas.'} />
        ) : (
          <Table
            caption="Equipo del proyecto"
            columns={[
              { key: 'person', label: 'Persona' },
              { key: 'role', label: 'Rol' },
              { key: 'percent', label: 'Dedicación', align: 'end' },
              { key: 'period', label: 'Periodo' },
              { key: 'state', label: 'Estado' },
              ...(canTeam ? [{ key: 'actions', label: 'Acciones', hidden: true, align: 'end' }] : []),
            ]}
          >
            {(team.data ?? []).map((a) => {
              const [stateLabel, tone] = ALLOCATION_STATES[a.state] ?? ['—', 'neutral'];
              return (
                <tr key={a.id}>
                  <Cell label="Persona">
                    <span className={adminStyles.primaryText}>{a.user?.name}</span>
                    {a.user?.position && <span className={adminStyles.secondary}>{a.user.position}</span>}
                  </Cell>
                  <Cell label="Rol">{a.role_label ?? <span className={adminStyles.placeholder}>—</span>}</Cell>
                  <Cell label="Dedicación" align="end">
                    <span className="tabular">{a.allocation_percent} %</span>
                  </Cell>
                  <Cell label="Periodo">
                    {formatCalendarDate(a.start_date)} – {a.end_date ? formatCalendarDate(a.end_date) : 'sin fecha de fin'}
                  </Cell>
                  <Cell label="Estado">
                    <Badge tone={tone} dot>
                      {stateLabel}
                    </Badge>
                  </Cell>
                  {canTeam && (
                    <Cell label="Acciones" align="end">
                      <span className={adminStyles.rowActions}>
                        {a.state !== 'ended' && (
                          <Button size="sm" variant="ghost" icon="edit" onClick={() => setEditingAllocation(a)} aria-label={`Editar la asignación de ${a.user?.name}`}>
                            Editar
                          </Button>
                        )}
                        {a.state === 'current' && (
                          <Button size="sm" variant="ghost" onClick={() => setConfirming({ type: 'end', allocation: a })}>
                            Terminar
                          </Button>
                        )}
                        <Button size="sm" variant="dangerGhost" icon="trash" onClick={() => setConfirming({ type: 'remove', allocation: a })} aria-label={`Eliminar la asignación de ${a.user?.name}`} />
                      </span>
                    </Cell>
                  )}
                </tr>
              );
            })}
          </Table>
        )}
      </section>

      <section className={styles.section} aria-labelledby="hours-title">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="hours-title" className={styles.sectionTitle}>
              Horas por semana
            </h2>
            <p className={styles.sectionText}>Aprobadas, enviadas y en borrador. Las rechazadas no cuentan.</p>
          </div>
          <Select
            label="Semanas"
            value={String(weeks)}
            onChange={(e) => setWeeks(Number(e.target.value))}
            options={[4, 8, 12].map((w) => ({ value: String(w), label: `Últimas ${w}` }))}
          />
        </header>
        {hours.error ? (
          <ErrorState error={hours.error} onRetry={hours.reload} compact />
        ) : !hours.data ? (
          <LoadingState label="Sumando horas…" />
        ) : hours.data.people.length === 0 ? (
          <EmptyState compact icon="clock" title="Sin horas en este periodo" message="Cuando el equipo registre horas aparecerán aquí, semana por semana." />
        ) : (
          <div className={projectStyles.hoursTable}>
            <table>
              <caption className="visually-hidden">Horas por persona y semana</caption>
              <thead>
                <tr>
                  <th scope="col">Persona</th>
                  {hours.data.weeks.map((w) => (
                    <th key={w} scope="col">
                      {weekLabel(w)}
                    </th>
                  ))}
                  <th scope="col">Total</th>
                </tr>
              </thead>
              <tbody>
                {hours.data.people.map((row) => (
                  <tr key={row.user.id}>
                    <th scope="row">
                      <span className={adminStyles.primaryText}>{row.user.name}</span>
                      {row.pending_minutes > 0 && <span className={adminStyles.secondary}>{`${formatHours(row.pending_minutes)} sin aprobar`}</span>}
                    </th>
                    {row.weeks.map((minutes, i) => (
                      <td key={hours.data.weeks[i]} className="tabular">
                        {minutes ? formatHours(minutes, { unit: false }) : <span className={projectStyles.zero}>·</span>}
                      </td>
                    ))}
                    <td className="tabular">
                      <strong>{formatHours(row.total_minutes)}</strong>
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <td>Total</td>
                  {hours.data.totals.map((minutes, i) => (
                    <td key={hours.data.weeks[i]} className="tabular">
                      {formatHours(minutes, { unit: false })}
                    </td>
                  ))}
                  <td className="tabular">{formatHours(hours.data.totals.reduce((a, b) => a + b, 0))}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </section>

      <section className={styles.section} aria-labelledby="data-title">
        <header className={styles.sectionHeader}>
          <h2 id="data-title" className={styles.sectionTitle}>
            Datos del proyecto
          </h2>
        </header>
        <dl className={styles.facts}>
          <div>
            <dt>Líder</dt>
            <dd>{current.leader?.name ?? <span className={adminStyles.placeholder}>Sin líder</span>}</dd>
          </div>
          <div>
            <dt>Vigencia</dt>
            <dd>
              {formatCalendarDate(current.start_date)} – {current.end_date ? formatCalendarDate(current.end_date) : 'sin fecha de fin'}
            </dd>
          </div>
          <div>
            <dt>Contrato</dt>
            <dd>
              {current.contract?.code} · {current.contract?.name}
            </dd>
          </div>
          <div>
            <dt>Descripción</dt>
            <dd>{current.description ?? <span className={adminStyles.placeholder}>Sin descripción</span>}</dd>
          </div>
          <div>
            <dt>Registrado</dt>
            <dd>{formatDateTime(current.created_at)}</dd>
          </div>
        </dl>
      </section>

      {current.can.delete && !closed && (
        <p>
          <Button variant="dangerGhost" icon="trash" onClick={() => setModal('delete')}>
            Eliminar proyecto
          </Button>
        </p>
      )}

      <ProjectFormModal open={modal === 'edit'} project={current} onClose={() => setModal(null)} onSaved={refresh} />
      <ProjectStatusModal open={modal === 'status'} project={current} onClose={() => setModal(null)} onSaved={refresh} />
      <LeaderModal open={modal === 'leader'} project={current} onClose={() => setModal(null)} onSaved={refresh} />
      <AllocationModal open={modal === 'assign'} project={current} onClose={() => setModal(null)} onSaved={refresh} />
      <AllocationModal open={Boolean(editingAllocation)} allocation={editingAllocation} onClose={() => setEditingAllocation(null)} onSaved={refresh} />
      <ConfirmDialog
        open={Boolean(confirming)}
        title={confirming?.type === 'end' ? 'Terminar asignación' : 'Eliminar asignación'}
        message={
          confirming?.type === 'end'
            ? `${confirming.allocation.user?.name} deja el proyecto hoy. Sus horas registradas se conservan y su capacidad queda libre desde mañana.`
            : `¿Eliminar la asignación de ${confirming?.allocation.user?.name ?? ''}? Solo es posible si no ha registrado horas en el proyecto; si ya las tiene, termínala.`
        }
        confirmLabel={confirming?.type === 'end' ? 'Terminar hoy' : 'Eliminar asignación'}
        tone={confirming?.type === 'end' ? 'primary' : 'danger'}
        loading={busy}
        onCancel={() => setConfirming(null)}
        onConfirm={confirmAction}
      />
      <ConfirmDialog
        open={modal === 'delete'}
        title="Eliminar proyecto"
        message={`¿Eliminar ${current.code}? Solo es posible si nadie ha registrado horas. Si ya hubo trabajo, ciérralo para conservar sus cifras.`}
        confirmLabel="Eliminar proyecto"
        loading={busy}
        onCancel={() => setModal(null)}
        onConfirm={removeProject}
      />
    </>
  );
}
