create table if not exists public.push_subscriptions (
 id uuid primary key default gen_random_uuid(),
 apoderado_id uuid not null references public.usuarios(id) on delete cascade,
 endpoint text not null unique,
 p256dh text not null,
 auth text not null,
 creado_en timestamptz not null default now()
);
create index if not exists idx_push_subscriptions_guardian on public.push_subscriptions(apoderado_id);
alter table public.push_subscriptions enable row level security;
drop policy if exists bloquear_api_publica on public.push_subscriptions;
create policy bloquear_api_publica on public.push_subscriptions for all using (false) with check (false);
