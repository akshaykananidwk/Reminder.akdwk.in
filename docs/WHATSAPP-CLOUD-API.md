# WhatsApp Cloud API — second provider

Krishna Reminder can send through **two** WhatsApp providers, and both can be
configured at the same time:

| Provider | What it is | Limits |
|---|---|---|
| `bulk` | The existing `bulk.akdwk.in` gateway | None imposed by Meta |
| `cloud` | Meta's official WhatsApp Cloud API (`graph.facebook.com`) | **24-hour window**, template approval, per-conversation pricing |

**Admin → WhatsApp → Provider** picks which is tried first. Leave *"Fall back to
the other provider when this one fails"* on and the second one takes over
automatically when the first is unavailable — which is exactly what is wanted
while the bulk gateway's session is down.

Nothing about the bulk gateway changed. Turning the Cloud API on does not turn
it off, and turning the Cloud API off returns you to exactly the old behaviour.

---

## 1. Read this first: the 24-hour rule

This is the single thing that decides whether Cloud API works for a reminder
app, and it has no equivalent on the bulk gateway.

Meta only accepts a **free-form** message within **24 hours of that person's
last WhatsApp message to you**. Outside that window every free-form send is
rejected:

```
(#131047) Message failed to send because more than 24 hours have passed
since the customer last replied to this number.
```

A reminder is proactive by definition. "Remind me tomorrow at 10" means the
message goes out roughly 24 hours *after* the person last wrote to you — i.e.
almost always outside the window. **Without an approved template, most reminders
will simply not arrive.**

So the service handles it rather than letting it fail:

- It looks up that number's last inbound message in `wa_inbound_raw`.
- **Inside 24 hours** → normal free-form text, full Gujarati, no approval needed.
- **Outside 24 hours** → the approved template named in Admin → WhatsApp, with
  the reminder text passed as the body parameter.
- **Outside 24 hours and no template configured** → the send fails immediately
  with a message saying exactly that, instead of being handed to Meta to reject.

The bulk gateway has none of these restrictions, which is a good reason to keep
it as the primary once its session is back and use Cloud API as the fallback.

### Creating the template

Meta → WhatsApp Manager → **Message templates** → Create.

- **Category:** Utility (not Marketing — utility templates are cheaper and are
  approved for reminders)
- **Name:** e.g. `krishna_reminder` — this is what goes in Admin → WhatsApp
- **Language:** Gujarati (`gu`), and add Hindi (`hi`) and English (`en`) too
- **Body:** exactly one variable, for example

  ```
  🙏 જય શ્રી કૃષ્ણ

  {{1}}

  — Krishna Reminder, AK Computer
  ```

Approval usually takes a few minutes to a few hours. Until it is approved,
out-of-window sends will fail.

> A body parameter may not contain a newline, a tab, or four or more
> consecutive spaces — Meta rejects those. The reminder text is flattened to
> single spaces before it is sent, so this is handled for you.

---

## 2. What to collect from Meta

From **Meta for Developers → your app → WhatsApp → API Setup**:

| Field | Where | Notes |
|---|---|---|
| **Phone number ID** | API Setup | Digits only, e.g. `123456789012345`. **Not** the phone number. This is the most common mistake |
| **WhatsApp Business account ID** | API Setup | Optional |
| **Access token** | See below | |
| **App secret** | Settings → Basic | Signs the incoming webhook |

### Get a *permanent* access token

The token shown on the API Setup page is a **temporary 24-hour test token**. If
you paste that in, WhatsApp will stop working tomorrow with error 190. Create a
permanent one instead:

1. **Business Settings → Users → System users → Add** — create a system user
   with the **Admin** role.
2. **Add assets** → your WhatsApp app → grant **Full control**.
3. **Generate new token** → select your app → tick **`whatsapp_business_messaging`**
   and **`whatsapp_business_management`** → set expiry to **Never**.
4. Copy it once — Meta will not show it again.

---

## 3. Configure Krishna Reminder

