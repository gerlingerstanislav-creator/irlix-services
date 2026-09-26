FROM node:22-alpine AS build
WORKDIR /app/apps/timesheets
COPY apps/timesheets/package.json ./
RUN npm install
COPY apps/timesheets /app/apps/timesheets
COPY packages/ui /app/packages/ui
COPY packages/auth /app/packages/auth
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/timesheets/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/apps/timesheets/dist /usr/share/nginx/html
EXPOSE 80
