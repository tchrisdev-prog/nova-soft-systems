import { Link } from 'react-router';
import { Button } from '../components/ui/Button';
import { Icon } from '../components/ui/Icon';
import { PageHeader } from '../components/ui/PageHeader';
import { EmptyState, ErrorState } from '../components/ui/StateMessage';
import { MODULE_ICONS } from '../config/modules';
import { MODULE_ROUTES } from '../config/navigation';
import { useApiResource } from '../hooks/useApiResource';
import { useAuth } from '../hooks/useAuth';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { catalogService } from '../services/catalogService';
import { auditActionLabel, auditSummary } from '../utils/auditLabels';
import { firstName, formatNumber, formatRelative } from '../utils/format';
import styles from './DashboardPage.module.scss';

function Metric({ label, value, detail }) {
  return (
    <div className={styles.metric}>
      <dt className={styles.metricLabel}>{label}</dt>
      <dd className={styles.metricValue}>
        <span className="tabular">{value}</span>
        {detail && <span className={styles.metricDetail}>{detail}</span>}
      </dd>
    </div>
  );
}

export default function DashboardPage() {
  useDocumentTitle('Panel');
  const { user, modules } = useAuth();
  const { data, error, loading, reload } = useApiResource((signal) => catalogService.dashboard({ signal }), []);
  const myModules = modules.filter((m) => m.can_access && MODULE_ROUTES[m.code]);

  return (
    <>
      <PageHeader
        title={`Hola, ${firstName(user.name)}`}
        description="Resumen de Nova Sync Systems con la información que tu rol te permite consultar."
      />

      {error ? (
        <section className={styles.panel}>
          <ErrorState error={error} onRetry={reload} compact />
        </section>
      ) : (
        <section aria-label="Indicadores" aria-busy={loading}>
          <dl className={styles.metrics}>
            {loading || !data ? (
              Array.from({ length: 3 }, (_, i) => (
                <div key={i} className={styles.metric} aria-hidden="true">
                  <span className={styles.skeleton} />
                  <span className={styles.skeletonValue} />
                </div>
              ))
            ) : (
              <>
                {data.users && (
                  <Metric label="Usuarios activos" value={formatNumber(data.users.active)} detail={`de ${formatNumber(data.users.total)} autorizados`} />
                )}
                {data.users && (
                  <Metric label="Pendientes de primer ingreso" value={formatNumber(data.users.pending_first_login)} detail="cuentas autorizadas" />
                )}
                {data.roles && <Metric label="Roles activos" value={formatNumber(data.roles.active)} detail={`de ${formatNumber(data.roles.total)} roles`} />}
                <Metric label="Módulos disponibles" value={formatNumber(data.modules.active)} detail={`${formatNumber(data.modules.planned)} en desarrollo`} />
              </>
            )}
          </dl>
        </section>
      )}

      <div className={styles.columns}>
        <section className={styles.panel} aria-labelledby="my-modules">
          <header className={styles.panelHeader}>
            <h2 id="my-modules" className={styles.panelTitle}>
              Tus módulos
            </h2>
            <Link to="/app/modules" className={styles.panelLink}>
              Ver catálogo completo
            </Link>
          </header>

          {myModules.length === 0 ? (
            <EmptyState
              icon="key"
              title="Aún no tienes módulos asignados"
              message="Tu cuenta está activa, pero ninguno de tus roles incluye permisos sobre los módulos disponibles. Pide al administrador del sistema que te asigne un rol."
              compact
            />
          ) : (
            <ul className={styles.moduleList}>
              {myModules.map((module) => (
                <li key={module.code}>
                  <Link to={MODULE_ROUTES[module.code]} className={styles.moduleLink}>
                    <span className={styles.moduleIcon}>
                      <Icon name={MODULE_ICONS[module.code] ?? 'grid'} size={20} />
                    </span>
                    <span className={styles.moduleText}>
                      <span className={styles.moduleName}>{module.name}</span>
                      <span className={styles.moduleDescription}>{module.description}</span>
                    </span>
                    <Icon name="chevron-right" size={18} className={styles.chevron} />
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>

        {data?.recent_activity && (
          <section className={styles.panel} aria-labelledby="recent-activity">
            <header className={styles.panelHeader}>
              <h2 id="recent-activity" className={styles.panelTitle}>
                Actividad reciente
              </h2>
              <Link to="/app/audit" className={styles.panelLink}>
                Ver auditoría
              </Link>
            </header>
            {data.recent_activity.length === 0 ? (
              <EmptyState icon="activity" title="Sin actividad registrada" message="Aquí verás los cambios de roles, permisos y accesos." compact />
            ) : (
              <ol className={styles.activity}>
                {data.recent_activity.map((log) => (
                  <li key={log.id} className={styles.activityItem}>
                    <span className={styles.activityAction}>{auditActionLabel(log.action)}</span>
                    <span className={styles.activityMeta}>
                      {log.actor?.name ?? 'Sistema'}, {formatRelative(log.created_at)}
                    </span>
                    {auditSummary(log) && <span className={styles.activitySummary}>{auditSummary(log)}</span>}
                  </li>
                ))}
              </ol>
            )}
          </section>
        )}
      </div>

      {!data?.users && !loading && myModules.length > 0 && (
        <p className={styles.footnote}>Algunos indicadores solo se muestran a quienes tienen permiso para consultarlos.</p>
      )}

      {myModules.length === 0 && (
        <div className={styles.footnote}>
          <Button to="/app/modules" variant="secondary" icon="grid">
            Ver los módulos del sistema
          </Button>
        </div>
      )}
    </>
  );
}
