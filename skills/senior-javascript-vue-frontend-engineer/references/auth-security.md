# Frontend security, identity and OAuth/OIDC

Read for auth, authorization, dynamic content/URLs, untrusted data, dependencies and deployments. Security decisions must match server contracts and the installed SDK; frontend alone cannot enforce access control.

## OAuth 2.0 / OIDC / Keycloak

1. Determine architecture: same-origin app with backend-for-frontend (BFF) session, or browser-only public OAuth client; inspect existing provider SDK, configured issuer/client/redirect URIs, callback/refresh/log-out behavior and server authorization model.
2. Prefer established library + **Authorization Code with PKCE** for browser-only public clients; never embed a client secret in browser assets. Avoid implicit grant and hand-rolled token exchange. Apply `state`, OIDC nonce and issuer/audience/redirect validation per SDK and applicable protocol (do not implement token validation with ad-hoc decoding).
3. Prefer BFF + hardened `HttpOnly`, `Secure`, appropriately scoped `SameSite` cookies when the architecture supports it. Such cookies need server CSRF protections where relevant; JS frontend cannot set HttpOnly.
4. For browser-only clients prefer provider adapter defaults and memory token storage; don't persist access/refresh tokens in localStorage, sessionStorage or URLs. Keycloak JS adapter holds tokens in memory; configure clean bootstrap before router if URL mutates, and use `updateToken` as documented. Silent SSO depends on third-party-cookie policy; test fallback.
5. Session states: bootstrapping, anonymous, authenticated, refreshing, expired, forbidden, logout. Prevent guard loops and API request storms during refresh (single-flight where needed), clear user-private cached data on logout/identity change, avoid showing stale privileged UI during bootstrap.
6. Backend must authorize every sensitive operation. Route guards/buttons are only convenient client-side presentation. Permission decisions should match authoritative response and handle 403 even when a UI control is hidden.
7. Do not ship credentials/keys in `VITE_` variables: they are bundled into client code. Review source maps and debug logs.

## XSS / other browser threats

- Never compile untrusted Vue templates or use unsafe dynamic component/template code. Use escaped interpolation (`{{ value }}`) by default. Avoid `v-html` with external content; if rich HTML is truly required, use reviewed allowlist sanitization plus backend validation/sandboxing appropriate to the threat model.
- Validate user-influenced `href`, navigation targets and downloads against allowlisted protocols and destinations. Avoid `javascript:`, untrusted styles and arbitrary HTML attributes. Consider tabnabbing safeguards for external links.
- Examine CSP, supply-chain integrity, third-party scripts, dependency audit, clickjacking protections, cookies, CORS and CSRF with the responsible platform/backend owners. Note ownership rather than claiming frontend-only mitigation.
- Never expose raw error payloads into HTML or write access tokens to logs. Consider file upload type/size limits, content trust, CSV formula injection on exports, and replay/double-submit risks.

## Mandatory review for auth-related change

Redirect and callback parameters; renewal concurrency; multiple tabs; back/forward; browser 3rd-party cookie restrictions; expired session mid-mutation; 401 vs 403; logout cache cleanup; offline/retry; role changes; external links; XSS of API data; protected route deep link; SSR client/server isolation when applicable.

Sources: https://www.rfc-editor.org/rfc/rfc10017.html ; https://www.keycloak.org/securing-apps/javascript-adapter ; https://vuejs.org/guide/best-practices/security ; https://vite.dev/guide/env-and-mode
