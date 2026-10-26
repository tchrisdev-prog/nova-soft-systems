# Módulo de Proyectos y Capacidad

Responde a los problemas del caso Nexus Safe-Cloud:
- falta de visibilidad de proyectos, contratos y rentabilidad;
- promesas comerciales sin saber la capacidad;
- datos duplicados entre sedes;
- errores de facturación;
- controles de acceso inconsistentes.

**Estado:**
- **Clientes y contratos:** disponible, con pantallas.
- **Proyectos y capacidad** y **Registro de horas:** tienen la API lista y protegida, pero todavía aparecen como *En desarrollo* porque les faltan las pantallas.

## 1. Parámetros de negocio

| Parámetro | Valor | Dónde se cambia |
| --- | --- | --- |
| Moneda | Pesos colombianos (COP), montos enteros | — |
| Zona horaria del negocio | `America/Bogota` | `NOVA_TIMEZONE` |
| Capacidad semanal por defecto | 42 horas (jornada máxima legal en Colombia desde el 15 de julio de 2026) | `NOVA_DEFAULT_WEEKLY_HOURS` y por persona en su perfil |
| Bloques de registro | 15 minutos | `config/nova.php` → `timesheets.step_minutes` |
| Registro hacia atrás | 31 días | `NOVA_TIMESHEET_BACKDATE_DAYS` |
| Umbral de riesgo | 90 % de las horas consumidas | `config/nova.php` → `projects.risk_threshold` |

- **Líneas de servicio:** software a la medida, soporte de infraestructura, servicios de nube y consultoría de ciberseguridad.
- **Formas de cobro:** bolsa de horas y mensualidad recurrente.
- **Coberturas:** horario hábil (8×5), extendido y 24/7.

Las horas se guardan en **minutos** y los montos en **pesos**, ambos como enteros, para evitar errores de redondeo. La interfaz convierte a horas al mostrar.

## 2. Modelo de datos

```
sites ── staff_profiles ── users ──┬── allocations ── projects ── contracts ── clients
                                   └── time_entries ──┘              ├── contract_hour_movements
                                                                     ├── contract_periods
                                                                     └── contract_revisions
```

| Tabla | Para qué sirve | Notas |
| --- | --- | --- |
| `sites` | Medellín, Bogotá, Remoto | Se crean con `nova:setup-projects`. |
| `staff_profiles` | Sede, cargo, capacidad semanal y costo por hora de cada persona | Separado de `users`: acceso por un lado, operación por el otro. El costo es dato sensible. |
| `clients` | Clientes con código automático (`CLI-001`), NIT opcional con dígito de verificación, sector y sede responsable | No se borra un cliente con contratos; se desactiva. |
| `client_contacts` | Varios contactos por cliente: comercial, técnico, facturación, directivo u otro; uno es el principal | El primero es principal automáticamente; si se borra el principal, el más antiguo toma su lugar. |
| `document_sequences` | Consecutivos de los códigos (`CLI-001`, `CT-2026-001`) | Se bloquea la fila de la serie: dos personas nunca reciben el mismo código. |
| `contracts` | Línea de servicio, forma de cobro, cobertura, estado, fechas, valor mensual y horas incluidas | El cliente, el código y la forma de cobro no se cambian después de crearlo. |
| `contract_hour_movements` | Extracto de la bolsa: inicial, recargas y ajustes | **Inmutable.** El saldo se calcula, no se guarda. |
| `contract_periods` | Cada mes de una mensualidad con el valor y las horas pactadas ese mes | Un cambio de condiciones aplica desde el mes siguiente. |
| `contract_revisions` | Historial de cada contrato: qué cambió (antes y después), quién, cuándo, por qué e IP | **Inmutable**, escrito en la misma transacción que el cambio. |
| `projects` | Proyecto o servicio continuo de un contrato, con líder, sede, fechas y presupuesto de horas | El riesgo se calcula; no es un estado manual. |
| `allocations` | Consultor asignado a un proyecto con un % de su capacidad y fechas | Avisa la sobreasignación y exige confirmarla. |
| `time_entries` | Horas por persona, proyecto y día, con marcas de facturable, fuera de horario y disponibilidad | Guarda el costo de la hora al aprobarse. |

**Índices pensados para las consultas frecuentes:**
- `time_entries (project_id, status, work_date)`: consumo de contratos.
- `time_entries (user_id, work_date)`: registro diario y capacidad.
- `allocations (user_id, start_date)`: sobreasignación.

## 3. Permisos y plantillas de roles

