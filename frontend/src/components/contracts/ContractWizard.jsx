import { useEffect, useMemo, useState } from 'react';
import { Button } from '../ui/Button';
import { Checkbox, Select, TextArea, TextField } from '../ui/Field';
import { Modal } from '../ui/Modal';
import { useReferenceData } from '../../hooks/useReferenceData';
import { useToast } from '../../hooks/useToast';
import { clientsService } from '../../services/clientsService';
import { contractsService } from '../../services/contractsService';
import { cx } from '../../utils/cx';
import { formatCalendarDate, formatCurrency, todayInBogota } from '../../utils/format';
import formStyles from '../ui/Form.module.scss';
import { OptionCards } from './OptionCards';
import styles from './Contracts.module.scss';

const STEPS = ['Cliente', 'Servicio', 'Condiciones', 'Revisión'];

const LINE_TEXT = {
  custom_software: 'Aplicaciones y plataformas desarrolladas para el cliente.',
  infrastructure_support: 'Mesa de ayuda, servidores, redes y mantenimiento.',
  cloud_services: 'Operación, monitoreo y costos de la nube del cliente.',
  cybersecurity: 'Pruebas de seguridad, SOC y cumplimiento.',
};

const BILLING_TEXT = {
  hour_bag: 'El cliente compra un paquete de horas que se va consumiendo. Se puede recargar.',
  monthly_recurring: 'Un valor fijo cada mes, con o sin horas incluidas. Se renueva mes a mes.',
};

const COVERAGE_TEXT = {
  business_hours: 'Lunes a viernes en horario laboral.',
  extended: 'Horario ampliado: noches o fines de semana acordados.',
  '24x7': 'Todos los días, a cualquier hora, con turnos de disponibilidad.',
};

// Campo del servidor → paso donde se corrige.
const FIELD_STEP = {
  client_id: 0, name: 1, service_line: 1, billing_model: 1, coverage: 1,
  start_date: 2, end_date: 2, initial_minutes: 2, initial_amount: 2, monthly_fee: 2, included_minutes_per_month: 2, notes: 2,
};

const toMinutes = (hours) => Math.round(Number(hours) * 4) * 15; // bloques de 15 minutos

function initialValues(clientId) {
  return {
    client_id: clientId ? String(clientId) : '',
    name: '',
    service_line: '',
    billing_model: '',
    coverage: 'business_hours',
    start_date: todayInBogota(),
    end_date: '',
    initial_hours: '',
    initial_amount: '',
    monthly_fee: '',
    included_hours: '',
    availability_only: false,
    notes: '',
  };
}

function validate(step, v) {
  const errors = {};
  if (step === 0 && !v.client_id) errors.client_id = 'Elige el cliente.';
  if (step === 1) {
    if (v.name.trim().length < 3) errors.name = 'Escribe un nombre que identifique el servicio (mínimo 3 caracteres).';
    if (!v.service_line) errors.service_line = 'Elige la línea de servicio.';
    if (!v.billing_model) errors.billing_model = 'Elige cómo se cobra.';
    if (!v.coverage) errors.coverage = 'Elige la cobertura.';
  }
  if (step === 2) {
    if (!v.start_date) errors.start_date = 'Indica la fecha de inicio.';
    if (v.end_date && v.end_date < v.start_date) errors.end_date = 'La fecha de fin debe ser igual o posterior a la de inicio.';
    if (v.billing_model === 'hour_bag') {
      if (!(Number(v.initial_hours) > 0)) errors.initial_minutes = 'Indica cuántas horas compra el cliente.';
      if (v.initial_amount === '' || Number(v.initial_amount) < 0) errors.initial_amount = 'Indica el valor de la bolsa.';
    } else {
      if (v.monthly_fee === '' || Number(v.monthly_fee) < 0) errors.monthly_fee = 'Indica el valor mensual.';
      if (!v.availability_only && !(Number(v.included_hours) >= 0 && v.included_hours !== '')) {
        errors.included_minutes_per_month = 'Indica las horas incluidas o marca que se cobra solo por disponibilidad.';
      }
    }
  }
  return errors;
}

/**
 * Registrar un contrato en cuatro pasos. Cada paso pide solo lo que
 * corresponde a la forma de cobro elegida, así no se mezclan los datos de
 * una bolsa con los de una mensualidad. El contrato queda en borrador.
 */
