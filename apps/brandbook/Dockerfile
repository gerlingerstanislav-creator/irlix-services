FROM node:22-alpine AS build
WORKDIR /app/apps/brandbook
COPY apps/brandbook/package.json ./
RUN npm install
COPY apps/brandbook /app/apps/brandbook
COPY packages/ui /app/packages/ui
COPY packages/auth /app/packages/auth
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/brandbook/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/brandbook/dist /usr/share/nginx/html
EXPOSE 80
