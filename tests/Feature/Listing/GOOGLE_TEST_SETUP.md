# Google My Business Integration Test Setup

## Prerequisites

Before running the integration tests, you need to set up the following environment variables in your `.env` file:

### 1. OAuth Credentials (Already Set)
```env
GOOGLE_GMP_CLIENT_ID=1040523144508-2ta575slckbnc6hsk75cd19b7ej0jigc.apps.googleusercontent.com
GOOGLE_GMP_CLIENT_SECRET=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
GOOGLE_GMP_REDIRECT_URI=https://api.localmator.com/api/auth/google/callback
```

### 2. Test Tokens (Need to Obtain)

You need to manually complete the OAuth flow once to get test tokens:

```env
# Get this from completing OAuth flow
GOOGLE_GMP_TEST_ACCESS_TOKEN=

# Optional: For testing token refresh
GOOGLE_GMP_TEST_REFRESH_TOKEN=

# Optional: For testing authorization code exchange
GOOGLE_GMP_TEST_AUTH_CODE=
```

### 3. Test Account & Location IDs

After getting an access token, fetch these values:

```env
# Format: accounts/1234567890
GOOGLE_GMP_TEST_ACCOUNT_ID=

# Format: locations/1234567890
GOOGLE_GMP_TEST_LOCATION_NAME=
```

## How to Get Test Tokens

### Step 1: Get Authorization Code

1. Run this PHP script to generate the OAuth URL:

```php
<?php
require __DIR__.'/vendor/autoload.php';

$clientId = '1040523144508-2ta575slckbnc6hsk75cd19b7ej0jigc.apps.googleusercontent.com';
$redirectUri = 'https://api.localmator.com/api/auth/google/callback';

$scopes = [
    'https://www.googleapis.com/auth/business.manage',
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile',
];

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => implode(' ', $scopes),
    'response_type' => 'code',
    'access_type' => 'offline',
    'prompt' => 'consent',
];

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

echo "Visit this URL:\n\n$url\n\n";
echo "After authorization, copy the 'code' parameter from the callback URL\n";
```

2. Visit the generated URL in your browser
3. Grant permissions
4. Copy the `code` parameter from the callback URL

### Step 2: Exchange Code for Tokens

```bash
curl -X POST https://oauth2.googleapis.com/token \
  -d "code=YOUR_AUTH_CODE_HERE" \
  -d "client_id=1040523144508-2ta575slckbnc6hsk75cd19b7ej0jigc.apps.googleusercontent.com" \
  -d "client_secret=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" \
  -d "redirect_uri=https://api.localmator.com/api/auth/google/callback" \
  -d "grant_type=authorization_code"
```

Response will contain:
```json
{
  "access_token": "ya29.a0...",
  "refresh_token": "1//0g...",
  "expires_in": 3600,
  "token_type": "Bearer"
}
```

Add these to your `.env`:
```env
GOOGLE_GMP_TEST_ACCESS_TOKEN=ya29.a0...
GOOGLE_GMP_TEST_REFRESH_TOKEN=1//0g...
```

### Step 3: Get Account ID

```bash
curl -X GET \
  "https://mybusinessaccountmanagement.googleapis.com/v1/accounts" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

Response:
```json
{
  "accounts": [
    {
      "name": "accounts/1234567890",
      "accountName": "My Business",
      ...
    }
  ]
}
```

Add to `.env`:
```env
GOOGLE_GMP_TEST_ACCOUNT_ID=accounts/1234567890
```

### Step 4: Get Location Name

```bash
curl -X GET \
  "https://mybusinessbusinessinformation.googleapis.com/v1/accounts/1234567890/locations" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

Response:
```json
{
  "locations": [
    {
      "name": "locations/9876543210",
      "title": "My Store",
      ...
    }
  ]
}
```

Add to `.env`:
```env
GOOGLE_GMP_TEST_LOCATION_NAME=locations/9876543210
```

## Running the Tests

### Run All Integration Tests
```bash
php artisan test tests/Feature/Listing/GoogleMyBusinessIntegrationTest.php
```

### Run Specific Test Groups
```bash
# OAuth flow tests only
php artisan test --filter="Google My Business OAuth Flow"

# Account & location fetching tests
php artisan test --filter="Google My Business Account & Location Fetching"

# Listing sync tests
php artisan test --filter="Google My Business Listing Sync"

# API endpoint tests
php artisan test --filter="Google My Business API Endpoints"

# Token refresh tests
php artisan test --filter="Google My Business Token Refresh"
```

### Run with Verbose Output
```bash
php artisan test tests/Feature/Listing/GoogleMyBusinessIntegrationTest.php -v
```

## Test Coverage

The integration tests cover:

### ✅ OAuth Flow
- OAuth URL generation with correct parameters
- All required scopes included
- Authorization code exchange
- Invalid code handling
- Network error handling

### ✅ Account & Location Fetching
- Fetch business accounts
- Invalid token handling
- Fetch locations for account
- Invalid account ID handling
- Fetch detailed location information
- Non-existent location handling
- Address data validation

### ✅ Listing Sync
- Sync listing from Google
- Discrepancy detection
- Expired token handling

### ✅ API Endpoints
- Connect endpoint
- Store credentials endpoint
- Sync listing endpoint
- Platform status endpoint

### ✅ Token Refresh
- Refresh access token
- Invalid refresh token handling

### ✅ Error Handling
- Rate limiting
- API quota exceeded
- Scope validation

## Important Notes

1. **Access tokens expire in 1 hour** - You may need to refresh them for long test sessions
2. **Refresh tokens don't expire** - Keep them safe, they provide ongoing access
3. **API has quotas** - Don't run tests too frequently to avoid hitting limits
4. **Real API calls** - These tests make actual calls to Google's servers
5. **Test data** - Use a test Google My Business account, not production data

## Troubleshooting

### "Access token expired"
Refresh your token or get a new one following Step 2 above.

### "Insufficient permissions"
Make sure you granted all required scopes during OAuth flow. Use `prompt=consent` to re-authorize.

### "Location not found"
Verify the location name format is correct: `locations/{id}` not just the ID.

### "Account not found"
Verify the account ID format is correct: `accounts/{id}` not just the ID.
