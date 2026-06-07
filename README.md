# FOSSBilling TPP Wholesale Registrar Module

A domain registrar adapter for [FOSSBilling](https://fossbilling.org) that integrates with [TPP Wholesale](https://www.tppwholesale.com.au), the leading ANZ domain registrar.

> **Built for New Zealand and Australian hosting businesses.** If you resell domains through TPP Wholesale and run FOSSBilling as your billing platform, this module connects the two automatically.

---

## Features

- Domain availability checking against the TPP Wholesale registry
- Domain registration (.co.nz, .nz, .com.au, .au, .com and more)
- Domain renewal
- Domain transfers (inbound)
- Nameserver management
- Contact (WHOIS) management
- Domain locking/unlocking
- EPP/auth code retrieval for outbound transfers
- **Test mode** — log all API calls without registering real domains
- Auto-derives TPP console account reference from API User ID

---

## Requirements

- [FOSSBilling](https://fossbilling.org) 0.8.2 or later
- PHP 8.3 or later
- A TPP Wholesale reseller account
- TPP Legacy API credentials (Account No, Login, Password)

---

## Installation

### Step 1 — Download the module

Download the latest release from the [GitHub releases page](https://github.com/grant436/fossbilling-tpp-wholesale/releases).

Inside the zip you will find `TPPWholesale.php`. This is the only file you need.

### Step 2 — Copy to your FOSSBilling installation

Copy `TPPWholesale.php` to:

```
/library/Registrar/Adapter/TPPWholesale.php
```

If you are running FOSSBilling in Docker, copy it into the container:

```bash
docker cp TPPWholesale.php fossbilling:/var/www/html/library/Registrar/Adapter/TPPWholesale.php
```

Or download directly from GitHub into the container:

```bash
docker exec fossbilling curl -L \
  "https://github.com/grant436/fossbilling-tpp-wholesale/releases/download/v1.0.0/TPPWholesale.php" \
  -o /var/www/html/library/Registrar/Adapter/TPPWholesale.php
```

### Step 3 — Install in FOSSBilling

1. Log into your FOSSBilling admin panel
2. Go to **Domain Management → Registrars**
3. Click **New Domain Registrar**
4. Select **TPPWholesale** from the list
5. Click the **cog icon** next to TPPWholesale
6. Enter your TPP API credentials (see [Configuration](#configuration) below)
7. Click **Save**

### Step 4 — Assign the registrar to your TLDs

1. Go to **Domain Management → Top Level Domains**
2. Edit each TLD you want to use TPP Wholesale for
3. Set the **Registrar** dropdown to **TPPWholesale**
4. Save

---

## Configuration

| Field                         | Description                                                                          | Required |
| **Account Number**            | Your TPP numeric account number                                                      | Yes      |
| **User ID**                   | Your TPP API login (e.g. `SER-993-API`)                                              | Yes      |
| **Password**                  | Your TPP API password                                                                | Yes      |
| **Console Account Reference** | Your TPP account reference (e.g. `SER-993`). Leave blank to auto-derive from User ID | No       |
| **Enable Test Mode**          | Logs all API calls without sending them to TPP                                       | No       |

### Finding Your TPP API Credentials

1. Log into your [TPP Wholesale console](https://theconsole.tppwholesale.com.au)
2. Go to **Account Settings → API Preferences**
3. Find the **API Login Information (Legacy)** section
4. Note the following fields:
   - **Account No** — a numeric ID (e.g. `1001148`)
   - **Login** — your API username (e.g. `SER-993-API`)
   - **Password** — your API password

> **Note:** These are your legacy API credentials, not your regular console login credentials. They are found specifically under API Preferences.

### Console Account Reference

The Console Account Reference (e.g. `SER-993`) determines which account in your TPP reseller profile new domains are created under. 

If you leave this field blank, the module will **automatically derive** it from your User ID by stripping the `-API` suffix:

- User ID: `SER-993-API` → Account Reference: `SER-993`

This means in most cases you can leave the field blank.

### Recommended Nameservers

Set your default nameservers in **Domain Management → Nameservers** to TPP's current nameservers:

```
ns1.partnerconsole.net
ns2.partnerconsole.net
ns3.partnerconsole.net
```

These will be passed automatically to TPP when registering new domains.

---

## Test Mode

Test mode allows you to verify your configuration and see exactly what the module sends to TPP — without registering real domains or incurring costs.

### Enabling Test Mode

1. Go to **Domain Management → Registrars**
2. Click the **cog icon** next to TPPWholesale
3. Enable **Test Mode**
4. Save

### What Test Mode Does

When test mode is active:

- All API calls are **logged but not sent** to TPP
- Authentication returns a fake session ID
- Contact creation returns a fake contact ID
- Domain registration, renewal and transfers are simulated
- All log entries are prefixed with `[TEST MODE]`

### Viewing Test Mode Logs

All module activity — including test mode output — is written to the FOSSBilling **event log**:

```
/data/log/event/event-YYYY-MM-DD.log
```

To tail the log in real time (Docker installation):

```bash
docker exec fossbilling tail -f /var/www/html/data/log/event/event-2026-06-07.log
```

Replace the date with today's date.

### Example Test Mode Log Output

```
[TEST MODE] TPP registerDomain: example.co.nz for 1 year(s)
[TEST MODE] TPP authenticate: skipping — test mode active, returning fake session ID
[TEST MODE] TPP createContact details: {"firstName":"Jane","lastName":"Smith",...}
[TEST MODE] TPP createContact params: {"SessionID":"TEST-SESSION-ID","Type":"Domains",...}
[TEST MODE] TPP httpGet [TEST MODE — not sent]: https://theconsole.tppwholesale.com.au/api/order.pl?...
[TEST MODE] TPP registerDomain nameservers: ["ns1.partnerconsole.net","ns2.partnerconsole.net"]
[TEST MODE] TPP registerDomain full query: SessionID=TEST-SESSION-ID&Type=Domains&...&AccountOption=CONSOLE&AccountID=SER-993&...
[TEST MODE] TPP registerDomain: test mode — domain NOT registered with TPP
```

This shows you the exact API call that would have been sent, including all parameters, nameservers and account reference — without spending any money.

---

## Log Files

All module activity is written to the FOSSBilling event log. This is the primary place to look when troubleshooting.

### Log Location

```
/data/log/event/event-YYYY-MM-DD.log
```

### Viewing Logs

**Docker installation:**
```bash
docker exec fossbilling tail -50 /var/www/html/data/log/event/event-2026-06-07.log
```

**Standard installation:**
```bash
tail -50 /path/to/fossbilling/data/log/event/event-2026-06-07.log
```

### What Gets Logged

In normal (live) mode the following is logged for every operation:

- Method called and domain name
- TPP authentication response
- Contact details being sent to TPP
- Full API query string
- TPP API response
- Order ID or contact ID returned by TPP

### Example Live Log Output

```
TPP registerDomain: example.co.nz for 1 year(s)
TPP authenticate response: OK: abc123sessionid
TPP createContact details: {"firstName":"Jane","lastName":"Smith",...}
TPP createContact params: {"SessionID":"abc123","Type":"Domains",...}
TPP createContact response: OK: 1000123456
TPP createContact: created contact ID 1000123456
TPP registerDomain nameservers: ["ns1.partnerconsole.net","ns2.partnerconsole.net"]
TPP registerDomain full query: SessionID=abc123&Type=Domains&Object=Domain&Action=Create&...
TPP registerDomain response: OK: 26863251
TPP registerDomain: order placed, Order ID: 26863251
```

---

## Supported TLDs

Supports all TLDs available through TPP Wholesale. Commonly used TLDs include:

| TLD       | Notes                                     |
| `.co.nz`  | New Zealand — no eligibility requirements |
| `.nz`     | New Zealand — no eligibility requirements |
| `.com.au` | Australia — requires ABN/ACN (see below)  |
| `.net.au` | Australia — requires ABN/ACN              |
| `.org.au` | Australia — requires ABN/ACN              |
| `.au`     | Australia — requires ABN/ACN              |
| `.com`    | Generic — no eligibility requirements     |
| `.net`    | Generic — no eligibility requirements     |
| `.org`    | Generic — no eligibility requirements     |

---

## Australian Domain Requirements (.au, .com.au)

Australian domains require eligibility details at registration time. The module currently uses sensible defaults for business registrants:

| Field              | Default Value                               |
| Registrant ID Type | ABN (code `2`)                              |
| Eligibility Type   | Company (code `5`)                          |
| Eligibility Reason | Close and substantial connection (code `2`) |

The ABN/ACN value is read from a custom order form field named `abn`. If this field is not present on the order form, the registration will be submitted with an empty ABN which may be rejected by TPP for `.au` domains.

> **Future improvement:** A custom order form for `.au` domains that collects ABN/ACN from the customer is planned.

---

## Troubleshooting

### Authentication Failed (ERR: 102)

**Cause:** Incorrect API credentials.

**Fix:**
1. Log into the TPP console
2. Go to **Account Settings → API Preferences → API Login Information (Legacy)**
3. Verify the Account No, Login and Password match exactly what you entered in FOSSBilling
4. Note: these are separate from your regular console login credentials

### Domain Registration Failed (ERR: 603)

**Cause:** Invalid eligibility fields — most commonly occurs with `.au` domains missing ABN/ACN.

**Fix:** Ensure the customer's ABN is being collected and passed. For now, `.au` domains may need to be registered manually in the TPP console.

### Nameservers Not Being Passed

**Cause:** Default nameservers not configured in FOSSBilling.

**Fix:**
1. Go to **Domain Management → Nameservers**
2. Add `ns1.partnerconsole.net`, `ns2.partnerconsole.net`, `ns3.partnerconsole.net`
3. Save
4. Enable test mode and trigger a test registration — check the log for `TPP registerDomain nameservers:` to confirm they are being passed

### Domain Stuck on "Waiting Registrar Approval"

**Cause:** TPP performs manual checks on new reseller accounts for the first few domain registrations.

**Fix:** This resolves automatically once TPP has verified your account. Contact TPP support if it persists beyond a business day.

### Orders Not Activating Automatically After Payment

**Cause:** The payment gateway IPN (webhook) is not reaching FOSSBilling, so the payment is not being confirmed automatically.

**Fix:**
- **Stripe:** Check your Stripe webhook endpoint is set to `https://yourdomain.com/ipn.php?gateway_id=X`
- **PayPal:** Check your PayPal IPN URL is set correctly under Account Settings → Notifications → Instant Payment Notifications
- Check the event log for `Received transaction` entries

### Contact Has No Address or Phone

**Cause:** The customer's FOSSBilling profile is incomplete.

**Fix:** Make phone number, address, city, postcode and country required fields under **Settings → Client → Required Profile Fields**. This ensures TPP always receives complete contact information.

---

## Known Limitations

| Limitation                        | Detail                                                                                            |
| **No WHOIS privacy**              | TPP Wholesale does not offer WHOIS privacy protection. The module returns `true` for privacy calls to avoid breaking FOSSBilling workflow.   |
| **No direct domain deletion**     | TPP does not support domain deletion via API. Deletion requests are logged but must be completed manually in the TPP console.              |
| **No .nz contact updates**        | TPP's API does not support contact updates for `.nz` domains. Contact changes for `.nz` must be made manually in the TPP console.         |
| **Australian domain eligibility** | Full ABN/ACN collection via the FOSSBilling order form is not yet implemented. `.au` domains currently use default eligibility values. |
| **Session-based authentication**  | TPP uses session-based auth (not API keys). Sessions expire after 15 minutes. The module authenticates fresh for each API call.    |

---

## Contributing

Contributions are welcome, particularly around:

- `.au` domain eligibility — collecting ABN/ACN from the customer order form
- Additional TLD-specific handling
- Unit test coverage

### How to Contribute

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Make your changes
4. Commit: `git commit -m "Add your feature"`
5. Push: `git push origin feature/your-feature`
6. Open a pull request

### Reporting Issues

Please open an issue at [github.com/grant436/fossbilling-tpp-wholesale/issues](https://github.com/grant436/fossbilling-tpp-wholesale/issues) and include:

- Your FOSSBilling version
- The relevant section of your event log
- The domain TLD you were registering
- The error message or unexpected behaviour

---

## License

Apache 2.0 — see [LICENSE](LICENSE)

---

## Author

Built by Grant Charsley, [ServMe IT Limited](https://www.servmeit.co.nz) — NZ-based managed service provider.

TPP Wholesale is a trademark of TPP Wholesale Pty Ltd. This module is not officially affiliated with TPP Wholesale.