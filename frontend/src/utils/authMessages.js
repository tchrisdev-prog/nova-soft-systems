// Mensajes para los códigos que el backend envía en /login?error=...
export const AUTH_ERROR_MESSAGES = {
  not_configured:
    'El inicio de sesión con Microsoft 365 aún no está configurado en el servidor. El administrador debe completar las variables de Microsoft Entra ID.',
  access_denied: 'Cancelaste el inicio de sesión en Microsoft. Puedes intentarlo de nuevo cuando quieras.',
  invalid_state: 'La solicitud de inicio de sesión expiró o no es válida. Vuelve a intentarlo desde esta página.',
  invalid_token: 'No fue posible verificar tu identidad con Microsoft. Vuelve a intentarlo.',
  tenant_mismatch: 'Tu cuenta pertenece a otra organización. Usa tu cuenta corporativa autorizada.',
  not_authorized:
    'Tu cuenta de Microsoft 365 no está autorizada para ingresar. Solicita acceso al administrador del sistema.',
  account_inactive: 'Tu cuenta está desactivada. Contacta al administrador del sistema.',
  account_mismatch:
    'Este correo ya está vinculado a otra identidad de Microsoft. Contacta al administrador del sistema.',
  provider_error: 'Microsoft no respondió como se esperaba. Intenta de nuevo en unos minutos.',
  session_expired: 'Tu sesión expiró. Ingresa nuevamente para continuar.',
};

export function authErrorMessage(code) {
  return code ? AUTH_ERROR_MESSAGES[code] ?? 'No fue posible iniciar sesión. Vuelve a intentarlo.' : null;
}
