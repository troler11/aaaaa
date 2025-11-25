# Usa uma imagem oficial do PHP com Apache
FROM php:8.2-apache

# Instala dependências do sistema
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    git \
    unzip

# Instala extensões do PHP
# AQUI ESTÁ A CORREÇÃO: Adicionamos 'pdo' e 'pdo_mysql'
RUN docker-php-ext-install curl session pdo pdo_mysql

# Ativa o mod_rewrite do Apache (para rotas funcionarem)
RUN a2enmod rewrite

# Copia todos os arquivos do seu projeto para a pasta do servidor
COPY . /var/www/html/

# Configura permissões para a pasta de cookies (CRÍTICO para sua API funcionar)
RUN mkdir -p /var/www/html/cookies && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 777 /var/www/html/cookies

# Expõe a porta 80
EXPOSE 80
