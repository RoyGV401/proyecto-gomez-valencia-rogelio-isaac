FROM nginx:alpine

# Reemplazar la configuración por defecto de Nginx con nuestro proxy inverso
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Copiar los archivos estáticos (HTML, CSS, JS) al directorio público de Nginx
COPY src/ /usr/share/nginx/html/

EXPOSE 80