Las policies revisan **permisos**, nunca nombres de roles: renombrar un rol no cambia lo que puede hacer. Además de las acciones estándar, cada módulo declara acciones propias. La matriz de permisos del panel las muestra en la columna *Acciones del módulo*.

| Permiso | Gerencia | Subgerencia | Comercial | Líder de proyecto | Consultor |
| --- | :-: | :-: | :-: | :-: | :-: |
| `clients.view` | ✓ | ✓ | ✓ | ✓ | |
| `clients.create` / `clients.update` | ✓ | ✓ | ✓ | | |
| `clients.delete` | ✓ | | | | |
| `clients.manage` (estado de contratos, recargas) | ✓ | ✓ | ✓ | | |
| `projects.view` (todos los proyectos) | ✓ | ✓ | ✓ | | |
| `projects.create` / `projects.update` / `projects.manage` | ✓ | ✓ | | | |
| `projects.delete` | ✓ | | | | |
| `projects.lead` (los proyectos que lidera) | ✓ | ✓ | | ✓ | |
| `projects.capacity` (disponibilidad) | ✓ | ✓ | ✓ | ✓ | |
| `projects.staff` (perfiles de consultores) | ✓ | ✓ | | | |
| `projects.financials` (costos internos, tarifas, márgenes) | ✓ | ✓ | | | |
| `timesheets.create` (sus propias horas) | ✓ | ✓ | | ✓ | ✓ |
| `timesheets.view` (todas las horas) | ✓ | ✓ | | | |
| `timesheets.approve` (proyectos que lidera) | ✓ | ✓ | | ✓ | |
| `timesheets.approve_any` (cualquier proyecto) | ✓ | ✓ | | | |

Hay dos reglas que no dependen del permiso sino del dato:
- **Liderazgo:** con `projects.lead` y `timesheets.approve`, una persona solo ve y aprueba lo de los proyectos que lidera.
- **Propiedad:** cada quien registra, edita y envía solo sus propias horas. **Nadie aprueba sus propias horas**, ni siquiera Gerencia. Esta regla se valida en la Policy y otra vez en el servicio.

## 4. Reglas de negocio

**Contratos**
- Nacen en borrador.
- Flujo de estados:
  - borrador → activo o cancelado;
  - activo ↔ suspendido;
  - activo o suspendido → finalizado o cancelado.
- Un contrato finalizado o cancelado no se reabre; si el cliente continúa, se firma uno nuevo.
- Suspender o cancelar exige un motivo, que queda en el historial.
- Una bolsa de horas exige horas iniciales y su valor. Una mensualidad exige el valor mensual; las horas incluidas pueden quedar vacías cuando el servicio se cobra solo por disponibilidad (las horas igual se registran y se miden como costo).
- Recargas y ajustes son movimientos nuevos, nunca ediciones:
  - una recarga suma horas;
  - un ajuste puede restar, exige motivo y no puede dejar la bolsa en negativo.
- Al activarse una mensualidad se abre el mes en curso. Después, `nova:contracts-renew` (programado a diario a las 00:15 de Bogotá) abre cada mes nuevo sin duplicar.
- Solo se eliminan contratos en borrador y sin proyectos.

**Horas**
- Se registran 24/7, cualquier día, en bloques de 15 minutos.
- Los registros pueden marcarse como *fuera de horario* y *disponibilidad*. Los recargos de ley quedan para un futuro módulo de nómina.
- Solo se registran horas en:
  - proyectos *En curso* con contrato activo;
  - fechas que no sean futuras ni de hace más de 31 días;
  - proyectos donde la persona está asignada en esa fecha o es la líder.
- Un día no puede pasar de 24 horas por persona. La fila del usuario se bloquea para que dos registros simultáneos no superen el límite.
- Ciclo de vida: borrador → enviado → aprobado o rechazado.
  - Rechazar exige motivo.
  - Corregir un rechazado lo devuelve a borrador.
  - Lo aprobado ya no se modifica ni se borra.
- Aprobar en lote es **todo o nada**.
- Al aprobar se guarda el costo de la hora del consultor ese día (`cost_rate`): un aumento futuro no cambia la rentabilidad de lo ya aprobado.

