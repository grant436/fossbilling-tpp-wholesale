# FOSSBilling TPP Wholesale Registrar Module

A domain registrar adapter for [FOSSBilling](https://fossbilling.org) that integrates with [TPP Wholesale](https://www.tppwholesale.com.au), the leading ANZ domain registrar.

## Features

- Domain availability checking
- Domain registration (.co.nz, .nz, .com.au, .au, .com and more)
- Domain renewal
- Domain transfers
- Nameserver management
- Contact management
- Domain locking/unlocking
- EPP/auth code retrieval
- Auto-derives TPP console account reference from API credentials

## Requirements

- FOSSBilling 0.8.2 or later (PHP 8.3+)
- TPP Wholesale reseller account with API access enabled
- TPP Legacy API credentials (Account No, Login, Password)

## Installation

1. Download `TPPWholesale.php`
2. Copy it to `/library/Registrar/Adapter/TPPWholesale.php` in your FOSSBilling installation
3. In FOSSBilling admin go to **Domain Management → Registrars**
4. Click **New Domain Registrar** and select **TPPWholesale**
5. Click the cog icon and enter your TPP API credentials

## Configuration

| Field | Description | Required |
|---|---|---|
| Account Number | Your TPP account number (found in API Login Credentials) | Yes |
| User ID | Your TPP API login (e.g. SER-993-API) | Yes |
| Password | Your TPP API password | Yes |
| Console Account Reference | Your TPP account reference (e.g. SER-993). Leave blank to auto-derive from User ID | No |

### Finding Your TPP API Credentials

1. Log into your TPP Wholesale console
2. Go to **Account Settings → API Preferences**
3. Find the **API Login Information (Legacy)** section
4. Use the Account No, Login and Password shown there

## Supported TLDs

Supports all TLDs available through TPP Wholesale including:

- `.co.nz` `.nz` — New Zealand domains
- `.com.au` `.net.au` `.org.au` `.au` — Australian domains (requires ABN/ACN)
- `.com` `.net` `.org` — Generic TLDs
- And many more

## Notes

- TPP Wholesale does not support WHOIS privacy protection
- Domain deletion must be completed manually in the TPP console
- `.nz` domains do not support contact updates via API
- Australian `.au` domains require ABN/ACN eligibility details

## Contributing

Contributions welcome — particularly around:
- `.au` domain eligibility field improvements
- Additional TLD-specific handling
- Test coverage

## License

Apache 2.0 — see [LICENSE](LICENSE)

## Author

Built by Grant Charsley, [ServMe IT Limited](https://www.servmeit.co.nz) — NZ-based managed service provider.