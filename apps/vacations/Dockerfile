FROM node:22-alpine AS build
WORKDIR /app/apps/vacations
COPY apps/vacations/package.json ./
RUN npm install
COPY apps/vacations /app/apps/vacations
COPY packages/ui /app/packages/ui
COPY packages/auth /app/packages/auth
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/vacations/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/vacations/dist /usr/share/nginx/html
EXPOSE 80
