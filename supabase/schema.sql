-- Airport First — inquiry capture and guest reviews
-- Run in the Supabase SQL editor (project: xugzwpsviggvswkvzqiu).
--
-- Safe to re-run: every object is created with IF NOT EXISTS / OR REPLACE, and
-- every policy, trigger and constraint is dropped before it is recreated.
--
-- WHO IS STAFF
-- "Signed in" is not the same as "staff". Supabase lets anyone create an
-- account with the public anon key unless sign-ups are switched off, so a
-- policy written `to authenticated using (true)` would hand every guest's phone
-- number to whoever registers. Staff are therefore listed explicitly in
-- public.staff, and every privileged policy asks public.is_staff().
--
-- To make someone staff, create their user under Authentication > Users, then:
--
--     insert into public.staff (user_id)
--     select id from auth.users where email = 'owner@example.com';
--
-- Also switch off Authentication > Sign In / Providers > "Allow new users to
-- sign up", so the only accounts that exist are ones you created.
--
-- The Supabase dashboard (Table Editor, SQL editor) runs as a privileged role
-- that bypasses row-level security, so staff can moderate from there without
-- any admin page.


-- ---------------------------------------------------------------------------
-- Staff allow-list
-- ---------------------------------------------------------------------------

create table if not exists public.staff (
  user_id     uuid primary key references auth.users (id) on delete cascade,
  created_at  timestamptz not null default now()
);

-- RLS on with no policies: nobody reaches this table through the API. It is
-- edited from the SQL editor only.
alter table public.staff enable row level security;
revoke all on public.staff from anon, authenticated;

-- SECURITY DEFINER so the check can read public.staff, which the caller
-- cannot. search_path is pinned empty so nothing the caller controls can
-- shadow the table it reads.
create or replace function public.is_staff()
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1 from public.staff where user_id = (select auth.uid())
  );
$$;

revoke all on function public.is_staff() from public;
grant execute on function public.is_staff() to anon, authenticated;


-- ---------------------------------------------------------------------------
-- Inquiries
-- ---------------------------------------------------------------------------

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

-- Size caps. These stop the public key being used to fill the database, not to
-- validate a booking (send-mail.php does that), so they sit well above what the
-- form produces: an over-long field must never cost the desk a real inquiry.
-- NOT VALID: enforced on new rows, existing rows are left alone.
alter table public.airport_inquiries drop constraint if exists airport_inquiries_size_caps;
alter table public.airport_inquiries add constraint airport_inquiries_size_caps check (
      char_length(name)    <= 200
  and char_length(phone)   <= 80
  and coalesce(char_length(email),   0) <= 254
  and char_length(pickup)  <= 300
  and char_length(dropoff) <= 300
  and coalesce(char_length(time),    0) <= 40
  and coalesce(char_length(flight),  0) <= 40
  and coalesce(char_length(pax),     0) <= 20
  and coalesce(char_length(vehicle), 0) <= 200
  and coalesce(char_length(notes),   0) <= 2000
  and coalesce(char_length(source),  0) <= 60
) not valid;

-- The page sends its own created_at, and a caller could send handled = true to
-- hide a row from the desk's queue. Neither is the caller's to decide.
create or replace function public.airport_inquiries_on_insert()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.created_at := now();
  new.handled    := false;
  return new;
end;
$$;

drop trigger if exists airport_inquiries_on_insert on public.airport_inquiries;
create trigger airport_inquiries_on_insert
  before insert on public.airport_inquiries
  for each row execute function public.airport_inquiries_on_insert();

alter table public.airport_inquiries enable row level security;

-- The website files an inquiry with the public key and can do nothing else:
-- it can never read, change or delete guest contact details, not even its own.
drop policy if exists "anon can submit inquiries" on public.airport_inquiries;
drop policy if exists "public can submit inquiries" on public.airport_inquiries;
create policy "public can submit inquiries"
  on public.airport_inquiries
  for insert
  to anon, authenticated
  with check (true);

