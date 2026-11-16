-- Migración acumulada desde el SQL inicial compartido.
-- Ejecutar una vez en el SQL Editor de PostgreSQL/Supabase.

-- Directorio y DNI del estudiante. El código institucional conserva su UNIQUE.
alter table public.estudiantes add column if not exists nombre_completo text;
alter table public.estudiantes add column if not exists telefono_apoderado text;
alter table public.estudiantes add column if not exists dni varchar(8);
alter table public.estudiantes add column if not exists correo text;
alter table public.estudiantes add column if not exists inicio_sesion_perueduca boolean;

update public.estudiantes
set nombre_completo = nullif(trim(concat_ws(' ', nombres, apellidos)), '')
where nombre_completo is null;

create unique index if not exists ux_estudiantes_dni
    on public.estudiantes (dni)
    where dni is not null and dni <> '';

-- Calendario escolar, añadido después del esquema inicial.
create table if not exists public.calendario_escolar (
    fecha date primary key,
    estado text not null check (estado in ('lectivo', 'feriado', 'vacaciones', 'suspension')),
    evento text not null,
    alarma_activa boolean not null default true,
    creado_por uuid references public.usuarios(id) on delete set null,
    creado_en timestamptz not null default now(),
    actualizado_en timestamptz not null default now()
);
create index if not exists idx_calendario_estado_fecha
    on public.calendario_escolar (estado, fecha);

-- Mantiene actualizado el timestamp y completa nombre_completo si hace falta.
create or replace function public.actualizar_timestamp()
returns trigger
language plpgsql
as $$
begin
    new.actualizado_en = now();
    if tg_table_name = 'estudiantes' then
        if new.nombre_completo is null or trim(new.nombre_completo) = '' then
            new.nombre_completo = nullif(trim(concat_ws(' ', new.nombres, new.apellidos)), '');
        end if;
    end if;
    return new;
end;
$$;

drop trigger if exists calendario_actualizado_en on public.calendario_escolar;
create trigger calendario_actualizado_en before update on public.calendario_escolar
for each row execute function public.actualizar_timestamp();

-- La tabla nueva queda protegida como las demás tablas de la aplicación PHP.
alter table public.calendario_escolar enable row level security;
drop policy if exists bloquear_api_publica on public.calendario_escolar;
create policy bloquear_api_publica on public.calendario_escolar
    for all to public using (false) with check (false);
