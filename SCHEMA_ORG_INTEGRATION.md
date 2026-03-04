# Schema.org Integration Guide

## How It Works

The schema.org markup is **automatically embedded in HTML responses** (not a separate endpoint). It's rendered as `<script type="application/ld+json">` tags for SEO crawlers.

## User Flow

### 1. **Frontend loads embed code**
Customer adds this snippet to their website:
```html
<script src="YOUR_DOMAIN/embed/showcase.js" data-showcase="{embedKey}" data-theme="light" data-layout="list"></script>
```

### 2. **Browser loads iframe with reviews**
- Script injects an iframe pointing to: `/embed/preview/{embedKey}`
- This renders the showcase view with reviews

### 3. **Backend generates schema markup**
```
GET /api/v1/embed/{embedKey}/reviews
  ↓
EmbedController::getReviews()
  ↓
Generates LocalBusinessSchemaGenerator + ReviewSchemaGenerator
  ↓
Returns showcase.blade.php with {!! $schemaJson !!}
```

### 4. **Rendered HTML includes JSON-LD**
The response contains:
```html
<html>
  <head>
    <script type="application/ld+json">
      [
        {"@context": "https://schema.org", "@type": "LocalBusiness", ...},
        {"@context": "https://schema.org", "@type": "Review", ...},
        {"@context": "https://schema.org", "@type": "Review", ...}
      ]
    </script>
  </head>
  <body>
    <!-- Visible review cards here -->
  </body>
</html>
```

## The Pipeline

| Step | What Happens |
|------|--------------|
| 1. Embed key generated | `POST /api/v1/tenants/{id}/embed/showcase` returns iframe code |
| 2. Frontend embeds code | Customer adds `<script>` to their page |
| 3. Iframe loads | Browser loads `/embed/preview/{embedKey}` |
| 4. Backend queries reviews | Fetches location + reviews from database |
| 5. Schemas generated | `LocalBusinessSchemaGenerator` + `ReviewSchemaGenerator` instantiated |
| 6. HTML rendered | Blade view (`showcase.blade.php`) injects `{$schemaJson}` in `<head>` |
| 7. SEO crawlers see schema | Google/Bing bots parse JSON-LD for rich snippets |

## Backend Code Flow

```php
// Step 1: Endpoint receives request
public function getReviews(string $embedKey)
{
    $location = Location::where('embed_key', $embedKey)->first();
    $reviews = $location->reviews()->limit($limit)->get();
    
    // Step 2: Generate schemas
    $schemas = [];
    $schemas[] = new LocalBusinessSchemaGenerator($location);
    
    foreach ($reviews as $review) {
        $schemas[] = new ReviewSchemaGenerator($review);
    }
    
    // Step 3: Convert to JSON-LD script tags
    $schemaJson = SchemaHelper::toMultipleJsonLd($schemas);
    
    // Step 4: Render view with schema embedded
    return response()->view('embed.showcase', [
        'schemaJson' => $schemaJson,  // ← Injected in <head>
        'reviews' => $reviews,
    ]);
}
```

## What Gets Embedded

**Each review generates a `Review` schema:**
```json
{
  "@context": "https://schema.org",
  "@type": "Review",
  "author": {"@type": "Person", "name": "John Doe"},
  "reviewRating": {"@type": "Rating", "ratingValue": 5},
  "reviewBody": "Great service!",
  "datePublished": "2026-03-04"
}
```

**Business gets a `LocalBusiness` schema:**
```json
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "My Store",
  "url": "https://example.com",
  "address": {...}
}
```

## Is It Connecting to External APIs?
**No.** The schema.org markup is pure metadata. It doesn't call Google, Bing, or any external service. SEO crawlers **pull it from the HTML** to understand your content better.

## Where It's Used
- ✅ Embedded widget `/embed/preview/{embedKey}` 
- ✅ Can be extended to product pages, business pages, etc.
- ❌ NOT a standalone API (it's server-rendered HTML with embedded JSON-LD)
