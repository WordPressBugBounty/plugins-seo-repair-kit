/**
 * SEO Repair Kit - Onboarding JavaScript
 *
 * @since 2.1.0
 */
(function($){
  'use strict';

  var $overlay, $modal, $body, $stepsList, $close, $progressBar;
  var state = { 
    index: 0, 
    steps: [],
    setupData: {
      mode: 'easy',
      postTypes: [],
      links_schedule: 'manual',
      keytrackEnabled: false,
      schemaTypes: [],
      notifications: {
        weeklyReport: false,
        keytrackAlerts: false,
        brokenLinks: false
      },
      site_info_consent: true,
      email: '',
      alt_scan: false,
      redir_enabled: false,
      redir_default: '301',
      pro_intent: false
    }
  };

  function esc(s){ 
    var map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'};
    return (s == null ? '' : String(s)).replace(/[&<>"']/g, function(m){
      return map[m];
    }); 
  }

  function formatNumber(value){
    var num = Number(value);
    if (!isFinite(num)) {
      return '0';
    }
    if (window.Intl && typeof Intl.NumberFormat === 'function') {
      return Intl.NumberFormat().format(num);
    }
    return String(num);
  }

  function getPostTypeLabel(slug){
    var postTypes = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.postTypes) ? window.SRK_ONBOARDING_DATA.postTypes : [];
    for (var i = 0; i < postTypes.length; i++) {
      if (postTypes[i].name === slug) {
        return postTypes[i].label;
  }
    }
    return slug;
  }

  function renderNavigation(){
    var isFirst = state.index === 0;
    var isLast = state.index === state.steps.length - 1;
    var nextLabel = isLast ? 'Finish' : 'Next';
    var showSkip = !isLast && !isFirst;

    return ''+
      '<div class="srk-step-actions">'+
        '<div class="srk-step-actions-left">'+
          '<button type="button" class="button srk-btn-back" '+(isFirst ? 'disabled' : '')+'>Back</button>'+
            '</div>'+
        '<div class="srk-step-actions-right">'+
          (showSkip ? '<button type="button" class="button srk-btn-skip">Skip</button>' : '')+
          '<button type="button" class="button button-primary srk-btn-next">'+esc(nextLabel)+'</button>'+
          '</div>'+
      '</div>';
  }

  function renderWelcome(){
    var heroImage = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.assets && window.SRK_ONBOARDING_DATA.assets.welcomeImage)
      ? window.SRK_ONBOARDING_DATA.assets.welcomeImage
      : (window.SRK_ONBOARDING_DATA ? window.SRK_ONBOARDING_DATA.pluginUrl + 'images/SRK-Onboarding-Image.svg' : '');

    return ''+
      '<section class="srk-welcome-screen srk-welcome-modern">'+
        '<div class="srk-welcome-card srk-duo">'+
          '<div class="srk-welcome-left">'+
            '<header class="srk-welcome-copy">'+
              '<p class="srk-welcome-eyebrow">Welcome to</p>'+ 
              '<h1 class="srk-welcome-heading">SEO Repair Kit</h1>'+ 
              '<p class="srk-welcome-sub">Launch your site\'s SEO setup in minutes with a guided walkthrough of the tools that keep rankings healthy.</p>'+ 
            '</header>'+ 
            '<ul class="srk-welcome-bullets">'+
              '<li>Identify and fix SEO issues effortlessly</li>'+
              '<li>Analyze and track performance in search engines</li>'+ 
              '<li>Optimize content for better rankings</li>'+ 
              '<li>Leverage AI-powered recommendations</li>'+ 
              '<li>Enjoy a clear, step-by-step setup</li>'+ 
            '</ul>'+
            '<div class="srk-welcome-actions">'+
              '<button type="button" class="srk-btn-primary srk-btn-large" data-action="start">Let\'s Get Started</button>'+ 
            '</div>'+
          '</div>'+
          (heroImage ? '<div class="srk-welcome-art"><img src="'+esc(heroImage)+'" alt="SEO Repair Kit onboarding illustration"></div>' : '')+
        '</div>'+
      '</section>';
  }

  function renderLinkScanner(){
    var onboardingData = window.SRK_ONBOARDING_DATA || {};
    var postTypes = Array.isArray(onboardingData.postTypes) ? onboardingData.postTypes : [];
    var selected = Array.isArray(state.setupData.postTypes) ? state.setupData.postTypes.slice() : [];
    if (!selected.length && onboardingData.saved && Array.isArray(onboardingData.saved.postTypes)) {
      selected = onboardingData.saved.postTypes.slice();
    }
    state.setupData.postTypes = selected.slice();

    var stats = onboardingData.stats || {};
    var postStats = stats.postTypes || {};
    var storedSchedule = (onboardingData.saved && onboardingData.saved.setup && onboardingData.saved.setup.links_schedule) || stats.linksSchedule || 'manual';
    var schedule = state.setupData.links_schedule || storedSchedule || 'manual';
    var cadenceDescription = 'Pick how often Link Scanner should run.';
    var cadenceBlock = ''+
      '<div class="srk-cadence-form">'+
        '<div class="srk-cadence-head">'+
          '<strong>Cadence</strong>'+ 
          '<span>'+esc(cadenceDescription)+'</span>'+ 
            '</div>'+
        '<label class="srk-summary-field" for="srkLinkSchedule">Frequency</label>'+ 
        '<select id="srkLinkSchedule" class="srk-select" data-links-schedule>'+ 
          '<option value="manual" '+(schedule === 'manual' ? 'selected' : '')+'>Run manually</option>'+ 
          '<option value="weekly" '+(schedule === 'weekly' ? 'selected' : '')+'>Weekly scan</option>'+ 
          '<option value="monthly" '+(schedule === 'monthly' ? 'selected' : '')+'>Monthly scan</option>'+ 
        '</select>'+ 
      '</div>';


    var pills = postTypes.map(function(pt){
      var checked = selected.indexOf(pt.name) !== -1 ? 'checked' : '';
      var meta = postStats[pt.name] || {};
      var totalCount = meta.total != null ? meta.total : ((meta.published || 0) + (meta.drafts || 0) + (meta.scheduled || 0));
      var countLabel = totalCount === 1 ? '1 item' : formatNumber(totalCount) + ' items';
      return ''+
        '<label class="srk-pill">'+
          '<input type="checkbox" name="post_types[]" value="'+esc(pt.name)+'" '+checked+' data-post-type="'+esc(pt.name)+'">'+
          '<span class="srk-pill-check"></span>'+ 
          '<span class="srk-pill-info">'+
            '<strong>'+esc(pt.label)+'</strong>'+ 
          '</span>'+ 
          '<span class="srk-pill-count">'+esc(countLabel)+'</span>'+ 
        '</label>';
    }).join('');

    return ''+
      '<div class="srk-setup-step srk-link-step">'+
        '<div class="srk-step-header">'+
          '<span class="srk-step-eyebrow">Link Scanner</span>'+ 
          '<h2 class="srk-step-title">Choose where to monitor for broken links</h2>'+
          '<p class="srk-step-desc">Select the post types you want to include when the Link Scanner runs.</p>'+
        '</div>'+
        '<div class="srk-step-body">'+
          '<section class="srk-step-column">'+
            cadenceBlock+
            '<h3 class="srk-column-title">Select post types</h3>'+ 
            '<p class="srk-column-sub">We recommend starting with pages and posts that receive the most visitors.</p>'+ 
            '<div class="srk-pill-grid">'+pills+'</div>'+ 
          '</section>'+ 
        '</div>'+
        renderNavigation()+
      '</div>';
  }

  function renderKeyTrack(){
    var siteKitInstalled = (typeof google !== 'undefined' && google.sitekit) ? true : false;
    var enabled = !!state.setupData.keytrackEnabled;
    var installLink = 'https://wordpress.org/plugins/google-site-kit/';

    var statusBox = siteKitInstalled
      ? '<div class="srk-info-box srk-success"><span class="srk-info-icon">✓</span><div>Google Site Kit is connected. We\'ll sync data automatically.</div></div>'
      : '<div class="srk-info-box srk-warning"><span class="srk-info-icon"></span><div>Requires the free <a href="'+installLink+'" target="_blank" rel="noopener">Google Site Kit</a> plugin.</div></div>';

    var heroImage = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.assets && window.SRK_ONBOARDING_DATA.assets.keytrackImage)
      ? window.SRK_ONBOARDING_DATA.assets.keytrackImage
      : (window.SRK_ONBOARDING_DATA ? window.SRK_ONBOARDING_DATA.pluginUrl + 'admin/images/KeyTrack-Image.svg' : '');
    var hero = heroImage
      ? '<div class="srk-keytrack-art"><img src="'+esc(heroImage)+'" alt="KeyTrack illustration"></div>'
      : '<div class="srk-keytrack-art" aria-hidden="true"></div>';

    var primaryCta = '';

    var benefitsHtml = '<ul class="srk-keytrack-benefit-list">'+[
      'Surface queries that drive visits and conversions',
      'Catch drops in clicks before rankings slide',
      'Tie technical fixes to measurable gains'
    ].map(function(line){ return '<li>'+esc(line)+'</li>'; }).join('')+'</ul>';
    
    return ''+
      '<div class="srk-setup-step srk-keytrack-card">'+
        '<div class="srk-step-header">'+
          '<span class="srk-step-eyebrow">Search Console</span>'+ 
          '<h2 class="srk-step-title">Connect Search Console insights to KeyTrack</h2>'+ 
          '<p class="srk-step-desc">Authorise Google Search Console so we can pull clicks, impressions, CTR, and average position right alongside your SEO Repair Kit fixes.</p>'+ 
        '</div>'+
        '<div class="srk-keytrack-layout">'+
          '<div class="srk-keytrack-copy">'+
            statusBox+
            benefitsHtml+
            '</div>'+
          hero+
        '</div>'+
        '<div class="srk-keytrack-controls">'+
          '<div class="srk-keytrack-cta">'+
            (siteKitInstalled ? '<span class="srk-cta-note">Google Site Kit is connected.</span>' : '')+
          '</div>'+ 
        '</div>'+ 
        renderNavigation()+
      '</div>';
  }

  function renderSchema(){
    var selected = Array.isArray(state.setupData.schemaTypes) ? state.setupData.schemaTypes : [];
    var onboardingData = window.SRK_ONBOARDING_DATA || {};
    var hasPro = !!(onboardingData.license && onboardingData.license.active);
    var upgradeLink = (onboardingData && onboardingData.links) ? onboardingData.links.upgrade : '#';
    
    var schemas = [
      {key: 'article', label: 'Article Schema', desc: 'For blog posts and articles'},
      {key: 'faq', label: 'FAQ Schema', desc: 'For Q&A content'},
      {key: 'product', label: 'Product Schema', desc: 'For e-commerce products', premium: true},
      {key: 'event', label: 'Event Schema', desc: 'For events and bookings', premium: true},
      {key: 'recipe', label: 'Recipe Schema', desc: 'For cooking recipes', premium: true},
      {key: 'job', label: 'Job Posting Schema', desc: 'For job listings', premium: true}
    ];
    
    var schemaHtml = schemas.map(function(schema){
      var checked = selected.indexOf(schema.key) !== -1 ? 'checked' : '';
      var locked = !hasPro;
      var showPremiumBadge = locked || schema.premium;
      var premiumBadge = showPremiumBadge ? '<span class="srk-premium-badge">Premium</span>' : '';
      var disabled = (locked || (schema.premium && !hasPro)) ? 'disabled' : '';
      var itemClass = 'srk-schema-item' + (disabled ? ' disabled' : '');
      
      return ''+
        '<label class="'+itemClass+'">'+
          '<input type="checkbox" name="schema_types[]" value="'+esc(schema.key)+'" '+checked+' '+disabled+' data-schema="'+esc(schema.key)+'">'+
          '<div class="srk-schema-content">'+
            '<div class="srk-schema-header">'+
              '<strong>'+esc(schema.label)+'</strong>'+premiumBadge+
            '</div>'+
            '<span class="srk-schema-desc">'+esc(schema.desc)+'</span>'+
          '</div>'+
        '</label>';
    }).join('');
    
    return ''+
      '<div class="srk-setup-step srk-schema-step'+(hasPro ? '' : ' premium')+'">'+
        '<div class="srk-step-header">'+
          '<div class="srk-step-labels">'+
            '<span class="srk-step-eyebrow">Schema Manager</span>'+
            (hasPro ? '' : '<span class="srk-step-premium-pill">Premium</span>')+
          '</div>'+
          '<h2 class="srk-step-title">Enable structured data templates</h2>'+
          '<p class="srk-step-desc">Turn on the schema types you use most. You can customise fields inside the Schema Manager later.</p>'+
        '</div>'+
        '<div class="srk-schema-grid">'+schemaHtml+'</div>'+ 
        (!hasPro ? '<div class="srk-info-box srk-info">'+
          '<span class="srk-info-icon">💎</span>'+
          '<div>Unlock every schema type with <a href="'+upgradeLink+'" target="_blank" rel="noopener">SEO Repair Kit Pro</a>.</div>'+ 
        '</div>' : '')+
        renderNavigation()+
      '</div>';
  }

  function renderNotifications(){
    var notifications = state.setupData.notifications || {};
    var defaultEmail = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.adminEmail) ? window.SRK_ONBOARDING_DATA.adminEmail : '';
    var email = state.setupData.email || defaultEmail;
    var consent = (typeof state.setupData.site_info_consent !== 'undefined') ? !!state.setupData.site_info_consent : true;
    
    return ''+
      '<div class="srk-setup-step srk-notifications-step">'+
        '<div class="srk-step-header">'+
          '<span class="srk-step-eyebrow">Notifications</span>'+
          '<h2 class="srk-step-title">Decide how you want to stay informed</h2>'+
          '<p class="srk-step-desc">Pick the alerts that help you stay ahead of SEO regressions and opportunities.</p>'+
        '</div>'+ 
        '<div class="srk-notification-group">'+
          '<label class="srk-notification-item">'+
            '<input type="checkbox" name="weekly_report" '+(notifications.weeklyReport ? 'checked' : '')+' data-notification="weeklyReport">'+
            '<div class="srk-notification-content">'+
              '<strong>Weekly SEO report</strong>'+ 
              '<span>Summary of link scans and schema activity</span>'+ 
            '</div>'+
          '</label>'+
          '<label class="srk-notification-item">'+
            '<input type="checkbox" name="keytrack_alerts" '+(notifications.keytrackAlerts ? 'checked' : '')+' data-notification="keytrackAlerts">'+ 
            '<div class="srk-notification-content">'+
              '<strong>KeyTrack alerts</strong>'+ 
              '<span>Email when keywords spike or dip</span>'+ 
            '</div>'+
          '</label>'+
          '<label class="srk-notification-item">'+
            '<input type="checkbox" name="broken_links" '+(notifications.brokenLinks ? 'checked' : '')+' data-notification="brokenLinks">'+ 
            '<div class="srk-notification-content">'+
              '<strong>Broken link alerts</strong>'+ 
              '<span>Heads-up when new broken links appear</span>'+ 
            '</div>'+
          '</label>'+
        '</div>'+ 
        '<div class="srk-privacy-consent">'+
          '<label class="srk-notification-item srk-consent-item">'+
            '<input type="checkbox" name="site_info_consent" '+(consent ? 'checked' : '')+' data-site-info-consent>'+
            '<div class="srk-notification-content">'+
              '<strong>Send basic site info to SEO Repair Kit</strong>'+
              '<span>Share website name, URL, admin email, post count and plugin version to help improve the product. No detailed server or database information is sent.</span>'+
            '</div>'+
          '</label>'+
        '</div>'+
        '<div class="srk-email-input">'+
          '<label>Email address</label>'+ 
          '<input type="email" name="email" value="'+esc(email)+'" placeholder="you@example.com" data-email-input>'+ 
          '<span class="srk-email-validation"></span>'+
        '</div>'+
        renderNavigation()+
      '</div>';
  }

  function renderRedirectionAndAlt(){
    var redirEnabled = !!state.setupData.redir_enabled;
    var defaultCode = state.setupData.redir_default || '301';
    var altEnabled = !!state.setupData.alt_scan;

    return ''+
      '<div class="srk-setup-step srk-ops-step">'+
        '<div class="srk-step-header">'+
          '<span class="srk-step-eyebrow">Keep traffic flowing</span>'+
          '<h2 class="srk-step-title">Fine-tune redirections and image alt monitoring</h2>'+
          '<p class="srk-step-desc">Protect SEO equity by automatically redirecting moved URLs and surfacing images that need descriptive alt text.</p>'+
      '</div>'+
        '<div class="srk-ops-grid">'+
          '<section class="srk-ops-card">'+
            '<header class="srk-ops-card-header">'+
              '<div class="srk-ops-icon" aria-hidden="true">↪</div>'+
              '<div>'+ 
                '<h3>Redirection manager</h3>'+ 
                '<p>Automatically route outdated URLs, preserve link equity, and track hits on each redirect.</p>'+ 
              '</div>'+ 
            '</header>'+ 
            '<label class="srk-toggle-item">'+
              '<input type="checkbox" '+(redirEnabled ? 'checked' : '')+' data-redir-enabled>'+
              '<div class="srk-toggle-content">'+
                '<strong>Enable redirection manager</strong>'+ 
                '<span>Create 301/302 redirects with analytics</span>'+ 
              '</div>'+ 
            '</label>'+ 
            '<div class="srk-radio-group">'+
              '<span class="srk-field-label">Default redirect type</span>'+ 
              '<label class="srk-radio-option">'+
                '<input type="radio" name="srk_redir_default" value="301" '+(defaultCode === '301' ? 'checked' : '')+' data-redir-default="301">'+ 
                '<span>301 · Permanent</span>'+ 
              '</label>'+ 
              '<label class="srk-radio-option">'+
                '<input type="radio" name="srk_redir_default" value="302" '+(defaultCode === '302' ? 'checked' : '')+' data-redir-default="302">'+ 
                '<span>302 · Temporary</span>'+ 
              '</label>'+ 
            '</div>'+ 
          '</section>'+ 
          '<section class="srk-ops-card">'+
            '<header class="srk-ops-card-header">'+
              '<div class="srk-ops-icon" aria-hidden="true">🖼</div>'+
              '<div>'+ 
                '<h3>Image alt monitor</h3>'+ 
                '<p>Spot missing alt attributes and improve accessibility plus image SEO with curated recommendations.</p>'+ 
              '</div>'+ 
            '</header>'+ 
            '<label class="srk-toggle-item">'+
              '<input type="checkbox" '+(altEnabled ? 'checked' : '')+' data-alt-scan>'+
              '<div class="srk-toggle-content">'+
                '<strong>Enable alt text scanning</strong>'+ 
                '<span>Compile a queue of images missing alt descriptions</span>'+ 
              '</div>'+ 
            '</label>'+ 
            '<ul class="srk-summary-points srk-ops-points">'+
              '<li>Prioritise high-traffic posts first</li>'+ 
              '<li>Use descriptive, human-readable phrases</li>'+ 
              '<li>Alt text updates cascade across your site</li>'+ 
            '</ul>'+ 
          '</section>'+ 
        '</div>'+ 
        renderNavigation()+
      '</div>';
  }

  function renderChatbot(){
    var proIntent = !!state.setupData.pro_intent;
    var links = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.links) ? window.SRK_ONBOARDING_DATA.links : {};
    var chatbotActive = !!(window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.chatbot && window.SRK_ONBOARDING_DATA.chatbot.enabled);

    return ''+
      '<div class="srk-setup-step srk-chatbot-step premium">'+
        '<section class="srk-chatbot-hero">'+
          '<div class="srk-chatbot-glow"></div>'+ 
          '<span class="srk-chatbot-status-pill">'+(chatbotActive ? 'Live on your site' : 'Premium mode')+'</span>'+ 
          '<h2>AI concierge for every SEO workflow</h2>'+ 
          '<p>Turn on the assistant to answer in-context questions, surface fixes, and keep editors moving fast.</p>'+ 
        '</section>'+ 
        '<section class="srk-chatbot-body">'+
          '<div class="srk-chatbot-benefits">'+
            '<ul class="srk-summary-points srk-summary-points-tick">'+
              '<li>Instant answers for schema, redirects, and content fixes</li>'+ 
              '<li>Auto-generated checklists tailored to each issue</li>'+ 
              '<li>Shareable conversations for faster team execution</li>'+ 
            '</ul>'+ 
            '<div class="srk-chat-preview">'+
              '<div class="srk-chat-bubble user">“Why did my blog post drop in impressions last week?”</div>'+ 
              '<div class="srk-chat-bubble bot">“Clicks dipped after new 404 errors. Fix the broken links and request reindexing to recover.”</div>'+ 
            '</div>'+ 
          '</div>'+ 
          '<div class="srk-chatbot-panel">'+
            '<div class="srk-chatbot-status">'+
              '<span class="srk-status-label">Current status</span>'+ 
              '<span class="srk-status-indicator '+(chatbotActive ? 'active' : 'inactive')+'">'+(chatbotActive ? 'Active' : 'Not enabled')+'</span>'+ 
            '</div>'+ 
            '<div class="srk-chatbot-cta">'+
              (links.chatbot ? '<a href="'+links.chatbot+'" class="srk-btn-primary srk-btn-medium" data-srk-nav="1">Launch AI Chatbot</a>' : '')+
              '<button type="button" class="srk-btn-secondary srk-btn-medium" data-action="finish-onboarding">Go to Dashboard</button>'+ 
            '</div>'+ 
          '</div>'+ 
        '</section>'+ 
        renderNavigation()+
      '</div>';
  }

  function renderCompletion(){
    var L = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.links) ? window.SRK_ONBOARDING_DATA.links : {};
    var summary = [];
    
    if (state.setupData.postTypes.length > 0) {
      summary.push('✓ '+state.setupData.postTypes.length+' post types selected for scanning');
    }
    if (state.setupData.links_schedule && state.setupData.links_schedule !== 'manual') {
      var cadenceLabel = state.setupData.links_schedule === 'weekly' ? 'weekly' : (state.setupData.links_schedule === 'monthly' ? 'monthly' : 'daily');
      summary.push('✓ Link scans scheduled '+cadenceLabel);
    }
    if (state.setupData.keytrackEnabled) {
      summary.push('✓ KeyTrack enabled');
    }
    if (state.setupData.schemaTypes.length > 0) {
      summary.push('✓ '+state.setupData.schemaTypes.length+' schema types enabled');
    }
    if (Object.values(state.setupData.notifications).some(function(v){ return !!v; })) {
      summary.push('✓ Email notifications configured');
    }
    
    return ''+
      '<div class="srk-completion-screen">'+
        '<div class="srk-completion-icon">✓</div>'+
        '<h1 class="srk-completion-title">Setup complete</h1>'+ 
        '<p class="srk-completion-subtitle">Your core SEO tools are ready to roll.</p>'+ 
        (summary.length ? '<div class="srk-completion-summary">'+
          '<h3>Configured in this session</h3>'+ 
          '<ul>'+summary.map(function(s){ return '<li>'+esc(s)+'</li>'; }).join('')+'</ul>'+ 
        '</div>' : '')+
        '<div class="srk-next-steps">'+
          '<h3>Next steps</h3>'+ 
          '<ol>'+
            '<li>Run your first broken link scan</li>'+ 
            '<li>Review results in KeyTrack</li>'+ 
            '<li>Add schema to a priority page</li>'+ 
          '</ol>'+
        '</div>'+
        '<div class="srk-completion-cta">'+
          (L.dashboard ? '<a href="'+L.dashboard+'" class="srk-btn-primary srk-btn-large" data-action="goto-dashboard">Go to dashboard</a>' : '')+
        '</div>'+
        renderNavigation()+
      '</div>';
  }

  function makeSteps(){
    state.steps = [
      { title: 'Welcome', type: 'welcome', render: renderWelcome },
      { title: 'Link Scanner', type: 'setup', render: renderLinkScanner },
      { title: 'KeyTrack', type: 'setup', render: renderKeyTrack },
      { title: 'Schema', type: 'setup', render: renderSchema },
      { title: 'Notifications', type: 'setup', render: renderNotifications },
      { title: 'AI Assistant', type: 'setup', render: renderChatbot }
    ];
  }

  function updateProgress(){
    var progress = ((state.index + 1) / state.steps.length) * 100;
    if ($progressBar) {
      $progressBar.css('width', progress + '%');
    }
  }

  function render(){
    var step = state.steps[state.index];
    if (!step) return;
    
    $stepsList.empty();
    for (var i = 0; i < state.steps.length; i++){
      var cls = (i < state.index) ? 'done' : (i === state.index ? 'current' : '');
      $stepsList.append(
        '<li class="'+cls+'">'+
          '<button type="button" class="srk-step-link" data-index="'+i+'">'+
            '<span class="srk-step-number" aria-hidden="true">'+(i+1)+'</span>'+
          '</button>'+
        '</li>'
      );
    }
    
    var bodyHtml = step.render();
    if (step.type !== 'welcome' && bodyHtml.indexOf('srk-step-actions') === -1) {
      bodyHtml += renderNavigation();
    }
    
    $body.html(bodyHtml);
    
    updateProgress();
    attachStepHandlers();
  }

  function attachStepHandlers(){
    $body.find('input[data-post-type]').on('change', function(){
      var val = $(this).val();
      if ($(this).is(':checked')) {
        if (state.setupData.postTypes.indexOf(val) === -1) {
          state.setupData.postTypes.push(val);
        }
      } else {
        state.setupData.postTypes = state.setupData.postTypes.filter(function(pt){ return pt !== val; });
      }
    });
    
    $body.find('[data-action="select-all-pt"]').on('click', function(){
      $body.find('input[data-post-type]').prop('checked', true).trigger('change');
    });
    $body.find('[data-action="deselect-all-pt"]').on('click', function(){
      $body.find('input[data-post-type]').prop('checked', false).trigger('change');
    });
    
    $body.find('[data-links-schedule]').on('change', function(){
      var allowed = ['manual','weekly','monthly'];
      var val = $(this).val();
      state.setupData.links_schedule = allowed.indexOf(val) !== -1 ? val : 'manual';
    });

    $body.find('input[data-keytrack]').on('change', function(){
      state.setupData.keytrackEnabled = $(this).is(':checked');
    });
    
    $body.find('input[data-schema]').on('change', function(){
      var val = $(this).val();
      if ($(this).is(':checked')) {
        if (state.setupData.schemaTypes.indexOf(val) === -1) {
          state.setupData.schemaTypes.push(val);
        }
      } else {
        state.setupData.schemaTypes = state.setupData.schemaTypes.filter(function(s){ return s !== val; });
      }
    });
    
    $body.find('input[data-notification]').on('change', function(){
      var key = $(this).data('notification');
      state.setupData.notifications[key] = $(this).is(':checked');
    });

    $body.find('input[data-site-info-consent]').on('change', function(){
      var isChecked = $(this).is(':checked');
      state.setupData.site_info_consent = isChecked;
      
      // or to send site health info if checked
      if (window.SRK_ONBOARDING_DATA && SRK_ONBOARDING_DATA.ajaxUrl) {
        $.post(SRK_ONBOARDING_DATA.ajaxUrl, {
          action: 'srk_save_consent',
          nonce: SRK_ONBOARDING_DATA.nonce,
          consent: isChecked ? 1 : 0
        });
      }
    });
    
    $body.find('input[data-redir-enabled]').on('change', function(){
      state.setupData.redir_enabled = $(this).is(':checked');
    });

    $body.find('input[name="srk_redir_default"]').on('change', function(){
      state.setupData.redir_default = $(this).val();
    });

    $body.find('input[data-alt-scan]').on('change', function(){
      state.setupData.alt_scan = $(this).is(':checked');
    });

    $body.find('input[data-pro-intent]').on('change', function(){
      state.setupData.pro_intent = $(this).is(':checked');
    });

    $body.find('input[data-email-input]').on('input', function(){
      state.setupData.email = $(this).val();
      $body.find('.srk-email-validation').text('').removeClass('valid invalid');
    }).on('blur', function(){
      var email = $(this).val();
      var $validation = $body.find('.srk-email-validation');
      $validation.removeClass('valid invalid');
      if (!email) {
        state.setupData.email = '';
        return;
      }
      var valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
      if (valid) {
        state.setupData.email = email;
        $validation.text('✓ Valid email').addClass('valid');
      } else {
        $validation.text('Please enter a valid email').addClass('invalid');
      }
    });
    
    $body.find('.srk-btn-back').on('click', function(){ if (state.index > 0) { back(); } });

    $body.find('.srk-btn-skip').on('click', function(){
      syncCurrentStepState();
      next();
    });

    $body.find('.srk-btn-next').on('click', function(){
      next();
    });

    $body.find('[data-action="finish-onboarding"]').on('click', function(){
      saveSetupData();
      closeOnboarding();
      var dashboardLink = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.links) ? window.SRK_ONBOARDING_DATA.links.dashboard : null;
      if (dashboardLink) {
        setTimeout(function(){ window.location.assign(dashboardLink); }, 300);
      }
    });

    $body.find('[data-action="goto-dashboard"]').on('click', function(e){
      e.preventDefault();
      saveSetupData();
      var href = $(this).attr('href');
      if (href) {
        closeOnboarding();
        setTimeout(function(){ window.location.assign(href); }, 300);
      }
    });

    $body.find('[data-action="start-scan"]').on('click', function(e){
      e.preventDefault();
      saveSetupData();
      var dashboardLink = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.links) ? window.SRK_ONBOARDING_DATA.links.dashboard : null;
      if (dashboardLink) {
        window.open(dashboardLink, '_blank', 'noopener');
      }
    });

    $body.find('[data-action="start"]').on('click', function(){ next(); });
    $body.find('[data-action="skip"]').on('click', function(e){
      e.preventDefault();
      state.index = state.steps.length - 1;
      render();
    });
  }

  function saveSetupData(){
    if (!window.SRK_ONBOARDING_DATA || !SRK_ONBOARDING_DATA.ajaxUrl) return;
    
    var data = {
      action: 'srk_setup_onboarding_save',
      nonce: SRK_ONBOARDING_DATA.nonce,
      final: state.index === state.steps.length - 1 ? 1 : 0,
      data: JSON.stringify(state.setupData)
    };
    
    $.post(SRK_ONBOARDING_DATA.ajaxUrl, data);
  }

  function openOnboarding(){
    $('body').addClass('modal-open srk-onboarding-active');
    $overlay.removeClass('srk-hidden').attr('aria-hidden','false');
    $modal.removeClass('srk-hidden').attr('aria-hidden','false');
    state.index = 0;
    render();
    setTimeout(function(){ $('.srk-onboarding-close').trigger('focus'); }, 50);
  }

  function closeOnboarding(){
    $overlay.addClass('srk-hidden').attr('aria-hidden','true');
    $modal.addClass('srk-hidden').attr('aria-hidden','true');
    $('body').removeClass('modal-open srk-onboarding-active');
    if (state.index > 0) {
      saveSetupData();
    }
  }

  function shouldSaveCurrentStep(){
    var current = state.steps[state.index];
    return current && current.type === 'setup';
  }

  function syncCurrentStepState(){
    var current = state.steps[state.index];
    if (!current) return;

    // Sync Link Scanner step (step 2: index 1)
    if (current.type === 'setup' && state.index === 1) {
      // Sync post type checkboxes
      var postTypes = [];
      $body.find('input[data-post-type]:checked').each(function(){
        postTypes.push($(this).val());
      });
      state.setupData.postTypes = postTypes;
      
      // Sync schedule dropdown
      var schedule = $body.find('[data-links-schedule]').val();
      if (schedule) {
        state.setupData.links_schedule = schedule;
      }
    }

    // Sync Notifications step (step 5: index 4)
    if (current.type === 'setup' && state.index === 4) {
      // Sync notification checkboxes
      $body.find('input[data-notification]').each(function(){
        var key = $(this).data('notification');
        state.setupData.notifications[key] = $(this).is(':checked');
      });
      
      // Sync consent checkbox
      var consentCheckbox = $body.find('input[data-site-info-consent]');
      if (consentCheckbox.length) {
        state.setupData.site_info_consent = consentCheckbox.is(':checked');
      }
      
      // Sync email
      var emailInput = $body.find('input[data-email-input]');
      if (emailInput.length) {
        var emailVal = emailInput.val();
        if (emailVal) {
          state.setupData.email = emailVal;
        }
      }
    }
  }

  function saveCurrentStepIfNeeded(){
    if (shouldSaveCurrentStep()) {
      saveSetupData();
    }
  }

  function next(){ 
    if (state.index < state.steps.length - 1){ 
      saveCurrentStepIfNeeded();
      state.index++; 
      render(); 
    } else {
      // Final step: save, close, then redirect to the SRK dashboard.
      saveSetupData();
      closeOnboarding();
      var dashboardLink = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.links)
        ? window.SRK_ONBOARDING_DATA.links.dashboard
        : null;
      if (dashboardLink) {
        setTimeout(function(){ window.location.assign(dashboardLink); }, 300);
      }
    }
  }

  function back(){ 
    if (state.index > 0){ 
      saveCurrentStepIfNeeded();
      state.index--; 
      render(); 
    } 
  }

  function jumpTo(i){ 
    i = +i; 
    if (i >= 0 && i < state.steps.length){ 
      state.index = i; 
      render(); 
    } 
  }

  $(function(){
    $overlay = $('#srkOnboardingOverlay');
    $modal = $('#srkOnboardingModal');
    $body = $('#srkOnboardingBody');
    $stepsList = $modal.find('.srk-onboarding-steps');
    $close = $modal.find('.srk-onboarding-close');
    $progressBar = $modal.find('.srk-progress-bar-fill');

    if (window.SRK_ONBOARDING_DATA && SRK_ONBOARDING_DATA.adminEmail) {
      state.setupData.email = SRK_ONBOARDING_DATA.adminEmail;
    }

    if (window.SRK_ONBOARDING_DATA && SRK_ONBOARDING_DATA.saved) {
      if (SRK_ONBOARDING_DATA.saved.postTypes) {
        state.setupData.postTypes = [].concat(SRK_ONBOARDING_DATA.saved.postTypes);
      }
      if (SRK_ONBOARDING_DATA.saved.setup) {
        var setup = SRK_ONBOARDING_DATA.saved.setup;
        state.setupData.links_schedule = setup.links_schedule || 'manual';
        state.setupData.keytrackEnabled = !!setup.enable_keytrack;
        if (setup.schema_defaults) {
          state.setupData.schemaTypes = Object.keys(setup.schema_defaults).filter(function(key){ return !!setup.schema_defaults[key]; });
        }
        // Notifications: default ON when not yet explicitly set in saved setup.
        state.setupData.notifications.weeklyReport =
          (typeof setup.weekly_report === 'undefined') ? true : !!setup.weekly_report;
        state.setupData.notifications.keytrackAlerts =
          (typeof setup.keytrack_alerts === 'undefined') ? true : !!setup.keytrack_alerts;
        state.setupData.notifications.brokenLinks =
          (typeof setup.broken_links_notify === 'undefined') ? true : !!setup.broken_links_notify;
        // Basic site info consent: default ON when not present.
        state.setupData.site_info_consent =
          (typeof setup.site_info_consent !== 'undefined') ? !!setup.site_info_consent : true;
        if (setup.notification_email) {
          state.setupData.email = setup.notification_email;
        }
        state.setupData.redir_enabled = !!setup.redir_enabled;
        state.setupData.redir_default = setup.redir_default || '301';
        state.setupData.alt_scan = !!setup.alt_scan;
        state.setupData.pro_intent = !!setup.pro_intent;
      }
    } else {
      // No saved setup yet – default key onboarding choices.
      // Notifications: all on by default.
      state.setupData.notifications.weeklyReport = true;
      state.setupData.notifications.keytrackAlerts = true;
      state.setupData.notifications.brokenLinks = true;
      state.setupData.site_info_consent = true;
      // Link Scanner: weekly cadence, and focus on Posts & Pages initially.
      state.setupData.links_schedule = 'weekly';
      state.setupData.postTypes = ['post', 'page'];
    }

    makeSteps();

    $close.on('click', function(){
      closeOnboarding();
      var dashboardLink = (window.SRK_ONBOARDING_DATA && window.SRK_ONBOARDING_DATA.links)
        ? window.SRK_ONBOARDING_DATA.links.dashboard
        : null;
      if (dashboardLink) {
        setTimeout(function(){ window.location.assign(dashboardLink); }, 300);
      }
    });
    $overlay.on('click', function(e){ if (e.target === $overlay[0]) { closeOnboarding(); } });
    $stepsList.on('click', '.srk-step-link', function(){ jumpTo($(this).data('index')); });

    $(document).on('click', 'a.srk-cta[data-srk-nav]', function(e){
      var href = $(this).attr('href');
      if (href) {
        e.preventDefault();
        closeOnboarding();
        setTimeout(function(){ window.location.assign(href); }, 300);
      }
    });

    // Only open onboarding if runNow is true AND onboarding has never been completed
    if (window.SRK_ONBOARDING_DATA && SRK_ONBOARDING_DATA.runNow) {
      var setup = (SRK_ONBOARDING_DATA.saved && SRK_ONBOARDING_DATA.saved.setup) ? SRK_ONBOARDING_DATA.saved.setup : {};
      var isCompleted = !!(setup.completed && setup.completed_at);
      if (!isCompleted) {
        setTimeout(openOnboarding, 400);
      }
    }

    $(document).on('keydown', function(e){
      if ($modal.hasClass('srk-hidden')) return;
      if (e.key === 'Escape') { closeOnboarding(); }
      if (e.key === 'ArrowRight' && state.index < state.steps.length - 1) { next(); }
      if (e.key === 'ArrowLeft' && state.index > 0) { back(); }
    });
  });

})(jQuery);
