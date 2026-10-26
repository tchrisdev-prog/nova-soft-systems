import { lazy, Suspense } from 'react';
import { BrowserRouter, Route, Routes } from 'react-router';
import { FullPageStatus } from '../components/ui/FullPageStatus';
import LandingPage from '../pages/LandingPage';
import NotFoundPage from '../pages/NotFoundPage';
import { ProtectedRoute } from './ProtectedRoute';
import { RequirePermission } from './RequirePermission';
import { ScrollToTop } from './ScrollToTop';

// El panel se carga bajo demanda: la landing no descarga código administrativo.
const LoginPage = lazy(() => import('../pages/LoginPage'));
const AdminLayout = lazy(() => import('../layouts/AdminLayout'));
const DashboardPage = lazy(() => import('../pages/DashboardPage'));
const ModulesPage = lazy(() => import('../pages/ModulesPage'));
const RolesPage = lazy(() => import('../pages/RolesPage'));
const RoleDetailPage = lazy(() => import('../pages/RoleDetailPage'));
const UsersPage = lazy(() => import('../pages/UsersPage'));
const AuditLogPage = lazy(() => import('../pages/AuditLogPage'));
const ClientsPage = lazy(() => import('../pages/ClientsPage'));
const ClientDetailPage = lazy(() => import('../pages/ClientDetailPage'));
const ContractsPage = lazy(() => import('../pages/ContractsPage'));
const ContractDetailPage = lazy(() => import('../pages/ContractDetailPage'));
const ProjectsPage = lazy(() => import('../pages/ProjectsPage'));
const ProjectDetailPage = lazy(() => import('../pages/ProjectDetailPage'));
const CapacityPage = lazy(() => import('../pages/CapacityPage'));
const StaffPage = lazy(() => import('../pages/StaffPage'));

export function AppRouter() {
  return (
    <BrowserRouter>
      <ScrollToTop />
      <Suspense fallback={<FullPageStatus label="Cargando…" />}>
        <Routes>
          <Route path="/" element={<LandingPage />} />
          <Route path="/login" element={<LoginPage />} />

          <Route element={<ProtectedRoute />}>
            <Route path="/app" element={<AdminLayout />}>
              <Route index element={<DashboardPage />} />
              <Route path="modules" element={<ModulesPage />} />
              <Route
                path="roles"
                element={
                  <RequirePermission anyOf={['access_control.view']}>
                    <RolesPage />
                  </RequirePermission>
                }
              />
              <Route
                path="roles/:roleId"
                element={
                  <RequirePermission anyOf={['access_control.view']}>
                    <RoleDetailPage />
                  </RequirePermission>
                }
              />
              <Route
                path="users"
                element={
                  <RequirePermission anyOf={['users.view']}>
                    <UsersPage />
                  </RequirePermission>
                }
              />
              <Route
                path="audit"
                element={
                  <RequirePermission anyOf={['audit.view']}>
                    <AuditLogPage />
                  </RequirePermission>
                }
              />
              {/* Clientes y contratos: la API vuelve a validar cada permiso. */}
              {[
                ['clients', <ClientsPage key="clients" />],
                ['clients/:clientId', <ClientDetailPage key="client" />],
                ['contracts', <ContractsPage key="contracts" />],
                ['contracts/:contractId', <ContractDetailPage key="contract" />],
              ].map(([path, page]) => (
                <Route key={path} path={path} element={<RequirePermission anyOf={['clients.view']}>{page}</RequirePermission>} />
              ))}
              {/* Proyectos y capacidad: cada pantalla con el permiso que la API exige. */}
              {[
                ['projects', <ProjectsPage key="projects" />, ['projects.view', 'projects.lead']],
                ['projects/:projectId', <ProjectDetailPage key="project" />, ['projects.view', 'projects.lead']],
                ['capacity', <CapacityPage key="capacity" />, ['projects.capacity']],
                ['staff', <StaffPage key="staff" />, ['projects.staff']],
              ].map(([path, page, anyOf]) => (
                <Route key={path} path={path} element={<RequirePermission anyOf={anyOf}>{page}</RequirePermission>} />
              ))}
              <Route path="*" element={<NotFoundPage inPanel />} />
            </Route>
          </Route>

          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </Suspense>
    </BrowserRouter>
  );
}
