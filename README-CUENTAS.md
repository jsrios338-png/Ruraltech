# Cuentas de RuralTech en Vercel

## Roles

La aplicación admite únicamente dos roles: `admin` (administrador) y `teacher` (docente).
Las cuentas antiguas con rol de estudiante se convierten a docente al leer la base de datos;
se conserva su identificador, contraseña, progreso y archivos.

## Crear o recuperar el administrador

Las cuentas y sus hashes de contraseña se guardan en Vercel Blob, no en este repositorio.
No escribas contraseñas en archivos de configuración, documentación ni GitHub.

1. En Vercel, abre **Project → Settings → Environment Variables** y agrega `SETUP_TOKEN`
   para **Production**. Usa un token temporal aleatorio distinto de la contraseña del admin.
2. Despliega de nuevo el proyecto para que la función reciba la variable.
3. Abre `https://<dominio>/setup.php` y completa el formulario de forma privada.
   - Si no existe administrador, crea uno.
   - Si existe exactamente uno, actualiza su nombre, correo y contraseña sin borrar su progreso.
   - Si hay más de un administrador, el formulario se bloquea por seguridad.
4. Comprueba el acceso en `https://<dominio>/`.
5. Elimina `SETUP_TOKEN` de las variables de Production y vuelve a desplegar inmediatamente.

El formulario invalida las sesiones anteriores del administrador. Después de ingresar, cambia
la contraseña temporal por una única y guárdala en un gestor de contraseñas.