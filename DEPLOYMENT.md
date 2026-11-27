# Airport First — deployment

Static page plus a few small PHP files and the client's own cPanel MySQL
database. No build step, no Node, no third-party database or mail account.

> ### Live contact details
>
> Every contact channel now points at the client. Change them together.
>
> | Where | Value |
> |---|---|
> | Company | Airport First (Pvt) Ltd &mdash; WTC West Tower, 37th Floor, Colombo 00100 |
> | `DISPATCH_NUMBER` (`index.html`), all `wa.me/…` and `tel:` links | `94714784444` / `+94714784444` |
> | Displayed phone | `+94 71 478 4444` |
> | `NOTIFY_EMAIL` (`index.html`), `mailto:` links, displayed email | `airportfirst1@gmail.com` |
> | `MAIL_TO` (`send-mail.php`, and the disabled Edge Function) | `airportfirst1@gmail.com` |
> | Instagram | `https://www.instagram.com/airportfirst/` |
> | Facebook | **search link for now** &mdash; replace with the page's own URL (search `FACEBOOK_URL` in `index.html`) |
> | Structured data (`application/ld+json` in `<head>`) | repeats the company name, address, phone and email &mdash; keep in step |
>
> **Sender:** `MAIL_FROM` in `send-mail.php` is `noreply@airportfirst.vip`. It
> is only the sender; the mailbox must exist in cPanel or SMTP authentication
> fails.

## 0. What to upload

Everything below goes into `public_html/`:

```
index.html
send-mail.php          inquiry mailer + saves each inquiry to MySQL (§3)
reviews-api.php        guest reviews: approved list (GET) and new submissions (POST)
admin-reviews.php      password-protected moderation and inquiry list
db-config.php          PDO connection; reads the credentials from above public_html
af-common.php          shared helpers (real visitor IP, origin check, references)
schema.sql             the tables — run once in phpMyAdmin (served as 403)
.htaccess              blocks *.log/*.sql/*.md, db-config.php, af-common.php; headers
PHPMailer/src/         the mail library (§3)
assets/
```

And **above** `public_html/` (never inside it, never in git):

```
airportfirst-db.php    database credentials, admin password hash, IP-hash salt
airportfirst-smtp.php  the mailbox password (§3)
```

**Supabase is no longer used.** The page loads no Supabase script and the CSP no
longer allows any `*.supabase.co` host. The `supabase/` folder in the repo is
kept only as a record of the old setup; don't upload it (the shipped
`.htaccess` 404s it at the domain root). Before the free Supabase project is
paused or deleted, export anything worth keeping from **Table Editor →
`airport_inquiries` / `airport_reviews` → Export to CSV**.

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

There are **no keys or credentials in the page at all**: inquiries and reviews
go to PHP files on the same host, which hold the database connection server-side.

There is **no Google Maps key and no maps billing**. The pick-up and drop-off
suggestions merge two free sources:

1. **`AF_PLACE_DATA`** in `index.html`: 280 Sri Lankan towns, airports,
   railway stations, landmarks and hotels, each with district and province,
   matched instantly from 2 typed characters. To add one, append
   `["Name", districtIndex, kindIndex, "alias, alias"]`. The district and
   kind indexes come from `AF_DISTRICTS` and `AF_KINDS` just above it.
2. **Photon** (`photon.komoot.io`), a free OpenStreetMap search built for
   type-ahead, queried 300 ms after typing stops (3+ characters), limited to
   Sri Lanka's bounding box and to results whose country code is `LK`. It
   surfaces any village, street or hotel not in the list. The dropdown
   credits OpenStreetMap, as the data licence requires, and the CSP's
   `connect-src` allows the host.

Nominatim is used only for the single reverse lookup behind "Use current
location": its usage policy does not allow search-as-you-type. If Photon is
slow or down, the built-in list still works and the dropdown says so.

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

## 2. Database (cPanel MySQL) — four steps

1. **Create the database and user.** cPanel → **Manage My Databases** (or
   *MySQL Database Wizard*): create a database (e.g. `cpuser_airport`), create a
   user with a long generated password, and add the user to the database with
   **ALL PRIVILEGES**. Note the full prefixed names cPanel shows.

