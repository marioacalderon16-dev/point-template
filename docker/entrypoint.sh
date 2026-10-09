#!/bin/sh
set -e

# Un solo MPM (prefork, requerido por mod_php); Railway puede terminar con varios habilitados
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod mpm_prefork >/dev/null

# Railway asigna el puerto en $PORT
PORT="${PORT:-8080}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Scheduler en segundo plano (tareas programadas en config/schedule.php) salvo SCHEDULER_ENABLED=false
if [ "${SCHEDULER_ENABLED:-true}" = "true" ]; then
  (while true; do su -s /bin/sh www-data -c "php /var/www/html/scheduler run" || true; sleep 60; done) &
fi

exec apache2-foreground
