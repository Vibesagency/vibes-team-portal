<?php

if (!defined('ABSPATH')) {
    exit;
}

class VTP_App {
    public static function boot(): void {
        add_shortcode('vtp_login', [self::class, 'login']);
        add_shortcode('vtp_portal', [self::class, 'portal']);
        add_shortcode('vtp_quote_review', [self::class, 'quote_review']);
        add_shortcode('vtp_project_review', [self::class, 'project_review']);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_action('template_redirect', [self::class, 'guard']);
        add_filter('wp_robots', [self::class, 'noindex']);
        add_filter('show_admin_bar', [self::class, 'admin_bar']);
    }

    public static function guard(): void {
        if (is_page('team-login') && is_user_logged_in()) {
            wp_safe_redirect(home_url('/team-portal/'));
            exit;
        }
        if (is_page('team-portal') && !is_user_logged_in()) {
            wp_safe_redirect(home_url('/team-login/'));
            exit;
        }
        if (is_page('team-portal')) {
            $user = wp_get_current_user();
            if ((bool) get_user_meta($user->ID, 'vtp_disabled', true)) {
                wp_logout();
                wp_safe_redirect(home_url('/team-login/?account=disabled'));
                exit;
            }
        }
        if (is_page(['team-login', 'team-portal', 'quotation-review', 'project-review'])) {
            $content = is_page('team-login') ? self::login() : (is_page('team-portal') ? self::portal() : (is_page('quotation-review') ? self::quote_review() : self::project_review()));
            self::standalone($content);
        }
    }

    private static function standalone(string $content): void {
        status_header(200);
        nocache_headers();
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html(is_page('team-login') ? 'Vibes Team Login' : (is_page('quotation-review') ? 'Review Vibes Quotation' : (is_page('project-review') ? 'Review Vibes Project' : 'Vibes Team Portal'))); ?></title>
            <?php wp_head(); ?>
        </head>
        <body class="vtp-standalone">
            <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php wp_footer(); ?>
        </body>
        </html><?php
        exit;
    }

