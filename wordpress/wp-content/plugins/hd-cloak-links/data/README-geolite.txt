GeoLite2 databases (optional but recommended)
=============================================

Place MaxMind GeoLite2 files here (or update paths in Smart Links → Settings):

  data/GeoLite2-Country.mmdb   → country allowlist
  data/GeoLite2-ASN.mmdb       → cloud ASN blocking

Download (free MaxMind account required):
  https://dev.maxmind.com/geoip/geolite2-free-geolocation-data

Without these files:
  - Bot UA filter still works
  - Datacenter CIDR filter still works
  - Country allowlist and ASN cloud block are skipped

Do not commit MaxMind DB files if your license forbids redistribution.
