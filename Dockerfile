FROM php:8.3-apache

# Use production PHP configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Set Apache ServerName and block direct HTTP access to SQLite database files
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && echo '<FilesMatch "\.(db|sqlite|sqlite3)$">\n    Require all denied\n</FilesMatch>' > /etc/apache2/conf-available/security-sqlite.conf \
    && a2enconf security-sqlite

# Set working directory to Apache web root
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Ensure Apache user (www-data) owns the files and directory for SQLite write operations
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html

EXPOSE 80

# Health check verifying Apache and PHP respond properly
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD php -r 'file_get_contents("http://127.0.0.1/") ?: exit(1);'

CMD ["apache2-foreground"]
