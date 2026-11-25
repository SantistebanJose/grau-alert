# Grau-Alert

Sistema web de asistencia escolar con PHP, PostgreSQL y lectura de carné QR. El auxiliar verifica visualmente la foto y el nombre que aparecen en pantalla antes de marcar la asistencia. No utiliza reconocimiento facial.

## Requisitos

- PHP 8.2 o superior.
- Extensiones PHP `pdo` y `pdo_pgsql` habilitadas.
- PostgreSQL. Para Supabase, usar una conexión de base de datos privada desde el servidor PHP.
- HTTPS para usar la cámara en un sitio publicado. En desarrollo, `localhost` también permite usarla.

## Preparar la base de datos

1. Crea una base PostgreSQL en Supabase.
2. Abre el SQL Editor y ejecuta el contenido de [`database/schema.sql`](database/schema.sql).
3. Configura en el servidor PHP estas variables de entorno:

   - `DB_HOST`: host de PostgreSQL.
   - `DB_PORT`: puerto de PostgreSQL (normalmente `5432`; usa el puerto indicado por Supabase para el método de conexión elegido).
   - `DB_NAME`: nombre de la base.
   - `DB_USER`: usuario privado de PostgreSQL.
   - `DB_PASSWORD`: contraseña de PostgreSQL.
   - `APP_SETUP_KEY`: una clave larga y aleatoria para habilitar la creación del primer administrador.

Si la base ya tenía el esquema inicial, ejecuta la migración [`2026_11_10_delta_desde_esquema_inicial.sql`](database/migrations/2026_11_10_delta_desde_esquema_inicial.sql) antes de abrir el directorio escolar. Añade DNI, correo y estado de sesión de PerúEduca, sin modificar el código oficial ya existente.

Para habilitar las notificaciones push, ejecuta también [`2026_11_25_push_subscriptions.sql`](database/migrations/2026_11_25_push_subscriptions.sql) en el SQL Editor de Supabase. En Render configura `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY` y `PUSH_VAPID_SUBJECT` como variables privadas. Genera un par de claves con `composer install` y `php scripts/generate-vapid-keys.php`; guarda ambas claves en Render y conserva el mismo par. El asunto debe ser un correo de contacto con formato `mailto:correo@institucion.edu.pe`. Después de desplegar, cada apoderado debe iniciar sesión en su dispositivo y pulsar **Activar notificaciones** en el Portal familiar.

La conexión usa `PDO` con `pgsql`, SSL obligatorio y la zona horaria `America/Lima`. No pongas las credenciales dentro de `config.php`, no las subas al repositorio y nunca uses la clave `service_role` en el navegador. La contraseña de la base solo debe estar disponible para PHP en el servidor.

## Estructura MVC

- `views/app.php`: interfaz web.
- `views/pages/`: vistas de cada página.
- `controllers/ApiController.php`: rutas y validación HTTP.
- `config/database.php`: conexión PDO y sesión.
- `models/`: modelos de datos (consultas de dominio).
- `public/assets/`: CSS, JavaScript e imágenes.
- `database/schema.sql`: esquema PostgreSQL.

## Iniciar en Apache local

En XAMPP, habilita `extension=pdo_pgsql` en el `php.ini` que utiliza Apache y reinícialo. Crea `config.local.php` en la raíz copiando el patrón `config.local.php.example`; ahí coloca las credenciales de la base que contiene el esquema Grau-Alert. Para PostgreSQL local, usa `DB_SSLMODE => 'disable'`; en un servidor remoto, usa `require`.

Abre `http://localhost/Grau-Alerta/`. La primera visita permite crear al administrador desde localhost. Usa una contraseña de al menos 12 caracteres. Las rutas `/config`, `/controllers`, `/models`, `/views` y `/database` están bloqueadas para acceso web directo.

## Páginas enlazadas

- `login.php`: acceso y configuración inicial.
- `index.php`: resumen del día.
- `estudiantes.php`: matrícula y carnés QR.
- `asistencia.php`: lector de cámara y confirmación visual.
- `comunidad.php`: cuentas de personal y apoderados.
- `reportes.php`: reporte diario, CSV y cierre de faltas.
- `avisos.php`: avisos disponibles en el portal familiar.
- `familia.php`: consulta de asistencia para apoderados.

En “Comunidad escolar” se puede importar un Excel o CSV con una fila por relación apoderado-estudiante. Se usa el código de matrícula para resolver al estudiante; cada correo crea una cuenta una sola vez y puede repetirse para vincular hermanos. Las cuentas nuevas reciben claves temporales exportadas al terminar la importación.

## Flujo actual

- Administración: registra DNI único, Código Estudiante único, nombres, apellidos, nivel, grado y una URL de foto autorizada.
- Importa padrones Excel por hojas; se guardan DNI, Código Estudiante, nivel, correo y estado de sesión de PerúEduca. Las contraseñas incluidas en el archivo se ignoran.
- El sistema crea un UUID QR único para cada estudiante y permite imprimir el carné.
- Auxiliar: abre “Marcar asistencia”, escanea con la cámara, compara a la persona presente con la foto y confirma.
- PostgreSQL impide más de una asistencia diaria por estudiante.
- La pantalla de inicio presenta el total de registros del día.

Los avisos de ingreso, tardanza e inasistencia quedan disponibles en el portal familiar. Con VAPID configurado y el permiso activado por el apoderado, el servidor envía también notificaciones push al dispositivo instalado. La alerta audible usa el sonido configurado por el sistema operativo y puede depender del modo silencio y los permisos del dispositivo. Los avisos con teléfono asociado aparecen en “Avisos” como pendientes e incluyen “Enviar aviso”: abre WhatsApp Web o la aplicación con el número y el texto listos; el personal debe pulsar Enviar en WhatsApp y luego puede marcar el aviso enviado en Grau-Alert. La asistencia QR considera tardanza según las reglas escolares configuradas. Las inasistencias y avisos se generan al usar “Cerrar día, marcar faltas y preparar avisos” en Reportes, para la fecha lectiva seleccionada.

La interfaz carga las librerías del lector QR, generador QR y fuentes desde CDN; para uso sin conexión habrá que alojarlas localmente.