    public static function noindex(array $robots): array {
        if (is_page(['team-login', 'team-portal', 'quotation-review', 'project-review'])) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
            $robots['noarchive'] = true;
        }
        return $robots;
    }

    public static function admin_bar(bool $show): bool {
        return is_page(['team-login', 'team-portal', 'quotation-review', 'project-review']) ? false : $show;
    }

    public static function assets(): void {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $is_portal_path = (bool) preg_match('#/team-portal/?$#', $path);
        if (!is_page(['team-login', 'team-portal', 'quotation-review', 'project-review']) && !$is_portal_path) return;
        wp_enqueue_style('vtp-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap', [], null);
        wp_enqueue_style('vtp-app', VTP_URL . 'assets/css/portal.css', ['vtp-fonts'], '0.6.61-connect-same-page');
        wp_enqueue_style('vtp-approval', VTP_URL . 'assets/css/approval.css', ['vtp-app'], '0.6.35-our-work-route');
        wp_enqueue_script('vtp-quote-reference', VTP_URL . 'assets/js/quotation-reference.js', [], '0.6.35-our-work-route', false);
        if (is_page('team-portal') || $is_portal_path) {
            wp_enqueue_script('vtp-app', VTP_URL . 'assets/js/portal.js', ['vtp-quote-reference'], '0.6.59-connect-same-page', true);
            wp_localize_script('vtp-app', 'VTP', [
                'root' => esc_url_raw(rest_url('vibes/v1/')),
                'nonce' => wp_create_nonce('wp_rest'),
                'logout' => wp_logout_url(home_url('/team-login/')),
                'logo' => VTP_URL . 'assets/images/vibes-logo-cropped.png',
                'quoteBackground' => VTP_URL . 'assets/images/quotation-six-month-background.png',
            ]);
        }
    }

    public static function login(): string {
        $error = '';
        if (isset($_GET['login']) && $_GET['login'] === 'failed') $error = 'The email or password is incorrect.';
        if (isset($_GET['account']) && $_GET['account'] === 'disabled') $error = 'This account is no longer active. Contact your administrator.';
        ob_start(); ?>
        <main class="vtp-login-page">
            <div class="vtp-orbit vtp-orbit-login"></div>
            <header class="vtp-login-head">
                <span class="vtp-logo-image" role="img" aria-label="Vibes Agency"></span>
            </header>
            <section class="vtp-login-grid">
                <div class="vtp-login-intro">
                    <p class="vtp-eyebrow">THE CREATIVE ENERGY YOUR TEAM DESERVES</p>
                    <h1>Work together.<br><span>Create better.</span></h1>
                    <p>Your private Vibes workspace for projects, people and everything we create together.</p>
                </div>
                <div class="vtp-login-card">
                    <h2>Welcome to Vibes</h2>
                    <p>Sign in with your company account</p>
                    <?php if ($error): ?><div class="vtp-alert error"><?php echo esc_html($error); ?></div><?php endif; ?>
                    <?php wp_login_form([
                        'redirect' => home_url('/team-portal/'),
                        'label_username' => 'Email address',
                        'label_password' => 'Password',
                        'label_remember' => 'Remember me',
                        'label_log_in' => 'Sign in →',
                        'remember' => true,
                    ]); ?>
                    <a class="vtp-forgot" href="<?php echo esc_url(wp_lostpassword_url()); ?>">Forgot password?</a>
                    <div class="vtp-login-note">Employee accounts are created by an administrator.</div>
                </div>
            </section>
        </main>
        <?php return ob_get_clean();
    }

    private static function nav_icon(string $name): string {
        $icons = [
            'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11.5 12 5l8 6.5V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z"/></svg>',
            'employees' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'announcements' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 18-5v12L3 13zM7 14l1.5 6h4L11 13"/></svg>',
            'projects' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/></svg>',
            'inquiries' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v12H8l-4 3z"/><path d="M8 9h8M8 13h5"/></svg>',
            'quotes' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h6M9 16h6"/></svg>',
            'invoices' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
            'catalog' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM4 10h16"/><path d="M8 15h3M15 14v4M13 16h4"/></svg>',
            'tasks' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>',
            'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
            'leave' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 15l2 2 5-5"/></svg>',
            'files' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6l2 3h8v13H4z"/><path d="M4 9h16"/></svg>',
            'directory' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="3" width="6" height="5" rx="1"/><rect x="2" y="16" width="6" height="5" rx="1"/><rect x="16" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M5 16v-4h14v4"/></svg>',
            'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.6v-.09A1.7 1.7 0 0 0 8 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 3.6 15a1.7 1.7 0 0 0-1.6-1H2v-4h.09A1.7 1.7 0 0 0 3.6 8a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 8 3.6 1.7 1.7 0 0 0 9 2h4a1.7 1.7 0 0 0 2 1.6 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 8a1.7 1.7 0 0 0 1.6 1h.09v4H21a1.7 1.7 0 0 0-1.6 2z"/></svg>',
            'bell' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/></svg>',
        ];
        return $icons[$name] ?? '';
    }

    public static function portal(): string {
        $user = wp_get_current_user();
        $is_admin = current_user_can('vtp_manage_employees');
        ob_start(); ?>
        <div id="vtp-app" class="vtp-app" data-role="<?php echo $is_admin ? 'admin' : 'employee'; ?>">
            <aside class="vtp-sidebar">
                <a class="vtp-brand" href="#dashboard"><span class="vtp-logo-image" role="img" aria-label="Vibes Agency"></span></a>
                <nav id="vtp-nav">
                    <div class="vtp-nav-group vtp-nav-group-dashboard" data-nav-group="dashboard">
                        <a href="#dashboard" data-route="dashboard" class="active"><i><?php echo self::nav_icon('dashboard'); ?></i><span>Dashboard</span></a>
                    </div>
                    <div class="vtp-nav-group" data-nav-group="sales">
                        <span class="vtp-nav-section-label">Sales &amp; Clients</span>
                        <?php if (current_user_can('vtp_manage_sales')): ?><a href="#inquiries" data-route="inquiries"><i><?php echo self::nav_icon('inquiries'); ?></i><span>Inquiries</span><b id="vtp-inquiry-badge" class="vtp-nav-badge"></b></a><?php endif; ?>
                        <?php if (current_user_can('vtp_manage_sales')): ?><a href="#quotations" data-route="quotations"><i><?php echo self::nav_icon('quotes'); ?></i><span>Quotations</span></a><?php endif; ?>
                    </div>
                    <div class="vtp-nav-group" data-nav-group="projects">
                        <span class="vtp-nav-section-label">Project Management</span>
                        <a href="#projects" data-route="projects"><i><?php echo self::nav_icon('projects'); ?></i><span>Projects</span></a>
                        <a href="#tasks" data-route="tasks"><i><?php echo self::nav_icon('tasks'); ?></i><span><?php echo $is_admin ? 'Tasks' : 'My Tasks'; ?></span></a>
                        <a href="#calendar" data-route="calendar"><i><?php echo self::nav_icon('calendar'); ?></i><span>Calendar</span></a>
                        <a href="#files" data-route="files"><i><?php echo self::nav_icon('files'); ?></i><span>Files &amp; Brand Assets</span></a>
                    </div>
                    <div class="vtp-nav-group" data-nav-group="team">
                        <span class="vtp-nav-section-label">Team &amp; HR</span>
                        <?php if ($is_admin): ?><a href="#employees" data-route="employees"><i><?php echo self::nav_icon('employees'); ?></i><span>Employees</span></a><?php endif; ?>
                        <a href="#directory" data-route="directory"><i><?php echo self::nav_icon('directory'); ?></i><span>Organization Chart</span></a>
                        <a href="#leave" data-route="leave"><i><?php echo self::nav_icon('leave'); ?></i><span>Leave Management</span></a>
                        <a href="#announcements" data-route="announcements"><i><?php echo self::nav_icon('announcements'); ?></i><span>Announcements</span></a>
                    </div>
                    <div class="vtp-nav-group" data-nav-group="website">
                        <span class="vtp-nav-section-label">Website Management</span>
                        <a href="#our-work" data-route="our-work"><i><?php echo self::nav_icon('files'); ?></i><span>Our Work</span></a>
                        <?php if ($is_admin): ?><a href="#pricing" data-route="pricing"><i><?php echo self::nav_icon('catalog'); ?></i><span>Services &amp; Pricing</span></a><?php endif; ?>
                    </div>
                    <div class="vtp-nav-group" data-nav-group="finance">
                        <span class="vtp-nav-section-label">Finance</span>
                        <?php if (current_user_can('vtp_manage_sales')): ?><a href="#invoices" data-route="invoices"><i><?php echo self::nav_icon('invoices'); ?></i><span>Invoices</span></a><?php endif; ?>
                    </div>
                    <div class="vtp-nav-group" data-nav-group="reports">
                        <span class="vtp-nav-section-label">Reports &amp; Insights</span>
                    </div>
                </nav>
                <div class="vtp-sidebar-links" aria-label="Vibes Agency links">
                    <a class="vtp-site-link" href="https://vibesagency.co/" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.7 2.5 4 5.5 4 9s-1.3 6.5-4 9c-2.7-2.5-4-5.5-4-9s1.3-6.5 4-9Z"/></svg>
                        <span>vibesagency.co</span>
                    </a>
                    <div class="vtp-social-links">
                        <a href="https://www.facebook.com/profile.php?id=61587533361981" target="_blank" rel="noopener noreferrer" aria-label="Vibes Agency on Facebook"><svg viewBox="0 0 24 24" aria-hidden="true"><path class="fill" d="M14 21v-8h3l.5-3H14V8.2c0-.9.3-1.7 1.8-1.7H18V3.8c-.5-.1-1.5-.2-2.7-.2-2.7 0-4.5 1.6-4.5 4.6V10H8v3h2.8v8Z"/></svg></a>
                        <a href="https://www.instagram.com/vibes_agencyco/" target="_blank" rel="noopener noreferrer" aria-label="Vibes Agency on Instagram"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.4" cy="6.7" r=".8" class="fill"/></svg></a>
                        <a href="https://www.linkedin.com/company/vibes-agency/" target="_blank" rel="noopener noreferrer" aria-label="Vibes Agency on LinkedIn"><svg viewBox="0 0 24 24" aria-hidden="true"><path class="fill" d="M6.6 8.2H3.3V21h3.3V8.2ZM5 3A2 2 0 1 0 5 7a2 2 0 0 0 0-4Zm4.2 5.2V21h3.3v-6.3c0-1.7.3-3.3 2.4-3.3 2.1 0 2.1 1.9 2.1 3.4V21h3.3v-7c0-3.4-.7-6-4.7-6-1.9 0-3.2 1.1-3.7 2.1h-.1V8.2H9.2Z"/></svg></a>
                        <a href="https://www.youtube.com/channel/UCZh2UxJfEoVDF3Xi1YBljpg" target="_blank" rel="noopener noreferrer" aria-label="Vibes Agency on YouTube"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="m10 9 5 3-5 3Z" class="fill"/></svg></a>
                        <a href="https://www.tiktok.com/@_vibesagency_" target="_blank" rel="noopener noreferrer" aria-label="Vibes Agency on TikTok"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4v10.2a4.2 4.2 0 1 1-3.3-4.1M14 4c.6 2.3 2 3.7 4.5 4"/></svg></a>
                    </div>
                </div>
            </aside>
            <div class="vtp-main">
                <header class="vtp-topbar">
                    <label class="vtp-search"><span>⌕</span><input type="search" id="vtp-global-search" placeholder="Search projects, people, tasks…"></label>
                    <div class="vtp-top-actions">
                        <button id="vtp-notification-button" class="vtp-icon-button" aria-label="Notifications"><?php echo self::nav_icon('bell'); ?><b id="vtp-unread"></b></button>
                        <div class="vtp-user-wrap">
                            <button class="vtp-user-menu" id="vtp-user-menu" aria-haspopup="true" aria-expanded="false">
                                <img id="vtp-top-avatar" src="<?php echo esc_url(VTP_REST::avatar_url($user->ID, 96)); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
                                <span><strong><?php echo esc_html($user->display_name); ?></strong><small><?php echo esc_html(get_user_meta($user->ID, 'vtp_position', true) ?: ($is_admin ? 'Administrator' : 'Team member')); ?></small></span>
                                <svg class="vtp-user-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg>
                            </button>
                            <div id="vtp-user-dropdown" class="vtp-user-dropdown" hidden>
                                <a href="#profile" data-profile-link>My profile</a>
                                <a href="<?php echo esc_url(wp_logout_url(home_url('/team-login/'))); ?>">Log out</a>
                            </div>
                        </div>
                    </div>
                </header>
                <main id="vtp-view" class="vtp-view" aria-live="polite"><div class="vtp-loader"><span></span><p>Loading your workspace…</p></div></main>
            </div>
            <div id="vtp-drawer" class="vtp-drawer" aria-hidden="true"><div class="vtp-drawer-panel"><button class="vtp-drawer-close" aria-label="Close">×</button><div id="vtp-drawer-content"></div></div></div>
            <div id="vtp-toast" class="vtp-toast" role="status"></div>
        </div>
        <?php return ob_get_clean();
    }

    public static function quote_review(): string {
        ob_start(); ?>
        <main class="vtp-client-review">
            <header class="vtp-client-review-head">
                <img src="<?php echo esc_url(VTP_URL . 'assets/images/vibes-logo-cropped.png'); ?>" alt="Vibes Agency">
                <span>SECURE QUOTATION REVIEW</span>
            </header>
            <section id="vtp-client-quote" class="vtp-client-quote-card">
                <div class="vtp-loader"><span></span><p>Loading your quotation…</p></div>
            </section>
        </main>
        <script>
        (() => {
          const root = <?php echo wp_json_encode(esc_url_raw(rest_url('vibes/v1/public/quotations/'))); ?>;
          const logo = <?php echo wp_json_encode(esc_url(VTP_URL . 'assets/images/vibes-logo-cropped.png')); ?>;
          const background = <?php echo wp_json_encode(esc_url(VTP_URL . 'assets/images/quotation-six-month-background.png')); ?>;
          const host = document.getElementById('vtp-client-quote');
          const token = new URLSearchParams(location.search).get('token') || '';
          const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
          const money = (value, currency) => new Intl.NumberFormat('en-SA',{style:'currency',currency}).format(Number(value || 0));
          const date = value => value ? new Date(value + 'T12:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '—';

          function render(quote) {
            const items = Array.isArray(quote.items) ? quote.items : [];
            const explicitMonths = Number(quote.contract_months || 0);
            const contractMonths = explicitMonths > 1 ? [explicitMonths] : [...new Set(items.map(item => Number(item.durationMonths || 0)).filter(months => months > 1))];
            const contractRates = [...new Set(items.map(item => Number(item.durationDiscount ?? item.annualDiscount ?? 0)).filter(rate => rate > 0))];
            const contractLabel = Number(quote.period_index || 0) > 0 ? `Month ${Number(quote.period_index)} of ${explicitMonths || contractMonths[0]}` : (contractMonths.length === 1 ? `${contractMonths[0]} month${contractMonths[0] === 1 ? '' : 's'}` : '');
            const contractRate = Number(quote.discount_rate || 0) || (contractRates.length === 1 ? contractRates[0] : 0);
            const contractBlock = contractLabel ? `<section class="vtp-public-contract"><div><small>${Number(quote.period_index || 0) > 0 ? 'MONTHLY PERIOD' : 'CONTRACT TERM'}</small><strong>${esc(contractLabel)}</strong></div><div><small>DISCOUNT</small><strong>${contractRate ? `${contractRate}% included` : 'Included in prices'}</strong></div><p>${Number(quote.period_index || 0) > 0 ? 'This quotation covers the listed deliverables for this monthly service period.' : `Prices shown cover the full contract term${contractRate ? ' and include the applicable contract discount' : ''}.`}</p></section>` : '';
            const discountRow = Number(quote.discount_amount || 0) > 0 ? `<div><dt>Discount (${Number(quote.discount_rate || 0)}%)</dt><dd>-${money(quote.discount_amount, quote.currency)}</dd></div>` : '';
            const clientLocation = quote.country || quote.location || 'Location';
            const responded = ['approved','changes_requested'].includes(quote.status);
            host.innerHTML = `<article class="vtp-public-quote"><section class="vtp-public-paper">
              <header><img src="${logo}" alt="Vibes Agency"><div class="vtp-public-heading"><div class="vtp-public-title"><span>QUOTATION</span><i></i><b></b></div><strong aria-hidden="true">${esc(quote.quote_number)}</strong><dl><div><dt>Issued</dt><dd>${date(String(quote.created_at || '').slice(0,10))}</dd></div><div><dt>Valid until</dt><dd>${date(quote.valid_until)}</dd></div><div><dt>Currency</dt><dd>${esc(quote.currency)}</dd></div></dl></div></header>
              <section class="vtp-public-parties"><div><small>PREPARED FOR</small><h2>${esc(quote.company || quote.client_name)}</h2><p>${quote.company ? `${esc(quote.client_name)}<br>` : ''}${esc(clientLocation)}<br>${esc(quote.email || '')}${quote.phone ? `<br>${esc(quote.phone)}` : ''}</p></div><div><small>PREPARED BY</small><h2>Vibes Agency</h2><p>Beirut, Lebanon<br>finance@vibesagency.co<br>+9615271247&nbsp;&nbsp;+96170606046</p></div></section>${contractBlock}
              <div class="vtp-public-table-wrap"><table><thead><tr><th>No.</th><th>Label</th><th>Service / deliverable</th><th>Qty</th><th>Unit price</th><th>Total</th></tr></thead><tbody>${items.map((item,index)=>`<tr><td>${index+1}</td><td><b>${esc(item.label || '—')}</b></td><td>${esc(item.description)}</td><td>${Number(item.quantity)}</td><td>${money(item.unitPrice,quote.currency)}</td><td>${money(item.total,quote.currency)}</td></tr>`).join('')}</tbody></table></div>
              <section class="vtp-public-total"><div></div><dl><div><dt>Subtotal</dt><dd>${money(quote.subtotal,quote.currency)}</dd></div>${discountRow}<div><dt>VAT (${Number(quote.vat_rate)}%)</dt><dd>${money(quote.vat_amount,quote.currency)}</dd></div><div class="total"><dt>Total</dt><dd>${money(quote.total,quote.currency)}</dd></div></dl></section>
              <section class="vtp-public-terms"><small>TERMS</small><p>${esc(quote.terms || '')}</p></section><div class="vtp-public-accent"><i></i><i></i><i></i></div>
              <footer><strong>We don’t follow trends, <b>WE SET VIBES.</b></strong><span>vibesagency.co</span></footer></section>
              ${responded ? `<div class="vtp-public-complete"><strong>${quote.status === 'approved' ? 'Quotation approved' : 'Changes requested'}</strong><p>Thank you. Vibes Agency has been notified.</p></div>` : `<form id="vtp-client-response" class="vtp-public-response">
                <h2>Respond to this quotation</h2><label>Your full name<input name="name" value="${esc(quote.client_name)}" required></label>
                <label>Message or requested changes<textarea name="message" rows="4" placeholder="Optional for approval; required for questions or changes"></textarea></label>
                <label class="vtp-public-accept"><input type="checkbox" name="accepted"> I confirm that I am authorized to approve this scope and price.</label>
                <div class="vtp-public-actions"><button type="button" data-action="approve" class="primary">Approve quotation</button><button type="button" data-action="changes">Request changes</button><button type="button" data-action="question">Ask a question</button></div>
                <p id="vtp-client-status" role="status"></p>
              </form>`}
            </article>`;
            const reference = window.VTPQuoteReference?.render(quote, background);
            if (reference) host.querySelector('.vtp-public-paper').outerHTML = reference;
          }

          async function load() {
            if (!/^[a-f0-9]{64}$/.test(token)) throw new Error('This quotation link is invalid.');
            const response = await fetch(root + token,{headers:{Accept:'application/json'}});
            const data = await response.json().catch(()=>({}));
            if (!response.ok) throw new Error(data.message || 'This quotation is unavailable.');
            render(data);
          }

          host.addEventListener('click', async event => {
            const button = event.target.closest('[data-action]');
            if (!button) return;
            const form = document.getElementById('vtp-client-response');
            const name = form.name.value.trim();
            const message = form.message.value.trim();
            const action = button.dataset.action;
            const status = document.getElementById('vtp-client-status');
            if (!name) { form.name.focus(); return; }
            if (action === 'approve' && !form.accepted.checked) { status.textContent = 'Please confirm that you are authorized to approve the quotation.'; return; }
            if (action !== 'approve' && !message) { form.message.focus(); status.textContent = 'Please add your question or requested changes.'; return; }
            [...form.querySelectorAll('button')].forEach(item => item.disabled = true);
            status.textContent = 'Sending your response…';
            try {
              const response = await fetch(root + token + '/respond',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action,name,message,accepted:form.accepted.checked})});
              const data = await response.json().catch(()=>({}));
              if (!response.ok) throw new Error(data.message || 'Unable to save your response.');
              status.textContent = data.message;
              await load();
            } catch (error) {
              status.textContent = error.message;
              [...form.querySelectorAll('button')].forEach(item => item.disabled = false);
            }
          });

          load().catch(error => { host.innerHTML = `<div class="vtp-public-error"><h1>Quotation unavailable</h1><p>${esc(error.message)}</p><a href="https://vibesagency.co/contact-creative-agency">Contact Vibes Agency</a></div>`; });
        })();
        </script>
        <?php return ob_get_clean();
    }

    public static function project_review(): string {
        ob_start(); ?>
        <main class="vtp-client-review vtp-project-review-page">
            <header class="vtp-client-review-head">
                <img src="<?php echo esc_url(VTP_URL . 'assets/images/vibes-logo-cropped.png'); ?>" alt="Vibes Agency">
                <span>SECURE PROJECT REVIEW</span>
            </header>
            <section id="vtp-client-project" class="vtp-client-quote-card">
                <div class="vtp-loader"><span></span><p>Loading your project review…</p></div>
            </section>
        </main>
        <script>
        (() => {
          const root = <?php echo wp_json_encode(esc_url_raw(rest_url('vibes/v1/public/projects/'))); ?>;
          const logo = <?php echo wp_json_encode(esc_url(VTP_URL . 'assets/images/vibes-logo-cropped.png')); ?>;
          const host = document.getElementById('vtp-client-project');
          const token = new URLSearchParams(location.search).get('token') || '';
          const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
          const date = value => value ? new Date(String(value).replace(' ', 'T') + (String(value).length === 10 ? 'T12:00:00' : '')).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '—';
          const files = task => (Array.isArray(task.files) ? task.files : []).filter(item => item && item.url).map(item => `<a class="vtp-public-file" href="${esc(item.url)}" target="_blank" rel="noopener noreferrer">${esc(item.name || 'Open delivered file')} ↗</a>`).join('');
          function render(payload) {
            const project = payload.project || {};
            const tasks = Array.isArray(payload.tasks) ? payload.tasks : [];
            const responded = ['approved','changes_requested','meeting_requested','call_requested'].includes(project.client_review_status);
            host.innerHTML = `<article class="vtp-public-quote vtp-public-project"><section class="vtp-public-paper">
              <header><img src="${logo}" alt="Vibes Agency"><div class="vtp-public-heading"><div class="vtp-public-title"><span>PROJECT REVIEW</span><i></i><b></b></div><strong>${esc(project.name)}</strong><dl><div><dt>CLIENT</dt><dd>${esc(project.client || project.client_name || '')}</dd></div><div><dt>QUOTATION</dt><dd>${esc(project.quote_number || '—')}</dd></div><div><dt>MONTH</dt><dd>${esc(project.client_review_month_label || 'Project delivery')}</dd></div></dl></div></header>
              <section class="vtp-public-parties"><div><small>DELIVERY STATUS</small><h2>${esc(project.progress || 0)}% complete</h2><p>${tasks.length} deliverable${tasks.length === 1 ? '' : 's'} included in this review.</p></div><div><small>PREPARED BY</small><h2>Vibes Agency</h2><p>Beirut, Lebanon<br>+96170606046<br><a href="https://vibesagency.co">vibesagency.co</a></p></div></section>
              <section class="vtp-public-project-tasks"><small>DELIVERABLES</small>${tasks.length ? tasks.map(task => `<article class="vtp-public-task"><div><span class="vtp-task-id">${esc(task.service_label || 'SERVICE')} · ${esc(task.task_number || '')}</span><h3>${esc(task.title)}</h3><p>${esc(task.submission_note || task.description || 'Delivered service')}</p></div><div>${files(task)}</div></article>`).join('') : '<p>No deliverables are available for review yet.</p>'}</section>
              <footer><strong>WE SET VIBES.</strong><span>vibesagency.co</span></footer></section>
              ${responded ? `<div class="vtp-public-complete"><strong>${project.client_review_status === 'approved' ? 'Project approved' : project.client_review_status === 'changes_requested' ? 'Changes requested' : project.client_review_status === 'meeting_requested' ? 'Meeting requested' : 'Call requested'}</strong><p>Thank you. Vibes Agency has been notified.</p></div>` : `<form id="vtp-project-response" class="vtp-public-response"><h2>Review this delivery</h2><label>Your full name<input name="name" value="${esc(project.client_name || '')}" required></label><label>Comments or request<textarea name="message" rows="4" placeholder="Add feedback, questions, or scheduling details"></textarea></label><label class="vtp-public-accept"><input type="checkbox" name="accepted"> I confirm that I am authorized to respond for this project.</label><div class="vtp-public-actions"><button type="button" data-action="approve" class="primary">Approve delivery</button><button type="button" data-action="changes">Request changes</button><button type="button" data-action="meeting">Schedule a meeting</button><button type="button" data-action="call">Request a direct call</button></div><p id="vtp-project-status" role="status"></p></form>`}
            </article>`;
          }
          async function load() {
            if (!/^[a-f0-9]{64}$/.test(token)) throw new Error('This project link is invalid.');
            const response = await fetch(root + token, {headers:{Accept:'application/json'}});
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'This project review is unavailable.');
            render(data);
          }
          host.addEventListener('click', async event => {
            const button = event.target.closest('[data-action]');
            if (!button) return;
            const form = document.getElementById('vtp-project-response');
            const name = form.name.value.trim();
            const message = form.message.value.trim();
            const action = button.dataset.action;
            const status = document.getElementById('vtp-project-status');
            if (!name) { form.name.focus(); return; }
            if (!form.accepted.checked) { status.textContent = 'Please confirm that you are authorized to respond.'; return; }
            if (action !== 'approve' && !message) { form.message.focus(); status.textContent = 'Please add a comment or scheduling detail.'; return; }
            [...form.querySelectorAll('button')].forEach(item => item.disabled = true);
            status.textContent = 'Sending your response…';
            try {
              const response = await fetch(root + token + '/respond',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action,name,message,accepted:form.accepted.checked})});
              const data = await response.json().catch(()=>({}));
              if (!response.ok) throw new Error(data.message || 'Unable to save your response.');
              status.textContent = data.message;
              await load();
            } catch (error) { status.textContent = error.message; [...form.querySelectorAll('button')].forEach(item => item.disabled = false); }
          });
          load().catch(error => { host.innerHTML = `<div class="vtp-public-error"><h1>Project unavailable</h1><p>${esc(error.message)}</p><a href="https://vibesagency.co/contact-creative-agency">Contact Vibes Agency</a></div>`; });
        })();
        </script>
        <?php return ob_get_clean();
    }
}
