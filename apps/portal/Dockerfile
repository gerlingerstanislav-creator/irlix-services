FROM nginx:1.27-alpine
COPY apps/portal/nginx.conf /etc/nginx/conf.d/default.conf
COPY apps/portal/index.html /usr/share/nginx/html/index.html
COPY apps/portal/favicon.svg /usr/share/nginx/html/favicon.svg
EXPOSE 80
