Running this example:

```
# Setup env
cp .env.example .env

# Create SQLite database:
touch database/database.sqlite

# Migrate schema
php artisan migrate

# Run webserver
php artisan serve
```
