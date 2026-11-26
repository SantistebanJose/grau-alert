-- Perfil editable y registro de salida de estudiantes.
-- Ejecutar una vez en Supabase SQL Editor antes de desplegar esta versión.

alter table public.asistencias
    add column if not exists hora_salida timestamptz;

alter table public.alertas
    drop constraint if exists alertas_tipo_check;

alter table public.alertas
    add constraint alertas_tipo_check
    check (tipo in ('ingreso', 'tardanza', 'inasistencia', 'incidencia', 'salida'));
