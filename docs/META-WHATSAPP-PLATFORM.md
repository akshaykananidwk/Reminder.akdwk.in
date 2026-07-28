# WhatsApp Business Platform — official Meta Cloud API

This is now the **only** way Krishna Reminder sends WhatsApp. The
`bulk.akdwk.in` gateway has been taken out of the send path.

Everything below runs against the official Graph API at
`graph.facebook.com`. There is no WhatsApp Web automation anywhere in this
codebase, no unofficial library, and no third-party gateway.

---

## 0. What "removed" means, precisely

`meta_only_mode` is a setting, and it defaults to **on**. With it on,
`WhatsAppService::providerOrder()` returns `['cloud']` and nothing else —
the bulk gateway is not tried even if its endpoint, API key and session id
are still sitting in the settings table.

Those old credentials are deliberately **not deleted**. If a number turns
out not to be registered with the Cloud API yet, turning `meta_only_mode`
off is the only thing standing between a business and a day of undelivered
reminders. Deleting the credentials would take that away for no benefit —
they are unreachable either way.

Turn it off only as an emergency measure, and turn it back on once the
number is registered.

---

## 1. Connecting an account

**Admin → WhatsApp Platform → Connect WhatsApp.**

Two paths, both complete:

### Embedded Signup (recommended)

Requires a Meta app of type *Business* with the WhatsApp product added,
and an Embedded Signup configuration. Put the App ID, App Secret and
configuration ID into **Admin → WhatsApp Platform → Meta app**.

The customer presses *Connect WhatsApp*, and everything after that happens
inside Meta's own dialog — they pick or create the Business, the WhatsApp
Business Account and the phone number. Nothing is typed into this site and
no Facebook password is ever seen by it.

When the dialog finishes, this application does the rest, in this order:

1. **Exchange the authorisation code for a token.** Server-side, always.
   The exchange needs the app secret, and an app secret in JavaScript is an
   app secret published.
2. **Inspect the token** with `debug_token`, which is how the WABA id is
   discovered when the browser did not report it.
3. **Store the account**, with the access token encrypted (AES-256-GCM,
   the same key as every other secret in this install).
4. **Subscribe our app to the WABA's webhooks.** This is the step everyone
   forgets. Without it, a perfectly configured callback URL receives
   nothing at all, which looks exactly like a broken webhook and costs an
   afternoon.
5. **Sync the phone numbers**, with quality rating and messaging tier.
6. **Register the number** with the Cloud API using its six-digit two-step
   PIN. Until this succeeds, every send answers `133010`.
7. **Sync templates**, so anything created in Meta's dashboard is usable
   here immediately.

Each step reports its own result on screen. A connection that stored a
token but failed to subscribe looks identical to a working one until the
first webhook does not arrive, so the difference is shown rather than
hidden behind a single tick.

### System User token

For a business that already has a permanent token. Paste the token, the
WABA id (optional — discovered from the token when the app credentials are
set) and optionally the app secret. Steps 3–7 above run identically.

This exists so nobody has to wait for Meta app review to use the product.

---

## 2. The 24-hour rule

Meta accepts a **free-form** message only within **24 hours of that
person's last message to you**. Outside it, every free-form send is
rejected with `131047` and the reminder never arrives.

A reminder app is proactive by definition — "remind me tomorrow at 10"
fires roughly a day after the person last wrote. So most sends are outside
the window, and **without an approved template most reminders would simply
not arrive.**

`WhatsAppService::sendViaMeta` therefore checks the window on every send:

* **Inside** → plain text (or an image with a caption).
* **Outside** → the approved template configured in settings; failing
  that, any approved `UTILITY` template with a single variable, preferring
  the user's own language.
* **Outside, with no approved template at all** → the send fails
  immediately and loudly, naming what to do about it, rather than being
  handed to Meta to reject into a log nobody reads.

---

## 3. Templates

**Admin → WA templates.**

Create, validate, submit, edit, sync, clone, import, export, delete.

Validation happens here, before submission, because Meta rejects days
later without naming the reason. Caught locally:

* Variables numbered with a gap — `{{1}}` then `{{3}}`.
* A body that begins or ends with a variable.
* A header with more than one variable.
* A name with capitals, spaces or punctuation (and names are permanent —
  a typo means a new template and another wait).
* A footer over 60 characters, a body over 1024.
* A URL button with no URL, a call button with no number.

At send time, `WaTemplateService::parameters()` flattens every value:
Meta rejects a parameter containing a newline, a tab, or four or more
consecutive spaces, with error `132005`, which names none of those things.

Approval status is mirrored locally and never assumed. It is updated two
ways: the `message_template_status_update` webhook in real time, and
`cron/meta_sync.php` as the reconciliation pass for approvals that arrived
while the site was down.

---

## 4. Webhooks

One URL serves everything:

```
https://reminder.akdwk.in/api/wa_webhook.php
```

Verify token: shown on the WhatsApp Platform page. Subscribe to at least
`messages`, `message_template_status_update` and
`phone_number_quality_update`.

Meta signs every body with `X-Hub-Signature-256`, HMAC-SHA256 using the
**app secret** — not the verify token, and not this application's own
webhook secret. Verification tries the connected account's own secret
first, then the platform app secret, so a customer who connected with
their own Meta app is verified against theirs.

Handling, in order:

1. **Store the raw event**, then process it. If processing throws, the
   event is still on disk and `cron/meta_sync.php` replays it. Without
   this, a bad deploy silently loses every billing record that arrived
   during it.
2. **Split** one body into its independent facts. A single `value` can
   carry three status receipts and two inbound messages; each becomes its
   own event with its own dedup key, so one bad message cannot lose the
   other four.
