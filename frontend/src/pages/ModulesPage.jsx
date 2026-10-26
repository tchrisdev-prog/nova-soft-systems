import { Badge } from '../components/ui/Badge';
import { Button } from '../components/ui/Button';
import { Icon } from '../components/ui/Icon';
import { PageHeader } from '../components/ui/PageHeader';
import { ErrorState, LoadingState } from '../components/ui/StateMessage';
import { MODULE_ICONS, actionLabel, sortActions } from '../config/modules';
import { MODULE_ROUTES } from '../config/navigation';
import { useApiResource } from '../hooks/useApiResource';
import { useAuth } from '../hooks/useAuth';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { catalogService } from '../services/catalogService';
import { cx } from '../utils/cx';
import styles from './ModulesPage.module.scss';

const STATUS_TONE = { active: 'success', planned: 'neutral', inactive: 'danger' };

export default function ModulesPage() {
  useDocumentTitle('Módulos');
  const auth = useAuth();
  const { data, error, loading, reload } = useApiResource((signal) => catalogService.modules({ signal }), []);
  const access = Object.fromEntries(auth.modules.map((m) => [m.code, m]));

  return (
    <>
      <PageHeader
        title="Módulos del sistema"
        description="Catálogo de módulos de Nova Sync Systems. Los módulos en desarrollo ya forman parte del modelo de permisos y se habilitarán a medida que se publiquen."
      />

      {loading && !data && <LoadingState label="Cargando módulos…" />}
      {error && <ErrorState error={error} onRetry={reload} />}

      {data && (
        <ul className={styles.list}>
          {data.map((module) => {
            const mine = access[module.code];
            const actions = sortActions(mine?.actions ?? []);
            const route = MODULE_ROUTES[module.code];

            return (
              <li key={module.code} className={cx(styles.item, styles[module.status])}>
                <span className={styles.icon}>
                  <Icon name={MODULE_ICONS[module.code] ?? 'grid'} size={22} />
                </span>
                <div className={styles.body}>
                  <div className={styles.titleRow}>
                    <h2 className={styles.name}>{module.name}</h2>
                    <Badge tone={STATUS_TONE[module.status]} dot>
                      {module.status_label}
                    </Badge>
                  </div>
                  <p className={styles.description}>{module.description}</p>
                  <p className={styles.access}>
                    {module.status !== 'active'
                      ? 'Aún no disponible.'
                      : actions.length > 0
                        ? `Tu acceso: ${actions.map((a) => actionLabel(a).toLowerCase()).join(', ')}.`
                        : 'No tienes permisos en este módulo.'}
                  </p>
                </div>
                {route && mine?.can_access && (
                  <Button to={route} variant="secondary" size="sm" className={styles.open}>
                    Abrir
                  </Button>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </>
  );
}
