FROM php:8.2-cli

WORKDIR /app

COPY . .

RUN docker-php-ext-install pdo pdo_pgsql

EXPOSE 10000

CMD ["php", "-S", "0.0.0.0:10000", "-t", "."]