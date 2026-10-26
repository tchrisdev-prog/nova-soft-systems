# Imagen web: compila la interfaz React con Vite y la sirve con nginx, que
# además reenvía /api, /auth, /sanctum y /up al backend (PHP-FPM). El
# navegador ve un único origen, así la cookie de sesión funciona sin CORS.

# --- Compilación ----------------------------------------------------------
FROM node:24-alpine AS build
WORKDIR /app

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi

COPY . .
# Vacío: la API vive en el mismo origen que la interfaz.
ENV VITE_API_BASE_URL=""
RUN npm run build

# --- Servidor -------------------------------------------------------------
FROM nginx:1.27-alpine AS web

# Dirección del contenedor de PHP-FPM dentro de la red de Docker. La imagen
# oficial de nginx la reemplaza en la plantilla al arrancar.
ENV PHP_UPSTREAM=app:9000

RUN rm -f /etc/nginx/conf.d/default.conf
COPY docker/nginx.conf /etc/nginx/templates/default.conf.template
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=5 \
  CMD wget -q --spider http://127.0.0.1:8080/healthz || exit 1
