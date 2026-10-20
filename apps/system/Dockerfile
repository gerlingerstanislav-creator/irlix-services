FROM node:22-alpine AS build
WORKDIR /app/apps/system
COPY apps/portal/package.json apps/portal/package-lock.json ./
RUN npm ci && ln -s /app/apps/system/node_modules /app/node_modules
COPY apps/system /app/apps/system
COPY apps/portal/src /app/apps/portal/src
COPY apps/migration/src /app/apps/migration/src
COPY packages/auth /app/packages/auth
COPY packages/ui /app/packages/ui
RUN npm run build
FROM nginx:1.27-alpine
COPY apps/system/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/system/dist /usr/share/nginx/html
COPY apps/portal/favicon.svg /usr/share/nginx/html/favicon.svg
EXPOSE 80
