-- Airport First — inquiry capture
-- Run once in the Supabase SQL editor (project: meoinwznmdjyjmbgjdjl).

create table if not exists public.airport_inquiries (
  id          uuid primary key default gen_random_uuid(),
  name        text not null,
  phone       text not null,
  email       text,
  pickup      text not null,
  dropoff     text not null,
  date        date,
  time        text,
  flight      text,
  pax         text,
  vehicle     text,
  notes       text,
  source      text default 'website-hero-form',
  created_at  timestamptz not null default now(),
  handled     boolean not null default false
);

create index if not exists airport_inquiries_created_at_idx
  on public.airport_inquiries (created_at desc);

alter table public.airport_inquiries enable row level security;

-- The website submits with the public anon key, so anon may INSERT only.
-- It must never be able to read, update or delete guest contact details.
drop policy if exists "anon can submit inquiries" on public.airport_inquiries;
create policy "anon can submit inquiries"
  on public.airport_inquiries
  for insert
  to anon
  with check (true);

-- Staff read the queue through an authenticated dashboard session.
drop policy if exists "authenticated can read inquiries" on public.airport_inquiries;
create policy "authenticated can read inquiries"
  on public.airport_inquiries
  for select
  to authenticated
  using (true);

drop policy if exists "authenticated can update inquiries" on public.airport_inquiries;
create policy "authenticated can update inquiries"
  on public.airport_inquiries
  for update
  to authenticated
  using (true)
  with check (true);


-- ---------------------------------------------------------------------------
-- Guest reviews
-- ---------------------------------------------------------------------------

create table if not exists public.airport_reviews (
  id          uuid primary key default gen_random_uuid(),
  name        text not null,
  country     text,
  flag        text,
  rating      smallint not null check (rating between 1 and 5),
  review      text not null,
  avatar_url  text,
  published   boolean not null default true,
  created_at  timestamptz not null default now()
);

create index if not exists airport_reviews_created_at_idx
  on public.airport_reviews (created_at desc);

alter table public.airport_reviews enable row level security;

-- Anyone may read published reviews and post a new one...
drop policy if exists "anyone can read published reviews" on public.airport_reviews;
create policy "anyone can read published reviews"
  on public.airport_reviews
  for select
  to anon, authenticated
  using (published);

drop policy if exists "anyone can post a review" on public.airport_reviews;
create policy "anyone can post a review"
  on public.airport_reviews
  for insert
  to anon
  with check (true);

-- ...but ONLY an authenticated staff session may delete or unpublish one.
-- This is what actually secures moderation: the ?admin flag in the page merely
-- reveals the buttons, and a visitor who forges it still gets a 401 from here.
drop policy if exists "only staff can delete reviews" on public.airport_reviews;
create policy "only staff can delete reviews"
  on public.airport_reviews
  for delete
  to authenticated
  using (true);

drop policy if exists "only staff can moderate reviews" on public.airport_reviews;
create policy "only staff can moderate reviews"
  on public.airport_reviews
  for update
  to authenticated
  using (true)
  with check (true);