**Proyectos**
- El código lo genera el sistema: `PRY-AAAA-NNN`, con el año de inicio (el consecutivo vuelve a 1 cada año).
- Se crean sobre contratos en borrador o activos, nunca antes del inicio del contrato ni después de su fin. Nacen *Planeados*.
- El líder debe ser una cuenta activa con `projects.lead`; se revisa en el servidor.
- Estados: planeado → en curso o cerrado; en curso → pausado o cerrado; pausado → en curso o cerrado. Un proyecto cerrado no se reabre.
  - Iniciar exige que el contrato esté activo.
  - Pausar exige un motivo.
  - **Cerrar se bloquea (`409 pending_time_entries`) mientras haya horas enviadas sin aprobar o en borrador**: primero se aprueban o se rechazan. El mensaje dice cuántas son.
  - Al cerrar, el equipo queda libre: las asignaciones vigentes terminan hoy y las que aún no empezaban se borran.
- El contrato del proyecto no se cambia. El líder cambia el estado de sus proyectos y gestiona su equipo, pero no edita el presupuesto ni se cambia de líder (eso es de `projects.update` / `projects.manage`).
- Solo se elimina un proyecto sin horas registradas; si ya hubo trabajo, se cierra.

**Salud del proyecto** (calculada al momento, nunca guardada)
- Se mide contra el presupuesto del proyecto o, si no tiene, contra la bolsa del contrato (que comparten todos sus proyectos). Una mensualidad sin presupuesto no tiene riesgo que medir.
- Lo consumido es lo aprobado más lo enviado sin aprobar.
- Señales: *Cerca del límite de horas* (desde 90 %), *Presupuesto superado*, *Pasó la fecha de fin*, *Sin equipo asignado* y *Las horas se acaban antes del cierre* (al ritmo de las últimas 4 semanas).

**Asignaciones**
- Si una asignación deja a la persona por encima del 100 % en algún día del rango, la API responde `409 overallocation`.
- Para continuar hay que confirmarla (`confirm_overallocation: true`). La auditoría guarda el pico alcanzado.
- Los proyectos cerrados no cuentan para la carga.
- Al editar, la confirmación solo se pide si el cambio sube la carga: bajar el % o acortar la fecha de alguien sobreasignado siempre se permite.
- La persona y la fecha de inicio no se cambian: se termina la asignación y se crea otra, para que el historial diga la verdad.
- *Terminar* conserva las horas; *Eliminar* solo corrige un error y se bloquea si la persona ya registró horas en el proyecto.

**Capacidad**
- Semanas de lunes a domingo desde la semana en curso; horizonte de 4, 8 (por defecto) o 12 semanas.
- Capacidad semanal = jornada del perfil (42 h por defecto) × días hábiles / 5. Los **festivos de Colombia** no son días hábiles: se calculan (fijos, Ley Emiliani y los que dependen de la Semana Santa) y se verificaron contra el calendario oficial de 2026.
- Lo asignado se cuenta día hábil por día hábil: una asignación que empieza un miércoles solo ocupa desde el miércoles.
- Colores: libre por debajo del 70 %, ocupado del 70 % al 100 % y sobreasignado por encima del 100 %.
- También se muestra lo registrado esa semana (sin rechazos) para comparar lo planeado con lo real.
- La búsqueda de disponibilidad devuelve a quien tiene el % pedido libre en **todo** el rango, medido con su día más cargado.
- Solo aparecen personas activas con perfil laboral. Quien entra por primera vez con Microsoft 365 no tiene perfil hasta que se le cree en *Perfiles*: el sistema no adivina su sede ni su jornada.
- La capacidad nunca incluye costos. El costo por hora solo lo ve y lo cambia quien tiene `projects.financials`; a los demás se les rechaza el campo y la auditoría registra que cambió, sin guardar el valor.

**Clientes y contactos**
- El código del cliente lo genera el sistema y no se cambia.
- El NIT es opcional, pero si se escribe debe traer el dígito de verificación correcto (método de la DIAN). Los puntos y espacios se limpian, y no se repite entre clientes.
- Los contactos se envían junto con el cliente al registrarlo y después se gestionan uno por uno. Agregar o editar contactos exige `clients.update`; el Líder de proyecto los consulta, pero no los cambia.

**Alertas** (calculadas al momento, nunca guardadas)
- Bolsa por agotarse: desde el 80 % de consumo (`NOVA_BAG_ALERT_THRESHOLD`).
- Bolsa excedida.
- Mensualidad que superó sus horas incluidas este mes.
- Contrato activo que vence en 30 días o menos (`NOVA_CONTRACT_EXPIRING_DAYS`), o cuya fecha de fin ya pasó.

Las listas calculan consumo y alertas de toda la página con pocas consultas agrupadas, no con una consulta por contrato.

