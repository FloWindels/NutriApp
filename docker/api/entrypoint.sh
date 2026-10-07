#!/bin/sh
# Mavi'oh — démarrage du conteneur API ou planificateur.
#   MAVIOH_ROLE=api        (défaut) migrations + catalogue, puis php-fpm
#   MAVIOH_ROLE=scheduler  attend que l'API ait migré, puis la commande donnée
set -e
cd /var/www/html

echo "Attente de PostgreSQL sur ${DB_HOST}:${DB_PORT}…"
until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -q; do sleep 1; done

if [ "${MAVIOH_ROLE:-api}" = "api" ]; then
  php artisan migrate --force
  # Catalogues idempotents (exercices, sports, magasins) ; en production, jamais de compte démo.
  php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Les dossiers d'écriture peuvent venir d'un volume créé par root.
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
