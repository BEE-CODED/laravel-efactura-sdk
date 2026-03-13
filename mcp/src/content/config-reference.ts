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

Number of times a failed HTTP request will be automatically retried.

### \`http.retry_delay\`

| | |
|---|---|
| **Type** | integer (seconds) |
| **Environment variable** | \`EFACTURA_RETRY_DELAY\` |
| **Default** | \`5\` |
| **Required** | No |

Number of seconds to wait between retry attempts.

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

Rate limiting configuration to prevent exceeding ANAF API quotas. All defaults are set to **50% of ANAF's official limits** as a safety margin.

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
\`\`\`
`;
