# Tooling, Vite, deploy safety and CI

Only apply to deployment/infrastructure tasks; do not broaden a frontend feature into a CI rewrite.

- Versions: read `engines`, lockfile, `.nvmrc`/Volta and GitHub Actions Node before choosing versions. Do not blindly upgrade Vite/TypeScript/npm or add Pinia.
- Vite: understand `base`, static asset paths, generated chunks, sourcemaps, `import.meta.env` replacement, dev proxy vs production API topology and feature flags. `VITE_*` values are public build artifacts; never secrets.
- npm: use repo's package manager + frozen/ci install mode where supported; don't regenerate lockfile unnecessarily or run unknown lifecycle scripts on untrusted dependency trees.
- Docker: multi-stage build, deterministic install, artifact ownership, non-root image where reasonable; keep build-time public config separate from runtime secret management. Validate production asset output paths and caching.
- Nginx SPA: rewrite routes to app index where needed but **not** static assets/API endpoints; preserve meaningful 404 for missing assets; content types/compression, security headers and cache busting with hashed assets.
- GitHub Actions: lint + typecheck + test + build in correct package context; E2E on appropriate base URL/browser; PR checks and artifacts, avoid secrets on untrusted forks; principle of least privilege and concurrency protection. Respect existing workflow and protected branches.
- Rollback: who owns release, how to revert assets/config, cache invalidation, migration compatibility and user impact; never claim production ready merely because `vite build` passes.
- Report actual commands and results: e.g. `npm run typecheck` PASS, `npm run test:unit` NOT RUN (missing dependencies), browser screenshot NOT RUN.

Sources: https://vite.dev/guide/ ; https://docs.github.com/en/actions/security-for-github-actions ; https://nginx.org/en/docs/
