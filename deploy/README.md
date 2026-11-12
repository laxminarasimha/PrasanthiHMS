# Deploying to shared hosting (cPanel)

The app deploys as one site: Laravel serves both the API (`/api/...`) and the built React app.

## Requirements on the server

- PHP 8.3 or newer, with the `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `tokenizer`, `ctype`, `xml`, `bcmath` and `curl` extensions (all standard in cPanel)
- MySQL 8 database (already set up; see `REMOTE_DB_*` in `backend/.env`)
- A domain or subdomain whose **document root can be chosen**, for example `hms.yourdomain.com`

## One-time setup in cPanel

1. **Select PHP Version** (or MultiPHP Manager): choose PHP 8.3 or newer for the domain.
2. **Domains → Create a new domain**: create the subdomain and set its document root to `prasanthihms/public`.
3. **SSL/TLS Status**: run AutoSSL for the new subdomain so it has https.

## Each deployment

1. On your computer, build the package with the site's address:

   ```bash
   deploy/build.sh https://hms.yourdomain.com
   ```

2. Bring the server's database tables up to date:

   ```bash
   cd backend && php artisan db:sync-remote
   ```

3. In cPanel **File Manager**, go to the home folder (the one that contains `public_html`), upload `deploy/package/prasanthihms.zip`, and **Extract** it there. When redeploying, delete the old `prasanthihms` folder first, keeping `prasanthihms/storage/logs` only if you want the old logs.
4. Open the site and sign in.

## Before real patients go in (once)

The server database was first filled from sample data, including logins ending in `@prasanthi.test` whose password is public. Create your own admin login and switch those off. Run this on your computer; it asks for the password in the terminal and does not store it anywhere:

```bash
cd backend && php artisan hms:admin you@example.com --name="Your Name" --remote --disable-samples
```

The zip contains a `.env` file with the database password. Delete the zip from the server after extracting, and do not put it anywhere public.

## If something goes wrong

- **A blank page or "500" error**: look at `prasanthihms/storage/logs/` in File Manager. The usual causes are the PHP version being too old or the `storage` and `bootstrap/cache` folders not being writable (permission 755 for folders).
- **"404" for every page except the home page**: the document root is not `prasanthihms/public`, or `.htaccess` was not uploaded (enable "Show Hidden Files" in File Manager settings to see it).
- **Sign-in says the email or password is incorrect**: the server database has no staff accounts yet, or different ones from your local database.
