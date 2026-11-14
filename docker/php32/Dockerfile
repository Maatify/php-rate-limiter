# PHP 32-bit (PHP_INT_SIZE === 4) runtime for the Admission integer-width gate (#213).
# The image is built and run for linux/386, so the interpreter is a genuine 32-bit build; nothing emulates the integer width.
FROM php:8.4.27-cli
RUN docker-php-ext-install pcntl
