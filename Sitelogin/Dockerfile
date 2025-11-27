# Usa a imagem oficial do PHP com Apache
FROM php:8.2-apache

# 1. Instala dependências do sistema necessárias para o PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. Instala as extensões PHP necessárias (Postgres e PDO)
RUN docker-php-ext-install pdo pdo_pgsql pgsql

# 3. Habilita o mod_rewrite do Apache (opcional, mas bom ter)
RUN a2enmod rewrite

# 4. Copia seus arquivos para o servidor
COPY . /var/www/html/

# 5. Ajusta permissões para o Apache ler os arquivos e gravar na pasta temp
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 6. Configura o Render para usar a porta 80
EXPOSE 80
