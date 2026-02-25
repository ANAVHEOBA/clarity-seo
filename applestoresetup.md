# Apple App Store Review Sync + Reply Runbook

Use this runbook when integrating Apple App Store reviews/replies and debugging `Synced 0 reviews`.

## Confirmed Status
As of **February 25, 2026**, this implementation was validated live against App Store Connect:
- `GET /v1/apps` returned app `6758574978`.
- Review sync inserted Apple reviews in local DB.
- Reply publish succeeded and returned an Apple `customerReviewResponse` ID.

## Important: Which App Identifier Is Used?
For review sync endpoint calls, this integration uses:
- `app_store_id` (Apple app resource ID), e.g. `6758574978`

It does **not** call Apple review endpoints by `bundle_id`.

Expected location mapping:
- `locations.apple_app_store_app_id = <app_store_id>`

## End-to-End Flow
1. Generate App Store Connect API key (`.p8`, `Key ID`, `Issuer ID`).
2. Save account via API (`POST /apple-app-store/accounts`) with `.p8` content in `private_key`.
3. Save app mapping via API (`POST /apple-app-store/apps`) with `app_store_id`.
4. Set location `apple_app_store_app_id` to that same `app_store_id`.
5. (Optional) Sync listing profile endpoint (`POST /listings/sync/apple_app_store`).
6. Call review sync endpoint (`POST /reviews/sync`).
7. Publish replies through existing response publish endpoint.

## Required Records
- `apple_app_store_accounts`
  - must be active
  - key/issuer/private key must belong to team that owns the app
- `apple_app_store_apps`
  - must include correct `app_store_id`
  - if mapped account is set, it must be the right account for the app
- `locations`
  - `apple_app_store_app_id` must be set to `app_store_id`

## If You See `403 FORBIDDEN_ERROR`
Error example:
- `The API key in use does not allow this request`

### Likely causes
1. The key role/access is insufficient for Customer Reviews operations.
2. The key belongs to a different App Store Connect team than the target app.
3. The key has app restrictions and target app is not included.
4. Wrong `issuer_id` + `key_id` + `.p8` combination.
5. Using wrong app ID in `apple_app_store_app_id`.

### Quick checks
1. Test base access with same JWT/key:
   - `GET /v1/apps?limit=1` should return `200`.
2. Test customer reviews endpoint for target app ID:
   - `GET /v1/apps/{app_store_id}/customerReviews?limit=1`
3. In App Store Connect, verify key permissions and app access scope.
4. Confirm the app is visible to the key's role.

If step 1 works but step 2 is `403`, this is usually a permission/scope issue for Customer Reviews on that app.

## Local API Verification Commands

### 1) Save account
```bash
curl -X POST "http://localhost:8000/api/v1/tenants/{tenant}/apple-app-store/accounts" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d "$(jq -n \
    --arg name "Apple API Key" \
    --arg issuer "<issuer_id>" \
    --arg key "<key_id>" \
    --arg pk "$(cat /path/AuthKey_<key_id>.p8)" \
    '{name:$name, issuer_id:$issuer, key_id:$key, private_key:$pk}')"
```

### 2) Save app mapping
```bash
curl -X POST "http://localhost:8000/api/v1/tenants/{tenant}/apple-app-store/apps" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My iOS App",
    "app_store_id": "6758574978",
    "bundle_id": "com.innovatedagency.quiettime",
    "country_code": "US"
  }'
```

### 3) Set location mapping
```bash
curl -X PUT "http://localhost:8000/api/v1/tenants/{tenant}/locations/{location}" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"apple_app_store_app_id":"6758574978"}'
```

### 4) Sync reviews
```bash
curl -X POST "http://localhost:8000/api/v1/tenants/{tenant}/locations/{location}/reviews/sync" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

### 4.1) Sync Apple listing profile
```bash
curl -X POST "http://localhost:8000/api/v1/tenants/{tenant}/locations/{location}/listings/sync/apple_app_store" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

### 5) Reply to a synced review
```bash
curl -X POST "http://localhost:8000/api/v1/tenants/{tenant}/reviews/{review_id}/response/publish" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

## Code Behavior Notes
- Review sync calls Apple endpoint:
  - `GET /v1/apps/{app_store_id}/customerReviews`
- Listing sync calls Apple endpoint:
  - `GET /v1/apps/{app_store_id}`
- Reply publish calls Apple endpoint:
  - `POST /v1/customerReviewResponses` (create)
  - `PATCH /v1/customerReviewResponses/{id}` (update)
- `.p8` file path is not used at runtime; only key content is stored encrypted in DB.

## Apple Docs References
- App Store Connect API overview: https://developer.apple.com/help/app-store-connect/get-started/app-store-connect-api
- Role permissions: https://developer.apple.com/help/app-store-connect/reference/account-management/role-permissions
- Customer Reviews resource: https://developer.apple.com/documentation/appstoreconnectapi/customer-reviews
- List reviews endpoint: https://developer.apple.com/documentation/appstoreconnectapi/get-v1-apps-_id_-customerreviews
