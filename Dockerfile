# --- Étape 1 : compilation Tailwind locale (aucun CDN) ---
FROM node:20-alpine AS assets
WORKDIR /build
COPY package.json tailwind.config.js ./
COPY resources ./resources
COPY app/Views ./app/Views
COPY app/Support ./app/Support
COPY public/assets/js ./public/assets/js
RUN npm install --no-audit --no-fund && mkdir -p public/assets/css && npm run build:css

# --- Étape 2 : PHP + Apache ---
FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-tal.ini
COPY . /var/www/html
COPY --from=assets /build/public/assets/css/app.css /var/www/html/public/assets/css/app.css
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
 && mkdir -p /var/www/html/storage/logs \
 && chown -R www-data:www-data /var/www/html/storage
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
