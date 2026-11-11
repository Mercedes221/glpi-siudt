# Guía de actualización de GLPI 10.0.18 a 12.0.1

## Estado actual

La base de producción se migró de GLPI 10.0.18 a **GLPI 12.0.1** el 7 de
noviembre de 2026. La aplicación y MariaDB están en ejecución en Docker;
GLPI responde en <http://localhost:8080>.

La migración y las verificaciones técnicas principales terminaron correctamente.
OAuth está configurado y su emisión de tokens se probó. Para que los agentes
remotos lo usen falta definir y verificar una URL HTTPS válida y aplicar las
credenciales en cada equipo agente. La URL pública indicada no resolvió en DNS
(NXDOMAIN), por lo que no se guardó como endpoint. No hay equipos GLPI Agent
instalados todavía. El recolector de correo se desactivó sin borrar su
configuración; si se necesita recibir correo, falta corregir su servidor y
protocolo antes de reactivarlo.

## 1. Versiones y compatibilidad

- Origen: GLPI 10.0.18.
- Destino: GLPI 12.0.1.
- PHP: 8.3.
- Base de datos: MariaDB 10.11.
- Data Injection 2.14.7 no declara compatibilidad con GLPI 12. Su código no se
  incluye en este repositorio; en la instancia migrada quedó instalado, pero
  desactivado. No reactivarlo hasta confirmar una versión compatible.
- Para importar equipos de red se dispone del importador REST
  `tools/import-network-equipment.php`. Su modo predeterminado es vista previa;
  no usar `--apply` sin revisar primero los datos.
- Se eligió OAuth `client_credentials` para autenticación de inventario. La
  autenticación y la API de alto nivel quedaron habilitadas; se creó el cliente
  OAuth `SIUDT GLPI Agent`, restringido al permiso `inventory` y al grant
  `client_credentials`.

## 2. Respaldos

Antes de migrar se guardaron respaldos de la base de datos, la configuración
y los archivos fuera del repositorio, en una carpeta privada. No publicar la
ruta ni los hashes de esos respaldos.

```powershell
$backup = "<ruta-privada-de-respaldos>"
Get-ChildItem -LiteralPath $backup -File | Get-FileHash -Algorithm SHA256
```

Conservar los respaldos durante el periodo de observación. No copiarlos al
repositorio ni eliminarlos antes de confirmar la operación.

## 3. Cambios del contenedor y del tema

Se sustituyó el núcleo de GLPI por la distribución oficial 12.0.1 (SHA-256
verificado: `bf89a1be40ea76e4e7a6a5cea8fd24c9d822fab83e4e90519ff0c8d062f13291`).
El contenedor se actualizó a PHP 8.3 con las extensiones requeridas; MariaDB
10.11 y los volúmenes persistentes se conservaron. Apache sirve desde `public/`
y envía tanto `/apirest.php` como `/api.php/...` al enrutador de GLPI 12. La
ruta moderna es necesaria para emitir los tokens OAuth de los agentes.

El plugin local usa la clave válida `siudtfront` (GLPI 12 no acepta `_` en la
clave del plugin). Se instaló y activó durante la migración. El CSS se sirve
desde `/plugins/siudtfront/css/custom_theme.css`; la imagen construida publica
ese archivo también bajo `public/plugins/`. Se restauraron los archivos fuente
`siudtfront.php` y `setup.php` que se habían deshecho, y se reconstruyó la
imagen con ellos. El plugin conserva sus funciones de instalación y
desinstalación sin cambios de esquema propios.

## 4. Ensayo aislado

Antes de producción se probó la actualización en un proyecto Compose
independiente llamado `glpi12test`, con volúmenes separados de los de
producción. Se restauró una copia de la base y de los volúmenes de GLPI, y se
ejecutó `db:update` con la imagen 12.0.1.

El ensayo migró desde 10.0.18 hasta 12.0.1 aplicando automáticamente las
migraciones intermedias de GLPI 10 y 11. Pasaron las comprobaciones de versión,
esquema e integridad. La interfaz respondió HTTP 200; el plugin `SIUDT Front`
quedó activo y su CSS respondió HTTP 200. Data Injection permaneció
desactivado. Al terminar, se detuvieron los servicios de ensayo y se
conservaron sus volúmenes.

## 5. Migración de producción

Se mantuvo MariaDB en ejecución y se migró la base con el usuario del servidor
web:

```powershell
docker compose run --rm --no-deps --user www-data app `
    php bin/console --no-interaction db:update
