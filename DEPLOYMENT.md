# Airport First — deployment

Static page plus one small PHP mailer. Built for cPanel; no build step, no
Node, no third-party mail account.

> ### ⚠ FULLY ISOLATED FOR TESTING — restore before go-live
>
> Every contact channel, backend **and** visible copy, points at the tester.
> Nothing a visitor can tap reaches the client.
>
> | Where | Testing value | Go-live value |
> |---|---|---|
> | `DISPATCH_NUMBER` (`index.html`) | `94768071150` | `94714784444` |
> | All `wa.me/…` links | `94768071150` | `94714784444` |
> | All `tel:+…` links | `+94768071150` | `+94714784444` |
> | Displayed phone (top bar, header, why-choose, footer, mobile bar, modals) | `0768071150` | `+94 71 478 4444` |
> | `NOTIFY_EMAIL` (`index.html`) | `mhasitha481@gmail.com` | `airportfirst1@gmail.com` |
> | `MAIL_TO` (`send-mail.php`) | `mhasitha481@gmail.com` | `airportfirst1@gmail.com` |
> | `DEFAULT_TO` (`supabase/functions/send-inquiry-email/index.ts`) | `mhasitha481@gmail.com` | `airportfirst1@gmail.com` |
> | All `mailto:` links and displayed email | `mhasitha481@gmail.com` | `airportfirst1@gmail.com` |
>
> To restore, search the project for `0768071150`, `94768071150` and
> `mhasitha481@gmail.com` and swap each back.
>
> **Still on the client's domain:** `MAIL_FROM` in `send-mail.php` (and
> `FROM_ADDRESS` in the Edge Function) is `inquiries@airportfirst.lk`. That is a
> *sender*, not a recipient — no inquiry can reach the client through it — but on
> a test host where that mailbox does not exist, `mail()` may be rejected or
> filtered. Point it at a mailbox on the test domain while testing.

## 0. What to upload

Everything below goes into `public_html/`:

```
index.html
send-mail.php          inquiry mailer (PHP mail(), see §3)
.htaccess              denies *.log, sets security headers
assets/
  client-logo.png      the client's own logo artwork, used in both places
```

`supabase/` is only needed if you later move the database or mailer off cPanel;
it is not required for the site to run, and the shipped `.htaccess` 404s it if
uploaded by accident.

### The logo

Header and footer both use plain `<img src="assets/client-logo.png">` — the
client's own artwork, nothing generated. To update the logo, overwrite that one
file; no markup changes.

Two notes on the current file:

- Despite the `.png` name it is actually a **JPEG** (825×190), so it has a solid
  white background rather than transparency. Browsers sniff the content, so the
  path works as-is.
- Because of that, the footer places it on a white rounded plate — otherwise a
  white rectangle would sit on the navy background. If you supply a
  transparent-background PNG or a reversed (white) version, remove
  `rounded-2xl bg-white px-4 py-3 shadow-lux` from the footer `<a>` and it will
  sit directly on the dark background.

## 1. Static site

`index.html` needs no build step.

Hardcoded and live in the page:

| Credential | Value | Public? |
|---|---|---|
| Supabase project URL | `https://meoinwznmdjyjmbgjdjl.supabase.co` | Yes |
| Supabase anon key | `eyJhbGciOi…` | Yes — protected by RLS |

There is **no Google Maps key and no maps billing**. Location suggestions come
from `AF_LOCATIONS`, a plain array near the bottom of `index.html`, rendered by
a self-contained dropdown: no third-party request, nothing to rate-limit, and
nothing to restrict in a cloud console. To add a hotel or landmark, append one
`{ name, kind, icon }` entry to that array and mirror it in the `#af-locations`
datalist (the no-JS fallback).

The two location fields are always ordinary `<input type="text">` — never
disabled or read-only — so a guest can type any address the list does not know.

The pick-up field also carries a **Use current location** button: browser GPS,
then one free OpenStreetMap Nominatim reverse-geocode to turn the coordinates
into e.g. `Current Location: Kuliyapitiya, Kurunegala`. Two deployment notes:

