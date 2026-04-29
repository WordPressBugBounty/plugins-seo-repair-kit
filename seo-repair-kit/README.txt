=== SEO Repair Kit – Meta Manager, Schema Manager, SEO Content Monitoring, GSC Integration, Keyword & Rank Tracking ===
Contributors: torontodigits
Donate link: https://seorepairkit.com/
Tags: meta manager, schema markup, 404 monitor, broken link checker, 301 redirection
Requires at least: 5.0.0
Tested up to: 6.9.4
Requires PHP: 7.4.3
Stable tag: 2.1.7
Release Date: 29-04-2026
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

The ultimate WordPress plugin for SEO automation - from link fixing to AI-powered schema generation and chatbot support.

== Description ==

**SEO Repair Kit v2.1.7** by [TorontoDigits](https://www.torontodigits.com) helps WordPress site owners find, monitor, and fix practical SEO issues from one dashboard.

It combines technical SEO checks, metadata controls, crawling controls, Links Manager, automatic link scanning, 404 monitoring, smart redirects, notification emails, and Search Console insights, so you can improve visibility without relying on multiple plugins.

= Why SEO Repair Kit? =

Unlike single-purpose SEO plugins, SEO Repair Kit focuses on **practical SEO fixes and monitoring** in one place — including broken links, 404 errors, redirects, missing alt text, metadata, schema, and search performance.

This makes it ideal for:
* SEO agencies managing multiple clients
* Content-heavy websites
* Businesses looking for actionable SEO improvements
* Site owners who want automated link health monitoring
* Developers and marketers who need redirect and 404 visibility

= Core Features =

**Meta Manager**
* Manage SEO titles and descriptions globally and per content type
* Configure robots directives (including max preview directives)
* Override metadata on individual posts/pages
* Supports Gutenberg and Elementor workflows
* Supports dynamic template tags like `%title%`, `%excerpt%`, and `%site_title%`

**Schema Manager (Pro)**
* Build and manage JSON-LD schema with visual controls
* Supports common schema types such as Article, FAQ, Product, Event, Course, JobPosting, Review, and more
* Validate and preview mappings before output

**Links Manager**
* Scan internal and external links for HTTP status issues
* Review broken, healthy, redirected, and problematic links
* Export findings to CSV
* Create redirects from problem URLs
* Access link scanning, 404 monitoring, automation scan, notifications, and smart redirects from one Links Manager area

**Auto Scan**
* Schedule automatic link scans
* Choose scan intervals such as daily, every 3 days, weekly, biweekly, or monthly
* Configure scan scope, post types, batch size, links per post, and request timeout
* Store scan history and latest scan snapshots
* Re-check link health automatically without manual scanning

**Notifications**
* Send email reports after automated scans
* Receive broken-link alerts when issues are detected
* Receive clean scan reports when no broken links are found
* Configure recipients and email notification preferences
* Review alert history from the Links Manager

**Smart Redirects**
* Automatically create 301 redirects for broken internal singular pages
* Redirect deleted or broken post URLs to their post-type archive page
* Enable Smart Redirects per post type
* Reset all Smart Redirect records or reset records by selected post type
* Manage generated redirects from the Redirection Manager

**Redirection + 404 Monitoring**
* Create and manage 301/302 redirects
* Track redirect hits and logs
* Monitor 404 errors with actionable details
* Convert recurring 404 URLs into redirects quickly

**KeyTrack (Search Console Insights)**
* Works with Google Site Kit/Search Console
* View clicks, impressions, CTR, and average position
* Analyze page/query trends in one place
* Configure threshold-based reporting workflows

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

**Weekly SEO Summary**
* Receive scheduled email summaries with key SEO status metrics
* Includes Search performance, link health, image alt coverage, and redirect insights

== Screenshots ==

1. SEO Repair Kit Dashboard Overview
2. SEO Repair Kit Links Manager
3. SEO Repair Kit 404 Monitor
4. SEO Repair Kit Auto Scan
5. SEO Repair Kit Notifications
6. SEO Repair Kit Smart Redirect
7. SEO Repair Kit Advanced Redirections
8. SEO Repair Kit Import and Export Redirections
9. SEO Repair Kit Image Alt Text Manager
10. SEO Repair Kit Schema Manager
11. SEO Repair Kit KeyTrack Overview Dashboard
12. SEO Repair Kit Meta Manager Global Meta Overview
13. SEO Repair Kit Meta Manager Content Types Overview
14. SEO Repair Kit Meta Manager Taxonomies Overview
15. SEO Repair Kit Meta Manager Archives Overview
16. SEO Repair Kit Meta Manager Advance Settings
17. SEO Repair Kit Sitemap Manager
18. Bot Manager - llms.txt Management
19. Bot Manager - robots.txt Management
20. SEO Repair Kit AI Chatbot Interface
21. SEO Repair Kit Settings
22. SEO Repair Kit Upgrade to Pro
23. SEO Repair Kit Weekly Email Report
24. SEO Repair Kit KeyTrack Threshold Email Report
25. SEO Repair Kit Broken Links Detected Email Report
26. SEO Repair Kit No Broken Links Detected Email Report

== Changelog ==

= 2.1.7 =
* New: Renamed Link Scanner/Link Scan to **Links Manager**.
* New: Added Auto Scan with configurable schedules, scan scope, post types, batch limits, timeout, and email alerts.
* New: Added automated scan notification emails for broken links and clean scans.
* New: Added Notifications history for automation scan reports.
* New: Added Smart Redirects to automatically redirect broken internal singular URLs to post-type archive pages.
* New: Added Smart Redirect controls, records table, filters, status toggle, delete, and reset actions.
* Improvement: Updated Links Manager with tabs for Link Scan, 404 Monitor, Auto Scan, Notifications, and Smart Redirects.
* Improvement: Refined Links Manager UI, reports, redirect workflow, and latest scan snapshot handling.
* Fix: Improved Smart Redirect safety checks, cache clearing, and handling for post types without archive pages.

= 2.1.6 =
* Fix: Corrected text domain inconsistencies to use `seo-repair-kit`.
* Fix: Added missing text domain to weekly cron schedule label.
* Fix: Converted additional hardcoded admin notice strings to translation functions.
* Fix: Registered JavaScript translation loading with `wp_set_script_translations` for editor scripts.
* Improvement: Refined and shortened plugin description copy for WordPress.org compliance.

= 2.1.5 =
* New: Sitemap Control feature to manage WordPress core sitemap (wp-sitemap.xml)
* New: Include/exclude post types from sitemap dynamically
* New: Include/exclude taxonomies from sitemap dynamically
* Improvement: Automatic detection of all public post types and taxonomies
* Improvement: Added validation to prevent saving settings without enabling sitemap control
* Improvement: Added user guidance and sitemap troubleshooting notice

= 2.1.4 =
* Minor code update.
* Tested up to WordPress 6.9.4

= 2.1.3 =
* Introduced the new Meta Manager feature for centralized SEO metadata control
* Added Global SEO settings for homepage metadata, title separators, and default templates
* Added Content Types SEO templates for posts, pages, and custom post types
* Added Taxonomies SEO templates for categories, tags, and custom taxonomies
* Added Archive SEO controls for author archives, date archives, and search pages
* Implemented advanced robots meta directives including noindex, nofollow, noarchive, max-snippet, and preview settings
* Added dynamic SEO variables support such as `%title%`, `%excerpt%`, `%site_title%`, `%sep%`, `%current_date%`, `%current_day%`, `%month%`, and `%year%`
* Added per-post SEO meta box for custom title, description, robots directives, and canonical URL
* Added full compatibility with Gutenberg Block Editor
* Added support for Elementor editor for easy SEO metadata management while building pages

= 2.1.2 =
* Fixed XML output so the declaration is always at the start of the document
* Minor stability improvements for bot management outputs

= 2.1.1 =
* Introduced Bot Manager feature for comprehensive search engine and AI crawler control
* Added robots.txt management with visual editor and validation
* Introduced llms.txt generator for AI model training and content discovery
* Added AI bot access control to allow/block specific crawlers (ChatGPT, Claude, Google Bard, DeepSeek, Grok, Qwen AI, Meta Llama, Cohere, Mistral AI, Hugging Face, and more)
* Implemented server-level bot blocking with 403 responses
* Added automatic robots.txt rules for blocked bots
* Added Author schema type to Schema Manager for enhanced author markup
* Improved schema management capabilities with new author schema support
* Enhanced SEO tools with advanced bot management capabilities

= 2.1.0 =
* Introduced AI Chatbot for real-time SEO assistance and automation (Pro feature)
* Added Schema Manager supporting 15+ schema types (Pro feature)
* Enhanced dashboard UI and navigation for seamless feature access
* Added 404 Error Monitor for automatic 404 tracking and logging
* Implemented Weekly SEO Summary Email with comprehensive reporting
* Improved plugin performance and compatibility with latest WordPress release
* Enhanced onboarding flow with multi-step guided setup
* Added license management system for Pro features
* Improved error handling and logging throughout the plugin
* Minor bug fixes and stability improvements

= 2.0.0 =
* Introduced the KeyTrack feature for detailed SEO performance tracking
* Integrated Google Search Console support via Google Site Kit
* Added visual performance insights with interactive line charts
* Introduced tabs for Overview, Pages, Queries, and Settings in KeyTrack
* Added customizable date ranges for performance analysis
* Enabled threshold settings for custom performance monitoring
* Enhanced UI/UX with modal notifications and interactive elements
* Customized email report based on user preference settings for KeyTrack
* Improved redirection table schema with enhanced analytics
* Added redirection logs and hit tracking

= 1.1.0 =
* Added CRM integration functionality
* Improved UI/UX for better user experience
* Enhanced API client for better communication with external services

= 1.0.1 =
* Removed direct manipulation of PHP memory limit and max-execution time
* Fixed database table prefix issues
* Fixed CSS enqueuing for public-facing pages
* Fixed scanning whole content links of all post types
* Fixed retrieving HTTP status codes of scanned links
* Fixed alt text missing detection for media images
* Fixed redirection from old URLs to new URLs
* Fixed downloading CSV list of broken links
* Improved error handling and user feedback

== Upgrade Notice ==

= 2.1.7 =
**Feature Update:** Link Scanner has been renamed to **Links Manager**. This update adds Auto Scan, Notifications, and Smart Redirects. You can now schedule automatic scans, receive email reports, and automatically create 301 redirects for broken internal singular URLs to their post-type archive pages.

= 2.1.6 =
**Maintenance Release:** This update improves internationalization (i18n) support across the plugin. JavaScript translations are now properly loaded in the editor, text domains are standardized, and admin notices are fully translatable. Updating is recommended for multilingual sites.

= 2.1.5 =
**New Feature:** Sitemap Control – Manage your WordPress core sitemap (wp-sitemap.xml) by including or excluding specific post types and taxonomies. This helps remove unnecessary URLs and improve crawl efficiency. Updating is recommended for better sitemap management and SEO performance.

= 2.1.4 =
* Tested up to WordPress 6.9.4
* Improvement: Performance improvements, UI enhancements, and internal optimizations for better stability.

= 2.1.3 =
**Feature Update:** This release introduces the new **Meta Manager** feature, allowing centralized control of SEO titles, descriptions, robots directives, and canonical settings across your WordPress site. Updating is strongly recommended to ensure improved security, stability, and enhanced SEO metadata management.

= 2.1.2 =
This update fixes XML output ordering to prevent declaration placement errors and includes minor stability improvements for bot management outputs.

= 2.1.1 =
This version introduces Bot Manager - a comprehensive feature for managing robots.txt and llms.txt files, along with AI bot access control. You can now control which search engines and AI crawlers can access your content, generate llms.txt files for AI model discovery, and manage robots.txt with a visual editor. Author schema support has also been added to the Schema Manager. These additions provide better control over search engine crawling, AI model training, and enhanced schema markup capabilities.

= 2.1.0 =
This version introduces AI Chatbot and Schema Manager - enabling automated schema generation, validation, and real-time SEO assistance through AI. The plugin also includes 404 Error Monitor and Weekly SEO Summary Email features. Database migrations will run automatically on update.

= 2.0.0 =
The upgraded KeyTrack feature now seamlessly integrates with Google Search Console through the Site Kit plugin for advanced keyword tracking. Enhanced redirection system with better analytics and logging capabilities.

== Configurations & Use ==

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
   * Set notification email address
3. Complete the onboarding to save your preferences, or skip and configure later in Settings.

= Dashboard Overview =

1. Navigate to "SEO Repair Kit" in your WordPress admin menu to access the main dashboard.
2. The dashboard provides:
   * Site SEO Analysis with issue detection (critical, warning, suggestion)
   * Quick access widgets for all major features
   * Real-time status updates
   * SEO health score calculations
   * Direct links to fix identified issues
3. Use the "Re-check Status" button to refresh the analysis.

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

1. Go to **SEO Repair Kit → Sitemap Control**
2. Enable the option **“Enable Sitemap Control”**
3. Select the post types you want to include (Posts, Pages, Custom Post Types, etc.)
4. Select the taxonomies you want to include (Categories, Tags, custom taxonomies)
5. Click **Save Sitemap Settings**

Only the selected items will remain in your WordPress core sitemap.

Tip: If your sitemap is not opening, go to **Settings → Permalinks** and click “Save Changes” to refresh it.

= Bot Manager =

1. Navigate to "SEO Repair Kit" > "Bot Manager" in the admin menu.

2. **LLMs.txt Management**
   * Generate an llms.txt file for AI model discovery
   * Select post types and taxonomies to include
   * Allow or block specific AI bots
   * Preview and edit the generated file
   * Your file will be available at: yoursite.com/llms.txt

3. **Robots.txt Management**
   * Edit robots.txt using the visual editor
   * Validate syntax and preview changes
   * Apply enhanced SEO and security rules
   * Reset to WordPress recommended defaults
   * Available at: yoursite.com/robots.txt

4. **AI Bot Access Control**
   * Allow or block AI crawlers such as GPTBot, Claude, Gemini, Perplexity, Bing Chat, and others
   * Blocked bots receive a 403 response
   * Blocking rules are automatically added to robots.txt

5. **Additional Features**
   * Real-time robots.txt validation
   * Automatic sitemap detection
   * Built-in security rules
   * Easy reset to default configuration

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

1. Ensure you have an active Pro license (required for Schema Manager).
2. Navigate to "SEO Repair Kit" > "Schema Manager" in the admin menu.
3. Select a schema type from the available options (15+ types supported).
4. Configure schema assignment:
   * Choose post types to apply the schema
   * Map content fields to schema properties
   * Enable/disable specific schema fields
   * Preview the JSON-LD output
5. Save the schema configuration.
6. The schema will automatically be injected into your pages as JSON-LD markup.
7. Validate schema using Google's Rich Results Test tool.
8. Manage multiple schema types for different content types.
9. Use the visual field mapper to easily configure complex schemas.

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
   * Respects WordPress **Settings → Reading → Discourage search engines** option

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
   * Pro plan status and upgrade information
4. Reports are sent in beautiful HTML format with:
   * Visual charts and metrics
   * Actionable insights
   * Direct links to fix issues
   * Dashboard access links
5. View the last report status in Settings to verify delivery.

= Advanced Features =

1. **Analytics & Reporting**:
   * Track redirect hit counts
   * Monitor 404 error patterns
   * Analyze KeyTrack performance trends
   * View comprehensive SEO health scores

2. **Bot Management**:
   * Control AI crawler access to your content
   * Generate llms.txt files for AI model discovery
   * Manage robots.txt with visual editor
   * Block or allow specific AI bots
   * Server-level access control

3. **Integration**:
   * Google Site Kit integration for KeyTrack
   * Google Search Console data access
   * REST API endpoints for external integrations

= Troubleshooting =

* If KeyTrack doesn't show data, ensure Google Site Kit is installed and connected.
* If weekly emails aren't sending, check your server's mail configuration and email settings.
* If schema isn't appearing, verify your Pro license is active and schema is properly configured.
* If links aren't being detected, ensure the post type is selected in Settings.
* Check the plugin's debug logs for detailed error information.

== Installation ==

1. Download the plugin zip file.
2. Go to your WordPress admin panel and navigate to Plugins > Add New.
3. Click "Upload Plugin" and select the `seo-repair-kit.zip` file.
4. Click "Install Now" and then "Activate Plugin".
5. After activation, you'll be guided through an onboarding process to configure the plugin.

Alternatively, you can manually upload the plugin:
1. Extract the zip file.
2. Upload the `seo-repair-kit` folder to `/wp-content/plugins/` directory on your web server.
3. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= What is Links Manager? =
Links Manager is the central area for managing link health inside SEO Repair Kit. It helps you scan links, monitor 404 errors, schedule automatic scans, receive notification reports, and manage Smart Redirects.

= What is Auto Scan? =
Auto Scan allows SEO Repair Kit to scan your website links automatically on a schedule. You can configure scan interval, post types, link scope, batch size, request timeout, and email alerts.

= What is Smart Redirects? =
Smart Redirects automatically creates 301 redirects for broken internal singular URLs and sends them to their post-type archive page. For example, a broken blog post URL can redirect to the main blog archive.

= What does Sitemap Control do? =
It allows you to include or exclude specific post types and taxonomies from the default WordPress sitemap (wp-sitemap.xml).

= What's new in version 2.1.3? =
Version 2.1.3 introduces the new **Meta Manager** feature, providing centralized control of SEO titles, meta descriptions, robots directives, and canonical URLs across your WordPress site. Users can now configure global SEO templates, manage metadata for content types, taxonomies, and archives, and override metadata directly within the Gutenberg, and Elementor.

= What's new in version 2.1.2? =
This update fixes XML output ordering to prevent declaration placement errors and includes minor stability improvements for bot management outputs.

= What's new in version 2.1.1? =
Bot Manager has been introduced - a comprehensive feature for managing robots.txt and llms.txt files with AI bot access control. You can now control which search engines and AI crawlers access your content, generate llms.txt files for AI model discovery, and manage robots.txt with a visual editor. Author schema support has also been added to the Schema Manager. These new features provide enhanced control over search engine crawling, AI model training, and expanded schema markup options.

= What's new in version 2.1.0? =
AI Chatbot and Schema Manager are the two flagship features — offering AI-powered SEO support and multi-schema management for rich search results. The plugin also includes enhanced dashboard UI, improved performance, and better compatibility with the latest WordPress release.

= What is Meta Manager and how does it help SEO? =
Meta Manager allows you to centrally manage SEO titles, meta descriptions, robots directives, and canonical URLs across your WordPress website. It uses dynamic templates and variables to automatically generate optimized metadata for posts, pages, taxonomies, and archive pages.

= Can I override SEO metadata for individual posts or pages? =
Yes. Meta Manager adds an SEO meta box inside the editor where you can customize the SEO title, meta description, robots directives, and canonical URL for individual posts or pages. If left empty, the global template settings will be applied automatically.

= Which editors are supported by Meta Manager? =
Meta Manager is fully integrated with the WordPress Classic Editor, Gutenberg Block Editor, and Elementor page builder. The SEO Repair Kit Meta Manager box appears directly inside these editors so you can manage metadata while editing your content.

= Does Meta Manager support dynamic SEO variables? =
Yes. Meta Manager supports dynamic variables such as `%title%`, `%excerpt%`, `%site_title%`, `%sep%`, `%current_date%`, `%current_day%`, `%month%`, and `%year%`. These variables allow metadata to be automatically generated based on the content and site settings.

= Does the Schema Manager support JSON-LD? =
Yes, it automatically generates valid JSON-LD code compatible with Google's Structured Data guidelines. All schema markups are output as JSON-LD format in the page head.

= Do I need coding knowledge to use the AI Chatbot or Schema Manager? =
Not at all. Both features are fully visual and user-friendly, designed for beginners and experts alike. The Schema Manager provides a visual interface for configuring schema types, and the AI Chatbot offers conversational assistance.

= What is SEO Repair Kit, and how does it work? =
SEO Repair Kit is a comprehensive WordPress plugin designed to automate and simplify SEO management. It scans your website for broken links, missing alt text, and other SEO issues, then provides tools to fix them. It also includes advanced features like keyword tracking, schema management, and AI-powered assistance.

= Is the SEO Repair Kit compatible with my WordPress theme? =
Absolutely! SEO Repair Kit is designed to be compatible with a wide range of WordPress themes. Whether you're using a popular or custom theme, our plugin seamlessly integrates to provide optimal performance.

= Will SEO Repair Kit affect my website speed? =
No, the SEO Repair Kit is designed with performance in mind. It operates efficiently in the background without causing any noticeable slowdowns. Our team has optimized the plugin to ensure it enhances your website's functionality without compromising speed.

= What kind of support is available for SEO Repair Kit? =
We provide dedicated customer support to assist you with any questions or issues you may encounter. Please visit our support portal at https://support.seorepairkit.com/ to submit your query. You can also find helpful resources and documentation to resolve common issues on the support website.

= Is the SEO Repair Kit compatible with other plugins? =
In most cases, SEO Repair Kit is compatible with other plugins. However, it's always a good practice to test compatibility in a staging environment before installing major updates. Our support team can guide you through any potential compatibility concerns.

= Does it check internal and external links? =
Yes, it scans both internal links pointing to site pages and external links. The scanner checks HTTP status codes for all links found in your content.

= My website has 10K+ pages, will it scan all the pages? =
Yes, it can scan all the pages but it will take some time. To save you time, we have divided pages into different post types, allowing you to scan specific post types separately. You can also schedule automatic scans on a weekly or monthly basis.

= What happens to broken pages without redirects? =
Without redirects, broken pages will show 404 errors, causing visitors and search engines to leave quickly. The plugin's 404 Monitor tracks these errors, and you can easily create redirects from the 404 logs.

= What permissions does the plugin require? =
It requires administrator permissions in your WordPress site to scan, detect issues, and fix SEO problems. All features are accessible only to users with 'manage_options' capability.

= How does the plugin address missing alt text? =
The plugin identifies images without alt text and provides options to generate or input appropriate descriptions. You can view all images missing alt text on a dedicated page and update them individually or in bulk.

= How does the weekly email report work? =
The plugin automatically sends a comprehensive weekly SEO report to your admin email address. The report includes search performance metrics, broken links analysis, alt text status, redirection statistics, and more. You can enable or disable this feature in the Settings page.

= What is KeyTrack and how does it work? =
KeyTrack is a keyword performance tracking feature that integrates with Google Search Console via the Google Site Kit plugin. It tracks keyword positions, impressions, CTR, and clicks, providing visual insights and email reports based on your preferences.

= Do I need Google Site Kit for KeyTrack to work? =
Yes, KeyTrack requires Google Site Kit plugin to be installed and connected to your Google Search Console account. This integration allows KeyTrack to access your search performance data.

= Who can use the SEO Repair Kit? =
SEO Repair Kit is an ideal solution for a diverse range of users, including business owners, bloggers, content managers, designers, developers, and SEO professionals.

= Do I need coding skills to use the SEO Repair Kit? =
No, the SEO Repair Kit is designed to be beginner-friendly, and you can optimize your WordPress SEO without any coding knowledge. The plugin provides a user-friendly interface for easy configuration and management.

= How do I contact the SEO Repair Kit support team? =
If you encounter any issues, need assistance, or have questions about using SEO Repair Kit, our dedicated support team is here to help. Please visit our support portal at https://support.seorepairkit.com/ to submit your query. You can also find helpful resources and documentation to resolve common issues on the support website.

= What are the Pro features? =
Pro features include AI Chatbot for real-time SEO assistance and Schema Manager for advanced schema markup management. These features require an active Pro license. Free users have access to all other features including link scanning, alt text management, redirections, KeyTrack, and 404 monitoring.

You can also find helpful resources and documentation to resolve common issues on the support website.