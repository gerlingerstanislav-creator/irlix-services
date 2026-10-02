FROM node:22-alpine AS build
WORKDIR /repo/apps/equipment
COPY apps/equipment/package.json ./package.json
RUN npm install --no-audit --no-fund
COPY apps/equipment ./
COPY packages /repo/packages
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/equipment/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /repo/apps/equipment/dist /usr/share/nginx/html
EXPOSE 80
