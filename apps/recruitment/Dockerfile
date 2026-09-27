FROM node:22-alpine AS build
WORKDIR /app
COPY apps/recruitment/package*.json ./
RUN npm install
COPY apps/recruitment ./
COPY packages/ui /packages/ui
COPY packages/auth /packages/auth
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/recruitment/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
