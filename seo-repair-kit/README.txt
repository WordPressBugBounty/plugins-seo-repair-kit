=== SEO Repair Kit - Spam Monitor, Meta Manager, Schema Manager, SEO Content Monitoring, GSC Integration, Keyword & Rank Tracking ===
Contributors: torontodigits
Donate link: https://seorepairkit.com/
Tags: spam monitor, meta manager, broken link, schema markup, 301 redirect, 404 monitor
Requires at least: 5.0.0
Tested up to: 7.1
Requires PHP: 7.4.3
Stable tag: 2.1.10
Release Date: 21-08-2026
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Monitor and repair WordPress SEO with link scans, redirects, metadata, schema, Search Console insights, and indexed-spam monitoring.

== Description ==

**SEO Repair Kit** by [TorontoDigits](https://www.torontodigits.com) helps WordPress site owners monitor, identify, and fix practical SEO issues from one dashboard.

It combines link health monitoring, 404 tracking, redirects, metadata management, sitemap controls, image alt-text checks, Search Console insights, schema tools, bot management, automated reports, and indexed-spam monitoring.

SEO Repair Kit is designed for site owners, developers, marketers, content teams, and agencies that want actionable SEO tools without managing several separate workflows.

= Core Features =

**Links Manager**
* Scan internal and external links for HTTP status issues
* Review broken, healthy, redirected, and problematic links
* Export findings to CSV
* Create redirects from problem URLs
* Access Link Scan, 404 Monitor, Auto Scan, Notifications, and Smart Redirects from one Links Manager area

**Auto Scan**
* Schedule automatic link scans
* Choose scan intervals such as daily, every 3 days, weekly, biweekly, or monthly
* Configure scan scope, post types, batch size, links per post, and request timeout
* Store scan history and latest scan snapshots

**Notifications**
* Send automated scan reports and broken-link or clean-scan alerts by email
* Configure recipients and email notification preferences
* Review alert history from the Links Manager

**Smart Redirects**
* Automatically create 301 redirects from eligible broken singular URLs to their post-type archives
* Enable Smart Redirects per post type
* Reset all Smart Redirect records or reset records by selected post type
* Manage generated redirects from the Redirection Manager

**Redirection + 404 Monitoring**
* Create and manage 301/302 redirects
* Track redirect hits and logs
* Monitor 404 errors with actionable details
* Convert recurring 404 URLs into redirects quickly

**Meta Manager**
* Manage SEO titles and descriptions globally and per content type
* Configure robots directives, including max preview directives
* Override metadata on individual posts/pages
* Supports Gutenberg and Elementor workflows
* Supports dynamic template tags like `%title%`, `%excerpt%`, and `%site_title%`

**KeyTrack (Search Console Insights)**
* Works with Google Site Kit/Search Console
* View clicks, impressions, CTR, and average position
* Analyze page/query trends in one place
* Configure threshold-based reporting workflows

**Schema Manager (Pro)**
* Build and manage JSON-LD schema with visual controls
* Map WordPress content to supported schema properties
* Preview configured schema before output

**AI Chatbot (Pro)**
* Get contextual SEO Repair Kit guidance directly inside WordPress
* Ask questions about plugin features and common SEO workflows

**Sitemap Manager**
* Include/exclude post types and taxonomies from WordPress core sitemap
* Keep `wp-sitemap.xml` focused on important content
* Note: this controls only core WordPress sitemap output

**Bot Manager**
* Edit and validate `robots.txt`
* Generate and manage `llms.txt`
* Allow/block selected AI crawlers

**Alt Text Manager**
* Identify images missing alt text
* Update alt text records efficiently

**Spam Monitor (Pro)**
* Scan indexed Google SERP results for suspicious, spam, and critical URL risks
* Configure Spam Rules for language mismatch, spam keywords, suspicious URL patterns, and score thresholds
* Review risky URLs, returned SERP records, recent scans, alerts, and cleanup history
* Schedule Spam Monitor scans daily, every 3 days, weekly, biweekly, or monthly
* Send scan reports and alerts by email with saved scan history in the WordPress dashboard

Custom SERP-provider connections and other advanced Spam Monitor capabilities require the paid module.

**Weekly SEO Summary**
* Receive scheduled email summaries with key SEO status metrics
* Includes Search performance, link health, Spam Monitor status, image alt coverage, and redirect insights

== Screenshots ==

1. SEO Repair Kit Dashboard Overview
2. SEO Repair Kit - Links Manager
3. SEO Repair Kit 404 Monitor
4. SEO Repair Kit Auto Scan
5. SEO Repair Kit Notifications
6. SEO Repair Kit Smart Redirect
7. SEO Repair Kit Advanced Redirections
8. SEO Repair Kit Import and Export Redirections
9. SEO Repair Kit Image Alt Text Manager
10. SEO Repair Kit Schema Manager
11. SEO Repair Kit KeyTrack Overview Dashboard
12. Meta Manager – Global Meta
13. Meta Manager – Content Types Overview
14. Meta Manager – Taxonomies Overview
15. Meta Manager – Archives Overview
16. Meta Manager – Advance Settings
17. SEO Repair Kit - Sitemap Manager
18. Bot Manager - llms.txt Management
19. Bot Manager - robots.txt Management
20. SEO Repair Kit AI Chatbot Interface
21. SEO Repair Kit Settings
22. SEO Repair Kit Upgrade to Pro
23. SEO Repair Kit Weekly Email Report
24. SEO Repair Kit KeyTrack Threshold Email Report
25. SEO Repair Kit Broken Links Detected Email Report
26. SEO Repair Kit No Broken Links Detected Email Report
27. SEO Repair Kit Spam Monitor Dashboard
28. SEO Repair Kit Spam Rules
29. SEO Repair Kit Google SERP Scan
30. SEO Repair Kit Search Console Cleanup
31. SEO Repair Kit Spam Monitor Alerts
32. SEO Repair Kit Scheduled Spam Monitoring Settings

== Changelog ==

= 2.1.10 =
* Compatibility: Verified release metadata and admin/editor integration for the current WordPress compatibility target.

= 2.1.9 =
* New: Added Spam Monitor module for indexed Google SERP health checks, risky URL review, spam scoring, alerts, and scan history.
* New: Added Spam Rules for language mismatch detection, spam keyword categories, suspicious URL patterns, and configurable score thresholds.
* New: Added Google SERP Scan dashboard with provider status, scan configuration, returned SERP records, and recent scan history.
* New: Added scheduled Spam Monitoring with daily, every 3 days, weekly, biweekly, monthly, and testing interval support.
* New: Added Spam Monitor email reports, alert history, export/clear table actions, and paginated record tables.
* Improvement: Added Spam Monitor dashboard stats, charts, module status messaging, and WordPress admin UI refinements.

== Upgrade Notice ==

= 2.1.10 =
Compatibility release for current WordPress versions with hardened editor asset loading.

== Installation ==

1. Download the plugin zip file.
2. Go to your WordPress admin panel and navigate to Plugins > Add New.
3. Click "Upload Plugin" and select the `seo-repair-kit.zip` file.
4. Click "Install Now" and then "Activate Plugin".
5. After activation, you'll be guided through an onboarding process to configure the plugin.

For manual installation, upload the seo-repair-kit directory to /wp-content/plugins/ and activate the plugin from the WordPress Plugins screen.

== Configurations & Usage ==

After activation, open SEO Repair Kit from the WordPress admin menu.

= Initial Setup & Onboarding =

1. After activating the plugin, you'll be guided through an interactive onboarding process.
2. During onboarding, you can configure:
   * Post types to scan for broken links
   * Enable/disable KeyTrack feature
   * Set up link scanning schedule (manual, weekly, or monthly)
   * Select default schema types to use
   * Configure notification preferences (weekly reports, KeyTrack alerts, broken links notifications)
   * Enable alt text scanning
   * Enable redirection management
   * Review Spam Monitor module settings when available
   * Set notification email address
3. Complete the onboarding to save your preferences, or skip and configure later in Settings.

= Dashboard Overview =

Open SEO Repair Kit from the WordPress admin menu to view site health, SEO issues, feature status, and quick-access tools.

= Links Manager =

Go to "SEO Repair Kit" > "Links Manager" to manage link health from one place. It includes Link Scan, 404 Monitor, Auto Scan, Notifications, and Smart Redirects.

= Link Scan =

Use the Link Scan tab to manually scan selected post types, review link URLs, HTTP status codes, link context, and quickly export broken links or create redirects from scan results.

= Auto Scan =

Use the Auto Scan tab to schedule automatic link scans. Enable Automation, choose the scan interval, set link scope, scan coverage, batch limits, request timeout, email alerts, and save settings.

Important: Automation must be enabled before scheduled scans can run.

= Notifications =

Use the Notifications tab to review automated scan email history, including scan time, trigger type, checked links, broken links, email status, subject, and recipients. Reports are sent for broken-link scans and clean scans when alerts are enabled.

= Smart Redirects =

Use the Smart Redirects tab to automatically create 301 redirects for broken internal singular URLs to their post-type archive pages.

Examples:
* `/case-studies/broken-slug/` redirects to `/case-studies/`
* `/blog/deleted-post/` redirects to `/blog/`
* `/products/old-item/` redirects to `/products/`

You can enable Smart Redirects per post type, view generated records, toggle status, delete records with linked redirects, reset all records, reset by selected post type, and manage all redirects in the Redirection Manager.

Note: Post types without archive pages cannot be enabled for archive redirects.

= Alt Text Manager =

1. Navigate to "SEO Repair Kit" > "Image Alt Missing" in the admin menu.
2. View all images missing alt text with their details.
3. Update alt text individually:
   * Click on an image to edit
   * Enter descriptive alt text
   * Save changes
4. Use bulk update feature to update multiple images at once.
5. Filter and search images by post type or status.
6. Monitor alt text optimization progress and statistics.

= Redirection Manager =

1. Go to "SEO Repair Kit" > "Redirection" in the admin menu.
2. Create a new redirect:
   * Enter source URL (old URL)
   * Enter target URL (new URL)
   * Select redirect type (301 Permanent or 302 Temporary)
   * Optionally enable regex pattern matching
   * Set redirect status (active/inactive)
   * Save the redirect
3. View all redirects in a comprehensive table showing:
   * Source and target URLs
   * Redirect type and status
   * Hit count and last hit timestamp
   * Position for ordering
4. Edit or delete existing redirects.
5. Monitor most active redirects with hit analytics.
6. Use the redirect logs to track redirect performance.

= 404 Error Monitor =

1. Navigate to "SEO Repair Kit" > "404 Manager" (or access via Links Manager).
2. Enable 404 monitoring in Settings if not already enabled.
3. View all 404 errors with details:
   * Requested URL
   * Referrer information
   * User agent and IP address
   * Access count and timestamps
4. Create redirects directly from 404 logs:
   * Select a 404 error
   * Choose target URL
   * Create redirect with one click
5. Filter 404 errors by domain, date, or count.
6. Monitor 404 trends and patterns.

= Sitemap Control =

1. Go to **SEO Repair Kit Sitemap Control**
2. Enable the option **Enable Sitemap Control**
3. Select the post types you want to include (Posts, Pages, Custom Post Types, etc.)
4. Select the taxonomies you want to include (Categories, Tags, custom taxonomies)
5. Click **Save Sitemap Settings**

Only the selected items will remain in your WordPress core sitemap.

Tip: If your sitemap is not opening, go to **Settings Permalinks** and click œSave Changes to refresh it.

= Bot Manager =

Open SEO Repair Kit > Bot Manager to manage robots.txt, generate llms.txt, and configure supported AI crawler access.

= KeyTrack - Keyword Performance Tracking =

1. Install and activate Google Site Kit plugin (required for KeyTrack).
2. Connect Google Site Kit to your Google Search Console account.
3. Go to "SEO Repair Kit" > "KeyTrack" in the admin menu.
4. Create a KeyTrack configuration:
   * Enter a name for your KeyTrack
   * Select keywords to track
   * Choose date range for analysis
   * Configure threshold settings (optional)
   * Save configuration
5. View performance data in multiple tabs:
   * **Overview**: Summary metrics (clicks, impressions, CTR, average position)
   * **Pages**: Top performing pages with detailed metrics
   * **Queries**: Top search queries with performance data
   * **Settings**: Manage KeyTrack configurations
6. Set up email reports:
   * Configure threshold alerts
   * Set report frequency
   * Add recipient email addresses
7. Analyze trends with interactive line charts.
8. Export data for external analysis.
9. Watch the demo video: [youtube https://www.youtube.com/watch?v=uiWgcazUDcc]

= Schema Manager (Pro Feature) =

With the required Pro module active, open SEO Repair Kit > Schema Manager, choose a schema type, configure its field mappings, and save the schema.

Configured schema is output as JSON-LD on applicable pages.

= Spam Monitor (Pro Feature) =

Go to "SEO Repair Kit" > "Spam Monitor" to review indexed Google result health, risky URLs, spam scoring, and scan history from one dashboard.

Spam Monitor includes:
* **Dashboard**: Review total SERP scans, checked Google results, critical indexed spam, cleanup queue, scan status, and risk summaries.
* **Spam Rules**: Configure language mismatch rules, spam keyword categories, suspicious URL patterns, and score thresholds.
* **Google SERP Scan**: Run manual scans, review returned Google SERP records, and inspect recent scan history.
* **Search Console Cleanup**: Organize cleanup review steps for suspicious indexed URLs.
* **Alerts**: Review Spam Monitor alert history and recent notifications.
* **Settings**: Configure scheduled Spam Monitoring, alert recipients, scan cadence, and scan request limits.

Scheduled Spam Monitor scans can run daily, every 3 days, weekly, biweekly, or monthly. A short testing interval may be available for development/testing workflows.

Free users can scan with the SEO Repair Kit trial provider. The paid Spam Monitor module allows supported custom SERP provider connections such as Serper.dev, SERP API, and DataForSEO.

= Meta Manager =

1. Navigate to "SEO Repair Kit" > "Meta Manager".

2. Configure SEO using these tabs:

   **Global Meta**
   * Set title separator, homepage SEO title & description
   * Configure default SEO templates and knowledge graph

   **Content Types**
   * Define title and description templates for posts, pages, and CPTs
   * Configure robots directives

   **Taxonomies**
   * Set SEO templates for categories, tags, and custom taxonomies
   * Control indexing behavior

   **Archives**
   * Manage SEO settings for author, date, and search archives

   **Advanced Settings**
   * Configure robots directives (index/noindex, follow/nofollow, preview limits)
   * Default robots: `index, follow, max-image-preview:large`
   * Disable default to manually select directives
   * Respects WordPress **Settings Reading Discourage search engines** option

3. Save settings to apply SEO metadata automatically.

4. Override metadata per post/page using the **SEO Repair Kit Meta Manager** box.

5. **Editor Support**
   * Works with Gutenberg and Elementor editors.

6. Per-page options include:
   * Custom SEO title
   * Meta description
   * Robots directives
   * Canonical URL
   * Search result preview

7. If custom metadata is not defined, Meta Manager automatically applies the global template settings as a fallback.

= AI Chatbot (Pro Feature) =

1. Ensure you have an active Pro license.
2. Navigate to "SEO Repair Kit" > "AI Chatbot".
3. Ask the AI assistant for SEO guidance, troubleshooting, and optimization tips.

The chatbot can help with:
* Meta Manager configuration and SEO metadata guidance
* Schema Manager setup
* Redirection and broken link fixes
* KeyTrack keyword tracking insights
* General SEO best practices

It provides context-aware responses and real-time suggestions directly inside the WordPress dashboard.

== External Services ==

Some SEO Repair Kit features integrate with external services. These services are used only for functionality that requires external data or processing.

**Google Site Kit / Google Search Console**

KeyTrack uses Google Site Kit to access connected Google Search Console performance data such as clicks, impressions, CTR, queries, pages, and average position.

Learn more:
[Google Site Kit](https://sitekit.withgoogle.com/)

**Third-Party SERP Providers**

When configured in supported paid workflows, Spam Monitor may send SERP requests to the selected provider. Requests may include the domain, search parameters, and provider credentials required to retrieve search-result data.

Serper.dev: https://serper.dev/
SerpApi: https://serpapi.com/
DataForSEO: https://dataforseo.com/

= Settings Configuration =

1. Go to "SEO Repair Kit" > "Settings" in the admin menu.
2. **Post Types Settings**:
   * Select which post types to scan for broken links
   * Choose from all public post types
   * Save your selection
3. **404 Monitoring Settings**:
   * Enable or disable automatic 404 error tracking
   * 404 errors will be logged when enabled
4. **Weekly Report Email Settings**:
   * Enable or disable weekly SEO summary emails
   * View last report status and timestamp
   * Reports are sent to your admin email address
5. Save all settings to apply changes.

= Weekly SEO Summary Email =

1. Enable weekly reports in Settings (enabled by default).
2. Reports are automatically sent every week to your admin email.
3. Each report includes:
   * Search performance metrics from KeyTrack
   * Broken links analysis and health scores
   * Image alt text optimization status
   * Redirection statistics and analytics
   * Spam Monitor status and scan-risk highlights when available
   * Pro plan status and upgrade information
4. Reports are sent in beautiful HTML format with:
   * Visual charts and metrics
   * Actionable insights
   * Direct links to fix issues
   * Dashboard access links
5. View the last report status in Settings to verify delivery.


== Troubleshooting ==

* If KeyTrack shows no data, confirm Google Site Kit is installed and connected to the correct Google Search Console property.
* If Spam Monitor scans or provider settings are unavailable, verify the active module, configured SERP provider, and scan settings.
* If schema is not output, confirm Schema Manager is active and the schema assignment applies to the current content.
* If links are not detected, confirm the relevant post type is included in the scan settings.
* If `llms.txt` redirects to the homepage, resave the Bot Manager `llms.txt` configuration and ensure SEO Repair Kit is installed.

== Frequently Asked Questions ==

= Can SEO Repair Kit scan large websites? =
Yes. You can limit scans by post type and configure scope, batch settings, and schedules to make larger scans easier to manage.

= Do I need a SERP provider API key? =
Provider requirements depend on the active Spam Monitor configuration. The paid module can support custom provider connections such as Serper.dev, SerpApi, and DataForSEO.

= Does Schema Manager use JSON-LD? =
Yes. Schema Manager outputs configured structured data in JSON-LD format.

= Does KeyTrack require Google Site Kit? =
Yes. Google Site Kit must be installed and connected to the appropriate Google Search Console property.

= Why is KeyTrack not showing data? =
Confirm Google Site Kit is installed, connected, and linked to the correct Search Console property.

= Why is Spam Monitor not running? =
Check the Spam Monitor module status, provider status, saved schedule, quota, and alert email configuration.

= Why is schema not appearing? =
Confirm the Pro module is active and the schema mapping is assigned to the correct content type.

= Why are links not being detected? =
Confirm the relevant post type is selected in SEO Repair Kit settings and that the content contains scan-supported links.

= Why does llms.txt redirect to the homepage? =
Resave Bot Manager llms.txt content and confirm the site is running SEO Repair Kit.

= What are the Pro features? =
Pro capabilities include Schema Manager, AI Chatbot, and supported paid Spam Monitor functionality such as advanced SERP-provider connections.

Available free and paid functionality may vary by plugin version or active module.

You can also find helpful resources and documentation to resolve common issues on the support website.
