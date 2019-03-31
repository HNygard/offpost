FROM php:7.2-apache

RUN apt-get update && apt-get install -y libcurl4-gnutls-dev

RUN docker-php-ext-install curl
RUN docker-php-ext-install pdo pdo_mysql mysqli

#RUN apt-get install -y git

RUN a2enmod rewrite

COPY localhost.conf /etc/apache2/conf-enabled/localhost.conf
COPY phpdev.ini /usr/local/etc/php/conf.d/phpdev.ini

CMD apache2-foreground
