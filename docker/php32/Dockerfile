# PHP 32-bit (PHP_INT_SIZE === 4) runtime for the Admission integer-width gate (#213).
# The image is built and run for linux/386, so the interpreter is a genuine 32-bit build; nothing emulates the integer width.
# pcntl serves the race workers; ext-redis (pinned) lets the native-client boundary tests run on the same actual 32-bit build instead of
# failing closed for want of the extension (#240). The build tools are removed again in the same layer.
FROM php:8.4.27-cli
RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends $PHPIZE_DEPS \
    && docker-php-ext-install pcntl \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apt-get purge -y -qq --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
