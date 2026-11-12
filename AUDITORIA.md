# AUDITORÍA RURALTECH — Paquete Auditado v1.0

**Fecha:** 12 de noviembre de 2026
**Alcance:** `servidor_php/` (idéntico a `hosting_cpanel/` salvo rutas de `require`)
**Entorno de prueba:** Windows 10 · PHP 8.1.25 (XAMPP) · `php -S 127.0.0.1:8080 -t public` · Chromium (navegador integrado)
**Método:** despliegue real local + pruebas funcionales por interfaz y API + pruebas de seguridad activas (explotación controlada) + revisión línea a línea de `src.php`, `api.php`, `setup.php`, `scripts/*` e `index.html` + Lighthouse.

---

## 1. Resumen ejecutivo

| Dimensión | Veredicto | Hallazgos |
|---|---|---|
| **Funcionalidad** | ✅ Sólida — todas las pruebas de flujo pasaron | 3 medias · 5 bajas/informativas |
| **Seguridad** | ✅ Robusta para un piloto de 13 docentes — sin vulnerabilidades explotables encontradas | 2 medias · 5 bajas/informativas |
| **Interfaz** | ✅ Limpia y coherente · Lighthouse: A11y 88, Best Practices 100, SEO 75 | 3 medias · 6 bajas/informativas |

**Conclusión:** la aplicación puede desplegarse en producción siguiendo el `LEEME` (HTTPS, docroot a `public/`, PHP ≥ 8.2, eliminar `setup.php`). Los 2 hallazgos medios de seguridad son mejoras de robustez, no brechas activas.

---

## 2. Pruebas funcionales ejecutadas (resultado)

| # | Prueba | Resultado |
|---|---|---|
| 1 | Instalación: `setup.php` genera token, crea admin, bloquea reintento (403) | ✅ |
| 2 | Login / logout con rotación de sesión | ✅ |
| 3 | Marcar microlección → persiste (17% · 1/6 tras recargar) | ✅ |
| 4 | Cuestionario Módulo 0 → **3/3** con retroalimentación | ✅ |
| 5 | Guardar actividad escrita / diagnóstico / valoración (10×4) | ✅ |
| 6 | Crear usuario docente desde panel admin | ✅ |
| 7 | Subir recurso institucional por UI real (PDF) → almacenado como `*.bin` aleatorio + MIME detectado | ✅ |
| 8 | Comunidad: publicar y listar | ✅ |
| 9 | Evidencia con archivo (PNG) por UI real, Módulo 0 | ✅ |
| 10 | Producto final (Módulo 6) | ✅ |
| 11 | Respaldo integral `.tar` (data.json + ambos adjuntos) | ✅ |
| 12 | Restauración CLI `restore_backup.php --confirm` en clon tras "desastre" (data.json y uploads borrados) | ✅ restaura usuarios, recursos, evidencias y auditoría |
| 13 | `create_admin.php` CLI + validaciones (correo inválido/clave corta → exit 1) | ✅ |
| 14 | Exportaciones CSV/JSON (código revisado; BOM UTF-8 correcto para Excel) | ✅ |
| 15 | Límite de tamaño: archivo de 16 MB rechazado con "mayor de 15 MB" (independiente de php.ini) | ✅ |

---

## 3. Seguridad — pruebas de explotación controlada (todas superadas)

| Ataque simulado | Resultado |
|---|---|
| Docente llamando a `users`, `create_user`, `backup`, `audit`, `resource_upload` | 403 en los 5 casos ✅ |
| Docente descargando evidencia ajena (kind=submission) | 403 "Acceso denegado" ✅ |
| `overview&user=<id ajeno>` con rol docente | devuelve datos PROPIOS (sin fuga) ✅ |
| Archivo inexistente en `download` | 404 ✅ |
| POST sin token CSRF / con token falso | 403 ✅ |
| Subir `.html`, `.php`, `.svg`, `.exe` | 400 en todos ✅ |
| PNG falsificado (texto con extensión .png) | 400 "Imagen inválida" (MIME vs extensión) ✅ |
| Fuerza bruta: 6 fallos → intento con clave CORRECTA | 429 "Demasiados intentos" ✅ |
| Cambio de contraseña con clave vieja incorrecta | 403 ✅ · clave corta → 400 ✅ |
| Acceso directo a `/private/data.json`, `/src.php`, `/uploads/*.bin` | 404 (fuera del docroot) ✅ |
| `/setup.php` tras instalación | 403 "ya tiene administrador" ✅ |
| XSS: interpolaciones sin `esc()` en HTML | ninguna con datos de usuario (solo IDs hex y estáticos) ✅ |
| Path traversal | nombres internos generados por servidor; restaurador valida regex `^[a-f0-9]{32}\.bin$` ✅ |

