# Apple App Store Integration Guide

This project supports tenant-level Apple App Store Connect integration using API keys.

## What You Need From Apple
1. App Store Connect `Issuer ID`
2. App Store Connect `Key ID`
3. Downloaded `.p8` private key file (`AuthKey_<KEY_ID>.p8`)
4. A display name for the key/account in this system

You create/download these in:
- App Store Connect -> Users and Access -> Integrations -> Team Keys

Important:
- Apple allows `.p8` download only once.
- If you lose it, create a new key.

## How This Codebase Uses `.p8`
The app does **not** discover `.p8` by file path at runtime.

Flow:
1. You read `.p8` content locally.
2. You send content in API payload as `private_key`.
3. Backend stores it encrypted in DB (`apple_app_store_accounts.private_key`).
4. JWT is generated from DB value to call Apple API.

Reference lines:
- Request ingestion: `app/Http/Controllers/Api/V1/Listing/AppleAppStoreController.php`
- Encrypted cast: `app/Models/AppleAppStoreAccount.php`
- JWT signing usage: `app/Services/Listing/AppleAppStoreService.php`

## Environment Variables
These are global settings only:
- `APPLE_APP_STORE_API_BASE_URL` (default `https://api.appstoreconnect.apple.com`)
- `APPLE_APP_STORE_JWT_TTL_MINUTES` (default `20`)
- `APPLE_APP_STORE_JWT_AUDIENCE` (default `appstoreconnect-v1`)

Optional metadata (not used for runtime auth):
- `APPLE_APP_STORE_ISSUER_ID`
- `APPLE_APP_STORE_KEY_ID`
- `APPLE_APP_STORE_KEY_NAME`

Do **not** rely on `.env` private key for runtime auth in this architecture.

## API Endpoints
All routes are under `/api/v1` and require `auth:sanctum`.

### Accounts
- `GET /tenants/{tenant}/apple-app-store/accounts`
- `POST /tenants/{tenant}/apple-app-store/accounts`
- `POST /tenants/{tenant}/apple-app-store/accounts/{account}/test`
- `DELETE /tenants/{tenant}/apple-app-store/accounts/{account}`

### Apps
- `GET /tenants/{tenant}/apple-app-store/apps`
- `POST /tenants/{tenant}/apple-app-store/apps`
- `DELETE /tenants/{tenant}/apple-app-store/apps/{app}`

## Create Account Example
```bash
TOKEN="<sanctum_token>"
TENANT_ID="<tenant_id>"

PAYLOAD=$(jq -n \
  --arg name "[Expo] EAS Submit i_84lrrcJ7" \
  --arg issuer "3d24e14c-e344-4c39-bd2d-7dd0c414f476" \
  --arg key "645X2P2WBB" \
  --arg pk "$(cat /home/a/clarity-seo/AuthKey_645X2P2WBB.p8)" \
  '{name:$name, issuer_id:$issuer, key_id:$key, private_key:$pk}')

curl -s -X POST "http://localhost:8000/api/v1/tenants/${TENANT_ID}/apple-app-store/accounts" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

## Test Credentials
### JWT-only validation
```bash
curl -s -X POST "http://localhost:8000/api/v1/tenants/${TENANT_ID}/apple-app-store/accounts/${ACCOUNT_ID}/test" \
  -H "Authorization: Bearer ${TOKEN}"
```

### Live Apple API validation
```bash
curl -s -X POST "http://localhost:8000/api/v1/tenants/${TENANT_ID}/apple-app-store/accounts/${ACCOUNT_ID}/test?live=1" \
  -H "Authorization: Bearer ${TOKEN}"
```

Expected success:
- `valid: true`
- `status_code: 200` (live mode)

## Create App Mapping Example
```bash
curl -s -X POST "http://localhost:8000/api/v1/tenants/${TENANT_ID}/apple-app-store/apps" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "apple_app_store_account_id": 1,
    "name": "Quietarc",
    "app_store_id": "6758574978",
    "bundle_id": "com.innovatedagency.quiettime",
    "country_code": "US"
  }'
```

## Troubleshooting
- `401 NOT_AUTHORIZED`: issuer/key/private key mismatch, expired JWT, or wrong key.
- `private_key is not valid PEM`: bad formatting; include full BEGIN/END lines.
- No `.p8` available: create a new Team Key in App Store Connect.

## Verification Tests
Run:
```bash
php artisan test tests/Feature/Listing/AppleAppStoreIntegrationTest.php
```
