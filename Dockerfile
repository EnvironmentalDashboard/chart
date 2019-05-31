FROM php:7-apache
ADD https://github.com/EnvironmentalDashboard/includes/archive/master.zip /var/www/html/includes/
# https://gist.github.com/chronon/95911d21928cff786e306c23e7d1d3f3 for possible docker-php-ext-install values
RUN apt-get update && apt-get install -y unzip && \
	docker-php-ext-install pdo_mysql && \
  unzip -j /var/www/html/includes/master.zip -d /var/www/html/includes/ && \
  rm /var/www/html/includes/master.zip
COPY . /var/www/html/
EXPOSE 80