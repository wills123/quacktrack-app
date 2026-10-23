# Quack Track (Dockerized Nginx + PHP + MySQL)

A simple, secure shop-like internal tool to manage products you order for work, upload product images, track van stock, and log usage to charge the correct store.

## Features
- Login with admin user (created from .env on first run)
- Add products with image uploads (JPG/PNG/GIF), unique product IDs
- View all products (with images)
- **Shopping Cart & Ordering System**
  - Add products to cart with quantities and notes
  - Bulk add multiple items by SKU
  - Save and load order templates
  - Mark products as favorites
  - Send orders via email (opens Outlook with pre-filled content)
- Adjust van stock (add, use, adjust) with optional store field
- View transactions history (most recent 200)
- **Order Management**
  - Order history with reorder functionality
  - Order status tracking
  - Store-specific ordering
- CSRF protection, prepared statements, sessions hardened, Nginx denies PHP in uploads

## Prerequisites
- Docker Desktop, or Podman with `podman-compose` (Podman works on Fedora/Nobara)

## Setup
1. Create the project env file
   - Copy `.env.example` to `.env` and set strong values:
     - APP_SECRET, ADMIN_EMAIL, ADMIN_PASSWORD
     - MYSQL_ROOT_PASSWORD, MYSQL_PASSWORD
     - APP_URL must be the real public origin used for password-reset links. For local HTTP development, use APP_ENV=dev and APP_URL=http://localhost:8081.
     - For production behind HTTPS termination, set SESSION_COOKIE_SECURE=1 in the deployed environment.
2. Start the stack:
   - `docker compose up -d`
   - With Podman: `podman compose up -d`
3. If the database was just created and registration or another schema-dependent page reports missing columns, run the existing migrations after MySQL is ready:
   - Docker: `docker compose exec quacktrack-php php /var/www/html/app/run_migrations.php`
   - Podman: `podman compose exec quacktrack-php php /var/www/html/app/run_migrations.php`
4. Initialize the admin account:
   - Visit `http://localhost:8081/login.php` and sign in with the admin credentials from `.env`
   - If needed, run `http://localhost:8081/init_db.php` once to ensure the admin exists (the DB schema loads automatically).

## Usage
- **Products**: Add products and upload images at `/products.php`
- **Shopping**: Add items to cart, create orders at `/cart.php`
- **Orders**: View order history and reorder at `/orders.php`
- **Stock Management**: Manage van stock at `/stock.php`
- **Transactions**: Review transactions at `/transactions.php`
- **Testing**: Visit `/test_ordering.php` to verify the ordering system

## Security notes
- Nginx disallows executing PHP from `/uploads`
- PHP limits upload size to 5MB (adjust in `.env`)
- CSRF tokens on all forms
- Passwords stored using Argon2/bcrypt (via password_hash)
- Use HTTPS in production; enable `session.cookie_secure=1` behind TLS
- Authenticated sessions validate the User-Agent and expire after `SESSION_TIMEOUT` seconds of inactivity (default: 3600). IP and Accept-* header changes do not invalidate a session. User-Agent matching is an additional signal, not proof of client identity.
- Session IDs change at local/SSO login and registration, then every 30 minutes of authenticated activity. Periodic renewal leaves a 60-second alias for overlapping requests; it contains no authenticated state and resumes the same successor before controllers read session data. Expired aliases and logged-out successors cannot restore authentication. CSRF tokens survive periodic renewal.
- Existing sessions receive the new fingerprint format once on their next validated request. No database migration is required. A browser/User-Agent change requires signing in again.

## Backups
- MySQL data persists in the named Docker volume `parts_db_data`
- To back up: `docker compose exec db sh -c "mysqldump -u$MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE" > backup.sql`

## Stop and remove
- `docker compose down`
- To remove data (CAUTION): `docker volume rm parts-shop_parts_db_data`

