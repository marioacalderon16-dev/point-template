#!/bin/sh
set -e

# Un solo MPM (prefork, requerido por mod_php); Railway puede terminar con varios habilitados
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod mpm_prefork >/dev/null

# Railway asigna el puerto en $PORT
PORT="${PORT:-8080}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# storage/ puede ser un volumen montado (vacío y de root): crear sus carpetas y dárselas a Apache
mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/storage/jobs/failed /var/www/html/storage/uploads
chown -R www-data:www-data /var/www/html/storage

# Worker de la cola (jobs, eventos con fireAsync y rutas ->asyncable()) con WORKER_ENABLED=true.
# La cola y la caché son archivos de storage/: el worker tiene que correr en ESTE contenedor, no en otro
# servicio (no compartirían disco). --max-time lo reinicia cada hora para liberar memoria.
if [ "${WORKER_ENABLED:-false}" = "true" ]; then
  (while true; do su -s /bin/sh www-data -c "php /var/www/html/point work --max-time=3600" || true; sleep 1; done) &
fi

# Scheduler en segundo plano (tareas programadas en config/schedule.php) salvo SCHEDULER_ENABLED=false
if [ "${SCHEDULER_ENABLED:-true}" = "true" ]; then
  (while true; do su -s /bin/sh www-data -c "php /var/www/html/scheduler run" || true; sleep 60; done) &
fi

exec apache2-foreground
