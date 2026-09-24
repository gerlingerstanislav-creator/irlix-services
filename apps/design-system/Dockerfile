FROM node:22-alpine AS build
WORKDIR /app/apps/design-system
COPY apps/design-system/package.json ./
RUN npm install
COPY apps/design-system /app/apps/design-system
COPY packages/ui /app/packages/ui
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/design-system/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/design-system/dist /usr/share/nginx/html
EXPOSE 80
