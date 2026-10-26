export const APP_NAME = 'Nova Sync Systems';

// Inicio del flujo OAuth 2.0 / OpenID Connect. Es una navegación completa
// (no una llamada fetch): el navegador va a Laravel y de ahí a Microsoft.
export const MICROSOFT_LOGIN_PATH = '/auth/microsoft/redirect';

// Clave para recordar la ruta solicitada antes de ir a Microsoft.
export const NEXT_PATH_KEY = 'nova:next-path';

export const API_BASE = import.meta.env.VITE_API_BASE_URL ?? '';
