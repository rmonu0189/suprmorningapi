# Migrating data from Supabase (Postgres) to this API (MySQL)

This is optional. The app no longer talks to Supabase; you only need this if you want to keep existing users, catalog, or orders.

1. **Export** from Supabase: SQL dump or CSV per table (`users` / `auth.users`, `products`, `variants`, `pages`, `orders`, etc.). Your Edge Function logic for totals is not in this repo—reconcile order/charge rules in PHP if you import historical orders.

2. **Map IDs:** The PHP API uses UUID `users.id` (same shape as Supabase `auth.users.id` if you preserve them). Map `profiles` → `users` columns (`phone`, `full_name`, `role`).

3. **Import** into MySQL in dependency order: `users` → `brands` → `products` → `variants` → `inventory` → `pages` → then commerce tables (`carts`, `cart_items`, `addresses`, `loves`, `orders`, `order_items`, `payments`). Adjust column names/types (Postgres `timestamptz` → MySQL `DATETIME`, JSON columns, etc.). Run `database/migrations/006_payments.sql` if `payments` is missing.

4. **OTP / passwords:** This API uses phone OTP only; there is no password hash import from Supabase Auth. Users keep the same `id` and `phone` so the next OTP login matches `users.phone`.

5. **Refresh tokens:** Not portable; users sign in again after cutover.