export function ContractWizard({ open, client, onClose, onCreated }) {
  const toast = useToast();
  const reference = useReferenceData();
  const [step, setStep] = useState(0);
  const [values, setValues] = useState(initialValues(client?.id));
  const [errors, setErrors] = useState({});
  const [clients, setClients] = useState(null);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  useEffect(() => {
    if (!open) return;
    setValues(initialValues(client?.id));
    setStep(client ? 1 : 0);
    setErrors({});
    setFormError(null);

    if (!client) {
      clientsService
        .list({ status: 'active', per_page: 100 })
        .then((response) => setClients(response.data))
        .catch((err) => setFormError(err.message));
    }
  }, [open, client]);

  const set = (field, value) => setValues((v) => ({ ...v, [field]: value }));
  const isBag = values.billing_model === 'hour_bag';
  const selectedClient = client ?? clients?.find((c) => String(c.id) === values.client_id);
  const hourlyRate = useMemo(
    () => (isBag && Number(values.initial_hours) > 0 && values.initial_amount !== '' ? Number(values.initial_amount) / Number(values.initial_hours) : null),
    [isBag, values.initial_hours, values.initial_amount],
  );

  function next() {
    const stepErrors = validate(step, values);
    setErrors(stepErrors);
    if (Object.keys(stepErrors).length === 0) setStep((s) => s + 1);
  }

  async function submit() {
    const payload = {
      client_id: Number(values.client_id),
      name: values.name.trim(),
      service_line: values.service_line,
      billing_model: values.billing_model,
      coverage: values.coverage,
      start_date: values.start_date,
      end_date: values.end_date || null,
      notes: values.notes.trim() || null,
      ...(isBag
        ? { initial_minutes: toMinutes(values.initial_hours), initial_amount: Number(values.initial_amount) }
        : {
            monthly_fee: Number(values.monthly_fee),
            included_minutes_per_month: values.availability_only ? null : toMinutes(values.included_hours),
          }),
    };

    setSaving(true);
    setFormError(null);
    try {
      const response = await contractsService.create(payload);
      toast.success(response.message);
      onCreated(response.data);
    } catch (error) {
      if (error.code === 'validation_failed') {
        const fieldErrors = error.fieldErrors;
        setErrors(fieldErrors);
        const firstStep = Math.min(...Object.keys(fieldErrors).map((f) => FIELD_STEP[f] ?? 3));
        setStep(Number.isFinite(firstStep) ? firstStep : 3);
      } else setFormError(error.message);
    } finally {
      setSaving(false);
    }
  }

  const lineOptions = reference.options('service_lines').map((o) => ({ ...o, description: LINE_TEXT[o.value] }));
  const billingOptions = reference.options('billing_models').map((o) => ({ ...o, description: BILLING_TEXT[o.value] }));
  const coverageOptions = reference.options('coverages').map((o) => ({ ...o, description: COVERAGE_TEXT[o.value] }));
  const firstStep = client ? 1 : 0;

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      size="lg"
      title="Registrar contrato"
      description={selectedClient ? `Cliente: ${selectedClient.name}. El código del contrato se genera automáticamente.` : 'El código del contrato se genera automáticamente.'}
      footer={
        <>
          {step > firstStep ? (
            <Button variant="secondary" onClick={() => setStep((s) => s - 1)} disabled={saving}>
              Atrás
            </Button>
          ) : (
            <Button variant="secondary" onClick={onClose} disabled={saving}>
              Cancelar
            </Button>
          )}
          {step < 3 ? (
            <Button onClick={next}>Siguiente</Button>
          ) : (
            <Button onClick={submit} loading={saving} icon="check">
              Registrar contrato
            </Button>
          )}
        </>
      }
    >
      <div className={formStyles.form}>
        <ol className={styles.steps} aria-label="Pasos">
          {STEPS.map((label, i) => (
            <li key={label} className={cx(styles.step, i === step && styles.stepCurrent, i < step && styles.stepDone)} aria-current={i === step ? 'step' : undefined}>
              {label}
            </li>
          ))}
        </ol>

        {formError && (
          <p role="alert" className={formStyles.formError}>
            {formError}
          </p>
        )}

        {step === 0 && (
          <Select
            label="Cliente"
            value={values.client_id}
            onChange={(e) => set('client_id', e.target.value)}
            options={[{ value: '', label: clients ? 'Elige el cliente' : 'Cargando clientes…' }, ...(clients ?? []).map((c) => ({ value: String(c.id), label: `${c.name} (${c.code})` }))]}
            error={errors.client_id}
            hint="Solo aparecen los clientes activos."
          />
        )}

        {step === 1 && (
          <>
            <TextField
              label="Nombre del contrato"
              value={values.name}
              onChange={(e) => set('name', e.target.value)}
              error={errors.name}
              maxLength={150}
              placeholder="Por ejemplo: Soporte de infraestructura 2026"
              autoFocus
              required
            />
            <div className={formStyles.fieldset}>
              <span className={formStyles.legend}>Línea de servicio</span>
              <OptionCards legend="Línea de servicio" name="service_line" value={values.service_line} onChange={(v) => set('service_line', v)} options={lineOptions} error={errors.service_line} />
            </div>
            <div className={formStyles.fieldset}>
              <span className={formStyles.legend}>Forma de cobro</span>
              <OptionCards legend="Forma de cobro" name="billing_model" value={values.billing_model} onChange={(v) => set('billing_model', v)} options={billingOptions} error={errors.billing_model} />
            </div>
            <div className={formStyles.fieldset}>
              <span className={formStyles.legend}>Cobertura</span>
              <OptionCards legend="Cobertura" name="coverage" value={values.coverage} onChange={(v) => set('coverage', v)} options={coverageOptions} error={errors.coverage} columns={3} />
            </div>
          </>
        )}

        {step === 2 && (
          <div className={styles.grid}>
            <TextField label="Fecha de inicio" type="date" value={values.start_date} onChange={(e) => set('start_date', e.target.value)} error={errors.start_date} required />
            <TextField
              label="Fecha de fin"
              type="date"
              value={values.end_date}
              onChange={(e) => set('end_date', e.target.value)}
              error={errors.end_date}
              hint={isBag ? 'Opcional.' : 'Opcional. Vacía: se renueva mes a mes.'}
            />
            {isBag ? (
              <>
                <TextField
                  label="Horas de la bolsa"
                  type="number"
                  min="0.25"
                  step="0.25"
                  value={values.initial_hours}
                  onChange={(e) => set('initial_hours', e.target.value)}
                  error={errors.initial_minutes}
                  required
                />
                <TextField
                  label="Valor de la bolsa (COP)"
                  type="number"
                  min="0"
                  step="1000"
                  value={values.initial_amount}
                  onChange={(e) => set('initial_amount', e.target.value)}
                  error={errors.initial_amount}
                  hint={hourlyRate !== null ? `${formatCurrency(Number(values.initial_amount))} · ${formatCurrency(Math.round(hourlyRate))} por hora` : undefined}
                  required
                />
              </>
            ) : (
              <>
                <TextField
                  label="Valor mensual (COP)"
                  type="number"
                  min="0"
                  step="1000"
                  value={values.monthly_fee}
                  onChange={(e) => set('monthly_fee', e.target.value)}
                  error={errors.monthly_fee}
                  hint={values.monthly_fee !== '' ? formatCurrency(Number(values.monthly_fee)) : undefined}
                  required
                />
                <TextField
                  label="Horas incluidas al mes"
                  type="number"
                  min="0"
                  step="0.25"
                  value={values.availability_only ? '' : values.included_hours}
                  onChange={(e) => set('included_hours', e.target.value)}
                  error={errors.included_minutes_per_month}
                  disabled={values.availability_only}
                />
                <Checkbox
                  className={styles.full}
                  checked={values.availability_only}
                  onChange={(e) => set('availability_only', e.target.checked)}
                  label="Se cobra solo por disponibilidad"
                  description="Sin horas incluidas. Las horas igual se registran y se miden como costo interno."
                />
              </>
            )}
            <TextArea className={styles.full} label="Notas" value={values.notes} onChange={(e) => set('notes', e.target.value)} maxLength={1000} rows={3} hint="Opcional. Por ejemplo, el número de la propuesta aprobada." />
          </div>
        )}

        {step === 3 && (
          <>
            <dl className={styles.review}>
              <div>
                <dt>Cliente</dt>
                <dd>{selectedClient?.name}</dd>
              </div>
              <div>
                <dt>Contrato</dt>
                <dd>{values.name}</dd>
              </div>
              <div>
                <dt>Línea de servicio</dt>
                <dd>{reference.label('service_lines', values.service_line)}</dd>
              </div>
              <div>
                <dt>Forma de cobro</dt>
                <dd>{reference.label('billing_models', values.billing_model)}</dd>
              </div>
              <div>
                <dt>Cobertura</dt>
                <dd>{reference.label('coverages', values.coverage)}</dd>
              </div>
              <div>
                <dt>Vigencia</dt>
                <dd>
                  {formatCalendarDate(values.start_date)} – {values.end_date ? formatCalendarDate(values.end_date) : isBag ? 'sin fecha de fin' : 'se renueva mes a mes'}
                </dd>
              </div>
              {isBag ? (
                <div>
                  <dt>Bolsa inicial</dt>
                  <dd>
                    {values.initial_hours} h por {formatCurrency(Number(values.initial_amount))}
                  </dd>
                </div>
              ) : (
                <div>
                  <dt>Mensualidad</dt>
                  <dd>
                    {formatCurrency(Number(values.monthly_fee))} · {values.availability_only ? 'solo disponibilidad' : `${values.included_hours} h incluidas`}
                  </dd>
                </div>
              )}
            </dl>
            <p className={styles.note}>
              El contrato queda en <strong>borrador</strong>. Actívalo desde su detalle cuando el cliente lo firme; en una mensualidad, al activarlo se abre el mes en curso.
            </p>
          </>
        )}
      </div>
    </Modal>
  );
}
