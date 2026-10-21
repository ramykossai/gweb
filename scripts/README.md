# Scripts

Export prod (sur le VPS source) : utiliser une variante de `gweb-export.sh` (bash + rsync + mysqldump) — ne jamais inclure les tables PII listées dans `sql/03-EMPTY-pii-tables.txt`.

Import options après deploy : `wp db import sql/04-options-selected.sql` puis corriger `siteurl` / `home`.
