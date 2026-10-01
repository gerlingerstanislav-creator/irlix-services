FROM node:22-alpine AS build
WORKDIR /app
COPY apps/cv/package*.json ./
RUN npm install
COPY apps/cv ./
COPY packages/ui /packages/ui
COPY packages/auth /packages/auth
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/cv/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
