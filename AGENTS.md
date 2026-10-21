# AGENTS.md — Reprise GWEB (agent suivant)

Tu reprends un prototype WordPress de funnels ads déjà en prod (HealthDesire). Objectif : **scaler** et **déployer** cette stack sur l’infra GWEB sans casser le signal Meta ni exposer de PII.

## Règles non négociables

1. **Jamais** committer / pousser `wp_hd_leads`, drafts, `wp_tb_*` rows, logs clients, tokens API.
2. **Pixel** : toujours `1 loader + N init + 1 PageView`, primary ads en premier. Ne pas empiler N× PageView (fuite learning).
3. **CAPI Rockabilly** : `enabled=false` en prod GWEB sauf demande explicite. Le filtre `hd_meta_capi_enrich_lead_meta` doit rester actif (capture `fbp`/`fbc`/UA/IP).
4. **Secrets** : `wp-config.php`, access_token Meta, webhooks Espo → env / VPS only, jamais dans git.
5. Avant tout changement pixel/header : backup + rollback script.

## Carte mentale (où toucher quoi)

| Besoin | Fichier / option |
|--------|------------------|
| Pixel browser | `themes/hd-lite/header.php` |
| Forms / popup / Espo payload | `mu-plugins/hd-native-leads*` + option `hd_native_form_defs` |
| Enrich fbp (sans CAPI Rocka) | `mu-plugins/hd-meta-capi` + `enabled=false` |
| Map ads `page_id` → WP | `hd-page-id-router.php` + `hd_source_page_map` |
| Cloak | plugin `hd-cloak-links` + `hd_cloak_settings` |
| Catalog produits | `hd-catalog-products` + table `wp_hd_catalog_products` |
| Tracker clicks (code only) | `tb-tracker` — sample `tb_tracker_sample_rate` |
| LP static assets | `/white/{slug}/` |
| Contenu pages | SQL `02-content-posts-catalog.sql` |

## Incident connu (oct 2026) — ne pas reproduire

Désactiver entièrement `hd-meta-capi.php` a cassé l’enrich lead → `fbp` passé de ~100 % à ~23 %.  
**Correctif** : recharger le mu-plugin, garder `hd_meta_capi_settings.enabled = false`.

## Déploiement (ordre)

Voir `docs/DEPLOY.md`. Résumé :

1. WordPress frais + importer `sql/01` puis `02` puis `04`.
2. Copier `wp-content` (theme, mu-plugins, cloak, uploads, white).
3. Créer admin WP ; configurer `siteurl`/`home`.
4. Brancher Espo/webhooks/pixel primary en secrets VPS.
5. Smoke : LP `?page_id=2461` → Fire V4, submit lead test, merci, Espo, `fbp` présent.

## Scaling GWEB (pistes prioritaires)

- Multi-site ou multi-domaine avec même mu-plugins.
- CAPI sur **pixel ads primary** (pas Rocka) si besoin serveur.
- Réduire pixels backup init si Meta Events Manager le permet (prod HD garde 18 inits volontairement).
- Séparer DB tracker (retention) du WP app.
- CI : lint PHP mu-plugins + check header pixel (1 PV).

## Checks santé (prod)

```bash
# Sur VPS — adapter chemins
php /path/to/hd-monitor-tick.php   # clicks/CR fenêtres
# Leads: espo_ok ~100%, fbp >95%, fbclid >95%
```

## Contacts / origines

- Repo : `https://github.com/ramykossai/gweb`
- Source prod documentée : `healthdesire.online` (Hostinger VPS)
- Guide user HTML : `docs/guide-gweb.html`
