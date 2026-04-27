FROM php:8.2-cli

WORKDIR /app

# Install PostgreSQL dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

COPY . .

EXPOSE 10000

CMD ["php", "-S", "0.0.0.0:10000"]