# RuralTech — Edición Vercel

Versión de RuralTech adaptada para funcionar al **100 % en Vercel** con almacenamiento
en **Vercel Blob** (el sistema de archivos de Vercel es de solo lectura).

## Qué incluye

- **Tres roles**: `administrador`, `docente` y `estudiante`.
  - El administrador gestiona usuarios, recursos, respaldos, restauración, auditoría y valoraciones.
  - Los docentes siguen su propia ruta y **supervisan el progreso de los estudiantes** (página “Estudiantes”).
  - Los estudiantes participan de todo el aula: módulos, microlecciones, cuestionarios, actividades,
    evidencias, comunidad, producto final y valoración (sin panel de administración).
- Todas las funciones del paquete original: progreso por módulos, cuestionarios, comunidad,
  valoración de 10 criterios, banco de recursos, producto final, **respaldo TAR y restauración
  desde la interfaz** (con copia previa automática), log de auditoría visible.
- Mejoras derivadas de la auditoría: bloqueo de fuerza bruta **por cuenta y persistente**,
  diagnóstico inicial separado de la actividad del Módulo 0, fechas localizadas (es-CO),
  **barra de progreso real en las subidas**, botones deshabilitados durante peticiones,
  campo de repetición de contraseña, vista de auditoría y de valoraciones para el admin,
  sincronización automática del token CSRF en el navegador.

## Arquitectura

| Componente | Dónde | Nota |
|---|---|---|
| `index.html` | estático | SPA completa (frontend) |
| `api/index.php` | función PHP | toda la API (`/api.php?action=…`) |
| `api/setup.php` | función PHP | instalador del primer administrador |
| `api/_lib.php` | función (núcleo) | cliente Blob + capa de datos + sesiones |
| Vercel Blob | almacén privado | `data/data.json` (base de datos), `files/*.bin` (adjuntos), `backups/*.json` (copias previas) |
| vercel.json | configuración | runtime `vercel-php@0.7.4` (PHP 8.3) y reescrituras `/api.php` y `/setup.php` |

- La base de datos se escribe con **concurrencia optimista por ETag** (`x-if-match`),
  equivalente al bloqueo `flock` del paquete original; ante escrituras simultáneas se reintenta.
- Las **sesiones viajan dentro de data.json** (cookie `rt_sid` propia, HttpOnly, SameSite=Lax,
  Secure en HTTPS, rotación al iniciar y cerrar sesión).
- Las **subidas no pasan por la función** (Vercel limita el cuerpo a 4,5 MB): el servidor emite un
  *client token* firmado (HMAC-SHA256) y el navegador sube el archivo **directamente a Vercel Blob**
  con barra de progreso; luego el servidor valida el contenido real (finfo, tipo peligroso,
  imagen vs extensión) y lo registra en la base de datos. Límite por archivo: **15 MB**.

## Variables de entorno del proyecto (Vercel)

| Variable | Uso |
|---|---|
| `BLOB_READ_WRITE_TOKEN` | la conecta `vercel storage connect` (ya configurada) |
| `BLOB_STORE_ID` | identificador del almacén (ya configurada) |
| `SETUP_TOKEN` | código del instalador; **elimínela después de crear el administrador** |

## Despliegue (CLI)

```bash
npm i -g vercel
vercel login
vercel link --project ruraltech          # una vez por carpeta
vercel deploy --prod                     # despliegue a producción
```

## Instalación inicial

1. `vercel env add SETUP_TOKEN` (valor secreto, p. ej. 32 caracteres aleatorios; aplicar a producción).
2. Abra `https://<dominio>/setup.php`, escriba el código y los datos del administrador.
3. **Elimine la variable `SETUP_TOKEN`** para desactivar el instalador.

## Desactivar la protección de vista del despliegue

Si el dominio pide iniciar sesión con cuenta de Vercel (Deployment Protection → Vercel
Authentication), desactívelo en *Project → Settings → Deployment Protection* para que
docentes y estudiantes accedan libremente.

## Pruebas y operación

- Login limitado: 6 intentos fallidos por cuenta cada 10 minutos (persistente).
- Respaldo: panel de administración → “Descargar respaldo integral” (TAR con data.json + adjuntos).
- Restauración: panel de administración → “Restaurar respaldo” (TAR ≤ 4 MB, ≤ 20 adjuntos;
  se guarda copia previa automática en `backups/`).
- Auditoría: panel “📜 Auditoría” (últimos 100 eventos).
- Extensiones aceptadas: pdf, doc, docx, odt, jpg, jpeg, png, webp, txt, rtf, ppt, pptx, xls, xlsx.

## Desarrollo local

```powershell
# con un PHP >= 8.1 y las variables del almacén cargadas
$env:BLOB_READ_WRITE_TOKEN = "..."   # de .env.vercel
php -S 127.0.0.1:8080 api/index.php   # o usar un router que mapee /api.php
```

El frontend llama a `api.php` (reescrito a `api/index.php` en Vercel). Para desarrollo local,
sirva con un enrutador mínimo o renombre temporalmente.
