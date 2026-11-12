# RuralTech

Aplicacion educativa independiente basada en PHP. No es un plugin de Moodle.

## Paquetes

- `servidor_php/`: despliegue en un servidor PHP; configure el document root en `servidor_php/public/`.
- `hosting_cpanel/`: paquete preparado para la estructura indicada en `hosting_cpanel/LEEME_CPanel.txt`.

Consulte los archivos `README.md` y `LEEME_*.txt` de cada paquete para requisitos, instalacion y administracion.

La aplicacion requiere PHP y almacenamiento persistente en disco para sus datos y archivos. No se puede desplegar funcionalmente como un sitio estatico en Vercel.

## Datos privados

Los datos de usuarios, archivos subidos y el token de instalacion se mantienen fuera del repositorio. Configure permisos de escritura para el usuario del proceso PHP en las carpetas `private/` y `private/uploads/`.
