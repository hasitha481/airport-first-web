/**
 * send-inquiry-email
 * -----------------------------------------------------------------------------
 * Receives an inquiry from the website form and emails the dispatch desk via
 * Resend.
 *
 * The Resend API key lives here as a function secret, never in index.html:
 * Resend does not accept cross-origin calls from a browser, and a key shipped
 * in page source can be read by anyone and used to send mail as this domain.
 *
 * Deploy:
 *   supabase functions deploy send-inquiry-email
 *   supabase secrets set RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxxxxxx
 */

const RESEND_ENDPOINT = "https://api.resend.com/emails";
const FROM_ADDRESS = "Airport First <inquiries@airportfirst.lk>"; // must be a verified Resend domain
const DEFAULT_TO = "mhasitha481@gmail.com";

const CORS_HEADERS = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};

interface Inquiry {
  name?: string;
  phone?: string;
  email?: string;
  pickup?: string;
  dropoff?: string;
  date?: string;
  time?: string;
  flight?: string;
  pax?: string;
  vehicle?: string;
  notes?: string;
  source?: string;
  created_at?: string;
}

const escapeHtml = (value: unknown): string =>
  String(value ?? "").replace(/[&<>"]/g, (c) =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c] as string)
  );

function renderEmail(inquiry: Inquiry): string {
  const rows: Array<[string, string | undefined]> = [
    ["Name", inquiry.name],
    ["Phone / WhatsApp", inquiry.phone],
    ["Email", inquiry.email],
    ["Pick-up", inquiry.pickup],
    ["Drop-off", inquiry.dropoff],
    ["Date", inquiry.date],
    ["Arrival time", inquiry.time],
    ["Flight number", inquiry.flight],
    ["Passengers", inquiry.pax],
    ["Vehicle preference", inquiry.vehicle],
    ["Notes", inquiry.notes],
    ["Source", inquiry.source],
  ];

  const body = rows
    .filter(([, value]) => value)
    .map(
      ([label, value]) =>
        `<tr>
           <td style="padding:8px 14px;color:#64748B;font-size:13px;white-space:nowrap">${escapeHtml(label)}</td>
           <td style="padding:8px 14px;color:#0F172A;font-size:14px;font-weight:600">${escapeHtml(value)}</td>
         </tr>`
    )
    .join("");

  return `<!doctype html>
<html><body style="margin:0;background:#FAFAFB;font-family:'Plus Jakarta Sans',Inter,Arial,sans-serif">
  <div style="max-width:620px;margin:24px auto;background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:16px;overflow:hidden">
    <div style="background:#C8102E;padding:20px 24px">
      <div style="color:#fff;font-size:19px;font-weight:800;font-style:italic">Airport First</div>
      <div style="color:rgba(255,255,255,.75);font-size:10px;letter-spacing:.34em;text-transform:uppercase;margin-top:4px">Feel the Ambience</div>
    </div>
    <div style="padding:22px 10px">
      <p style="margin:0 14px 14px;color:#0F172A;font-size:15px;font-weight:700">New website inquiry</p>
      <table style="width:100%;border-collapse:collapse">${body}</table>
      <p style="margin:18px 14px 0;color:#64748B;font-size:12px">
        Reply within 15 minutes to hold the best-rate guarantee.
      </p>
    </div>
  </div>
</body></html>`;
}

Deno.serve(async (req: Request) => {
  if (req.method === "OPTIONS") {
    return new Response("ok", { headers: CORS_HEADERS });
  }
  if (req.method !== "POST") {
    return new Response(JSON.stringify({ error: "Method not allowed" }), {
      status: 405,
      headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
    });
  }

  const apiKey = Deno.env.get("RESEND_API_KEY");
  if (!apiKey) {
    return new Response(JSON.stringify({ error: "RESEND_API_KEY is not set" }), {
      status: 500,
      headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
    });
  }

  try {
    const { to, inquiry } = (await req.json()) as { to?: string; inquiry: Inquiry };

    const subject = `New inquiry — ${inquiry?.name ?? "Website guest"} · ${
      inquiry?.pickup ?? "?"
    } to ${inquiry?.dropoff ?? "?"}`;

    const resendResponse = await fetch(RESEND_ENDPOINT, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${apiKey}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        from: FROM_ADDRESS,
        to: [to ?? DEFAULT_TO],
        reply_to: inquiry?.email || undefined,
        subject,
        html: renderEmail(inquiry ?? {}),
      }),
    });

    const result = await resendResponse.json();
    if (!resendResponse.ok) {
      return new Response(JSON.stringify({ error: "Resend rejected the message", result }), {
        status: 502,
        headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
      });
    }

    return new Response(JSON.stringify({ ok: true, id: result?.id }), {
      headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
    });
  } catch (error) {
    return new Response(JSON.stringify({ error: String(error) }), {
      status: 400,
      headers: { ...CORS_HEADERS, "Content-Type": "application/json" },
    });
  }
});
