FROM node:22-alpine AS build
WORKDIR /app/apps/portal
COPY apps/portal/package.json ./
RUN npm install
COPY apps/portal /app/apps/portal
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/portal/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/portal/dist /usr/share/nginx/html
COPY apps/portal/favicon.svg /usr/share/nginx/html/favicon.svg
EXPOSE 80
