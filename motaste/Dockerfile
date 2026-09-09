FROM php:8.3-cli

# Install system dependencies including Node.js 20
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip libsqlite3-dev \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && docker-php-ext-install pdo_sqlite mbstring exif pcntl bcmath gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer & package files for caching
COPY composer.json composer.lock package.json package-lock.json ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application source
COPY . .

# Generate app key if not set
RUN php artisan key:generate --force 2>/dev/null || true

# Build frontend assets
RUN npm ci 2>/dev/null || npm install
RUN npm run build

# Permissions
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 8000
CMD php artisan serve --host=0.0.0.0 --port=8000