**Exportación a Excel** (`GET /contracts/export`)
- Archivo CSV separado por punto y coma, con BOM UTF-8, para que Excel en español lo abra directamente.
- Usa los mismos filtros que la lista, con un máximo de 5.000 filas, y se escribe por bloques.
- Las celdas que empiezan con `= + - @` se exportan como texto, para que nadie pueda colar una fórmula ("inyección CSV").
- Ingreso, costo y margen solo salen con `projects.financials`.
- Cada exportación queda en la auditoría.

**Fechas**
- Las fechas de calendario se guardan como `AAAA-MM-DD` (cast `CalendarDate`).
- Antes, en SQLite, Laravel las guardaba con hora y las comparaciones dejaban por fuera el último día del mes. En PostgreSQL no pasaba.

**Consumo**
- Solo cuentan las horas aprobadas; las enviadas se muestran como pendientes.
- Bolsa de horas: compradas, aprobadas, pendientes, saldo, % de consumo y si está excedida.
- Mensualidad: cada mes por separado, con horas incluidas, aprobadas, excedente y costo.
- El costo y el margen solo se incluyen en la respuesta con `projects.financials`. Para los demás, esos campos no existen en el JSON.

## 5. API (base `/api/v1`)

Todas las rutas requieren sesión, cuenta activa y CSRF en las escrituras, igual que el resto de la API.

| Método | Ruta | Autorización | Descripción |
| --- | --- | --- | --- |
| GET | `/reference-data` | sesión | Listas para formularios: líneas, cobros, coberturas, estados, sedes. |
| GET, POST | `/clients` | `clients.view` / `clients.create` | Listar (`search`, `sector`, `site_id`, `status`) y registrar. |
| GET, PUT, DELETE | `/clients/{id}` | `clients.view` / `update` / `delete` | Detalle con contactos y resumen (mensualidades, bolsas, alertas), edición y borrado sin contratos. |
| GET, POST | `/clients/{id}/contacts` | `clients.view` / `clients.update` | Contactos del cliente; agregar uno. |
| PUT, DELETE | `/clients/{id}/contacts/{contacto}` | `clients.update` | Editar (incluye `is_primary`) o quitar. Un contacto solo se encuentra dentro de su cliente. |
| GET | `/contracts/export` | `clients.view` | CSV para Excel con los filtros de la lista. |
| GET, POST | `/contracts` | `clients.view` / `clients.create` | Listar con consumo y alertas (`client_id`, `service_line`, `billing_model`, `coverage`, `status`) y registrar (el código se genera solo). |
| GET, PUT, DELETE | `/contracts/{id}` | `clients.view` / `update` / `delete` | Detalle, edición con `note` para el historial y borrado de borradores. |
| PATCH | `/contracts/{id}/status` | `clients.manage` | Cambio de estado (`status`, `note`). |
| GET, POST | `/contracts/{id}/hour-movements` | `clients.view` / `clients.manage` | Extracto de la bolsa; recarga o ajuste. |
| GET | `/contracts/{id}/history` | `clients.view` | Historial de cambios, del más reciente al más antiguo. |
| GET | `/contracts/{id}/consumption` | `clients.view` (+ `projects.financials` para costos) | Consumo de la bolsa o de los últimos `months` meses. |
| GET | `/projects` | `projects.view` (todos) o `projects.lead` (los suyos) | Listar con salud (`search`, `status`, `open`, `kind`, `contract_id`, `client_id`, `site_id`, `leader_id`, `mine`). |
| POST | `/projects` | `projects.create` | Crear (código automático, nace planeado). |
| GET | `/projects/leaders` | `projects.create`, `update` o `manage` | Personas que pueden liderar. |
| GET, PUT, DELETE | `/projects/{id}` | ver / `projects.update` / `projects.delete` | Detalle con salud, estados siguientes, permisos y costos (con `projects.financials`); edición; borrado sin horas. |
| PATCH | `/projects/{id}/status` | `projects.update` o líder del proyecto | Cambio de estado (`status`, `note`). |
| PATCH | `/projects/{id}/leader` | `projects.update` o `projects.manage` | Cambiar líder. |
| GET | `/projects/{id}/hours` | ver proyecto | Horas por persona y semana (`weeks` = 4, 8 o 12). |
| GET, POST | `/projects/{id}/allocations` | ver proyecto / `projects.manage` o líder | Equipo del proyecto; asignar consultor. |
| PATCH, DELETE | `/projects/{id}/allocations/{asignación}` | `projects.manage` o líder | Editar (%, fin, rol) o eliminar sin horas. La asignación solo se encuentra dentro de su proyecto. |
| POST | `/projects/{id}/allocations/{asignación}/end` | igual | Terminar (hoy o `end_date`). |
| GET | `/capacity` | `projects.capacity` | Carga semanal (`weeks`, `site_id`, `specialty`, `search`) con festivos y resumen por especialidad y sede. |
| GET | `/capacity/available` | `projects.capacity` | Disponibilidad (`percent`, `from`, `to`, `site_id`, `specialty`); máximo un año. |
| GET | `/staff` | `projects.staff` | Personas activas con su perfil (`search`, `site_id`, `specialty`, `missing`). |
| PUT | `/staff/{usuario}` | `projects.staff` (+ `projects.financials` para `hourly_cost`) | Crear o editar el perfil (`site_id`, `position`, `specialty`, `weekly_hours`). |
| GET | `/time-entries` | según `scope` | `scope=mine` (propias), `review` (las que puede aprobar), `all` (todas). Filtros: `project_id`, `user_id`, `status`, `from`, `to`, `is_after_hours`, `is_on_call`, `is_billable`. |
| GET | `/time-entries/summary` | igual | Totales del mismo conjunto: por estado, facturables, fuera de horario, disponibilidad y costo (con permiso). |
| GET | `/time-entries/projects` | `timesheets.create` | Proyectos donde la persona puede registrar hoy. |
| POST | `/time-entries` | `timesheets.create` | Registrar en borrador. |
| PUT, DELETE | `/time-entries/{id}` | dueño, en borrador o rechazado | Corregir o borrar. |
| POST | `/time-entries/submit` | dueño | Enviar un lote (`ids`). |
| POST | `/time-entries/approve` | líder o `approve_any`, nunca las propias | Aprobar un lote (`ids`), todo o nada. |
| POST | `/time-entries/reject` | igual | Rechazar un lote (`ids`, `reason`). |