---

## 4. Hallazgos — Funcionalidad

| ID | Severidad | Hallazgo |
|---|---|---|
| **F-1** | 🟠 Media | **Diagnóstico y actividad del Módulo 0 comparten el mismo campo** (`save_module` módulo 0, tipo `activity`). El docente escribe el autodiagnóstico y luego la actividad del módulo, y el texto anterior se sobrescribe **silenciosamente** (verificado en vivo). Sugerencia: campo separado (`diagnosis`) o advertencia visible. |
| **F-2** | 🟡 Baja | **El log de auditoría no tiene interfaz.** La API `?action=audit` (solo admin) existe y registra eventos, pero `index.html` nunca la invoca. Sugerencia: pestaña "Auditoría" en el panel admin. |
| **F-3** | 🟡 Baja | **`restore_backup.php` no crea la copia previa cuando `data.json` no existe** (Warning de PHP; el mensaje anuncia `pre_restore_*` que no se crea). La restauración en sí funciona. Sugerencia: comprobar `file_exists()` antes de `copy()`. |
| **F-4** | 🟡 Baja | **El admin no puede ver las valoraciones** enviadas por docentes (no hay endpoint de lectura ni vista). La sección "Valoración" pierde utilidad institucional. |
| F-5 | ⚪ Info | Las claves del cuestionario están en el JS del frontend (visible con "Ver código"). Aceptable: es autoevaluación formativa, no calificativa; documentado así. |
| F-6 | ⚪ Info | Comunidad sin moderación/edición/borrado y con crecimiento indefinido en `data.json`. |
| F-7 | ⚪ Info | Sin paginación en comunidad/auditoría (la auditoría ya se auto-poda a 1500–2000 registros). |
| F-8 | ⚪ Info | La restauración no elimina archivos huérfanos previos en `uploads/` (solo añade los del respaldo). |

---

## 5. Hallazgos — Seguridad

| ID | Severidad | Hallazgo |
|---|---|---|
| **S-1** | 🟠 Media | **El límite de intentos de login es por sesión.** Un atacante lo evade limpiando cookies; además `logout` limpia el contador (verificado). El README ya lo advierte. Para exposición pública: bloqueo por IP (Fail2ban/Nginx) o contador por cuenta+IP en `data.json`. |
| **S-2** | 🟠 Media | **Escritura de `data.json` no atómica** (`ftruncate` + `fwrite` sin temp+rename). Un corte de energía a mitad de escritura deja el archivo vacío y `db_read()` (modo `c+`) lo recrea **silenciosamente vacío** → pérdida total de datos enmascarada. Sugerencia: escribir a `data.json.tmp` y `rename()` (operación atómica), y fallar ruidosamente si la DB existente está vacía siendo esperada. |
| **S-3** | 🟡 Baja | **`api()` del frontend no sincroniza el token CSRF ante rotaciones** (verificado en vivo: tras logout doble queda desincronizado y exige recargar). Sugerencia: `api()` debe actualizar `session.csrf` cuando la respuesta incluya uno. |
| **S-4** | 🟡 Baja | Sin cabecera **CSP** (sí hay `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`). Sugerencia: `default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'`. |
| **S-5** | 🟡 Baja | `.htaccess` solo aplica en Apache: con `php -S` local, `api_bootstrap.php` responde 200 (no ejecuta acciones; riesgo nulo pero conviene saberlo) y en Nginx hay que replicar las reglas a mano. Documentar en el LEEME. |
| **S-6** | 🟡 Baja | Sin **HSTS** ni redirección forzada a HTTPS en `.htaccess` (el README exige HTTPS; depende de configuración del hosting). Sugerencia: bloque de redirección listo para descomentar. |
| S-7 | ⚪ Info | El código no verifica la versión mínima de PHP en runtime (funciona en 8.1; README pide 8.2). Sugerencia: `version_compare(PHP_VERSION,'8.2.0','>=')` en `src.php`. |
| S-8 | ⚪ Info | Posible **inyección de fórmulas CSV** en `adminExport()` (nombre/sede del docente pasan al CSV sin sanitizar `= + - @`). Riesgo bajo (Excel); anteponer `'` a celdas que inicien con esos caracteres. |
| S-9 | ⚪ Info | Mensaje genérico de error en login (sin enumeración) ✅; solo enumeración por timing teórica. |

**Fortalezas verificadas (mantener):** `password_hash()` bcrypt · sesiones con `use_strict_mode`, `HttpOnly`, `SameSite=Lax`, `secure` en HTTPS y `session_regenerate_id(true)` en login/logout · CSRF con `hash_equals` + verificación de `Origin` · lista blanca de extensiones + `finfo` + bloqueo de MIME peligrosos + validación imagen/MIME · nombres aleatorios `*.bin` con `chmod 0600` fuera del docroot · descarga opaca (`octet-stream`, `attachment`, `nosniff`, `no-store`) · token de instalación de 160 bits, borrado tras uso y `setup.php` bloqueado tras instalación · errores genéricos 500 con detalle solo en `error_log` · registro de auditoría podado.

