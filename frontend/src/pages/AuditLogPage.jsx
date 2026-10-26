import { useState } from 'react';
import { Select } from '../components/ui/Field';
import { PageHeader } from '../components/ui/PageHeader';
import { Pagination } from '../components/ui/Pagination';
import { EmptyState, ErrorState } from '../components/ui/StateMessage';
import { Cell, SkeletonRows, Table } from '../components/ui/Table';
import { useApiResource } from '../hooks/useApiResource';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { catalogService } from '../services/catalogService';
import { auditActionLabel, auditSummary } from '../utils/auditLabels';
import { formatDateTime } from '../utils/format';
import styles from './AdminPage.module.scss';

const COLUMNS = [
  { key: 'date', label: 'Fecha' },
  { key: 'action', label: 'Operación' },
  { key: 'actor', label: 'Realizada por' },
  { key: 'detail', label: 'Detalle' },
];

const FILTERS = [
  { value: '', label: 'Todas las operaciones' },
  { value: 'role.', label: 'Roles y permisos' },
  { value: 'user.', label: 'Usuarios' },
  { value: 'auth.', label: 'Accesos' },
  { value: 'super_admin.', label: 'Super Admin' },
];

const SUBJECT_TYPES = {
  user: 'Usuario',
  role: 'Rol',
  client: 'Cliente',
  contract: 'Contrato',
  project: 'Proyecto',
  allocation: 'Asignación',
  staff_profile: 'Perfil',
  time_entry: 'Registro de horas',
};

export default function AuditLogPage() {
  useDocumentTitle('Auditoría');
  const [action, setAction] = useState('');
  const [page, setPage] = useState(1);

  const { data, error, loading, reload } = useApiResource(
    (signal) => catalogService.auditLogs({ action, page, per_page: 20 }, { signal }),
    [action, page],
  );
  const logs = data?.data ?? [];

  return (
    <>
      <PageHeader
        title="Auditoría"
        description="Registro de operaciones sensibles: accesos, cambios de roles, permisos y estado de usuarios. Los registros no se pueden editar."
      />

      <section className={styles.panel} aria-label="Registro de auditoría">
        <div className={styles.toolbar}>
          <Select
            label="Tipo de operación"
            className={styles.filter}
            value={action}
            onChange={(e) => {
              setAction(e.target.value);
              setPage(1);
            }}
            options={FILTERS}
          />
        </div>

        {error ? (
          <ErrorState error={error} onRetry={reload} />
        ) : !loading && logs.length === 0 ? (
          <EmptyState icon="activity" title="Sin registros" message="Aún no hay operaciones de este tipo registradas." />
        ) : (
          <Table caption="Registro de auditoría" columns={COLUMNS}>
            {loading && !data ? (
              <SkeletonRows columns={COLUMNS.length} rows={8} />
            ) : (
              logs.map((log) => (
                <tr key={log.id}>
                  <Cell label="Fecha">
                    <span className={styles.nowrap}>{formatDateTime(log.created_at)}</span>
                  </Cell>
                  <Cell label="Operación">
                    <span className={styles.primaryText}>{auditActionLabel(log.action)}</span>
                    {log.subject && (
                      <span className={styles.secondary}>
                        {SUBJECT_TYPES[log.subject.type] ?? log.subject.type}: {log.subject.label ?? `#${log.subject.id}`}
                      </span>
                    )}
                  </Cell>
                  <Cell label="Realizada por">
                    {log.actor ? (
                      <>
                        <span className={styles.primaryText}>{log.actor.name}</span>
                        <span className={styles.secondary}>{log.actor.email}</span>
                      </>
                    ) : (
                      <span className={styles.placeholder}>Sistema o consola</span>
                    )}
                  </Cell>
                  <Cell label="Detalle" className={styles.muted}>
                    {auditSummary(log) || <span className={styles.placeholder}>—</span>}
                    {log.ip_address && <span className={styles.secondary}>IP {log.ip_address}</span>}
                  </Cell>
                </tr>
              ))
            )}
          </Table>
        )}

        <Pagination meta={data?.meta} onPageChange={setPage} label="registros" />
      </section>
    </>
  );
}
