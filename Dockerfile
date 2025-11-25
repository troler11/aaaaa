# Usa uma imagem oficial do PHP com Apache (igual hospedagem comum)
FROM php:8.2-apache

# Instala extensões necessárias e o cURL
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl session

# Ativa o mod_rewrite do Apache (bom para rotas)
RUN a2enmod rewrite

# Copia todos os seus arquivos para o servidor
COPY . /var/www/html/

# Cria a pasta de cookies e dá permissão TOTAL para o Apache escrever nela
RUN mkdir -p /var/www/html/cookies && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 777 /var/www/html/cookies

# Expõe a porta 80 (padrão web)
EXPOSE 80
