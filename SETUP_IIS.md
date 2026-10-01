# Hosting on IIS as http://venuebooking/

## 1. Physical path

Point the IIS site (or application) **physical path** to this folder:

```
...\venue-booking-system\
```

That folder must contain:

- `index.html`
- `pages\`
- `backend\`
- `web.config`  ← API rewrite

## 2. Bindings

- Host name: `venuebooking` (or `venuebooking.yourdomain.local`)
- Add to `C:\Windows\System32\drivers\etc\hosts` if needed:

```
127.0.0.1  venuebooking
```

(Use the IIS server IP if the site is on another machine.)

## 3. PHP on IIS

- Install PHP 8.x + **php_pdo_sqlsrv** + ODBC Driver for SQL Server
- In IIS Handler Mappings, `.php` must be mapped to `php-cgi.exe`
- Confirm: `http://venuebooking/backend/api/ping.php`

## 4. URL Rewrite

Install **IIS URL Rewrite** module, then:

| URL | Result |
|-----|--------|
| `http://venuebooking/backend/api/ping.php` | PHP alive (no DB) |
| `http://venuebooking/backend/api/health.php` | DB check |
| `http://venuebooking/api/health` | Same check via rewrite → `index.php` |

## 5. If the browser says “cannot be reached”

That is **network / DNS / IIS**, not SQL:

1. Can you open `http://venuebooking/` at all?
2. Is the IIS site **Started**?
3. Does `ping venuebooking` resolve?
4. Firewall blocking port 80/443?
5. Wrong physical path (empty site)?

## 6. SQL is separate

After `ping.php` works, open `health.php`.  
If health fails, the message is about **SQL Server / credentials / VenueBooking DB**, not the website URL.
