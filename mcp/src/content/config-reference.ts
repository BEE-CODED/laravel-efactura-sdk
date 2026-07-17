export const configReferenceContent = `# Laravel e-Factura SDK — Configuration Reference

Configuration file: \`config/efactura-sdk.php\`

Publish with:
\`\`\`bash
php artisan vendor:publish --tag=efactura-sdk-config
\`\`\`

---

## \`sandbox\`

| | |
|---|---|
| **Type** | boolean |
| **Environment variable** | \`EFACTURA_SANDBOX\` |
| **Default** | \`true\` |
| **Required** | No |

Controls which ANAF environment the SDK targets.

- \`true\` — Use the ANAF **test/sandbox** environment (\`https://api.anaf.ro/test/FCTEL/rest\`)
- \`false\` — Use the ANAF **production** environment (\`https://api.anaf.ro/prod/FCTEL/rest\`)

> Always keep \`true\` during development. Set \`false\` only in production.

---

## \`oauth\`

OAuth2 credentials obtained from [ANAF's OAuth2 system](https://www.anaf.ro/CompensareFacturi/).

### \`oauth.client_id\`

| | |
|---|---|
| **Type** | string |
| **Environment variable** | \`EFACTURA_CLIENT_ID\` |
| **Default** | none |
| **Required** | Yes |

The OAuth2 client ID issued by ANAF when registering your application.

### \`oauth.client_secret\`

| | |
|---|---|
| **Type** | string |
| **Environment variable** | \`EFACTURA_CLIENT_SECRET\` |
| **Default** | none |
| **Required** | Yes |

The OAuth2 client secret issued by ANAF when registering your application.

### \`oauth.redirect_uri\`

| | |
|---|---|
| **Type** | string (URL) |
| **Environment variable** | \`EFACTURA_REDIRECT_URI\` |
| **Default** | none |
| **Required** | Yes |

The callback URL registered with ANAF for OAuth2 authorization code flow. Must exactly match the URI registered in ANAF's developer portal.

---

## \`http\`

HTTP client settings for communicating with the ANAF API.

### \`http.timeout\`

| | |
|---|---|
| **Type** | integer (seconds) |
| **Environment variable** | \`EFACTURA_TIMEOUT\` |
| **Default** | \`30\` |
| **Required** | No |

Maximum number of seconds to wait for an API response before timing out.

### \`http.retry_times\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RETRY_TIMES\` |
| **Default** | \`3\` |
| **Required** | No |

Total number of attempts (**not** extra retries) for a failed HTTP request — \`3\` means one initial call plus up to two retries.

Read by **both \`EFacturaClient\` and \`AnafDetailsClient\`** (since v3.0.0 — previously \`AnafDetailsClient\` ignored it and was pinned to \`BaseApiClient\`'s hardcoded \`MAX_TRY_COUNT = 3\`). When the key is absent the hardcoded \`3\` still applies.

A **read** is retried when the transport fails outright or ANAF answers with status **0 or 5xx**. **4xx responses — including 429 — are never retried**; they raise \`ApiException\` on the first response.

> **Uploads are NOT covered by this key (since v3.0.0).** \`uploadDocument()\` / \`uploadB2CDocument()\`
> are non-idempotent and auto-retry only on transport errors that provably happened *before* the
> request left the machine (cURL errno 5, 6, 7, 35). A 5xx, a read timeout, or an unclassifiable
> transport error raises \`ApiException\` on the **first** attempt, no matter what \`retry_times\` says
> — ANAF issues a fresh \`index_incarcare\` per accepted POST, so a blind retry files the invoice
> twice. See the \`migration-v2-v3\` topic.

> **Each retry consumes global rate-limit quota (since v3.0.0).** ANAF meters per HTTP request, so
> every retry attempt calls \`checkGlobal()\` again. A tight \`global_per_minute\` can therefore surface
> a \`RateLimitExceededException\` mid-retry where v2 raised \`ApiException\` after exhausting attempts.

### \`http.retry_delay\`

| | |
|---|---|
| **Type** | integer (seconds) |
| **Environment variable** | \`EFACTURA_RETRY_DELAY\` |
| **Default** | \`5\` |
| **Required** | No |

Number of seconds to wait between retry attempts. This is a blocking \`sleep()\` on the calling process, and the delay is fixed — there is no backoff.

Read by **both \`EFacturaClient\` and \`AnafDetailsClient\`** (since v3.0.0 — previously \`AnafDetailsClient\` ignored it and was pinned to \`BaseApiClient\`'s hardcoded \`RETRY_DELAY = 5\` seconds). When the key is absent the hardcoded \`5\` still applies.

---

## \`logging\`

Logging configuration for API calls and debug output.

### \`logging.channel\`

| | |
|---|---|
| **Type** | string |
| **Environment variable** | \`EFACTURA_LOG_CHANNEL\` |
| **Default** | \`"efactura-sdk"\` |
| **Required** | No |

The Laravel logging channel to use for SDK log messages. Add a matching channel to \`config/logging.php\`:

\`\`\`php
'efactura-sdk' => [
    'driver' => 'daily',
    'path' => storage_path('logs/efactura-sdk.log'),
    'level' => 'debug',
    'days' => 30,
],
\`\`\`

### \`logging.debug\`

| | |
|---|---|
| **Type** | boolean |
| **Environment variable** | \`EFACTURA_DEBUG\` |
| **Default** | \`false\` |
| **Required** | No |

When \`true\`, enables verbose debug logging of all HTTP requests and responses.

---

## \`endpoints\`

Base URLs for ANAF API endpoints. These should not need to be changed unless ANAF updates their API.

### \`endpoints.api\`

| Key | URL |
|---|---|
| \`test\` | \`https://api.anaf.ro/test/FCTEL/rest\` |
| \`production\` | \`https://api.anaf.ro/prod/FCTEL/rest\` |

The active API base URL is selected automatically based on the \`sandbox\` config value.

### \`endpoints.oauth\`

| Key | URL |
|---|---|
| \`authorize\` | \`https://logincert.anaf.ro/anaf-oauth2/v1/authorize\` |
| \`token\` | \`https://logincert.anaf.ro/anaf-oauth2/v1/token\` |

OAuth2 authorization and token exchange endpoints.

### \`endpoints.services\`

Additional ANAF web service endpoints:

| Key | URL | Purpose |
|---|---|---|
| \`validate\` | \`https://webservicesp.anaf.ro/prod/FCTEL/rest/validare\` | Validate UBL XML before upload |
| \`transform\` | \`https://webservicesp.anaf.ro/prod/FCTEL/rest/transformare\` | Convert XML to PDF |
| \`verify_signature\` | \`https://webservicesp.anaf.ro/prod/FCTEL/rest/verificare-semnatura\` | Verify digital signatures |

### \`endpoints.company_lookup\`

| | |
|---|---|
| **URL** | \`https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva\` |
| **Purpose** | Look up company details by CUI (fiscal identification number) |

---

## \`rate_limits\`

Rate limiting configuration to prevent exceeding ANAF API quotas. Defaults are set to **50% of ANAF's official limits** as a safety margin — with one exception: \`company_lookup_per_second\` defaults to \`1\`, which is **100%** of ANAF's cap. A 50% margin cannot be expressed as an integer limit over a 1-second window (any lower integer would be \`0\` and disable lookups entirely).

### \`rate_limits.enabled\`

| | |
|---|---|
| **Type** | boolean |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_ENABLED\` |
| **Default** | \`true\` |
| **Required** | No |

Enable or disable rate limiting globally. Disable only for local testing.

### \`rate_limits.global_per_minute\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_GLOBAL\` |
| **Default** | \`500\` |
| **ANAF official limit** | 1000 calls/minute |
| **Valid range** | 1 – 1000 |

Maximum total API calls allowed per minute across all endpoints.

### \`rate_limits.rasp_upload_per_day_cui\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_RASP_UPLOAD\` |
| **Default** | \`500\` |
| **ANAF official limit** | 1000/day/CUI |
| **Valid range** | 1 – 1000 |

Maximum RASP file uploads per CUI (company tax ID) per day.

### \`rate_limits.status_per_day_message\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_STATUS\` |
| **Default** | \`50\` |
| **ANAF official limit** | 100/day/message |
| **Valid range** | 1 – 100 |

Maximum upload status queries per message ID per day.

### \`rate_limits.simple_list_per_day_cui\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_SIMPLE_LIST\` |
| **Default** | \`750\` |
| **ANAF official limit** | 1500/day/CUI |
| **Valid range** | 1 – 1500 |

Maximum simple list (non-paginated) queries per CUI per day.

### \`rate_limits.paginated_list_per_day_cui\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_PAGINATED_LIST\` |
| **Default** | \`50000\` |
| **ANAF official limit** | 100,000/day/CUI |
| **Valid range** | 1 – 100000 |

Maximum paginated list queries per CUI per day.

### \`rate_limits.download_per_day_message\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_DOWNLOAD\` |
| **Default** | \`5\` |
| **ANAF official limit** | 10/day/message |
| **Valid range** | 1 – 10 |

Maximum invoice XML downloads per message ID per day.

### \`rate_limits.company_lookup_per_second\`

| | |
|---|---|
| **Type** | integer |
| **Environment variable** | \`EFACTURA_RATE_LIMIT_COMPANY_LOOKUP\` |
| **Default** | \`1\` |
| **ANAF official limit** | 1/second |
| **Valid range** | 1 – 1 (the default already matches ANAF's cap) |

Maximum company-lookup (\`PlatitorTvaRest\`) requests per second, enforced by \`AnafDetailsClient\`. Unlike the other rate limits, the default is **100% of ANAF's limit**, not 50% — ANAF's cap is already 1/second and there is no lower positive integer. The bucket is a **single global one**: the limit is per request, not per CUI, so a 100-CUI batch consumes exactly one unit. Exceeding it throws \`RateLimitExceededException\`.

---

## Minimal \`.env\` configuration

\`\`\`dotenv
EFACTURA_SANDBOX=true
EFACTURA_CLIENT_ID=your-client-id
EFACTURA_CLIENT_SECRET=your-client-secret
EFACTURA_REDIRECT_URI=https://your-app.com/efactura/callback
\`\`\`

## Full \`.env\` reference

\`\`\`dotenv
# Environment
EFACTURA_SANDBOX=true

# OAuth2
EFACTURA_CLIENT_ID=
EFACTURA_CLIENT_SECRET=
EFACTURA_REDIRECT_URI=

# HTTP
EFACTURA_TIMEOUT=30
EFACTURA_RETRY_TIMES=3
EFACTURA_RETRY_DELAY=5

# Logging
EFACTURA_LOG_CHANNEL=efactura-sdk
EFACTURA_DEBUG=false

# Rate limits
EFACTURA_RATE_LIMIT_ENABLED=true
EFACTURA_RATE_LIMIT_GLOBAL=500
EFACTURA_RATE_LIMIT_RASP_UPLOAD=500
EFACTURA_RATE_LIMIT_STATUS=50
EFACTURA_RATE_LIMIT_SIMPLE_LIST=750
EFACTURA_RATE_LIMIT_PAGINATED_LIST=50000
EFACTURA_RATE_LIMIT_DOWNLOAD=5
EFACTURA_RATE_LIMIT_COMPANY_LOOKUP=1
\`\`\`
`;
