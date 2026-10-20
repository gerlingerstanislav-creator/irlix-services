FROM node:22-alpine AS build
WORKDIR /repo/apps/finance
COPY apps/finance/package.json ./package.json
RUN npm install --no-audit --no-fund
COPY apps/finance ./
COPY packages /repo/packages
RUN npm run build

FROM nginx:1.27-alpine
COPY apps/finance/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /repo/apps/finance/dist /usr/share/nginx/html
EXPOSE 80