- **Geolocation needs a secure context.** It works on `https://` and on
  `localhost`, and is refused on plain `http://` — so serve the production site
  over HTTPS or the button will always fall back to its toast.
- **Nominatim is free but rate-limited** (roughly one request per second, which
  the page enforces). It is fine for user-initiated lookups at this volume; if
  traffic grows, self-host Nominatim or move to a paid geocoder. On any failure
  the field falls back to raw coordinates, which the desk can still act on.

## 2. Database

Run `supabase/schema.sql` in the SQL editor. It creates two tables with their
row-level security policies:

- `airport_inquiries` — the anon key may **insert only**, so the website can
  file an inquiry but nobody can read guest contact details without an
  authenticated session.
- `airport_reviews` — anon may **read published rows and insert**; only an
  **authenticated** session may delete or unpublish.

`avatar_url` holds either nothing (the page then draws an initials avatar) or a
`data:image/jpeg;base64,…` string. Guest photos are cropped square, resized to
200×200 and re-encoded in the browser before submission, stepping JPEG quality
down until the payload is under 30 KB — so no image is uploaded at full size,
no storage bucket is needed, and rows stay small. Measured on a 3000×2000,
219 KB photo: 3 KB out. Worst case tested (full-frame noise): 7 KB.

### Review moderation

`?admin=true` plus the PIN in `ADMIN_PIN` reveals a delete control on each
review card. **That flag is presentation only** — it is client-side and anyone
can set it. The actual boundary is the delete policy above: a visitor who
forges the flag still gets a 401 from Postgres, and the page shows
"Could not delete — sign in as staff and try again".

For true owner-only moderation, sign the owner in with Supabase Auth and let
the page use that session; the PIN is a convenience lock on the UI, not
security. Change `ADMIN_PIN` before launch.

## 3. Email notifications

Two delivery routes are tried in order, so email works both on the live cPanel
host and on a laptop with no PHP at all.

### Route 1 — `send-mail.php` (production)

The page POSTs each inquiry as JSON to `send-mail.php` in the same folder, which
emails it to the address in `MAIL_TO` using PHP `mail()`. Nothing to sign up
for and no API key in the page.

### Route 2 — Web3Forms (localhost / no-PHP fallback)

If route 1 fails — which is exactly what happens on `python -m http.server` or
any static host, since the PHP file is served as a 404 or not executed — the
page falls through to Web3Forms, a free CORS-enabled endpoint that needs no
server of its own.

**One-time setup, needed for local email testing:**

1. Go to <https://web3forms.com>, enter `mhasitha481@gmail.com`, and they email
   you an access key. It is a UUID, e.g.
   `a1b2c3d4-1234-5678-9abc-de1234567890`.
2. Give the page the key, either way round:

```js
const WEB3FORMS_ACCESS_KEY = "YOUR_WEB3FORMS_ACCESS_KEY";   // ← paste here
```

   …or, without editing the file, load the page once as
   `http://localhost:5173/?web3key=<the-uuid>`. It is stored in `localStorage`
   for that browser (`af_web3forms_key`) and used from then on. A key
   hardcoded in the file always wins.

Web3Forms delivers to whichever address the key was created for, so there is
nothing else to configure. Until a key is set the page says so in the console
at load, route 2 is skipped, and the status pill settles amber rather than
green.

Two things worth knowing when testing:

- **Test from the browser, not curl.** Web3Forms only accepts client-side
  calls on the free plan; a server-side POST is refused with
  `403 … Use our API in client side`. Submitting the actual form works.
- The key is only checked for UUID shape locally. A well-formed but wrong key
  is rejected by the API and the pill stays amber — which is the honest
  outcome, since no mail was sent.

**Two settings before launch.** `send-mail.php` sends through PHPMailer over
authenticated SMTP as the mailbox itself. One setting is in the file:

