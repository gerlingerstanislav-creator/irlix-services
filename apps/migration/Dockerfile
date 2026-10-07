FROM node:22-alpine AS build
WORKDIR /app/apps/migration
COPY apps/migration/package.json apps/migration/package-lock.json ./
RUN npm ci
COPY apps/migration /app/apps/migration
COPY packages/auth /app/packages/auth
COPY packages/ui /app/packages/ui
RUN node --test tests/*.mjs && npm run build

FROM nginx:1.27-alpine
COPY apps/migration/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/migration/dist /usr/share/nginx/html
COPY apps/migration/favicon.svg /usr/share/nginx/html/favicon.svg
EXPOSE 80
