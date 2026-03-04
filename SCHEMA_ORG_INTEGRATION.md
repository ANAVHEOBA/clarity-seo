# Schema.org Integration Guide

## Overview
This application implements **JSON-LD schema markup** for SEO optimization, supporting Review, LocalBusiness, and Report schemas.

## Integration Flow

### 1. **Instantiate Generator**
```php
use App\Services\Schema\ReviewSchemaGenerator;

$generator = new ReviewSchemaGenerator($review);
```

### 2. **Generate Schema Data**
```php
$schema = $generator->generate();  // Returns structured array with @context, @type, data
```

### 3. **Validate Schema**
```php
if ($generator->validate()) {
    // All required fields present & valid
}
```

### 4. **Convert to JSON-LD**
```php
$jsonLd = $generator->toJson();  // Ready for <script type="application/ld+json">
```

## Available Generators

| Generator | Type | Use Case |
|-----------|------|----------|
| `ReviewSchemaGenerator` | Review | Individual review markup |
| `LocalBusinessSchemaGenerator` | LocalBusiness | Business profile data |
| `ReportSchemaGenerator` | Report | Review aggregation reports |

## Implementation Example

```php
// In your controller or view
$review = Review::find($id);
$generator = new ReviewSchemaGenerator($review);

if ($generator->validate()) {
    echo '<script type="application/ld+json">' . $generator->toJson() . '</script>';
}
```

## Helper Functions

Use `SchemaHelper` for utility operations:
```php
use App\Helpers\SchemaHelper;

SchemaHelper::createPersonSchema($name);
SchemaHelper::createRatingSchema($value, $min, $max);
SchemaHelper::createOrganizationSchema($name, $url);
```

## API Endpoint

**Embed widget with schema:**
```
GET /api/v1/tenants/{tenant}/embed/showcase
```
Automatically includes Review and LocalBusiness schemas in response.

## Validation

All schemas validate:
- ✓ Required fields presence
- ✓ `@context` = `https://schema.org`
- ✓ Correct `@type` value
- ✓ ISO 8601 date formatting

## Notes
- Schemas are extensible—override `generateData()` in custom generators
- URLs auto-format to absolute paths
- Ratings scale: 1-5 (auto-validated)
