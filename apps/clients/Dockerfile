FROM node:22-alpine AS build
WORKDIR /app/apps/clients
COPY apps/clients/package.json ./
RUN npm install
COPY apps/clients /app/apps/clients
COPY packages/ui /app/packages/ui
COPY packages/auth /app/packages/auth
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/clients/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/clients/dist /usr/share/nginx/html
EXPOSE 80
