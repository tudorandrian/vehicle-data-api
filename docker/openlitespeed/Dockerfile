FROM litespeedtech/openlitespeed:1.9.2-lsphp84

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && ln -sf /usr/local/lsws/lsphp84/bin/php /usr/local/bin/php

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/openlitespeed/httpd_config.conf /usr/local/lsws/conf/httpd_config.conf
COPY docker/openlitespeed/vhconf.conf       /usr/local/lsws/conf/vhosts/Example/vhconf.conf
COPY docker/openlitespeed/99-vehicle-data.ini /usr/local/lsws/lsphp84/etc/php/8.4/mods-available/99-vehicle-data.ini
COPY docker/openlitespeed/entrypoint.sh /usr/local/bin/vehicle-data-entrypoint.sh
RUN chmod +x /usr/local/bin/vehicle-data-entrypoint.sh

WORKDIR /var/www/vhosts/localhost/html

ENTRYPOINT ["/usr/local/bin/vehicle-data-entrypoint.sh"]
