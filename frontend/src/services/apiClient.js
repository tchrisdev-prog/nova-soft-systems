import { API_BASE } from '../config/app';

/**
 * Cliente HTTP de la API.
 *
 * - Autenticación por cookie de sesión HttpOnly (Laravel Sanctum, modo SPA):
 *   el frontend nunca guarda tokens ni contraseñas.
 * - Protección CSRF: antes de cualquier operación que modifica datos se
 *   obtiene la cookie XSRF-TOKEN y se envía en la cabecera X-XSRF-TOKEN.
 * - Errores normalizados en ApiError { status, code, message, errors }.
 */

export class ApiError extends Error {
  constructor({ status, code, message, errors }) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.errors = errors ?? {};
  }

  /** Primer mensaje por campo: { name: '...' } */
  get fieldErrors() {
    return Object.fromEntries(
      Object.entries(this.errors).map(([field, messages]) => [field, Array.isArray(messages) ? messages[0] : messages]),
    );
  }
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
let unauthorizedHandler = null;

/** Lo registra AuthContext para reaccionar a sesiones expiradas (401). */
export function onUnauthorized(handler) {
  unauthorizedHandler = handler;
}

function readCookie(name) {
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null;
}

async function ensureCsrfCookie(force = false) {
  if (!force && readCookie('XSRF-TOKEN')) return;
  await fetch(`${API_BASE}/sanctum/csrf-cookie`, { credentials: 'include', headers: { Accept: 'application/json' } });
}

function buildUrl(path, query) {
  const url = new URL(`${API_BASE}${path}`, window.location.origin);
  Object.entries(query ?? {}).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value);
  });
  return url.toString();
}

async function parseError(response) {
  let body = {};
  try {
    body = await response.json();
  } catch {
    // Respuesta sin cuerpo JSON (p. ej. un proxy caído).
  }

  const fallback =
    response.status >= 500
      ? 'El servidor no pudo completar la solicitud. Intenta de nuevo en unos minutos.'
      : 'No fue posible completar la solicitud.';

  return new ApiError({
    status: response.status,
    code: body.code ?? `http_${response.status}`,
    message: body.message ?? fallback,
    errors: body.errors,
  });
}

export async function request(method, path, { query, body, signal, retryOnCsrf = true } = {}) {
  const upper = method.toUpperCase();

  if (!SAFE_METHODS.has(upper)) await ensureCsrfCookie();

  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const xsrf = readCookie('XSRF-TOKEN');
  if (xsrf && !SAFE_METHODS.has(upper)) headers['X-XSRF-TOKEN'] = xsrf;

  let response;
  try {
    response = await fetch(buildUrl(path, query), {
      method: upper,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      credentials: 'include',
      signal,
    });
  } catch (error) {
    if (error.name === 'AbortError') throw error;
    throw new ApiError({
      status: 0,
      code: 'network_error',
      message: 'No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo.',
    });
  }

  if (response.status === 419 && retryOnCsrf) {
    await ensureCsrfCookie(true);
    return request(method, path, { query, body, signal, retryOnCsrf: false });
  }

  if (!response.ok) {
    const error = await parseError(response);
    if (error.status === 401 && unauthorizedHandler) unauthorizedHandler(error);
    throw error;
  }

  if (response.status === 204) return null;
  return response.json();
}

/**
 * Descarga un archivo (por ejemplo, la exportación a Excel) con la misma
 * sesión y el mismo manejo de errores que el resto de la API.
 */
export async function downloadFile(path, { query, fallbackName = 'descarga' } = {}) {
  let response;
  try {
    response = await fetch(buildUrl(path, query), {
      headers: { Accept: 'text/csv, application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'include',
    });
  } catch {
    throw new ApiError({ status: 0, code: 'network_error', message: 'No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo.' });
  }

  if (!response.ok) {
    const error = await parseError(response);
    if (error.status === 401 && unauthorizedHandler) unauthorizedHandler(error);
    throw error;
  }

  const disposition = response.headers.get('Content-Disposition') ?? '';
  const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? fallbackName;
  const url = URL.createObjectURL(await response.blob());
  const link = Object.assign(document.createElement('a'), { href: url, download: name });
  document.body.append(link);
  link.click();
  link.remove();
  // Se libera en el siguiente ciclo para que el navegador alcance a iniciar la descarga.
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

export const api = {
  get: (path, options) => request('GET', path, options),
  post: (path, body, options) => request('POST', path, { ...options, body }),
  put: (path, body, options) => request('PUT', path, { ...options, body }),
  patch: (path, body, options) => request('PATCH', path, { ...options, body }),
  delete: (path, options) => request('DELETE', path, options),
};
