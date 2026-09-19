# Ravan backend (Laravel 13)

```
cp .env.example .env && php artisan key:generate
php artisan migrate --seed      # signal catalog + consent texts (+ demo data in local)
php artisan test                # 9 feature tests (sqlite in-memory)
vendor/bin/pint --test
php artisan serve
php artisan reverb:start        # websockets (install: composer require laravel/reverb && php artisan reverb:install)
```

Configuration lives in `config/ravan.php` (analysis service, WebRTC provider, consent versions, retention).

Demo accounts (local only, password `change-me-please`): `admin@ravan.local`, `dr.sara@ravan.local` (verified clinician), `dr.pending@ravan.local` (pending), `patient@ravan.local`.

See `docs/02-database.md`, `docs/03-api.md`.
