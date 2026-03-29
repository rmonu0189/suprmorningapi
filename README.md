# SuprMorning API

Secure-by-default Core PHP API skeleton (same layering as before, domain routes removed).

- PDO / prepared statements when you add repositories
- Strict validation via `Validator` + `ValidationException`
- Security headers + configurable CORS
- IP rate limiting + login lockout helpers (`src/Security/`) for when you add auth
- Stateless JWT helpers (`Jwt`) + `AuthMiddleware` (JWT-only; add DB checks when you have users)
- Central exception handling

## Layout

```
database/
  schema.sql       — full MySQL schema (fresh installs)
  migrations/      — incremental SQL for existing databases (optional)
public/
  index.php
  api-docs.html
src/
  Core/          App, Router, Request, Response, Database, Jwt, Env, Validator, …
  Controllers/   AuthController (+ your controllers)
  Middleware/    AuthMiddleware
  Security/      RateLimiter, LoginLockout
storage/logs/
```

## Quick start

1. `cp .env.example .env` and set `JWT_SECRET` and database credentials when you use MySQL. Create the database, then load tables: `mysql -u USER -p DB_NAME < database/schema.sql`.
2. From project root: `php -S localhost:8000 -t public`
3. Open `http://localhost:8000/api-docs.html` for endpoint reference.

## Docs page

Open `GET /api-docs.html` on the same host as the API for a short SuprMorning reference.

## Deploy

Point the web server document root to `public/`. For Apache, keep `public/.htaccess` (`mod_rewrite`, `AllowOverride All`).
