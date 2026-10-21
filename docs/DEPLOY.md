# Déployer GWEB (nouvelle instance WordPress)

## Prérequis

- PHP 8.1+ / MySQL 8 (ou MariaDB 10.6+)
- WordPress core frais (même majeure que la source si possible)
- Accès SSH + `wp-cli` recommandé
- Secrets : pixel primary ID, (optionnel) token CAPI, credentials Espo/webhooks

## 1. WordPress core

```bash
wp core download --path=/var/www/gweb
cd /var/www/gweb
wp config create --dbname=... --dbuser=... --dbpass=... --dbhost=127.0.0.1
wp core install --url=https://TON_DOMAINE --title="GWEB" --admin_user=... --admin_password=... --admin_email=...
```

## 2. Copier le code de ce repo

```bash
rsync -a wordpress/wp-content/themes/hd-lite/ wp-content/themes/hd-lite/
rsync -a wordpress/wp-content/mu-plugins/ wp-content/mu-plugins/
rsync -a wordpress/wp-content/plugins/hd-cloak-links/ wp-content/plugins/hd-cloak-links/
rsync -a wordpress/white/ white/   # docroot sibling or public_html/white
rsync -a wordpress/wp-content/uploads/ wp-content/uploads/
```

Activer thème + cloak :

```bash
wp theme activate hd-lite
wp plugin activate hd-cloak-links
```

## 3. Import SQL (ordre)

```bash
wp db import sql/01-schema-all-tables.sql
# Si conflits de tables déjà créées par WP, importer seulement les tables HD manquantes
# ou drop DB vide puis re-import schema+content.

wp db import sql/02-content-posts-catalog.sql
wp db import sql/04-options-selected.sql
```

Puis corriger URLs :

```bash
wp option update siteurl 'https://TON_DOMAINE'
wp option update home 'https://TON_DOMAINE'
wp search-replace 'https://healthdesire.online' 'https://TON_DOMAINE' --all-tables --skip-columns=guid
wp rewrite flush
```

## 4. Secrets post-deploy (VPS only)

```bash
# CAPI Rocka reste OFF
wp option patch update hd_meta_capi_settings enabled false
wp option patch update hd_meta_capi_settings send_view_content false

# Vérifier enrich chargé
wp eval 'echo has_filter("hd_native_lead_meta","hd_meta_capi_enrich_lead_meta") ? "enrich:ON\n" : "enrich:OFF\n";'

# Sample tracker
wp option update tb_tracker_sample_rate 20
```

Mettre à jour dans `hd_native_form_defs` les webhooks / projets Espo de **l’environnement GWEB** (ne pas réutiliser aveuglément la prod HD si isolation requise).

## 5. Pixel header

Contrôler `themes/hd-lite/header.php` :

- Primary pixel GWEB en premier `fbq('init', …)`
- Exactement un `fbq('track', 'PageView')`
- Pas de pixel Rocka browser si non désiré

## 6. Smoke test bout en bout

1. Ouvrir `/?page_id=2461` (doit router vers Fire Bowl V4).
2. DevTools → Network : `fbevents.js` + hit pixel.
3. Submit form test → row `wp_hd_leads` avec `fbp`/`fbc`/`fbclid`.
4. Espo reçoit le lead ; redirect merci.
5. Meta Events Manager : PageView + custom conversion merci (si configurée).

## 7. Ce qu’il ne faut PAS faire

- Importer un dump complet prod avec `wp_hd_leads` / `wp_tb_*`.
- Committer `wp-config.php` ou tokens.
- Remettre N× PageView “comme les shortcodes empilés d’avant”.
- Désactiver `hd-meta-capi.php` entièrement (casse l’enrich).

## Rollback

Garder un backup DB + `header.php` avant chaque change pixel. Scripts historiques HD : `~/.cursor/backups/hd-pixel-fix-*` / `hd-rocka-remove-*` sur le VPS source.
