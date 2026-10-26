import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { BackLink } from '../components/business/ModuleTabs';
import { ClientFormModal } from '../components/clients/ClientFormModal';
import { ContactFormModal } from '../components/clients/ContactFormModal';
import { Metric } from '../components/contracts/ContractBits';
import { ContractsTable } from '../components/contracts/ContractsTable';
import { ContractWizard } from '../components/contracts/ContractWizard';
import { Badge, StatusBadge } from '../components/ui/Badge';
import { Button } from '../components/ui/Button';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { PageHeader } from '../components/ui/PageHeader';
import { EmptyState, ErrorState, LoadingState } from '../components/ui/StateMessage';
import { useApiResource } from '../hooks/useApiResource';
import { useAuth } from '../hooks/useAuth';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useToast } from '../hooks/useToast';
import { clientsService } from '../services/clientsService';
import { contractsService } from '../services/contractsService';
import { cx } from '../utils/cx';
import { formatCurrency, formatDateTime, formatHours } from '../utils/format';
import adminStyles from './AdminPage.module.scss';
import clientStyles from '../components/clients/Clients.module.scss';
import styles from '../components/business/Business.module.scss';

export default function ClientDetailPage() {
  const { clientId } = useParams();
  const navigate = useNavigate();
  const { can } = useAuth();
  const toast = useToast();

  const client = useApiResource((signal) => clientsService.get(clientId, { signal }), [clientId]);
  const contracts = useApiResource((signal) => contractsService.list({ client_id: clientId, per_page: 100 }, { signal }), [clientId]);
  useDocumentTitle(client.data ? client.data.name : 'Cliente');

  const [editing, setEditing] = useState(false);
  const [contactForm, setContactForm] = useState(null); // null | 'new' | contacto
  const [removingContact, setRemovingContact] = useState(null);
  const [toggling, setToggling] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [creatingContract, setCreatingContract] = useState(false);
  const [busy, setBusy] = useState(false);

  if (client.error) {
    return (
      <>
        <BackLink to="/app/clients">Clientes</BackLink>
        <ErrorState error={client.error} title={client.error.status === 404 ? 'Este cliente no existe' : 'No se pudo cargar el cliente'} onRetry={client.error.status === 404 ? undefined : client.reload} />
      </>
    );
  }

  if (!client.data) return <LoadingState label="Cargando cliente…" />;

  const current = client.data;
  const canUpdate = current.can.update;
  const refresh = () => {
    client.reload();
    contracts.reload();
  };

  async function run(action, onDone) {
    setBusy(true);
    try {
      const response = await action();
      toast.success(response.message);
      onDone?.();
    } catch (error) {
      toast.error(error.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader
        title={current.name}
        description={`${current.sector.label} · atendido desde ${current.site?.name ?? '—'}`}
        actions={
          <>
            {canUpdate && (
              <Button variant="secondary" icon="edit" onClick={() => setEditing(true)}>
                Editar
              </Button>
            )}
            {current.can.create_contract && current.is_active && (
              <Button icon="plus" onClick={() => setCreatingContract(true)}>
                Nuevo contrato
              </Button>
            )}
          </>
        }
      >
        <BackLink to="/app/clients">Clientes</BackLink>
        <span className={styles.headerBadges}>
          <Badge>{current.code}</Badge>
          <StatusBadge active={current.is_active} />
        </span>
      </PageHeader>

      <dl className={styles.metrics}>
        <Metric label="Mensualidades activas" value={formatCurrency(current.summary.monthly_recurring)} detail="valor del mes en curso" />
        <Metric label="Contratos activos" value={current.summary.active_contracts} detail={`${current.contracts_count} en total`} />
        <Metric label="Saldo en bolsas" value={formatHours(current.summary.bag_balance_minutes)} detail={`${current.summary.hour_bags} ${current.summary.hour_bags === 1 ? 'bolsa activa' : 'bolsas activas'}`} />
        <Metric label="Alertas" value={current.summary.alerts} detail={current.summary.alerts ? 'revisa los contratos' : 'todo al día'} />
      </dl>

      <section className={styles.section} aria-labelledby="client-data">
        <header className={styles.sectionHeader}>
          <h2 id="client-data" className={styles.sectionTitle}>
            Datos del cliente
          </h2>
          {canUpdate && (
            <Button variant={current.is_active ? 'dangerGhost' : 'ghost'} size="sm" icon="power" onClick={() => setToggling(true)}>
              {current.is_active ? 'Desactivar cliente' : 'Activar cliente'}
            </Button>
          )}
        </header>
        <dl className={styles.facts}>
          <div>
            <dt>Código</dt>
            <dd>{current.code}</dd>
          </div>
          <div>
            <dt>NIT</dt>
            <dd>{current.tax_id ?? <span className={adminStyles.placeholder}>Sin registrar</span>}</dd>
          </div>
          <div>
            <dt>Sector</dt>
            <dd>{current.sector.label}</dd>
          </div>
          <div>
            <dt>Sede</dt>
            <dd>{current.site?.name}</dd>
          </div>
          <div>
            <dt>Registrado</dt>
            <dd>{formatDateTime(current.created_at)}</dd>
          </div>
        </dl>
      </section>

      <section className={styles.section} aria-labelledby="client-contacts">
        <header className={styles.sectionHeader}>
          <div>
            <h2 id="client-contacts" className={styles.sectionTitle}>
              Contactos
            </h2>
            <p className={styles.sectionText}>Quién negocia, quién reporta los incidentes y quién recibe las facturas.</p>
          </div>
          {canUpdate && (
            <Button variant="secondary" size="sm" icon="plus" onClick={() => setContactForm('new')}>
              Agregar contacto
            </Button>
          )}
        </header>
        <div className={styles.sectionBody}>
          {current.contacts.length === 0 ? (
            <EmptyState compact icon="users" title="Sin contactos" message="Agrega al menos un contacto comercial para este cliente." />
          ) : (
            <ul className={clientStyles.contacts}>
              {current.contacts.map((contact) => (
                <li key={contact.id} className={cx(clientStyles.contact, contact.is_primary && clientStyles.contactPrimary)}>
                  <div className={clientStyles.contactTop}>
                    <Badge tone="primary">{contact.type.label}</Badge>
                    {contact.is_primary && <Badge tone="accent">Principal</Badge>}
                  </div>
                  <div>
                    <p className={clientStyles.contactName}>{contact.name}</p>
                    {contact.position && <p className={clientStyles.contactPosition}>{contact.position}</p>}
                  </div>
                  <div className={clientStyles.contactLinks}>
                    {contact.email && <a href={`mailto:${contact.email}`}>{contact.email}</a>}
                    {contact.phone && <a href={`tel:${contact.phone.replace(/[^\d+]/g, '')}`}>{contact.phone}</a>}
                  </div>
                  {canUpdate && (
                    <div className={clientStyles.contactActions}>
                      <Button variant="ghost" size="sm" icon="edit" onClick={() => setContactForm(contact)}>
                        Editar
                      </Button>
                      {!contact.is_primary && (
                        <Button variant="ghost" size="sm" icon="check" loading={busy} onClick={() => run(() => clientsService.updateContact(current.id, contact.id, { is_primary: true }), client.reload)}>
                          Hacer principal
                        </Button>
                      )}
                      <Button variant="dangerGhost" size="sm" icon="trash" onClick={() => setRemovingContact(contact)}>
                        Quitar
                      </Button>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>

      <section className={styles.section} aria-labelledby="client-contracts">
        <header className={styles.sectionHeader}>
          <h2 id="client-contracts" className={styles.sectionTitle}>
            Contratos
          </h2>
        </header>
        {contracts.error ? (
          <ErrorState error={contracts.error} onRetry={contracts.reload} compact />
        ) : contracts.data && contracts.data.data.length === 0 ? (
          <EmptyState compact icon="briefcase" title="Sin contratos" message="Este cliente aún no tiene contratos registrados." />
        ) : (
          <ContractsTable contracts={contracts.data?.data ?? []} loading={!contracts.data} showClient={false} caption={`Contratos de ${current.name}`} />
        )}
      </section>

      {current.can.delete && current.contracts_count === 0 && (
        <p>
          <Button variant="dangerGhost" icon="trash" onClick={() => setDeleting(true)}>
            Eliminar cliente
          </Button>
        </p>
      )}

      <ClientFormModal
        open={editing}
        client={current}
        onClose={() => setEditing(false)}
        onSaved={(data) => {
          setEditing(false);
          client.setData(data);
        }}
      />

      <ContactFormModal
        open={contactForm !== null}
        clientId={current.id}
        contact={contactForm === 'new' ? null : contactForm}
        onClose={() => setContactForm(null)}
        onSaved={() => {
          setContactForm(null);
          client.reload();
        }}
      />

      <ContractWizard
        open={creatingContract}
        client={current}
        onClose={() => setCreatingContract(false)}
        onCreated={(contract) => navigate(`/app/contracts/${contract.id}`)}
      />

      <ConfirmDialog
        open={Boolean(removingContact)}
        title="Quitar contacto"
        message={`¿Quitar a ${removingContact?.name} de los contactos de ${current.name}?${removingContact?.is_primary ? ' Era el contacto principal: el siguiente contacto tomará su lugar.' : ''}`}
        confirmLabel="Quitar contacto"
        loading={busy}
        onCancel={() => setRemovingContact(null)}
        onConfirm={() => run(() => clientsService.removeContact(current.id, removingContact.id), () => {
          setRemovingContact(null);
          client.reload();
        })}
      />

      <ConfirmDialog
        open={toggling}
        title={current.is_active ? 'Desactivar cliente' : 'Activar cliente'}
        message={
          current.is_active
            ? `${current.name} dejará de aparecer al registrar contratos nuevos. Sus contratos actuales y su historial no cambian.`
            : `${current.name} volverá a estar disponible para registrar contratos.`
        }
        confirmLabel={current.is_active ? 'Desactivar cliente' : 'Activar cliente'}
        tone={current.is_active ? 'danger' : 'primary'}
        loading={busy}
        onCancel={() => setToggling(false)}
        onConfirm={() => run(() => clientsService.update(current.id, { is_active: !current.is_active }), () => {
          setToggling(false);
          refresh();
        })}
      />

      <ConfirmDialog
        open={deleting}
        title="Eliminar cliente"
        message={`¿Eliminar a ${current.name}? Solo es posible porque no tiene contratos. Esta acción no se puede deshacer.`}
        confirmLabel="Eliminar cliente"
        loading={busy}
        onCancel={() => setDeleting(false)}
        onConfirm={() => run(() => clientsService.remove(current.id), () => navigate('/app/clients'))}
      />
    </>
  );
}