```

El comando terminó al 100 % y confirmó la actualización a 12.0.1. Después,
GLPI había suspendido la ejecución de plugins como protección; se reanudó,
instaló y activó el tema, se configuró OAuth para inventario y se habilitó la
API de alto nivel:

```powershell
docker compose run --rm --no-deps --user www-data app `
    php bin/console --no-interaction plugin:resume_execution
docker compose run --rm --no-deps --user www-data app `
    php bin/console --no-interaction plugin:install siudtfront
docker compose run --rm --no-deps --user www-data app `
    php bin/console --no-interaction plugin:activate siudtfront
docker compose run --rm --no-deps --user www-data app `
    php bin/console --no-interaction config:set --context=inventory `
    auth_required client_credentials
docker compose run --rm --no-deps --user www-data app `
    php bin/console --no-interaction config:set enable_hlapi 1
docker compose up -d --force-recreate app
```

El primer inicio no pudo tomar el puerto 8080 porque seguía ocupado por el
contenedor aislado. Se detuvo el ensayo sin eliminar sus volúmenes y se recreó
la aplicación de producción; el siguiente inicio quedó correcto.

## 6. Verificaciones realizadas

Comandos de validación:

```powershell
docker compose exec -T -u www-data app php bin/console --version
docker compose exec -T -u www-data app php bin/console database:is_up_to_date
docker compose exec -T -u www-data app php bin/console database:check_schema_integrity
docker compose exec -T -u www-data app php bin/console system:check_requirements
docker compose exec -T -u www-data app php bin/console system:status --private
docker compose exec -T -u www-data app php bin/console plugin:list
docker compose ps
```

Resultados:

- CLI informó GLPI 12.0.1; `database:is_up_to_date` indicó que la base está
  actualizada, y `database:check_schema_integrity` confirmó el esquema.
- Todos los requisitos obligatorios de GLPI están satisfechos. La extensión
  LDAP no está instalada; es opcional.
- `SIUDT Front` aparece activo. Data Injection aparece instalado pero no
  activado en la instancia migrada; su código no se distribuye con este
  repositorio.
- La página principal y el CSS del tema respondieron HTTP 200. `/apirest.php`
  respondió HTTP 200. La ruta moderna `/api.php/token` emitió correctamente un
  token Bearer de una hora para el grant `client_credentials` y el alcance
  `inventory`; no se mostró el valor del token.
- `/api.php/v3.0/doc.json` respondió HTTP 200. `system:status --private`
  confirmó que la versión instalada de la base es 12.0.1 y está actualizada;
  el estado global quedó `OK`. El recolector `Admin` tenía el
  servidor/protocolo vacío, por lo que se desactivó conservando su
  configuración. La base, el sistema de archivos y las tareas programadas
  reportaron estado correcto.

## 7. Pendientes

1. Iniciar sesión en <http://localhost:8080> y revisar activos, tickets,
   adjuntos y configuración.
2. Publicar/confirmar la URL HTTPS de GLPI accesible desde los futuros agentes.
   La URL indicada hasta ahora no resolvió (NXDOMAIN) y no se configuró. Cuando
   haya una URL válida e instalados los agentes, usar el
   client ID/secret del archivo seguro indicado abajo y probar el envío de
   inventario. La emisión de tokens ya se verificó localmente.
3. Si se necesita el recolector `Admin`, corregir su servidor/protocolo y
   credenciales antes de activarlo. Actualmente está desactivado porque el
   servidor estaba vacío y no era funcional; se preservó el registro.
4. Si se ejecuta `tools/import-network-equipment.php`, empezar con vista previa
   y aplicar cambios únicamente tras revisar los registros y permisos.

El client ID, el secreto y la URL local del token se guardaron fuera del
repositorio en el archivo privado `glpi-agent-oauth.json`. Sus permisos
quedaron restringidos al usuario de Windows que lo creó.
No copiarlo al repositorio, no compartir su contenido por chat y rotar el
secreto si el archivo deja de estar bajo control del usuario.

## 8. Recuperación

Si fuera necesario revertir la migración, restaurar conjuntamente la base de
datos, la configuración y los archivos desde los respaldos de la sección 2 y
usar el código/imagen de GLPI 10.0.18. Restaurar solo el código no revierte el
esquema de base de datos. No ejecutar `docker compose down -v`, porque
eliminaría los volúmenes. Verificar los datos restaurados antes de permitir el
acceso de usuarios.