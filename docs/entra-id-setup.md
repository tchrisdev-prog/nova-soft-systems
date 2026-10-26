# Configuración de Microsoft Entra ID (Microsoft 365)

Nova Sync Systems autentica a los usuarios con **OpenID Connect** contra Microsoft Entra ID (antes Azure Active Directory), usando el flujo **Authorization Code con PKCE** y un cliente confidencial: el código se canjea en el servidor Laravel y el secreto nunca llega al navegador.

> Los nombres de menús del portal pueden variar con el tiempo. Si una opción no aparece con el nombre indicado, búscala en la documentación oficial: <https://learn.microsoft.com/entra/identity-platform/quickstart-register-app>.

## 1. Registrar la aplicación

1. Ingresa al **Microsoft Entra admin center** (<https://entra.microsoft.com>) con una cuenta que pueda registrar aplicaciones.
2. Ve a **Identity → Applications → App registrations** (o **Entra ID → App registrations**) y elige **New registration**.
3. **Name:** `Nova Sync Systems` (o el nombre que verán los usuarios).
4. **Supported account types:** **Accounts in this organizational directory only** (un solo tenant). La aplicación rechaza tokens de cualquier otro tenant.
5. Deja el **Redirect URI** vacío por ahora y selecciona **Register**.

En **Overview** copia:

- **Application (client) ID** → `MICROSOFT_CLIENT_ID`
- **Directory (tenant) ID** → `MICROSOFT_TENANT_ID`

## 2. Redirect URI

1. En **Manage → Authentication** selecciona **Add a platform → Web** (no "Single-page application": el canje del código lo hace el backend).
2. Agrega los Redirect URI que correspondan, **exactamente** como se usarán:
   - Desarrollo (con el proxy de Vite): `http://localhost:5173/auth/microsoft/callback`
   - Producción: `https://erp.tuempresa.com/auth/microsoft/callback`
3. No habilites "Access tokens" ni "ID tokens" de flujo implícito: no se usan.

El valor elegido va en `MICROSOFT_REDIRECT_URI` de `backend/.env`.

## 3. Secreto de cliente

1. **Manage → Certificates & secrets → Client secrets → New client secret.**
2. Define una descripción y una vigencia (Microsoft recomienda menos de 12 meses).
3. Copia el **Value** de inmediato (no se vuelve a mostrar) → `MICROSOFT_CLIENT_SECRET`.
4. Registra la fecha de vencimiento: al expirar, el inicio de sesión fallará con el código `provider_error` hasta que se configure un secreto nuevo.

Nunca guardes el secreto en el repositorio. En producción usa el gestor de secretos de tu plataforma.

## 4. Permisos y claims

- La aplicación solicita únicamente los scopes **`openid profile email`**. No necesita permisos de Microsoft Graph ni consentimiento de administrador adicional.
- La identidad se toma del **id_token** validado:
  - `oid`: identificador inmutable del usuario (se vincula en el primer ingreso).
  - `tid`: debe coincidir con `MICROSOFT_TENANT_ID`.
  - `email` o, si no existe, `preferred_username` (UPN).
- Recomendado: en **Token configuration → Add optional claim → ID → email** para incluir el correo en el id_token. Si tus usuarios tienen un correo distinto a su UPN, autorízalos en Nova con el valor que llegará en ese claim.

## 5. Variables en `backend/.env`

```dotenv
MICROSOFT_TENANT_ID=00000000-0000-0000-0000-000000000000      # Directory (tenant) ID
MICROSOFT_CLIENT_ID=00000000-0000-0000-0000-000000000000      # Application (client) ID
MICROSOFT_CLIENT_SECRET=                                      # Value del secreto
MICROSOFT_REDIRECT_URI=http://localhost:5173/auth/microsoft/callback

FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173
NOVA_ALLOWED_EMAIL_DOMAINS=tuempresa.com
NOVA_AUTO_PROVISION_USERS=false
```

(Los ceros son marcadores de formato, no valores válidos.)

Después de editar `.env`, si usaste `php artisan config:cache`, vuelve a ejecutarlo. Comprueba el estado con:

```bash
curl http://localhost:8000/api/v1/auth/config
# {"data":{"provider":"microsoft-entra-id","configured":true, ...}}
```

## 6. Qué valida el backend en cada inicio de sesión

1. `state` (protección CSRF del flujo) y `code_verifier` (PKCE) guardados en la sesión.
2. Canje del código en `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` con el secreto de cliente.
3. Firma RS256 del `id_token` con las claves públicas del tenant (JWKS, en caché y recargadas ante rotación).
4. `iss`, `aud`, `tid`, `exp`/`nbf` (con tolerancia de reloj configurable) y `nonce`.
5. Que la cuenta esté **autorizada** en Nova (pre-registrada por un administrador o por `nova:super-admin`), **activa**, del dominio permitido y no vinculada a otra identidad (`oid`).

Los tokens de Microsoft no se almacenan: solo se usan para confirmar la identidad. La sesión de Nova es una cookie HttpOnly emitida por Laravel.

## 7. Códigos de error que verá el usuario

| Código | Causa |
| --- | --- |
| `not_configured` | Faltan o son inválidas las variables `MICROSOFT_*`. |
| `access_denied` | El usuario canceló en la página de Microsoft. |
| `invalid_state` | La solicitud expiró o se abrió en otra sesión del navegador. |
| `invalid_token` | El id_token no superó la validación (firma, emisor, audiencia, nonce, vigencia). |
| `tenant_mismatch` | La cuenta pertenece a otro tenant. |
| `not_authorized` | La cuenta no fue autorizada en Nova o su dominio no está permitido. |
| `account_inactive` | La cuenta está desactivada en Nova. |
| `account_mismatch` | El correo ya está vinculado a otra identidad de Microsoft. |
| `provider_error` | Error de comunicación con Microsoft o secreto vencido. Revisa `storage/logs`. |

## 8. Checklist de prueba con un tenant real

- [ ] `GET /api/v1/auth/config` responde `configured: true`.
- [ ] `php artisan nova:super-admin <tu-correo>` ejecutado.
- [ ] El botón "Ingresar con Microsoft 365" abre `login.microsoftonline.com/<tenant>/...`.
- [ ] Tras autenticarte vuelves a `/app` y ves tu nombre y el distintivo Super Admin.
- [ ] Una cuenta del tenant no autorizada vuelve a `/login?error=not_authorized`.
- [ ] Una cuenta desactivada desde **Usuarios** pierde el acceso inmediatamente.
