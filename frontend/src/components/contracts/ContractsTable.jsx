import { Link } from 'react-router';
import { Cell, SkeletonRows, Table } from '../ui/Table';
import { formatCalendarDate, formatCurrency } from '../../utils/format';
import adminStyles from '../../pages/AdminPage.module.scss';
import { AlertList, ConsumptionBar, ContractStatusBadge } from './ContractBits';

/** Tabla de contratos con su consumo y alertas; se usa en la lista general y en el detalle del cliente. */
export function ContractsTable({ contracts, loading, showClient = true, caption = 'Contratos' }) {
  const columns = [
    { key: 'contract', label: 'Contrato' },
    ...(showClient ? [{ key: 'client', label: 'Cliente' }] : []),
    { key: 'type', label: 'Servicio y cobro' },
    { key: 'value', label: 'Valor', align: 'end' },
    { key: 'status', label: 'Estado' },
    { key: 'consumption', label: 'Consumo' },
    { key: 'alerts', label: 'Atención' },
  ];

  return (
    <Table caption={caption} columns={columns}>
      {loading ? (
        <SkeletonRows columns={columns.length} />
      ) : (
        contracts.map((contract) => (
          <tr key={contract.id}>
            <Cell label="Contrato">
              <Link to={`/app/contracts/${contract.id}`} className={adminStyles.primaryLink}>
                {contract.name}
              </Link>
              <span className={adminStyles.secondary}>
                {contract.code} · desde {formatCalendarDate(contract.start_date)}
              </span>
            </Cell>
            {showClient && (
              <Cell label="Cliente">
                <Link to={`/app/clients/${contract.client.id}`} className={adminStyles.primaryLink}>
                  {contract.client.name}
                </Link>
              </Cell>
            )}
            <Cell label="Servicio y cobro">
              <span className={adminStyles.primaryText}>{contract.service_line.label}</span>
              <span className={adminStyles.secondary}>
                {contract.billing_model.label} · {contract.coverage.label}
              </span>
            </Cell>
            <Cell label="Valor" align="end">
              <span className="tabular">
                {contract.billing_model.value === 'hour_bag' ? formatCurrency(contract.hour_bag?.contract_value) : formatCurrency(contract.consumption?.fee_amount ?? contract.monthly_fee)}
              </span>
              <span className={adminStyles.secondary}>{contract.billing_model.value === 'hour_bag' ? 'bolsa' : 'al mes'}</span>
            </Cell>
            <Cell label="Estado">
              <ContractStatusBadge status={contract.status} />
            </Cell>
            <Cell label="Consumo">{contract.status.value === 'draft' ? <span className={adminStyles.secondary}>Sin iniciar</span> : <ConsumptionBar consumption={contract.consumption} />}</Cell>
            <Cell label="Atención">
              <AlertList alerts={contract.alerts} emptyText="Al día" />
            </Cell>
          </tr>
        ))
      )}
    </Table>
  );
}
