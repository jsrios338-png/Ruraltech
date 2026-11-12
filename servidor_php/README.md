# RuralTech — servidor multiusuario

Esta versión **no es un plugin Moodle**. Es una aplicación PHP independiente que implementa las cinco áreas del proyecto RuralTech: módulos, actividades con adjuntos, recursos, seguimiento y administración. Para usar Moodle propiamente dicho, estos contenidos y flujos deben instalarse como actividades nativas de Moodle.

## Requisitos

- PHP 8.2 o superior, extensiones **fileinfo**, **openssl**, **Phar**, sesiones habilitadas y servidor web (Apache, Nginx o PHP para pruebas).
- HTTPS obligatorio en producción.
- Carpeta `private/` **fuera** de la raíz pública, nunca servida directamente por web.
- Permisos de escritura para el usuario del proceso PHP en `private/data.json` y `private/uploads/`.
- `upload_max_filesize=16M`, `post_max_size=17M` o mayores en `php.ini`.
- La app usa archivos JSON con bloqueos exclusivos, apropiado para un piloto pequeño (13 docentes), no una base de datos empresarial.

## Inicio rápido local

Desde la carpeta del proyecto:

```bash
php scripts/create_admin.php "Administrador RuralTech" admin@ejemplo.edu.co "CambiarEstaClaveSegura2026!"
php -S 127.0.0.1:8080 -t public
```

Visite `http://127.0.0.1:8080` e inicie sesión. **Sustituya el correo y la contraseña por credenciales propias y únicas.** Desde el panel administrador cree cuentas individuales para los docentes. En producción debe usar HTTPS y apuntar la raíz del servidor a `public/`.

## Seguridad

- Contraseñas almacenadas con `password_hash()`; sesiones HttpOnly, SameSite=Lax, token CSRF, control de roles y registros de auditoría.
- Los archivos se almacenan en `private/uploads/` con nombres aleatorios y se descargan solo con autorización. Límite: 15 MB por archivo; extensiones permitidas de documentos e imágenes.
- Las cargas son tratadas como archivos opacos para descarga (no se ejecutan ni se muestran embebidas).
- No cargue datos sensibles de estudiantes sin definir autorización, consentimiento y políticas de privacidad del centro.
- El control de intentos de inicio de sesión es por sesión; para exposición pública agregue bloqueo por IP en el servidor web, controles contra abuso, monitoreo y copias de seguridad programadas.
- Guarde la aplicación y `private/` fuera de repositorios públicos y mantenga actualizado PHP.

## Seguimiento

Cada docente acumula 36 indicadores: 3 lecciones revisadas, 1 actividad textual o adjunto, 1 cuestionario aprobado (2/3) y 1 reflexión, por seis módulos. El administrador ve el avance de todos los usuarios del mismo servidor. **No se requiere compartir navegador**.

## Respaldo y restauración

El administrador descarga un archivo `.tar` que contiene `data.json` y cada adjunto referenciado, **incluyendo PDF, Word e imágenes**.

Para restaurar desde la terminal del servidor, primero detenga las solicitudes web y conserve un respaldo anterior:

```bash
php scripts/restore_backup.php /ruta/RuralTech_respaldo_FECHA.tar --confirm
```

El restaurador comprueba estructura y presencia/tamaño de adjuntos, copia los archivos y reemplaza la información. Tras la restauración, compruebe que docentes, recursos y descargas funcionen.

## Notas pedagógicas

Se conservaron los seis módulos (0 a 5), con 18 microlecciones, guías, actividades, cuestionarios, autodiagnóstico, materiales descargables, comunidad y valoración. El autodiagnóstico es orientador, no calificativo. La medición de progreso es operacional (actividad realizada), **no prueba por sí sola mejora en competencias ni validación del proyecto**.

## Para integración real con Moodle

Moodle usa sus propias tablas, cuentas, permisos, actividades y API de archivos. Esta aplicación separada no sincroniza sus usuarios ni calificaciones con Moodle. Para implementación formal en Moodle debe migrarse la ruta a recursos, tareas, cuestionarios, foros y finalización de actividad, con las APIs soportadas y permisos de Moodle.