2. **Create the tables.** cPanel → **phpMyAdmin** → select the new database →
   **Import** → choose `schema.sql` → *Go* (or paste it into the **SQL** tab).
   It creates `airport_inquiries`, `guest_reviews` and
   `admin_login_attempts`, all InnoDB / `utf8mb4`, and is safe to re-run
   (`CREATE TABLE IF NOT EXISTS`).

3. **Add the credentials file above `public_html`.** cPanel → **File
   Manager** → your home folder (the one that *contains* `public_html`) →
   *+ File* → `airportfirst-db.php`:

   ```php
   <?php
   return [
       'host'                => 'localhost',
       'name'                => 'cpuser_airport',
       'user'                => 'cpuser_airportweb',
       'pass'                => 'the database user password',
       'admin_password_hash' => '',            // filled in step 4
       'hash_salt'           => 'any long random string, e.g. 40 characters',
   ];
   ```

   Apache cannot serve a file outside the web root and no commit can carry it.
   `db-config.php` (in `public_html`) only reads it.

4. **Set the admin password.** Generate a bcrypt hash — cPanel → **Terminal**
   (or any computer with PHP):

   ```bash
   php -r "echo password_hash('a-long-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Paste the `$2y$…` output as `admin_password_hash` in step 3's file (single
   quotes, so PHP leaves the `$` alone). Then open
   **`https://airportfirst.vip/admin-reviews.php`** and sign in. The plain
   password is never stored anywhere.

**Checks:** `GET /reviews-api.php` → `{"ok":true,"reviews":[],"count":0,…}`
once the database is connected (`503` means `airportfirst-db.php` is missing
or wrong — the PHP error log says which). `/db-config.php` and `/schema.sql`
should both answer `403`.

