/**
 * send-inquiry-email — DISABLED
 * -----------------------------------------------------------------------------
 * Not used by the site: inquiries are mailed by send-mail.php on the cPanel
 * host. This function is kept only for a possible move off cPanel, and it
 * answers every request with 410 Gone until ENABLED below is set to true.
 *
 * If it was ever deployed, delete it from the project as well:
 *   supabase functions delete send-inquiry-email
 *
 * Before enabling it, note what an earlier version got wrong and this one
 * fixes: the recipient came from the request body and any website could call
 * it, which made it an open mail relay for anyone holding the public anon key.
 * Now the recipient is fixed, only this site's origins are allowed, field
 * sizes are capped, and errors say nothing about the internals.
 *
 * Deploy (only once ENABLED is true):
 *   supabase functions deploy send-inquiry-email
 *   supabase secrets set RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxxxxxx
 */

const ENABLED = false;

const RESEND_ENDPOINT = "https://api.resend.com/emails";
const FROM_ADDRESS = "Airport First <noreply@airportfirst.vip>"; // must be a verified Resend domain
const MAIL_TO = "mhasitha481@gmail.com"; // TESTING — airportfirst1@gmail.com for go-live. Never taken from the request.

const ALLOWED_ORIGINS = ["https://airportfirst.vip", "https://www.airportfirst.vip"];
const MAX_BODY_BYTES = 16384;

const FIELD_LIMITS: Record<string, number> = {
  name: 120, phone: 60, email: 160, pickup: 200, dropoff: 200, date: 40,
  time: 40, flight: 40, pax: 20, vehicle: 120, notes: 1200, source: 60,
};

const LABELS: Array<[string, string]> = [
  ["name", "Name"],
  ["phone", "Phone / WhatsApp"],
  ["email", "Email"],
  ["pickup", "Pick-up"],
  ["dropoff", "Drop-off"],
  ["date", "Date"],
  ["time", "Arrival time"],
  ["flight", "Flight number"],
  ["pax", "Passengers"],
  ["vehicle", "Vehicle"],
  ["notes", "Notes"],
  ["source", "Source"],
];

const EMAIL_RE = /^[^\s@<>"]{1,64}@[A-Za-z0-9.-]{1,190}\.[A-Za-z]{2,24}$/;

function corsHeaders(origin: string | null): Record<string, string> {
  const headers: Record<string, string> = {
    "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Vary": "Origin",
  };
  if (origin && ALLOWED_ORIGINS.includes(origin)) {
    headers["Access-Control-Allow-Origin"] = origin;
  }
  return headers;
}

function json(status: number, body: unknown, origin: string | null): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...corsHeaders(origin), "Content-Type": "application/json" },
  });
}

const escapeHtml = (value: string): string =>
  value.replace(/[&<>"']/g, (c) =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c] as string)
  );

/** Strings only, trimmed, capped; line breaks survive in the notes alone. */
function clean(input: Record<string, unknown>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, max] of Object.entries(FIELD_LIMITS)) {
    const raw = input[key];
    if (typeof raw !== "string" && typeof raw !== "number") continue;
    let value = String(raw).trim();
    value = key === "notes"
      ? value.replace(/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/g, " ")
      : value.replace(/[\x00-\x1F\x7F]/g, " ");
    value = value.slice(0, max).trim();
    if (value) out[key] = value;
  }
  return out;
}

function renderEmail(inquiry: Record<string, string>): string {
  const rows = LABELS
    .filter(([key]) => inquiry[key])
    .map(([key, label]) =>
      `<tr><td valign="top"><strong>${escapeHtml(label)}</strong></td>` +
      `<td valign="top">${escapeHtml(inquiry[key]).replace(/\r?\n/g, "<br>")}</td></tr>`
    )
    .join("");
  return `<!doctype html><html><body>` +
    `<p><strong>New website inquiry</strong> — Airport First</p>` +
    `<table cellpadding="4" cellspacing="0" border="0">${rows}</table>` +
    `<p>Reply within 15 minutes to hold the best-rate guarantee.</p>` +
    `</body></html>`;
}

Deno.serve(async (req: Request) => {
  const origin = req.headers.get("Origin");

  if (!ENABLED) {
    return json(410, { ok: false, error: "This endpoint is not in use" }, origin);
  }

  if (req.method === "OPTIONS") {
    return new Response(null, { status: 204, headers: corsHeaders(origin) });
  }
  if (req.method !== "POST") {
    return json(405, { ok: false, error: "Method not allowed" }, origin);
  }
  if (!origin || !ALLOWED_ORIGINS.includes(origin)) {
    return json(403, { ok: false, error: "Request rejected" }, origin);
  }

  const apiKey = Deno.env.get("RESEND_API_KEY");
  if (!apiKey) {
    console.error("[send-inquiry-email] RESEND_API_KEY is not set");
    return json(500, { ok: false, error: "The dispatch desk could not be reached" }, origin);
  }

  try {
    const raw = await req.text();
    if (new TextEncoder().encode(raw).length > MAX_BODY_BYTES) {
      return json(413, { ok: false, error: "Request too large" }, origin);
    }
    const parsed = JSON.parse(raw);
    /* Older callers wrapped the fields as { inquiry: {...} }; accept both. A
       `to` in the body is ignored: the recipient is MAIL_TO and nothing else. */
    const source = parsed && typeof parsed === "object"
      ? (parsed.inquiry && typeof parsed.inquiry === "object" ? parsed.inquiry : parsed)
      : {};
    const inquiry = clean(source as Record<string, unknown>);

    if (!inquiry.name || (!inquiry.phone && !inquiry.email)) {
      return json(422, { ok: false, error: "Name and a phone or email are required" }, origin);
    }

    const replyTo = inquiry.email && EMAIL_RE.test(inquiry.email) ? inquiry.email : undefined;
    const subject = `New inquiry — ${inquiry.name} · ${inquiry.pickup ?? "?"} to ${inquiry.dropoff ?? "?"}`;

    const resendResponse = await fetch(RESEND_ENDPOINT, {
      method: "POST",
      headers: { Authorization: `Bearer ${apiKey}`, "Content-Type": "application/json" },
      body: JSON.stringify({
        from: FROM_ADDRESS,
        to: [MAIL_TO],
        reply_to: replyTo,
        subject,
        html: renderEmail(inquiry),
      }),
    });

    if (!resendResponse.ok) {
      console.error("[send-inquiry-email] Resend refused:", resendResponse.status, await resendResponse.text());
      return json(502, { ok: false, error: "Mail server rejected the message" }, origin);
    }
    return json(200, { ok: true }, origin);
  } catch (error) {
    console.error("[send-inquiry-email] bad request:", error);
    return json(400, { ok: false, error: "Malformed request" }, origin);
  }
});
