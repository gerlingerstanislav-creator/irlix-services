FROM node:22-alpine AS build
WORKDIR /app/apps/web
COPY apps/web/package.json ./
RUN npm install
COPY apps/web /app/apps/web
COPY packages/ui /app/packages/ui
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/web/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/web/dist /usr/share/nginx/html
EXPOSE 80
