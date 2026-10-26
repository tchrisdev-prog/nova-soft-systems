import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';

// En desarrollo, Vite reenvía al backend Laravel las rutas de la API, del
// flujo OAuth de Microsoft y de Sanctum. Así el navegador ve un único origen
// (http://localhost:5173) y la cookie de sesión funciona sin CORS.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.VITE_BACKEND_URL || 'http://localhost:8000';
  const proxy = { target, changeOrigin: false, xfwd: true };

  return {
    plugins: [react()],
    server: {
      port: 5173,
      strictPort: true,
      proxy: {
        '/api': proxy,
        '/auth': proxy,
        '/sanctum': proxy,
      },
    },
    preview: {
      port: 4173,
      proxy: {
        '/api': proxy,
        '/auth': proxy,
        '/sanctum': proxy,
      },
    },
    build: {
      sourcemap: false,
      target: 'es2022',
    },
  };
});
