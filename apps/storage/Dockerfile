FROM node:22-alpine AS build
WORKDIR /app/apps/storage
COPY apps/storage/package.json ./
RUN npm install --no-audit --no-fund && ln -s /app/apps/storage/node_modules /app/node_modules
COPY apps/storage /app/apps/storage
COPY packages/ui /app/packages/ui
RUN npm run build
FROM nginx:1.27-alpine
COPY apps/storage/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/storage/dist /usr/share/nginx/html
COPY apps/portal/favicon.svg /usr/share/nginx/html/favicon.svg
EXPOSE 80
