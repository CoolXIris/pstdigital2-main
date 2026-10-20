<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Gemini chatbot API

The Laravel chatbot calls the private FastAPI service configured by
`GEMINI_CHAT_API_URL`. Keep `GEMINI_API_KEY` and the shared
`GEMINI_CHAT_API_TOKEN` on the server; do not expose either value in browser code
or commit populated values to source control.

Install the Python service dependencies and start the API from the project root:

```powershell
py -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
uvicorn main:app --host 127.0.0.1 --port 8001
```

Set the same strong `GEMINI_CHAT_API_TOKEN` in Laravel and the Python process,
and set `GEMINI_API_KEY` for the Python process. `GEMINI_MODEL` defaults to
`gemini-flash-lite-latest`. For a separately hosted service, set
`GEMINI_CHAT_API_URL` to its private or HTTPS `/api/chat` URL.

## Canonical BPS indicators

`config/bps_indicators.json` is the shared source of canonical indicator IDs
and metadata. Laravel loads it through `config/bps_indicators.php`; the Python
service reads the JSON directly. Keep changes in the JSON source, not in
language-specific copies.

Set `BPS_API_KEY` in the server environment to run the live golden checks and
coverage check:

```powershell
php artisan bps:verify-canonical-goldens
php artisan bps:check-canonical-indicators
```

The daily coverage check is scheduled for 03:00 Asia/Jakarta. It checks every
canonical indicator against all 17 regencies/cities when the variable title
indicates kabupaten/kota coverage, checks the province row for province-level
series, and warns when the latest data is more than one year behind. Golden
questions and optional expected year/value snapshots are maintained in
`tests/Fixtures/bps-golden-questions.json`. Update the snapshot deliberately
after checking the published API value when BPS releases a newer period.
The canonical unemployment variable (var_id 334) remains province-only; when a
regional value is requested, the chatbot can use a matching SIMDASI table instead.

## CSV-backed BPS catalog

The Python chatbot reads `tabel_bps_sumsel.csv` from the project root (or from
`BPS_CATALOG_CSV`). The file must contain `kategori_subjek`, `subjek`,
`id_subjek`, `judul`, `id_tabel`, `jenis_tabel`, `tahun`, and `link_json`.
Rows with an empty link or `TAHUN_TIDAK_TERSEDIA` are ignored. The catalog is
reloaded when its file signature changes, so a refreshed weekly CSV can be used
without restarting the service.

Numerical data requests use the selected row's `link_json`; the API key is
appended from `BPS_API_KEY` at request time and is never included in logs or
answers. Dynamic rows are selected by table ID and year, static tables use the
single catalog row, and SIMDASI rows are selected by table ID and year. Python
and Laravel share persistent storage in the active Laravel database connection
(MySQL in this deployment). Python reads the `DB_*` settings from the same
environment as Laravel. The Laravel fallback uses the same CSV for numerical tables and reads the credential from
`BPS_API_KEY`, not from application-database settings. The CSV is the only
runtime catalog for numerical statistical tables. BRS
releases, publications, and statistical news are editorial records and are
queried separately from the BPS `pressrelease`, `publication`, and `news`
list/detail models when those intents are detected. These requests also use
`BPS_API_KEY`, include requested keyword/year/month/page filters, retrieve
detail abstracts for up to three results. Filtered lists are cached for one
hour and details for up to 24 hours, while unfiltered/latest lists are fetched
from WebAPI on each request. Answers
use only API-returned abstract/article text and include the release date and
source/PDF link when available; they do not claim to summarize unread PDFs.

The shared MySQL database stores raw table responses in `data_tabel`, dynamic-table
metadata in `tabel_meta`, and parsed values in `nilai_data`. Dynamic values are
decoded by matching every dimension-ID combination against `datacontent`
keys, then filtered with SQL before being sent to the language model. Static
tables and SIMDASI records remain raw JSON. Dynamic tables refresh every
seven days for the current or previous year and every 90 days for older years;
static tables refresh every 30 days. If refresh fails, an existing older table
response may be returned with an explicit staleness note. Editorial response
metadata is stored separately in MySQL. Run the Laravel migration after
deploying to create the BPS storage tables.

The CSV-generation utility is separate from chatbot runtime lookup and may be
used by the catalog-maintenance workflow to refresh the CSV.

Unresolved data requests are emitted by the Python service as structured
`BPS_QUERY_UNRESOLVED` warning log events. The logged question is length-limited
and email addresses and phone numbers are redacted.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