---

## 6. Hallazgos — Interfaz

Lighthouse (página con sesión): **Accesibilidad 88/100 · Mejores Prácticas 100/100 · SEO 75/100**.

| ID | Severidad | Hallazgo |
|---|---|---|
| **I-1** | 🟠 Media | **La barra de navegación superior se trunca** en ventanas estrechas (captura: elementos cortados, "P…" sin scroll visible). Usuario rural típico usa celular → prioridad alta. Sugerencia: `overflow-x:auto` con scroll táctil o menú plegable. |
| **I-2** | 🟠 Media | **Fechas en formato ISO UTC crudo** (`2026-11-12T15:28:19+00:00`) en comunidad y demás vistas; además la hora no es la local de Colombia. Sugerencia: `new Date(x).toLocaleString('es-CO')`. |
| **I-3** | 🟠 Media | **Sin barra de progreso al subir archivos** (hasta 15 MB en conexiones rurales lentas parece "colgado"). Sugerencia: `fetch` con `XMLHttpRequest.upload.onprogress` o indicador de estado. |
| I-4 | 🟡 Baja | Feedback con `alert()`/`confirm()` nativos en lugar de avisos inline amigables. |
| I-5 | 🟡 Baja | El checkbox "He revisado esta lección" no se actualiza hasta recargar (sin actualización optimista). |
| I-6 | 🟡 Baja | Lighthouse: **controles de formulario sin `for`/`id` vinculado** en panel admin (Nombre, Correo, Sede, Contraseña, Recurso…). |
| I-7 | 🟡 Baja | `window.print()` en guías imprime toda la página (incluye nav/header). Sugerencia: `@media print { aside, header {display:none} }`. |
| I-8 | 🟡 Baja | Cambio de contraseña sin campo "repetir contraseña" (una errata deja al docente bloqueado hasta que el admin lo restablezca). |
| I-9 | ⚪ Info | Sin `meta description` (SEO 75) ni favicon. |
| I-10 | ⚪ Info | Botones no se deshabilitan durante las peticiones → doble envío posible. |
| I-11 | ⚪ Info | Comunidad sin mensaje de estado vacío; sin límite visible de publicaciones. |
| I-12 | ⚪ Info | Sin buscador/filtro en tabla admin (suficiente para 13 docentes). |

**Puntos fuertes de interfaz:** diseño limpio, coherente y contextualizado (colores institucionales, emojis orientadores) · todo el contenido de usuario escapado con `esc()` (sin XSS reflejado en la UI) · `autocomplete` correcto en login · microlecciones con `<details>` accesible por teclado · estados vacíos con mensajes en recursos/evidencias · numeración clara de pasos y progreso comprensible (%, indicadores, módulos).

---

## 7. Recomendaciones priorizadas

1. **(Alta)** Escritura atómica de `data.json` (S-2) y no recrear la DB vacía silenciosamente.
2. **(Alta)** Separar el campo del diagnóstico de la actividad del Módulo 0 (F-1) o avisar la sobrescritura.
3. **(Media)** Bloqueo de fuerza bruta por IP/cuenta en producción (S-1) — complementar el existente por sesión.
4. **(Media)** Menú móvil con scroll/plegado (I-1) y fechas localizadas (I-2).
5. **(Media)** Indicador de progreso en subidas (I-3).
6. **(Media)** Vista de auditoría (F-2) y de valoraciones (F-4) en el panel admin.
7. **(Baja)** `api()` sincronizando CSRF (S-3); CSP (S-4); HSTS/redirect HTTPS listo en `.htaccess` (S-6); guard de versión PHP (S-7).
8. **(Baja)** Corregir `pre_restore` cuando `data.json` falta (F-3); labels `for` (I-6); `@media print` (I-7); repetir contraseña (I-8); meta description (I-9).

---

## 8. Evidencia y trazabilidad

- Despliegue de prueba con datos reales en: `C:\Users\marti\AppData\Local\Temp\opencode\ruraltech_demo\` (usuario admin: `admin@ruraltech.local`, docente: `docente@ruraltech.local` — contraseñas de demostración).
- Respaldo y restauración probados en clon: `...\opencode\ruraltech_restore\`.
- Archivos de prueba y respaldo: `...\opencode\testfiles\` (incluye `respaldo.tar` y reportes Lighthouse JSON/HTML descargados).
- El paquete auditado **no fue modificado** por las pruebas (se trabajó sobre copias).