**Códigos de error nuevos:**
- Contratos y clientes: `invalid_transition`, `contract_closed`, `contract_in_use`, `client_in_use`, `inactive_client`, `not_an_hour_bag`, `negative_hour_bag`.
- Horas: `project_not_active`, `contract_not_active`, `not_allocated`, `future_date`, `date_too_old`, `daily_limit_exceeded`, `not_submittable`, `not_submitted`, `self_review`.
- Proyectos: `contract_not_open`, `contract_not_active`, `outside_contract`, `invalid_leader`, `invalid_site`, `pending_time_entries`, `project_closed`, `project_has_time_entries`.
- Asignaciones: `overallocation`, `inactive_user`, `allocation_not_started`, `allocation_has_time`.

## 6. Comandos

| Comando | Qué hace |
| --- | --- |
| `php artisan nova:sync-permissions --prune` | Sincroniza el catálogo y retira los módulos que ya no existen (por ejemplo, los de la versión anterior). |
| `php artisan nova:setup-projects` | Crea las sedes y las plantillas de roles. No pisa los ajustes posteriores; `--reset-permissions` los restablece. |
| `php artisan nova:contracts-renew` | Abre el mes en curso de las mensualidades activas. Está programado a diario. |
| `php artisan nova:demo` | Datos de demostración (ver abajo). |

## 7. Datos de demostración

`php artisan nova:demo` carga una versión ficticia de Nexus Safe-Cloud:
- **Personas:** 47 (37 en Medellín y 10 en Bogotá): Gerencia, Subgerencia, 3 comerciales, 6 líderes y 36 consultores de desarrollo, QA, infraestructura, nube, ciberseguridad y SOC.
- **Clientes y contratos:** 12 clientes y 18 contratos de las cuatro líneas de servicio.
- **Proyectos:** 20, con sus equipos.
- **Horas:** unos 6.000 registros desde el inicio de cada contrato. En festivos nadie registra la jornada normal; los turnos 24/7 sí se cubren.
- **Perfiles:** cada persona con su especialidad, para filtrar la capacidad.

Casos preparados para la presentación:

| Caso | Dónde |
| --- | --- |
| Proyecto en riesgo (más del 90 % de la bolsa) | Portal de asociados (Cooperativa Financiera Altamira) |
| Consultor sobreasignado (120 %) | Asignación confirmada en Control de obras: app móvil |
| Recarga de bolsa con su historial | App de fidelización (Tiendas Guayacán) |
| Cambio de cobertura a 24/7 que aplica desde el mes siguiente | Soporte de infraestructura (Mercados Río Claro) |
| Mensualidad sin horas incluidas | Operación de nube (Cooperativa Altamira) |
| Contrato suspendido con motivo y contrato finalizado | Colegio Los Robles; Auditoría de seguridad (Moda Nativa) |
| Turnos de disponibilidad y horas fuera de horario | Contratos 24/7 |
| Horas enviadas pendientes de aprobar y algunos rechazos con motivo | Última semana |
| Capacidad libre en ciberseguridad | Analistas entre 40 % y 70 % asignados |

