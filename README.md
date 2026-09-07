# Garden IPTV

PHP-based Garden IPTV feed using the source logic from the supplied JioTV-Garden project.

- `playlist.php` — live Garden M3U
- `working.m3u` — latest GitHub Actions health-check result
- `non_working.m3u` — streams that failed the latest check
- `garden_feed.php` — source feed generator
- `generate_playlists.php` — refresh + stream health check
- `.github/workflows/update-playlists.yml` — automatic update every 6 hours

No JioTV-Go executable is used. Login/token cache files are intentionally not committed.
