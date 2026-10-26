const dateTime = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' });
const dateOnly = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium' });
const relative = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });
const number = new Intl.NumberFormat('es-CO');
const currency = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
const hoursFormat = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 2 });

export function formatDateTime(iso) {
  return iso ? dateTime.format(new Date(iso)) : '—';
}

export function formatDate(iso) {
  return iso ? dateOnly.format(new Date(iso)) : '—';
}

/**
 * Fechas de calendario ("2026-10-31"). Se leen como fecha local: con
 * new Date("2026-10-31") el navegador asume UTC y en Colombia mostraría el 30.
 */
export function formatCalendarDate(value) {
  if (!value) return '—';
  const [y, m, d] = value.slice(0, 10).split('-').map(Number);
  return dateOnly.format(new Date(y, m - 1, d));
}

/** Pesos colombianos sin decimales: $ 32.000.000 */
export function formatCurrency(value) {
  return typeof value === 'number' ? currency.format(value) : '—';
}

/** Minutos a horas: 90 → "1,5 h" */
export function formatHours(minutes, { unit = true } = {}) {
  if (typeof minutes !== 'number') return '—';
  const text = hoursFormat.format(minutes / 60);
  return unit ? `${text} h` : text;
}

/** 0.856 → "86 %" */
export function formatPercent(ratio) {
  return typeof ratio === 'number' ? `${Math.round(ratio * 100)} %` : '—';
}

/** "Hoy" en Colombia como AAAA-MM-DD, para los campos de fecha. */
export function todayInBogota() {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(new Date());
}

export function formatNumber(value) {
  return typeof value === 'number' ? number.format(value) : '—';
}

/** "hace 5 minutos", "ayer"... Para fechas antiguas usa la fecha completa. */
export function formatRelative(iso) {
  if (!iso) return '—';
  const diffSeconds = (new Date(iso).getTime() - Date.now()) / 1000;
  const abs = Math.abs(diffSeconds);
  if (abs < 60) return 'hace un momento';
  if (abs < 3600) return relative.format(Math.round(diffSeconds / 60), 'minute');
  if (abs < 86400) return relative.format(Math.round(diffSeconds / 3600), 'hour');
  if (abs < 86400 * 7) return relative.format(Math.round(diffSeconds / 86400), 'day');
  return formatDate(iso);
}

export function initials(name = '') {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('');
}

export function firstName(name = '') {
  return name.split(/\s+/)[0] ?? '';
}

/** "Ningún usuario", "1 usuario", "5 usuarios" */
export function countUsers(count = 0) {
  if (!count) return 'Ningún usuario';
  return `${formatNumber(count)} ${count === 1 ? 'usuario' : 'usuarios'}`;
}