```php
const MAIL_TO   = 'mhasitha481@gmail.com';        // TESTING — airportfirst1@gmail.com for go-live
const MAIL_FROM = 'noreply@airportfirst.vip';     // create this in cPanel first
```

**The other is the password, and it does not go in this file.** Create
`airportfirst-smtp.php` one level *above* `public_html`, containing one line:

```php
<?php return 'the mailbox password';
```

That is outside the web root, so Apache has nothing to serve, and outside the
repository, so no commit can carry it. `send-mail.php` reads it at startup; if
it is missing, mail fails, the inquiry is kept in the fallback log, and the
error log says which file to create. The script authenticates as `MAIL_FROM`
against `localhost` on port 587 — nothing else needs configuring.

**Upload PHPMailer alongside it.** The script has no other dependency, but it
will not send without this one: put the library's `src` folder at
`public_html/PHPMailer/src/`. Download the release zip from
[github.com/PHPMailer/PHPMailer](https://github.com/PHPMailer/PHPMailer/releases),
or run `composer require phpmailer/phpmailer` if the plan has Composer — a
`vendor/autoload.php` is picked up instead. If the library is missing, every
inquiry answers `500` and is written to the fallback log described below.

Create it under **cPanel → Email Accounts**. Sending "from" a Gmail address you
do not control is the usual reason these mails land in spam or bounce; the
script also passes `-f` so the envelope sender matches, which Gmail checks.

`Reply-To` is set to the guest's email when they gave one, so replying from the
inbox reaches them directly.

### Checks

- `GET send-mail.php` → `405` with JSON. If you see PHP source instead, PHP is
  not enabled for the folder.
- A refused SMTP session answers
  `{"ok":false,"error":"Mail server rejected the message"}`. Set
  `SMTP_DEBUG = true` and read the `[AirportFirst][smtp]` lines in **cPanel →
  Errors**: `535 Incorrect authentication data` is a wrong `SMTP_PASS` or a
  mailbox that does not exist yet, and `Could not connect` means nothing is
  listening on `SMTP_PORT` — fall back to port 25 with `SMTP_SECURE = ''`,
  which every cPanel box accepts on the loopback. Port 25 pairs with `''`, 587
  with `'tls'`, 465 with `'ssl'`; a mismatch just hangs.
- Every rejected inquiry is appended to `inquiries-fallback.log`, written one
  level *above* `public_html` so it is not web-readable. Nothing is ever lost
  silently. Check it if the desk reports a missing enquiry.

### Security notes

**Who may post.** The endpoint answers `POST` only, and only when `Origin` (or
`Referer`, if a browser sent no `Origin`) is one of `ALLOWED_HOSTS` or the
server's own hostname. A post from another site, or from a bare `curl` with no
origin at all, gets `403`. The page is static, so there is no session to hang a
CSRF token on; matching the origin is the equivalent a static form can offer.

**How often.** One submission per caller every 20 seconds, and at most 5 in 10
minutes, answered with `429` and a `Retry-After`. Counters live in
`af-throttle/` above the web root, keyed by a hash of the caller's IP rather
than the address itself, and are swept after a day. Behind Cloudflare the
guest's address is read from `CF-Connecting-IP`, which is trusted for
throttling and nothing else. If the store cannot be written the limiter fails
open and logs it — a broken counter must never stop a real booking.

**What gets through.** The body is refused over 16 KB, refused if it is not
valid UTF-8, and parsed at a shallow depth. Every field is then checked for
shape, not just length: the email against `FILTER_VALIDATE_EMAIL`, the phone
against a digits-and-punctuation pattern with at least 7 digits, the date
against `YYYY-MM-DD` and a real calendar (an ambiguous `03/04/2026` is refused
rather than guessed), the time normalised to 24-hour, passengers to 1–60, and
names, places and the vehicle refused if they carry `<`, `>` or `://`. More
than two links across the whole payload is treated as spam.

**Mail headers.** Control characters are stripped from every field and CR/LF
from all but the notes, so nothing reaching a header can carry a newline (the
header-injection vector); PHPMailer strips them again on the subject and
validates every address. A `Bcc:` smuggled into a name arrives as literal text
in the subject, not as a header. The `From` line is hardcoded to `MAIL_FROM`,
the mailbox SMTP authenticates as, so nothing a guest submits can appear
there — their address only ever reaches `Reply-To`, and only after validating.

**What the caller is told.** Every failure answers with one fixed sentence.
`display_errors` is off and an exception handler plus a shutdown handler catch
anything that escapes, so a stack trace or a server path can never reach the
response body — the detail goes to the PHP error log, where text from the
request is stripped of control characters first so it cannot forge log lines.

## 4. Submit flow

`collectAndDispatchInquiry()` keeps the guest on the page from start to finish.
**Nothing opens a window, nothing navigates, and no timer redirects anyone** —
a guest abroad on an unfamiliar site never has a tab appear behind their back.

1. **Validate** — the required fields, plus a strict format check on the email
   address (see below). An invalid field blocks the submit, shows a toast, and
   focuses the offender.
2. **Confirm immediately** — the "Thank you! Request received" card appears with
   an animated tick and a summary of the request (route, date, passengers,
   vehicle, contact details).
3. **Send in the background** — `fetch` to the mailer (§3) and a Supabase insert
   into `airport_inquiries`, run together via `Promise.allSettled`.
4. **Report honestly** — the status pill on the card settles to:
   - 🟢 *Sent to our dispatch team* — **green only when a mail endpoint
     returned a genuine success** (HTTP 2xx *and* its own `ok`/`success` flag).
   - 🟡 *Received — the desk has your request* — email failed, the database row
     saved. Amber, because nothing was actually delivered to an inbox.
   - 🔴 *Saved on this device — please tap WhatsApp below* — both failed, so the
     guest is pointed at the WhatsApp button.

Each route also times out after 12 s, so a host that accepts the connection but
never answers cannot leave the pill spinning.

**WhatsApp is opt-in only.** The "Need urgent support? Chat on WhatsApp" button
on the success card is a plain link the guest taps if they want it. There is no
auto-open, no `window.open` and no timer anywhere in the submit path.

### Email validation

`EMAIL_RE` in `index.html` accepts any real mailbox — Gmail, Outlook, Yahoo,
company domains, long TLDs like `.travel` — and rejects what actually breaks a
dispatch: a missing `@` or TLD, spaces, a leading/trailing/doubled dot, a
hyphen at the edge of a domain label, or anything over 254 characters.

The field is **optional** (phone is the required contact channel), so leaving it
blank still submits; a *malformed* address does not. To require an email
instead, set `EMAIL_REQUIRED = true` — one constant, next to the regex.

The address is validated once more before it is used as the mailer's `Reply-To`,
since Web3Forms rejects the whole submission over a malformed one.

The tour cards' "Inquire Special Tour Tariff" buttons also stay in-app: they
write the tour into the inquiry form's drop-off and notes and scroll the guest
there, so every request leaves through this one pipeline.

## 5. Local preview

Use PHP's built-in server so `send-mail.php` runs too:

```bash
php -S 127.0.0.1:5173 -t .
```

`python -m http.server 5173 --bind 127.0.0.1` also serves the page, but the
mailer will 501 because Python does not execute PHP.

## 6. Known follow-ups

- **Tailwind runs from the CDN.** Fine for launch; for best performance build
  the stylesheet with the Tailwind CLI and ship a static `.css`.
- **Location list is curated, not exhaustive.** The dropdown suggests 27 known
  Sri Lankan airports, cities, landmarks and hotels; anything else is typed
  free-hand and passes through to WhatsApp and Supabase unchanged. Extend
  `AF_LOCATIONS` as the desk notices repeat destinations.
- **A transparent or reversed logo would improve the footer.** The current file
  is a white-background JPEG, so it sits on a white plate there (see §0).
- **Destination photography is stock.** The cards use Unsplash imagery chosen
  to match each place; swap in the client's own photos when available.