**Admin → WhatsApp**, in the *Meta WhatsApp Cloud API* section:

- Access token, Phone number ID, App secret
- Graph version — `v23.0` unless you have a reason to change it
- Verify token — leave blank and one is generated when you save
- Template name and language — from step 1

Save, then click **Check connection**. It reads the phone number back from Meta
and sends nothing, so testing costs no conversation and messages nobody. A good
result names your verified business and its quality rating.

The token and the app secret are stored **AES-256-GCM encrypted** in the
`settings` table, like every other credential. They are never written to a
config file and never appear in a URL or an access log.

---

## 4. Point the webhook at the site

**Meta → your app → WhatsApp → Configuration → Edit**

| Field | Value |
|---|---|
| Callback URL | `https://reminder.akdwk.in/api/wa_webhook.php` |
| Verify token | the token shown in Admin → WhatsApp |

Click **Verify and save**, then under *Webhook fields* click **Manage** and
subscribe to **`messages`**. Without that subscription Meta accepts the URL and
then never sends anything to it.

Both providers share this one URL. The two are told apart by the payload itself:
Meta's carries `object: whatsapp_business_account`, so it is authenticated with
the app secret, while the bulk gateway keeps using `?secret=…`.

Copy both values from **Admin → WhatsApp → Inbound webhook — Meta Cloud API**;
there is a copy button next to each.

### Verify it worked

Send a WhatsApp message from your own phone to the business number, then check
**Admin → WhatsApp → Recent inbound**. The message should appear within seconds.

If it does not:

- `storage/logs/whatsapp-*.log` will say why.
- `Cloud API webhook accepted WITHOUT signature verification` means the app
  secret is not saved. It still works, but add the secret.
- Nothing at all in the log usually means the `messages` field is not subscribed.

---

## 5. Costs

Cloud API is not free. Meta bills **per 24-hour conversation**, not per message.
As of writing, in India, utility conversations are roughly ₹0.11–0.16 each, and
service (user-initiated) conversations are free.

For a reminder app that is one utility conversation per user per day at worst.
The bulk gateway has no per-message cost, which is another reason to keep it
primary and use Cloud API as the fallback.

---

## 6. Troubleshooting

| What you see | What it means |
|---|---|
| `131047` | Outside the 24-hour window — the template is missing or not approved |
| `190` / HTTP 401 | Token expired. You used the 24-hour test token; make a permanent System User token |
| `100` | Malformed request — usually a wrong phone number ID, or a template name/language that does not exist |
| `131030` | The recipient is not on the allowed list. While the app is in development mode, add the number under API Setup |
| `131026` | The destination cannot receive it — not on WhatsApp, or an unregistered business number |
| `133010` | The number is not registered with the Cloud API — finish registration in API Setup |
| `10` / `200` | The token lacks `whatsapp_business_messaging` |

Every one of these is translated into plain language before it reaches the
admin panel or the outbound log — you should never have to read a raw
`OAuthException`.

The **Via** column in Admin → WhatsApp → Outbound log shows which provider
handled each send, and for Cloud API whether it went as `text` or `template`.

---

## 7. Rotating a leaked token

If an access token is ever pasted somewhere it should not be — a chat, a
screenshot, a commit — treat it as compromised and rotate it immediately.
Anyone holding it can send WhatsApp messages as your business until it is
revoked:

1. **Business Settings → Users → System users** → select the user
2. Find the token → **Revoke**
3. **Generate new token** with the same two permissions
4. Paste the new one into Admin → WhatsApp and save

The old token stops working the moment it is revoked.

---

## 8. Verification

```bash
php tests/verify_cloud_api.php
```

39 assertions, no network needed. Covers the nested webhook envelope, delivery
receipts being ignored rather than stored as phantom messages, button/image/
interactive replies, the subscription handshake, app-secret signature
verification (including that the *verify token* is correctly **not** accepted
as the signing key), the 24-hour window logic, and the error translations.
