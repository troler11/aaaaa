FROM php:8.2-apache

# Instala dependências e extensões
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    git \
    libpq-dev \
    unzip \
    && docker-php-ext-install curl session pdo pdo_mysql

# Ativa o módulo de reescrita do Apache
RUN a2enmod rewrite

RUN docker-php-ext-install pdo pdo_pgsql pgsql

# --- CORREÇÃO DO ERRO 404 ---
# Configura o Apache para aceitar o arquivo .htaccess na pasta /var/www/html
# Isso troca "AllowOverride None" por "AllowOverride All" nas configurações
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copia os arquivos
COPY . /var/www/html/

# Permissões da pasta de cookies
RUN mkdir -p /var/www/html/cookies && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 777 /var/www/html/cookies

EXPOSE 80
