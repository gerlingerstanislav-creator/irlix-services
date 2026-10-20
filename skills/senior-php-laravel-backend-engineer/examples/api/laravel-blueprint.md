# Laravel adaptation: API implementation blueprint

This is a **design specimen**, not a drop-in patch. Map types and models to the real repository; adapt route/middleware and policy registration to the installed Laravel version. Full feature tests must use the actual PostgreSQL test DB.

```php
// routes/api.php (if this repository uses this layout)
Route::middleware(['auth:api'])->group(function () {
    Route::post('/v1/documents', [DocumentController::class, 'store']);
    Route::patch('/v1/documents/{document}', [DocumentController::class, 'update']);
});

// StoreDocumentRequest: validate string title, reject missing or empty title,
// authorize create via Gate/Policy before persistence.
// UpdateDocumentRequest: validate writable fields only; authorize update on resolved
// model with scope and permission, never pass $request->all() to update().

// Policy: check action permission AND document's authorized scope;
// do not trust scope/tenant ids received in payload.
// Controller: persist validated allowlisted attributes; derive scope from
// trusted principal; use DocumentResource; avoid returning model raw.
```

## Request tests

- 201 created, API Resource payload, tenant from trusted identity, no sensitive fields.
- 401 no auth; 403 missing action permission; 403/404 foreign scope consistent with project; 404 absent record.
- 422 invalid title: empty, >180, invalid nested relation; overposted `owner_id` / `scope` not persisted (or rejected explicitly).
- Migration: `documents` table foreign keys and optional domain-specific uniqueness only if warranted by requirements. Check global authorization of list/filter/count endpoints.
- Signed OpenAPI security scheme and 401/403/404/422 response examples; feature tests exercise actual middleware and auth adapter.