3. **Deduplicate** on a key derived from the event itself — `in_<wamid>`,
   `st_<wamid>_<status>`, template id plus decision. Meta redelivers
   anything it does not get a 200 for, far more often than people expect.
4. **Never move a message backwards.** A retried `sent` receipt can arrive
   after `read`; a chat that flips from read back to sent is worse than one
   that is merely slow. A `failed` always wins.

Inbound messages are stored in full — every type, with media ids, mime
types, reply context and interactive selections intact. The reminder
pipeline only needs the text, but a conversation view needs the rest and
none of it can be recovered later: Meta does not let you read back a
message you failed to store.

---

## 5. Pricing and billing

**Admin → WA billing.**

Be clear about what this is. **Meta does not send the amount.** The status
webhook carries the pricing *category* (marketing / utility /
authentication / service) and the conversation id, and leaves the money to
its published rate card. Any product claiming to show you "Meta's
real-time price" is multiplying a category by a number somebody typed in.

This does the same thing and says so:

* Rates live in `wa_price_rates`, per country and category, maintained by
  the operator and seeded **at zero** — a wrong price shown with
  confidence is worse than no price at all.
* While the card is empty the page leads with a warning and reports the
  totals as unknown, not as free.
* An optional markup percentage is applied on top, for reselling.
* Meta bills per 24-hour conversation, not per message, so the charge
  lands on the conversation and on its first message. Summing the message
  table and summing the conversation table therefore agree.

---

## 6. Media

Uploads are keyed by SHA-256 of the file contents. The same PDF sent to
two hundred customers is uploaded once and the Meta media id reused, which
saves bandwidth, rate limit and seconds per send. The cache expires at 25
days — Meta drops media at about 30, and a send failing on an expired id is
worse than an upload we did not strictly need.

Inbound media is downloaded with the bearer token attached. A plain fetch
of a Meta media URL returns 401, which is the single most common reason
people conclude that inbound images "do not work". Downloads land in
`storage/`, which `.htaccess` denies over HTTP: an inbound photo is a
customer's private message, not a public asset.

---

## 7. Retries, and the one rule that matters

`MetaGraph` retries with exponential backoff and jitter on network
failures, 429s, 5xx and Meta's transient error codes.

**A send is never retried on a timeout.** The Cloud API has no idempotency
key. cURL reports a read timeout identically whether Meta never saw the
request or accepted it and answered slowly — and repeating the second case
delivers the same reminder twice and bills twice. So a non-idempotent call
is retried only when the server explicitly told us to come back (429 or
503), which it cannot have done after accepting the message.

GETs, DELETEs, template submissions, webhook subscriptions and media
uploads are all safe to repeat and are marked idempotent.

---

## 8. Security

* Access tokens and app secrets are encrypted at rest with AES-256-GCM.
  A blank field in the admin form means "keep the stored value", never
  "erase it".
* `wa_api_log` records every Graph call for support, with tokens stripped
  two ways: by key name (`access_token`, `app_secret`, …) and by pattern,
  wherever a token happens to sit — a URL query string, a Bearer header,
  an error message that echoes the request. The destination number
  survives, because redaction is not deletion.
* Every admin route requires an admin; every state-changing route carries
  a CSRF token.
* Webhook signatures are verified; events that arrive unverified are
  counted and shown on the log page.
* Disconnecting an account clears its token and marks it disconnected. It
  does **not** delete it — messages, conversations and invoices reference
  it, and a business that reconnects tomorrow should still see last
  month's costs.

---

## 9. Multi-tenancy

Every Meta-facing row is addressed by a `waba_accounts` row, and each
account carries its own token, app secret, phone numbers, templates,
webhooks, conversations and billing. `owner_user_id` is the tenant; `NULL`
means the platform's own account.

`WabaAccountService::forUser()` resolves a tenant's own account first and
falls back to the platform account, so a single-business install needs no
configuration and a multi-business one cannot cross the streams. Send
methods take the account explicitly rather than looking one up globally,
which is what makes that guarantee real rather than aspirational.

---

## 10. Cron

```cron
*/15 * * * * /usr/bin/php /path/to/cron/meta_sync.php >/dev/null 2>&1
```

Webhooks do the real-time work. This is the safety net for what webhooks
lose: template decisions that arrived during a deploy, quality ratings that
change without a webhook, events that threw while being processed, and
expired media ids. It also warns when a number drops to RED quality, which
is the earliest signal that throughput is about to be cut.

---

## 11. Where things live

| Concern | File |
|---|---|
| Graph transport, retries, redaction, error text | `app/services/MetaGraph.php` |
| Accounts, tokens, numbers, webhook subscription | `app/services/WabaAccountService.php` |
| Sending every message type | `app/services/MetaMessageService.php` |
| Inbound, receipts, template and account events | `app/services/MetaWebhookService.php` |
| Templates | `app/services/WaTemplateService.php` |
| Media upload/download/cache | `app/services/MetaMediaService.php` |
| Pricing and billing | `app/services/MetaPricingService.php` |
| Embedded Signup | `app/services/MetaSignupService.php` |
| Admin pages | `app/controllers/admin/MetaController.php` |
| Schema | `database/migrations/2026_07_28_000004_meta_platform.sql` |
| Tests | `tests/verify_meta_platform.php` |

---

## 12. Ready for what comes next

The tables and the transport were built with the next products in mind, so
none of them needs a rewrite:

* **CRM / ERP** — `wa_messages` holds the full conversation with contact,
  direction, type and reply threading.
* **AI chatbot** — inbound of every type is stored with its interactive
  selections; replies go out through the same send API.
* **Flows, Catalog, Payments** — all are Graph endpoints under the same
  account and token, and `MetaGraph::call()` already handles auth, logging,
  retries and error translation for them.