**Seguridad de la demo**
- El comando se niega a ejecutarse en producción.
- En ambientes distintos de `local` y `testing` pide confirmación.
- Las cuentas usan `@demo.nexus.test`, sin identidad de Microsoft, así que nadie puede ingresar con ellas.
- Los clientes no tienen NIT, para no coincidir con empresas reales.
- `--reset` y `--remove` borran solo lo marcado con `DEMO-` o con ese dominio.
- Si en un ensayo se creó un contrato real sobre un cliente de demostración (o un proyecto real sobre un contrato de demostración), `--reset` y `--remove` se detienen sin borrar nada y dicen cuáles son. Con `--with-linked` se borran también, y el comando los nombra.

**Generación**
- Es determinista: con la misma fecha se obtiene lo mismo, lo que ayuda a ensayar.
- Los contratos se crean con los mismos servicios de la API, viajando en el tiempo a la fecha de cada evento, para que el historial tenga fechas creíbles.
- Las horas se insertan en bloque, porque la API no permite registrar fechas tan antiguas.

Con `--presenter=correo` una cuenta real ya autorizada queda como líder de dos proyectos con horas pendientes, para aprobarlas en vivo. No se le cambian los roles.

## 8. Pantallas de Clientes y contratos

| Ruta | Qué muestra |
| --- | --- |
| `/app/clients` | Clientes con sector, sede, contacto principal, contratos activos, valor de mensualidades y alertas. |
| `/app/clients/{id}` | Resumen, datos, contactos (agregar, editar, hacer principal, quitar), contratos y botón de contrato nuevo. |
| `/app/contracts` | Contratos con valor, barra de consumo y alertas. Tiene filtros, "solo con alertas" y exportación a Excel. |
| `/app/contracts/{id}` | Indicadores, consumo (barra o gráfica mensual con tabla), movimientos de la bolsa, proyectos, datos e historial en línea de tiempo. Desde aquí se edita, se cambia el estado y se recarga o ajusta la bolsa. |

El contrato nuevo se registra con un asistente de cuatro pasos:
1. Cliente.
2. Servicio.
3. Condiciones, que cambian según la forma de cobro.
4. Revisión.

## 9. Pantallas de Proyectos y capacidad

| Ruta | Permiso | Qué muestra |
| --- | --- | --- |
| `/app/projects` | `projects.view` o `projects.lead` | Proyectos con barra de consumo, señales de salud y tamaño del equipo. Filtros, "Mis proyectos" y "Solo con alertas". |
| `/app/projects/{id}` | igual | Indicadores (presupuesto o bolsa, aprobadas, pendientes, ritmo semanal y fecha proyectada de agotamiento, costo interno con permiso), equipo con asignar, editar, terminar y eliminar, horas por persona y semana (4/8/12) y datos. |
| `/app/capacity` | `projects.capacity` | Rejilla persona × semana con colores de carga y festivos marcados, resumen por especialidad y sede, y búsqueda de disponibilidad con botón *Asignar*. |
| `/app/staff` | `projects.staff` | Perfiles laborales (sede, cargo, especialidad, jornada; costo con `projects.financials`) y quién no tiene perfil. |

Los botones se muestran según los permisos que envía la API (`can`), pero cada operación se vuelve a autorizar en el servidor.

## 10. Siguiente fase

- Pantallas de Registro de horas: mis horas, aprobaciones y tablero de rentabilidad. Al publicarlas, cambiar `timesheets` a `active` en `config/nova.php`.
- Redirect URI de producción registrado en Entra ID (p. ej. `https://erp.tuempresa.com/auth/microsoft/callback`).
- En cada despliegue: `php artisan migrate --force && php artisan nova:sync-permissions && php artisan optimize`.
- Programador de tareas activo (`* * * * * php artisan schedule:run`): abre cada mes de las mensualidades (`nova:contracts-renew`).
- Nunca ejecutes `nova:demo` en producción (el comando se niega a hacerlo).
- Rota el secreto de cliente antes de su vencimiento (Entra ID lo exige).
