# REST API, OpenAPI and validation

## Contract-first checklist

1. Resource/action model; methods/paths; request and response schemas; authorization per operation and per object; field visibility/writeability; `Content-Type`/`Accept` and exact status codes.
2. Validation: types, ranges, lengths, formats, enum values, cross-field rules, unknown fields policy, nested arrays, uniqueness, relation ownership and consistency across requests. FormRequest `authorize()` must not silently replace needed object-policy checks.
3. Output: explicit API Resources/DTOs; stable JSON data shape, nullability, sorting, pagination, filtering, date timezone/format, privacy and avoiding lazy-loading induced N+1.
4. Errors: consistently distinguish 400 malformed, 401 unauthenticated, 403 forbidden, 404 hidden/missing, 409 conflict, 422 validation (project convention), 429 limit, 5xx transient. Reuse existing envelope. RFC 9457 `application/problem+json` is an option **only when compatible** with consumers.
5. Concurrency: `If-Match`/ETag or version columns for unsafe updates where lost updates matter; database constraints for hard invariants. For unsafe retried operations consider scoped idempotency keys with replay-safe stored results and retention policy.
6. OpenAPI: document security schemes/scopes, error schemas, 401/403/404/409/422/429, examples, nullable fields, required properties, pagination and breaking-change notes. Validate spec against handlers/tests.
7. Versioning: follow repo’s existing URI/header/media-type strategy. Prefer additive optional fields; never assume clients tolerate changing required field types, status codes, enum variants or authorization semantics.

## Example (illustrative, adapt to repo)

```php
final class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Report::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
```

For related objects, verify the relation belongs to the authenticated principal's authorized scope, **not only** `exists:...`. For updates use the exact allowed validated keys, permit only policy-authorized writable fields. Return errors without stack traces or internal identifiers. Build negative tests with authenticated user from a different tenant/scope, role-only access and scope-only access.
