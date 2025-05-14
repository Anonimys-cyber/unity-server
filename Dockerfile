# Вибираємо офіційний образ PHP з Apache
FROM php:8.2-apache

# Копіюємо всі файли проєкту у веб-директорію Apache
COPY . /var/www/html/

# Встановлюємо розширення mysqli для роботи з MySQL
RUN docker-php-ext-install mysqli

# Дозволяємо перезапис для .htaccess
RUN a2enmod rewrite

# Встановлюємо дозволи
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Вказуємо робочу директорію
WORKDIR /var/www/html

# Відкриваємо порт 80
EXPOSE 80
