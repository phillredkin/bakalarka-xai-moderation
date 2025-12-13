FROM php:8.3-apache

RUN a2enmod rewrite

COPY public/ /var/www/html/

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80