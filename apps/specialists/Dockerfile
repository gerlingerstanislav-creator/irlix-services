FROM node:22-alpine AS build
WORKDIR /repo/apps/specialists
COPY apps/specialists/package.json ./package.json
RUN npm install --no-audit --no-fund
COPY apps/specialists ./
COPY packages /repo/packages
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/specialists/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /repo/apps/specialists/dist /usr/share/nginx/html
EXPOSE 80
