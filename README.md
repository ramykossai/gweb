# GWEB — WordPress lead funnel stack (HealthDesire lineage)

Prototype WordPress **scalable** pour funnels ads → LP → popup lead → EspoCRM / Meta pixel, extrait de la prod `healthdesire.online` **sans données clients ni tracker**.

## Ce dépôt contient

| Chemin | Contenu |
|--------|---------|
| `wordpress/wp-content/themes/hd-lite/` | Thème LP / boutique |
| `wordpress/wp-content/mu-plugins/` | Native leads, CAPI enrich, tracker code, router `page_id`, catalog, harden |
| `wordpress/wp-content/plugins/hd-cloak-links/` | Cloaking geo / bots |
| `wordpress/white/` | Assets LP “white” (CSS/HTML produits) |
| `wordpress/wp-content/uploads/` | Médias boutique / LP (pas de logs/cache) |
| `sql/` | Schéma + contenu posts/catalog + options sélectionnées |
| `docs/` | Architecture, deploy, guide HTML |

## Explicitement exclus (restent sur le VPS)

- `wp_hd_leads` / `wp_hd_lead_drafts` (PII clients)
- `wp_tb_clicks` / `wp_tb_events` / audiences tracker
- Entrées Forminator, commentaires, logs Action Scheduler
- `wp_users` / mots de passe
- Tokens CAPI / Espo / API (redactés)

## Démarrage agent

1. Lire **`AGENTS.md`** (contrat de reprise).
2. Lire `docs/ARCHITECTURE.md` puis `docs/DEPLOY.md`.
3. Ouvrir `docs/guide-gweb.html` (présentation produit / guide user).

## Stack résumé

- **Pixel Meta** : 1× loader + N× `init` + 1× `PageView` (primary first).
- **Leads** : `hd-native-leads` → Espo + webhook map ; enrich `fbp`/`fbc` via filtre (CAPI Rocka **OFF**).
- **Router** : `hd_source_page_map` — URL ads `?page_id=SOURCE` → page WP réelle.
- **Cloak** : pays `MA,DZ,TN` + block bots/datacenter.
- **Tracker** : `tb-tracker` (sample rate configurable) — **données non versionnées**.

## Licence / usage

Usage interne Herbaltech / GWEB. Ne pas publier de dumps leads.
