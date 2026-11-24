-- Airport First — publish genuine guest testimonials
-- Run in the Supabase SQL editor (project: xugzwpsviggvswkvzqiu).
--
-- Use this ONLY for real feedback the client has actually received — an email,
-- a WhatsApp message, a Google or TripAdvisor review — from a guest who has
-- agreed to it being shown on the website. Copy their words, do not improve
-- them, and use the name they are happy to have displayed (e.g. first name
-- and initial). Invented reviews mislead customers and breach consumer
-- protection rules and platform policies.
--
-- Rows inserted here are published immediately: the website's
-- "submissions start unpublished" trigger only applies to the public key.
-- Limits from schema.sql: name 1–80 characters, review 12–600 characters,
-- rating 1–5, avatar_url left NULL (the page draws an initials avatar).
--
-- Replace every <…> placeholder, delete any rows you do not need, then run.
-- The final SELECT shows what is now live.

insert into public.airport_reviews (name, country, flag, rating, review, avatar_url, published)
values
  -- Example shape — a leisure guest:
  ('<Guest display name>', '<Country>', '<Flag emoji, e.g. 🇬🇧>', <1-5>,
   '<The guest''s own words, exactly as received. Double any apostrophe: it''s>',
   null, true),

  -- A corporate client (name the person or the organisation, with permission):
  ('<Name, Title or Company>', 'Sri Lanka', '🇱🇰', <1-5>,
   '<The client''s own words, exactly as received>',
   null, true);

select name, country, rating, left(review, 60) as review_start, published, created_at
from public.airport_reviews
order by created_at desc;

-- To take a review down later:
--   update public.airport_reviews set published = false where name = '<Guest display name>';