-- Reading and working the queue is staff only.
drop policy if exists "authenticated can read inquiries" on public.airport_inquiries;
drop policy if exists "staff can read inquiries" on public.airport_inquiries;
create policy "staff can read inquiries"
  on public.airport_inquiries
  for select
  to authenticated
  using ((select public.is_staff()));

drop policy if exists "authenticated can update inquiries" on public.airport_inquiries;
drop policy if exists "staff can update inquiries" on public.airport_inquiries;
create policy "staff can update inquiries"
  on public.airport_inquiries
  for update
  to authenticated
  using ((select public.is_staff()))
  with check ((select public.is_staff()));

drop policy if exists "staff can delete inquiries" on public.airport_inquiries;
create policy "staff can delete inquiries"
  on public.airport_inquiries
  for delete
  to authenticated
  using ((select public.is_staff()));


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
  published   boolean not null default false,
  created_at  timestamptz not null default now()
);

-- A table created by an earlier version of this file defaulted to published.
alter table public.airport_reviews alter column published set default false;

create index if not exists airport_reviews_created_at_idx
  on public.airport_reviews (created_at desc);

-- Caps that match the review form (the textarea allows 600 characters, the
-- page refuses fewer than 12). The avatar may only be the small inline image
-- the page's own compressor produces — never a remote URL, which would let a
-- poster make every visitor's browser fetch from a server they control.
-- NOT VALID: enforced on new rows, existing rows are left alone.
alter table public.airport_reviews drop constraint if exists airport_reviews_size_caps;
alter table public.airport_reviews add constraint airport_reviews_size_caps check (
      char_length(btrim(name)) between 1 and 80
  and coalesce(char_length(country), 0) <= 80
  and coalesce(char_length(flag),    0) <= 16
  and char_length(btrim(review)) between 12 and 600
) not valid;

alter table public.airport_reviews drop constraint if exists airport_reviews_avatar_inline;
alter table public.airport_reviews add constraint airport_reviews_avatar_inline check (
  avatar_url is null
  or (
        char_length(avatar_url) <= 60000
    and avatar_url ~ '^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/]+={0,2}$'
  )
) not valid;

-- Every review from the public waits for approval, whatever the page sends:
-- an older cached copy of index.html still posts published = true. The
-- dashboard's own role is left alone, so staff can add a review there already
-- published.
create or replace function public.airport_reviews_on_insert()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if current_user in ('anon', 'authenticated') and not public.is_staff() then
    new.published := false;
  end if;
  new.created_at := now();
  return new;
end;
$$;

drop trigger if exists airport_reviews_on_insert on public.airport_reviews;
create trigger airport_reviews_on_insert
  before insert on public.airport_reviews
  for each row execute function public.airport_reviews_on_insert();

alter table public.airport_reviews enable row level security;

-- The public sees approved reviews only; staff also see the ones waiting.
drop policy if exists "anyone can read published reviews" on public.airport_reviews;
create policy "anyone can read published reviews"
  on public.airport_reviews
  for select
  to anon, authenticated
  using (published or (select public.is_staff()));

-- Anyone may submit one, and it arrives unpublished (the trigger above runs
-- before this check, so a stale page's published = true is already false here).
drop policy if exists "anyone can post a review" on public.airport_reviews;
create policy "anyone can post a review"
  on public.airport_reviews
  for insert
  to anon, authenticated
  with check (not published or (select public.is_staff()));

-- Approving, editing and deleting is staff only.
drop policy if exists "only staff can delete reviews" on public.airport_reviews;
create policy "only staff can delete reviews"
  on public.airport_reviews
  for delete
  to authenticated
  using ((select public.is_staff()));

drop policy if exists "only staff can moderate reviews" on public.airport_reviews;
create policy "only staff can moderate reviews"
  on public.airport_reviews
  for update
  to authenticated
  using ((select public.is_staff()))
  with check ((select public.is_staff()));