**If MySQL is down or not set up**, nothing breaks for the guest: inquiries are
still emailed (and the email's reference is simply not on file), and the reviews
section shows no cards rather than an error.

### What is stored

- `airport_inquiries` — every validated inquiry, saved **before** the email is
  sent, with a reference such as `AF-7KQ3XM` that also appears in the email
  subject and on the guest's confirmation card. Route stops, leg distances and
  the total road distance (`distance_km`) get their own columns.
  `email_sent` flips to 1 once the mail goes out; `handled` is the desk's
  to-do flag in the admin page.
- `guest_reviews` — every submission arrives with `is_approved = 0`. The
  optional photo is the small `data:image/…;base64` the page makes (cropped
  square, 200×200, under 30 KB); a remote image URL is never accepted.
- Visitors' IP addresses are **never stored** — only a salted SHA-256 hash
  (`ip_hash`), used for rate limits.

### Review moderation — `admin-reviews.php`

New reviews are **not shown until approved.** The guest is told "your review
will appear once it has been approved". In `admin-reviews.php`:

- **Reviews** tab: pending first; *Approve*, *Unpublish* or *Delete* (asks
  first). The public list updates within a minute (it is cached 60 s).
- **Inquiries** tab: the latest 60, with *Mark handled*.

The page is guarded by: `password_verify` against the hash; a session cookie
that is `HttpOnly`, `Secure` and `SameSite=Strict`, regenerated at sign-in and
expiring after 30 idle minutes; a CSRF token on every action; at most 5 failed
sign-ins per 15 minutes per visitor (`admin_login_attempts`); and a strict CSP,
`noindex` and no caching. The old `?admin=true` PIN on the public page is
gone — nothing on `index.html` can change a review.

**Who may post a review.** Same origin only, body under 128 KB, a honeypot
field, name/rating/text validation (no markup, at most one link), and at most
**2 reviews per visitor per hour** (`429` after that).

## 3. Email notifications

Two delivery routes are tried in order, so email works both on the live cPanel
host and on a laptop with no PHP at all.

### Route 1 — `send-mail.php` (production)

The page POSTs each inquiry as JSON to `send-mail.php` in the same folder, which
saves it to MySQL (§2) and then emails it to `MAIL_TO` over SMTP. It answers
`{ok, emailed, stored, ref}`: if the mail fails but the row was saved the guest
is still told the desk has it; if both fail the inquiry goes to the fallback log.
Nothing to sign up for and no API key in the page.

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

### Keeping inquiry mail out of spam — check before go-live

In **cPanel → Email → Email Deliverability**, find `airportfirst.vip` and make
sure **SPF** and **DKIM** both show as valid. If either says "Problems exist",
use the **Repair** button (or copy the suggested records into the DNS zone if
the domain's DNS is hosted elsewhere, such as Cloudflare). Adding a simple
**DMARC** record helps as well:

```
_dmarc.airportfirst.vip  TXT  "v=DMARC1; p=none; rua=mailto:airportfirst1@gmail.com"
```

The mailer already does its part: `From` is always
`"Airport First Dispatch" <noreply@airportfirst.vip>` (the authenticated
mailbox), `Reply-To` is the guest's validated address, and each message has a
unique `Message-ID` on airportfirst.vip, a `Date`, `MIME-Version`, a plain
subject line, and a plain-text part that matches the HTML part. After go-live,
send one test inquiry and, in Gmail, open **⋮ → Show original**: SPF, DKIM
and DMARC should all say `PASS`.

### What the desk receives

Each inquiry email lists the booking, including **Est. distance** (for example
`~116 km (approx. 2h 40m) by road, CMB Airport to Kandy`), and a green
**Reply to Guest on WhatsApp** button. It opens a chat with the guest's number
(Sri Lankan `07…` numbers are converted to `+94`), pre-filled with their name,
route, distance and vehicle; add the price and tap send. The same route and
distance are stored with the inquiry in MySQL and appear in the guest's
WhatsApp copy.

The distance is measured in the browser: coordinates come from the place
dropdown (or a Photon lookup of the typed place, or GPS), and the road route
comes from the public OSRM server (`router.project-osrm.org`), with a
straight-line × 1.3 estimate if OSRM is slow. Pick-up defaults to CMB when left
empty. OSRM times exclude traffic, so treat them as a guide when quoting.
The public OSRM server is a free demo service without guarantees; for heavy
traffic, self-host OSRM or use a paid routing API.

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

**Bots.** A hidden `website` field (the honeypot) is invisible to people and
skipped by keyboard; a submission that fills it is answered as a success and
neither stored nor mailed.

**How often.** One submission per caller every 20 seconds, and at most 5 in 10
minutes, answered with `429` and a `Retry-After`. Counters live in
`af-throttle/` above the web root, keyed by a hash of the caller's IP rather
than the address itself, and are swept after a day.

The site is served through **Cloudflare**, so the connection's address
(`REMOTE_ADDR`) is normally a Cloudflare edge server shared by many guests. The
guest's real address arrives in `CF-Connecting-IP`, and the mailer uses it
**only when `REMOTE_ADDR` is inside Cloudflare's published ranges**
(`CLOUDFLARE_RANGES` in `af-common.php`, from cloudflare.com/ips). Anyone
calling the server directly with a made-up header is still keyed on their own
address. `X-Forwarded-For` is never trusted. If the host already rewrites
`REMOTE_ADDR` to the visitor's address, that works too. Refresh the range list
if Cloudflare ever announces new ranges. IPv6 callers are counted per /64. If
the store cannot be written the limiter fails open and logs it — a broken
counter must never stop a real booking.

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
3. **Send in the background** — one `fetch` to `send-mail.php` (§3), which
   stores the inquiry in MySQL and emails the desk.
4. **Report honestly** — the status pill on the card settles to:
   - 🟢 *Sent to our dispatch team · Ref AF-…* — **green only when a mail
     endpoint returned a genuine success** (HTTP 2xx *and* its own `ok`/`success` flag).
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
mailer will 501 because Python does not execute PHP, and the reviews section
stays empty. For the database locally, put an `airportfirst-db.php` in the
folder *above* the project pointing at a local MySQL/MariaDB.

## 6. Known follow-ups

- **Tailwind runs from the CDN.** Fine for launch; for best performance build
  the stylesheet with the Tailwind CLI and ship a static `.css`.
- **Place search relies on a free public service.** Photon's public server is
  free with fair-use limits and no uptime guarantee. If traffic grows a lot,
  self-host Photon or move to a paid geocoder. Free-typed addresses always pass
  through to the email and the database unchanged.
- **A transparent or reversed logo would improve the footer.** The current file
  is a white-background JPEG, so it sits on a white plate there (see §0).
- **Destination photography is stock.** The cards use Unsplash imagery chosen
  to match each place; swap in the client's own photos when available.
