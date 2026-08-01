<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'twentytwentyfive-style',
        get_template_directory_uri() . '/style.css',
        [],
        wp_get_theme( 'twentytwentyfive' )->get( 'Version' )
    );
    wp_enqueue_style(
        'ozupay-theme-style',
        get_stylesheet_uri(),
        [ 'twentytwentyfive-style' ],
        wp_get_theme()->get( 'Version' )
    );
    wp_enqueue_style(
        'ozupay-google-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@500;600&display=swap',
        [],
        null
    );
} );

// jQuery core/migrate are the last render-blocking <head> scripts on the front end — every
// other enqueued script already carries a defer strategy. Use WP core's own strategy API
// (not a manual script_loader_tag rewrite) so WP's dependency resolver can fall back to
// blocking automatically if anything not defer-safe ever gets added as a dependent.
add_action( 'wp_enqueue_scripts', function () {
    wp_script_add_data( 'jquery-core', 'strategy', 'defer' );
    wp_script_add_data( 'jquery-migrate', 'strategy', 'defer' );
}, 100 );

add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
    unset( $tabs['reviews'] );
    return $tabs;
} );

// Shop archive pricing cards: print each product's short description (a
// short highlight list set in the product's Excerpt field) between the price
// and the Add to Cart button. Scoped to the shop archive only — the classic
// loop hook also fires on other product listings (related products,
// category archives) where this checklist styling doesn't apply.
add_action( 'woocommerce_after_shop_loop_item_title', function (): void {
    if ( ! function_exists( 'is_shop' ) || ! is_shop() ) {
        return;
    }
    global $product;
    if ( ! $product ) {
        return;
    }

    echo '<p class="ozp-plan-tenure">per site &middot; per year</p>';

    $short_description = $product->get_short_description();
    if ( $short_description ) {
        echo '<div class="ozp-plan-features">' . wp_kses_post( $short_description ) . '</div>';
    }
}, 15 );

// Object Cache Pro prints a Redis metrics/analytics line as an HTML comment
// on every front-end response when its debug/footnote config is on. That
// leaks server-internal cache stats (hit ratios, memory usage, key counts)
// to every visitor and crawler. Suppress it via the plugin's own filter
// rather than touching server config.
add_filter( 'objectcache_omit_analytics_footnote', '__return_true' );

// ── Cloudflare Turnstile ─────────────────────────────────────────────────────

/**
 * Verify a Turnstile token server-side. Returns true on success.
 * Silently passes (returns true) if the secret key constant is not defined,
 * so staging environments without the constant don't break form submission.
 */
function ozp_verify_turnstile( string $token ): bool {
    if ( ! defined( 'OZP_TURNSTILE_SECRET_KEY' ) ) {
        return true;
    }
    if ( $token === '' ) {
        return false;
    }
    $response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 10,
        'body'    => [
            'secret'   => OZP_TURNSTILE_SECRET_KEY,
            'response' => $token,
        ],
    ] );
    if ( is_wp_error( $response ) ) {
        error_log( 'OzuPay Turnstile verify error: ' . $response->get_error_message() );
        return false;
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    return ! empty( $body['success'] );
}

// Enqueue Turnstile script on pages that have a protected form.
add_action( 'wp_enqueue_scripts', function () {
    $needs_turnstile = ( is_page( [ 'support', 'contact' ] ) && is_user_logged_in() )
        || is_page( [ 'feature-request', 'feature-requests', 'contact' ] )
        || ( function_exists( 'is_account_page' ) && is_account_page() );

    if ( $needs_turnstile && defined( 'OZP_TURNSTILE_SITE_KEY' ) ) {
        wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, false );
        add_filter( 'script_loader_tag', function ( string $tag, string $handle ): string {
            return $handle === 'cf-turnstile' ? str_replace( ' src=', ' async defer src=', $tag ) : $tag;
        }, 10, 2 );
    }
}, 20 );

// Add Turnstile widget inside the WooCommerce My Account login form.
add_action( 'woocommerce_login_form', function () {
    if ( ! defined( 'OZP_TURNSTILE_SITE_KEY' ) ) {
        return;
    }
    echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( OZP_TURNSTILE_SITE_KEY ) . '" data-appearance="interaction-only" style="margin:12px 0"></div>' . "\n";
} );

// Verify Turnstile token when WooCommerce login form is submitted.
add_filter( 'authenticate', function ( $user, string $username, string $password ) {
    if ( empty( $_POST['woocommerce-login-nonce'] ) ) {
        return $user;
    }
    $token = sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ?? '' ) );
    if ( ! ozp_verify_turnstile( $token ) ) {
        return new \WP_Error( 'turnstile_failed', __( 'Security check failed. Please try again.', 'ozupay-theme' ) );
    }
    return $user;
}, 30, 3 );

// Register nav menus.
add_action( 'after_setup_theme', function () {
    register_nav_menus( [
        'primary' => __( 'Primary Navigation', 'ozupay-theme' ),
        'footer'  => __( 'Footer Navigation', 'ozupay-theme' ),
    ] );
} );

// ── SEO & Accessibility ──────────────────────────────────────────────────────

// "Discourage search engines" (Settings > Reading) is unchecked — blog_public=1 —
// so WordPress's own wp_robots_no_robots() no longer adds noindex/nofollow and
// WooCommerce's own cart/checkout/my-account noindex filter is free to run without
// this theme fighting it. No filter needed here; do not re-add a blanket
// unset-noindex/force-follow hack, it previously raced WooCommerce's wp_robots
// filter and produced a contradictory `content="follow, noindex, nofollow"` tag
// on the cart and my-account pages.

// Hide WordPress and WooCommerce version from <meta name="generator"> — version
// exposure is an unnecessary attack-surface signal.
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// Favicon and Apple touch icon.
add_action( 'wp_head', function () {
    $uri = get_stylesheet_directory_uri();
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url( $uri . '/favicon.png' ) . '">' . "\n";
    echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( $uri . '/apple-touch-icon.png' ) . '">' . "\n";
}, 0 );

// Preconnect to Google Fonts before the stylesheet loads.
add_action( 'wp_head', function () {
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}, 0 );

// Skip-to-main-content link for keyboard and screen reader users.
add_action( 'wp_body_open', function () {
    echo '<a class="ozp-skip-link" href="#ozp-main">' . esc_html__( 'Skip to main content', 'ozupay-theme' ) . '</a>' . "\n";
} );

// Per-page document titles — keyword-rich, consistently cased.
add_filter( 'document_title_parts', function ( array $parts ): array {
    if ( is_front_page() ) {
        $parts['title']   = 'OzuPay — M-Pesa Payments Plugin for WordPress & WooCommerce';
        $parts['tagline'] = '';
        $parts['site']    = '';
    } elseif ( function_exists( 'is_shop' ) && is_shop() ) {
        $parts['title'] = 'Buy OzuPay M-Pesa Pro — Plans & Pricing';
        $parts['site']  = 'OzuPay';
    } elseif ( is_page( 'features' ) ) {
        $parts['title'] = 'OzuPay M-Pesa Plugin Features — Free vs Pro';
        $parts['site']  = 'OzuPay';
    } elseif ( is_page( [ 'docs', 'documentation' ] ) ) {
        $parts['title'] = 'M-Pesa Payments Plugin Setup & Configuration Guide';
        $parts['site']  = 'OzuPay';
    } elseif ( is_page( 'contact' ) ) {
        $parts['title'] = 'Contact OzuPay — M-Pesa Plugin Support & Enquiries';
        $parts['tagline'] = '';
        $parts['site']    = '';
    } elseif ( is_page( 'support' ) ) {
        $parts['title'] = 'Support';
        $parts['site']  = 'OzuPay';
    } elseif ( is_page( [ 'feature-request', 'feature-requests' ] ) ) {
        $parts['title'] = 'Feature Requests';
        $parts['site']  = 'OzuPay';
    } elseif ( is_page( 'about' ) ) {
        $parts['title'] = 'About OzuPay';
        $parts['site']  = 'OzuPay';
    } elseif ( is_page( [ 'privacy-policy', 'privacy' ] ) ) {
        $parts['title']   = 'OzuPay Privacy Policy — M-Pesa Payments Plugin';
        $parts['tagline'] = '';
        $parts['site']    = '';
    } elseif ( is_page( [ 'terms-of-use', 'terms' ] ) ) {
        $parts['title']   = 'OzuPay Terms of Use — M-Pesa Payments Plugin';
        $parts['tagline'] = '';
        $parts['site']    = '';
    } elseif ( is_page( [ 'refund-policy', 'refund' ] ) ) {
        $parts['title']   = 'OzuPay Refund Policy — 30-Day Money-Back Guarantee';
        $parts['tagline'] = '';
        $parts['site']    = '';
    } elseif ( function_exists( 'is_product' ) && is_product() ) {
        $slug = get_queried_object() ? get_queried_object()->post_name : '';
        if ( str_contains( $slug, 'one-site' ) ) {
            $parts['title'] = 'OzuPay M-Pesa Pro — KES 5,000/yr per site, every feature included';
            $parts['site']  = 'OzuPay';
        } else {
            // Legacy multi-site plan products — retired from sale.
            $parts['title'] = 'OzuPay M-Pesa Pro';
            $parts['site']  = 'OzuPay';
        }
    }
    return $parts;
} );

// WordPress core's own rel_canonical() also fires on wp_head and would print a
// second <link rel="canonical"> alongside the one the callback below outputs
// for every page type it covers. Unhook it here; the else branch below covers
// every page type core would otherwise have handled (archives, search, cart,
// my-account, etc.) with core's own wp_get_canonical_url(), so no page loses
// its canonical.
remove_action( 'wp_head', 'rel_canonical' );

// Meta description, canonical, Open Graph, Twitter Card, JSON-LD.
add_action( 'wp_head', function () {
    $og_image = esc_url( get_stylesheet_directory_uri() . '/og-image.jpg' );
    $site_url = esc_url( home_url( '/' ) );
    $json_ld  = [];

    // ── Per-page SEO data ──────────────────────────────────────────────────
    if ( is_front_page() ) {
        $title = 'OzuPay — M-Pesa Payments Plugin for WordPress & WooCommerce';
        $desc  = 'The M-Pesa payments plugin for WooCommerce. STK Push, C2B Paybill, M-Pesa on Delivery and B2C refunds — built for Kenyan merchants. Free and Pro plans.';
        $url   = $site_url;
        $type  = 'website';

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    "name": "OzuPay M-Pesa Payments Plugin",
    "applicationCategory": "BusinessApplication",
    "operatingSystem": "WordPress",
    "description": "OzuPay M-Pesa Payments Plugin is the complete M-Pesa payment plugin for WooCommerce. Accept STK Push, C2B Paybill, M-Pesa on Delivery and send B2C refunds from your WooCommerce store.",
    "url": "' . $site_url . '",
    "downloadUrl": "https://wordpress.org/plugins/ozupay/",
    "keywords": "mpesa, woocommerce, wordpress plugin, stk push, daraja, c2b paybill, kenya, m-pesa payment gateway",
    "offers": [
        { "@type": "Offer", "name": "OzuPay Free", "price": "0",    "priceCurrency": "KES", "availability": "https://schema.org/InStock", "url": "https://wordpress.org/plugins/ozupay/" },
        { "@type": "Offer", "name": "OzuPay Pro",  "price": "5000", "priceCurrency": "KES", "availability": "https://schema.org/InStock", "url": "' . esc_url( home_url( '/shop/' ) ) . '" }
    ],
    "publisher": { "@type": "Organization", "name": "OzuPay", "url": "' . $site_url . '" }
}';

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": "OzuPay",
    "url": "' . $site_url . '",
    "description": "OzuPay builds the M-Pesa Payments Plugin for WordPress and WooCommerce merchants in Kenya.",
    "contactPoint": { "@type": "ContactPoint", "contactType": "customer support", "url": "' . esc_url( home_url( '/contact/' ) ) . '" }
}';

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
        { "@type": "Question", "name": "What is the difference between the free and Pro OzuPay M-Pesa plugin?", "acceptedAnswer": { "@type": "Answer", "text": "The free plugin covers STK Push, Paybill, and Till (Buy Goods) payments and is available on WordPress.org. Pro adds M-Pesa on Delivery (COD deposit collection), B2C refunds from the WooCommerce order screen, an analytics dashboard, webhook events, payment links, QR codes, and a POS REST API." } },
        { "@type": "Question", "name": "Is OzuPay compatible with my WordPress theme?", "acceptedAnswer": { "@type": "Answer", "text": "OzuPay M-Pesa Payments Plugin adds a payment method to WooCommerce checkout and is compatible with any WordPress theme that supports WooCommerce, including block themes and page builders like Elementor and Divi." } },
        { "@type": "Question", "name": "Can I upgrade from the free OzuPay WooCommerce M-Pesa plugin to Pro later?", "acceptedAnswer": { "@type": "Answer", "text": "Yes. Install the Pro plugin and activate your license key. All existing settings — Paybill number, passkey, credentials — are retained automatically. No reconfiguration needed." } },
        { "@type": "Question", "name": "What happens when my OzuPay M-Pesa Payments Pro license expires?", "acceptedAnswer": { "@type": "Answer", "text": "Your site continues to process M-Pesa payments normally. An expired license only disables automatic updates and Pro features. Core STK Push and C2B Paybill payment processing is unaffected." } },
        { "@type": "Question", "name": "Does OzuPay offer a refund policy?", "acceptedAnswer": { "@type": "Answer", "text": "Yes. OzuPay M-Pesa Payments Plugin offers a 30-day money-back guarantee on all Pro plans. If the plugin does not work for your store within 30 days of purchase, contact support for a full refund." } },
        { "@type": "Question", "name": "Is OzuPay compatible with WooCommerce HPOS?", "acceptedAnswer": { "@type": "Answer", "text": "Yes. OzuPay M-Pesa Payments Plugin declares full compatibility with WooCommerce High-Performance Order Storage (HPOS) and is tested against WooCommerce 8+." } },
        { "@type": "Question", "name": "How do I get support for the OzuPay M-Pesa plugin?", "acceptedAnswer": { "@type": "Answer", "text": "Free users can use the WordPress.org support forums. Anyone with a Pro licence can open a support ticket — submit it from the Contact page with your site URL and issue description." } }
    ]
}';

    } elseif ( function_exists( 'is_shop' ) && is_shop() ) {
        $title = 'Buy OzuPay M-Pesa Pro — KES 5,000/yr Per Site | OzuPay';
        $desc  = 'OzuPay M-Pesa Pro: KES 5,000 per site, per year — every Pro feature included, no feature tiers. Volume pricing for agencies and multi-store merchants. 30-day money-back guarantee.';
        $url   = esc_url( home_url( '/shop/' ) );
        $type  = 'website';

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "Product",
    "name": "OzuPay M-Pesa Payments Pro",
    "description": "Every OzuPay Pro feature — STK Push, M-Pesa on Delivery, B2C refunds, analytics, webhooks, payment links, QR codes, and the POS REST API — in one licence per WooCommerce site.",
    "url": "' . esc_url( home_url( '/shop/' ) ) . '",
    "brand": { "@type": "Brand", "name": "OzuPay" },
    "offers": {
        "@type": "Offer",
        "price": "5000",
        "priceCurrency": "KES",
        "availability": "https://schema.org/InStock",
        "url": "' . esc_url( home_url( '/product/ozupay-m-pesa-payments-pro-one-site/' ) ) . '",
        "seller": { "@type": "Organization", "name": "OzuPay", "url": "' . $site_url . '" }
    }
}';

    } elseif ( is_page( 'features' ) ) {
        $title = 'OzuPay M-Pesa Plugin Features — Free vs Pro | OzuPay';
        $desc  = 'Full OzuPay feature list. Compare Free vs Pro: STK Push, C2B Paybill, M-Pesa on Delivery, B2C refunds, analytics, webhooks, and POS REST API.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "WebPage",
    "name": "OzuPay M-Pesa Plugin Features — Free vs Pro",
    "description": "Full feature comparison of OzuPay Free and Pro editions for WooCommerce M-Pesa payments.",
    "url": "' . esc_url( get_permalink() ) . '",
    "isPartOf": { "@type": "WebSite", "name": "OzuPay", "url": "' . $site_url . '" }
}';

    } elseif ( is_page( [ 'docs', 'documentation' ] ) ) {
        $title = 'M-Pesa Payments Plugin Setup & Configuration Guide | OzuPay';
        $desc  = 'OzuPay plugin docs: installation, Daraja API setup, STK Push, C2B callbacks, webhook configuration, and troubleshooting for M-Pesa WooCommerce payments.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "TechArticle",
    "name": "OzuPay M-Pesa Payments Plugin — Setup & Configuration Guide",
    "description": "Complete guide to installing, configuring, and operating OzuPay M-Pesa Payments (Free and Pro) on your WooCommerce store.",
    "url": "' . esc_url( get_permalink() ) . '",
    "about": { "@type": "SoftwareApplication", "name": "OzuPay M-Pesa Payments Plugin", "url": "' . $site_url . '" },
    "publisher": { "@type": "Organization", "name": "OzuPay", "url": "' . $site_url . '" }
}';

    } elseif ( is_page( 'contact' ) ) {
        $title = 'Contact OzuPay — M-Pesa Plugin Support & Enquiries';
        $desc  = 'Contact OzuPay. Submit a support ticket for the M-Pesa WooCommerce plugin, suggest a feature, or ask a pre-sales question. Pro customers get priority response.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "ContactPage",
    "name": "Contact OzuPay",
    "description": "Submit a support ticket or enquiry for OzuPay M-Pesa Payments Plugin.",
    "url": "' . esc_url( get_permalink() ) . '",
    "isPartOf": { "@type": "WebSite", "name": "OzuPay", "url": "' . $site_url . '" }
}';

    } elseif ( is_page( 'support' ) ) {
        $title = 'OzuPay Support — M-Pesa Plugin Help & Ticket';
        $desc  = 'Get help with OzuPay M-Pesa plugin. Anyone with a Pro licence can submit a support ticket with direct access to the OzuPay team.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

    } elseif ( is_page( [ 'feature-request', 'feature-requests' ] ) ) {
        $title = 'Feature Requests | OzuPay';
        $desc  = 'Request a new feature for OzuPay M-Pesa WooCommerce plugin. Share your ideas to help shape the product roadmap.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

    } elseif ( is_page( 'about' ) ) {
        $title = 'About OzuPay — M-Pesa Payments for WooCommerce';
        $desc  = 'OzuPay builds the M-Pesa Payments Plugin for WordPress and WooCommerce merchants in Kenya.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

    } elseif ( is_page( [ 'privacy-policy', 'privacy' ] ) ) {
        $title = 'Privacy Policy | OzuPay';
        $desc  = 'Privacy policy for OzuPay M-Pesa Payments Plugin and ozupay.com. How we collect, use, and protect your personal data.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

        $json_ld[] = '{ "@context": "https://schema.org", "@type": "WebPage", "name": "Privacy Policy", "url": "' . esc_url( get_permalink() ) . '", "isPartOf": { "@type": "WebSite", "name": "OzuPay", "url": "' . $site_url . '" } }';

    } elseif ( is_page( [ 'terms-of-use', 'terms' ] ) ) {
        $title = 'Terms of Use | OzuPay';
        $desc  = 'Terms of use for OzuPay M-Pesa Payments Plugin and ozupay.com. Your agreement with OzuPay for using our plugin and services.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

        $json_ld[] = '{ "@context": "https://schema.org", "@type": "WebPage", "name": "Terms of Use", "url": "' . esc_url( get_permalink() ) . '", "isPartOf": { "@type": "WebSite", "name": "OzuPay", "url": "' . $site_url . '" } }';

    } elseif ( is_page( [ 'refund-policy', 'refund' ] ) ) {
        $title = 'Refund Policy — 30-Day Money-Back Guarantee | OzuPay';
        $desc  = 'OzuPay offers a 30-day money-back guarantee on all Pro plans. Full refund if the plugin does not work for your WooCommerce store within 30 days of purchase.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

        $json_ld[] = '{ "@context": "https://schema.org", "@type": "WebPage", "name": "Refund Policy", "url": "' . esc_url( get_permalink() ) . '", "isPartOf": { "@type": "WebSite", "name": "OzuPay", "url": "' . $site_url . '" } }';

    } elseif ( function_exists( 'is_product' ) && is_product() ) {
        $product_obj = wc_get_product();
        $slug        = get_queried_object() ? get_queried_object()->post_name : '';
        $url         = esc_url( get_permalink() );
        $type        = 'website';

        $title       = 'OzuPay M-Pesa Pro — KES 5,000/yr Per Site | OzuPay';
        $desc        = 'OzuPay M-Pesa Payments Pro — one licence, every Pro feature. KES 5,000 per site, per year. 30-day money-back guarantee.';
        $plan_name   = 'OzuPay M-Pesa Payments Pro';
        $plan_price  = '5000';
        $plan_desc   = 'Every OzuPay Pro feature (STK Push, M-Pesa on Delivery, B2C refunds, analytics, webhooks, payment links, QR codes, POS REST API) for one WooCommerce site.';

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "Product",
    "name": "' . esc_js( $plan_name ) . '",
    "description": "' . esc_js( $plan_desc ) . '",
    "url": "' . $url . '",
    "brand": { "@type": "Brand", "name": "OzuPay" },
    "offers": {
        "@type": "Offer",
        "price": "' . $plan_price . '",
        "priceCurrency": "KES",
        "availability": "https://schema.org/InStock",
        "url": "' . $url . '",
        "seller": { "@type": "Organization", "name": "OzuPay", "url": "' . $site_url . '" }
    }
}';

    } elseif ( is_page( 'blog' ) ) {
        $title = 'Blog — M-Pesa & WooCommerce Guides | OzuPay';
        $desc  = 'Guides, troubleshooting, and comparisons for M-Pesa payments on WooCommerce: setup, Daraja API, security, and cost.';
        $url   = esc_url( get_permalink() );
        $type  = 'website';

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    "name": "OzuPay Blog",
    "description": "' . esc_js( $desc ) . '",
    "url": "' . $url . '",
    "isPartOf": { "@type": "WebSite", "name": "OzuPay", "url": "' . $site_url . '" }
}';

    } elseif ( is_singular( 'post' ) ) {
        $queried_id  = get_queried_object_id();
        $excerpt     = get_the_excerpt( $queried_id );
        $title       = get_the_title( $queried_id ) . ' | OzuPay Blog';
        $desc        = $excerpt ? $excerpt : get_the_title( $queried_id );
        $url         = esc_url( get_permalink( $queried_id ) );
        $type        = 'article';
        $thumb       = get_the_post_thumbnail_url( $queried_id, 'large' );
        if ( $thumb ) {
            $og_image = esc_url( $thumb );
        }

        $json_ld[] = '{
    "@context": "https://schema.org",
    "@type": "BlogPosting",
    "headline": "' . esc_js( get_the_title( $queried_id ) ) . '",
    "description": "' . esc_js( $desc ) . '",
    "url": "' . $url . '",
    "datePublished": "' . esc_js( get_the_date( 'c', $queried_id ) ) . '",
    "dateModified": "' . esc_js( get_the_modified_date( 'c', $queried_id ) ) . '",
    "author": { "@type": "Organization", "name": "OzuPay" },
    "publisher": { "@type": "Organization", "name": "OzuPay", "url": "' . $site_url . '" },
    "mainEntityOfPage": { "@type": "WebPage", "@id": "' . $url . '" }
}';

    } else {
        $canonical = function_exists( 'wp_get_canonical_url' ) ? wp_get_canonical_url() : '';
        if ( $canonical ) {
            echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
        }
        return;
    }

    $title_attr = esc_attr( $title );
    $desc_attr  = esc_attr( $desc );
    ?>
    <meta name="description" content="<?php echo $desc_attr; ?>">
    <link rel="canonical" href="<?php echo $url; ?>">

    <meta property="og:locale"       content="en_KE">
    <meta property="og:type"         content="<?php echo esc_attr( $type ?? 'website' ); ?>">
    <meta property="og:site_name"    content="OzuPay">
    <meta property="og:title"        content="<?php echo $title_attr; ?>">
    <meta property="og:description"  content="<?php echo $desc_attr; ?>">
    <meta property="og:url"          content="<?php echo $url; ?>">
    <meta property="og:image"        content="<?php echo $og_image; ?>">
    <meta property="og:image:width"  content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt"    content="OzuPay M-Pesa Payments Plugin for WooCommerce">

    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:site"        content="@OzuPay">
    <meta name="twitter:title"       content="<?php echo $title_attr; ?>">
    <meta name="twitter:description" content="<?php echo $desc_attr; ?>">
    <meta name="twitter:image"       content="<?php echo $og_image; ?>">
    <?php

    foreach ( $json_ld as $block ) {
        echo '<script type="application/ld+json">' . "\n" . $block . "\n" . '</script>' . "\n";
    }
}, 1 );

// ── Sitemap ──────────────────────────────────────────────────────────────────

add_action( 'init', function () {
    add_rewrite_rule( '^sitemap\.xml$', 'index.php?ozp_sitemap=1', 'top' );
} );

add_filter( 'query_vars', function ( array $vars ): array {
    $vars[] = 'ozp_sitemap';
    return $vars;
} );

// Without this, WordPress's canonical redirect 301s /sitemap.xml to
// /sitemap.xml/ before the rewrite above ever serves it — crawlers and
// Search Console sitemap submission should hit a direct 200, not a redirect.
add_filter( 'redirect_canonical', function ( $redirect_url ) {
    return get_query_var( 'ozp_sitemap' ) ? false : $redirect_url;
} );

add_action( 'template_redirect', function () {
    if ( ! get_query_var( 'ozp_sitemap' ) ) {
        return;
    }

    $now  = gmdate( 'c' );
    $urls = [
        [
            'loc'        => home_url( '/' ),
            'priority'   => '1.0',
            'changefreq' => 'weekly',
            'lastmod'    => $now,
            'image'      => [
                'loc'     => get_stylesheet_directory_uri() . '/og-image.jpg',
                'title'   => 'OzuPay — M-Pesa Payments for WooCommerce',
                'caption' => 'OzuPay integrates Safaricom Daraja 2.0 STK Push payments directly into WooCommerce checkout for Kenyan merchants.',
            ],
        ],
        [ 'loc' => home_url( '/features/' ),         'priority' => '0.9', 'changefreq' => 'weekly',  'lastmod' => $now ],
        [ 'loc' => home_url( '/shop/' ),             'priority' => '0.9', 'changefreq' => 'weekly',  'lastmod' => $now ],
        [ 'loc' => home_url( '/docs/' ),             'priority' => '0.8', 'changefreq' => 'weekly',  'lastmod' => $now ],
        [ 'loc' => home_url( '/blog/' ),             'priority' => '0.7', 'changefreq' => 'weekly',  'lastmod' => $now ],
        [ 'loc' => home_url( '/contact/' ),          'priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => $now ],
        [ 'loc' => home_url( '/privacy-policy/' ),   'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $now ],
        [ 'loc' => home_url( '/terms-of-use/' ),     'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $now ],
        [ 'loc' => home_url( '/refund-policy/' ),    'priority' => '0.4', 'changefreq' => 'yearly',  'lastmod' => $now ],
    ];

    if ( function_exists( 'wc_get_products' ) ) {
        foreach ( wc_get_products( [ 'status' => 'publish', 'limit' => -1 ] ) as $product ) {
            $mod    = $product->get_date_modified();
            $urls[] = [
                'loc'        => get_permalink( $product->get_id() ),
                'priority'   => '0.8',
                'changefreq' => 'monthly',
                'lastmod'    => $mod ? $mod->format( 'c' ) : $now,
            ];
        }
    }

    // Blog posts — picked up automatically as they're published, no manual sitemap edits needed.
    foreach ( get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1 ] ) as $post ) {
        $urls[] = [
            'loc'        => get_permalink( $post ),
            'priority'   => '0.6',
            'changefreq' => 'monthly',
            'lastmod'    => get_the_modified_date( 'c', $post ),
        ];
    }

    header( 'Content-Type: application/xml; charset=utf-8', true );
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    foreach ( $urls as $entry ) {
        echo '  <url><loc>' . esc_url( $entry['loc'] ) . '</loc>';
        echo '<lastmod>' . esc_html( $entry['lastmod'] ) . '</lastmod>';
        echo '<changefreq>' . esc_html( $entry['changefreq'] ) . '</changefreq>';
        echo '<priority>' . esc_html( $entry['priority'] ) . '</priority>';
        if ( ! empty( $entry['image'] ) ) {
            echo '<image:image><image:loc>' . esc_url( $entry['image']['loc'] ) . '</image:loc>';
            echo '<image:title>' . esc_html( $entry['image']['title'] ) . '</image:title>';
            echo '<image:caption>' . esc_html( $entry['image']['caption'] ) . '</image:caption></image:image>';
        }
        echo '</url>' . "\n";
    }
    echo '</urlset>';
    exit;
} );

// Advertise the sitemap in robots.txt.
add_filter( 'robots_txt', function ( string $output ): string {
    return $output . "\nSitemap: " . home_url( '/sitemap.xml' ) . "\n";
} );

// ── Free download landing page ──────────────────────────────────────────────
// The free-plugin download used to link straight to the R2-redirecting REST
// endpoint, which left the browser tab blank while the zip streamed. Route it
// through this on-site page instead: the file download kicks off invisibly
// (hidden iframe) while the tab shows a Pro upsell.

define( 'OZP_FREE_DOWNLOAD_URL', 'https://ozupay.com/wp-json/ozls/v1/download/free/ozupay_mpesa_payment_free' );

add_action( 'init', function () {
    add_rewrite_rule( '^get-free/?$', 'index.php?ozp_get_free=1', 'top' );
} );

add_filter( 'query_vars', function ( array $vars ): array {
    $vars[] = 'ozp_get_free';
    return $vars;
} );

// Same reasoning as the sitemap.xml rule above: without this, WordPress's
// canonical redirect 301s /get-free to /get-free/ before the rewrite rule
// ever gets a chance to serve it.
add_filter( 'redirect_canonical', function ( $redirect_url ) {
    return get_query_var( 'ozp_get_free' ) ? false : $redirect_url;
} );

add_action( 'template_redirect', function () {
    if ( ! get_query_var( 'ozp_get_free' ) ) {
        return;
    }

    header( 'Content-Type: text/html; charset=utf-8', true );
    ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, follow">
<title>Your download is starting&hellip; &mdash; OzuPay</title>
<link rel="stylesheet" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/style.css' ); ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@500;600&display=swap">
<link rel="icon" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/favicon.png' ); ?>">
<style>
/* style.css doesn't define these — front-page.html carries its own local
   :root/.ob button styles instead, so this page needs its own copy too. */
:root{
  --og:#00A651;--navy:#0F172A;
  --slate-600:#475569;--slate-500:#64748B;--slate-400:#94A3B8;
  --slate-200:#E4DECF;--slate-50:#F7F5EF;
}
.ozp-mono{font-family:'JetBrains Mono','Fira Code',monospace;}
.ob{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:13px 26px;border-radius:11px;font-size:15px;font-weight:700;cursor:pointer;transition:all .15s;border:none;font-family:'Inter',system-ui,sans-serif;line-height:1;text-decoration:none!important;}
.ob-g{background:linear-gradient(145deg,#00C45F,#00924A);color:#fff!important;box-shadow:0 8px 22px -6px rgba(0,166,81,.45);border:1.5px solid rgba(0,0,0,.18);}
.ob-g:hover{background:linear-gradient(145deg,#00D46A,#00834A);box-shadow:0 10px 28px -6px rgba(0,166,81,.55);}
</style>
</head>
<body style="margin:0;background:var(--slate-50);font-family:'Inter',system-ui,sans-serif;">

<header class="ozp-header">
    <div class="ozp-header-inner">
        <a href="/" class="ozp-logo">
            <img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/favicon.png' ); ?>" width="32" height="32" alt="" class="ozp-logo-icon" aria-hidden="true">
            OzuLabs
        </a>
        <nav class="ozp-header-nav" id="ozp-nav">
            <ul class="ozp-nav">
                <li><a href="/#features">Features</a></li>
                <li><a href="/#pricing">Pricing</a></li>
                <li><a href="/shop/">Shop</a></li>
                <li><a href="/docs/">Docs</a></li>
                <li><a href="/blog/">Blog</a></li>
                <li><a href="https://demo.ozupay.com/shop/" target="_blank" rel="noopener">Live Demo</a></li>
                <li><a href="/contact/">Contact Us</a></li>
            </ul>
        </nav>
        <div class="ozp-header-actions">
            <a href="/cart/" class="ozp-cart-btn" id="ozp-cart-btn" aria-label="View cart">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39A2 2 0 009.66 16h9.72a2 2 0 001.97-1.67L23 6H6"/></svg>
                <span class="ozp-cart-count" id="ozp-cart-count" aria-label="items in cart"></span>
            </a>
            <a href="/my-account/" class="ozp-btn ozp-btn-outline ozp-btn-sm" id="ozp-login-btn">Log in</a>
            <a href="/#pricing" class="ozp-btn ozp-btn-primary ozp-btn-sm">Buy Now &rarr;</a>
        </div>
        <button class="ozp-hamburger" id="ozp-hamburger" aria-label="Open menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>

<section style="padding:56px 20px 80px;">
  <div style="max-width:640px;margin:0 auto;text-align:center;">
    <div class="ozp-mono" style="font-size:11px;font-weight:600;letter-spacing:.08em;color:var(--og);text-transform:uppercase;margin-bottom:14px;">OzuPay Free</div>
    <h1 id="ozp-dl-title" style="margin:0 0 12px;font-size:clamp(24px,3vw,34px);line-height:1.15;letter-spacing:-.02em;font-weight:700;color:var(--navy);">Preparing your download&hellip;</h1>
    <p id="ozp-dl-sub" style="margin:0 0 8px;font-size:15px;color:var(--slate-600);">Your download will start automatically in a moment.</p>
    <p style="margin:0;font-size:13.5px;color:var(--slate-400);">Didn&rsquo;t start? <a href="<?php echo esc_url( OZP_FREE_DOWNLOAD_URL ); ?>" id="ozp-dl-fallback" style="color:var(--og);">Download manually</a>.</p>
  </div>

  <div style="max-width:420px;margin:44px auto 0;background:var(--navy);border-radius:22px;padding:34px 30px;box-shadow:0 30px 60px -22px rgba(15,23,42,.5),0 0 50px -8px rgba(0,166,81,.18);">
    <div class="ozp-mono" style="font-size:13px;font-weight:600;letter-spacing:.06em;color:#4ADE80;text-transform:uppercase;margin-bottom:16px;">While that downloads&mdash;checkout Pro features</div>
    <h2 style="margin:0 0 10px;font-size:22px;font-weight:700;color:#fff;letter-spacing:-.02em;">Refunds, M-Pesa on Delivery, analytics &amp; more</h2>
    <p style="margin:0 0 22px;font-size:14px;line-height:1.6;color:#E4DECF;">One licence, every Pro feature included &mdash; KES 5,000/yr per site.</p>
    <ul style="list-style:none;margin:0 0 24px;padding:0;display:flex;flex-direction:column;gap:11px;text-align:left;">
      <li style="display:flex;gap:9px;font-size:13.5px;color:#E4DECF;"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4ADE80" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex:none;margin-top:1px;" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>M-Pesa on Delivery &mdash; stop losing money on fake COD</li>
      <li style="display:flex;gap:9px;font-size:13.5px;color:#E4DECF;"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4ADE80" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex:none;margin-top:1px;" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>B2C refunds straight from the order screen</li>
      <li style="display:flex;gap:9px;font-size:13.5px;color:#E4DECF;"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4ADE80" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex:none;margin-top:1px;" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>Paybill &amp; Till fallback &mdash; no missed sales</li>
      <li style="display:flex;gap:9px;font-size:13.5px;color:#E4DECF;"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4ADE80" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex:none;margin-top:1px;" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>Analytics dashboard &amp; live KPIs</li>
      <li style="display:flex;gap:9px;font-size:13.5px;color:#E4DECF;"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4ADE80" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex:none;margin-top:1px;" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>Webhooks, QR codes &amp; payment links</li>
      <li style="display:flex;gap:9px;font-size:13.5px;color:#E4DECF;"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4ADE80" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex:none;margin-top:1px;" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>POS REST API for cashier apps</li>
    </ul>
    <a href="/#pricing" class="ob ob-g" style="width:100%;font-size:15px;padding:14px;border-radius:12px;justify-content:center;">
      See Pro pricing
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
    </a>
  </div>
</section>

<script>
(function () {
  var downloadUrl = <?php echo wp_json_encode( OZP_FREE_DOWNLOAD_URL ); ?>;
  var title = document.getElementById( 'ozp-dl-title' );
  var sub   = document.getElementById( 'ozp-dl-sub' );

  // redirect:'manual' keeps fetch from following the 302 to R2 (which would
  // fail cross-origin anyway) while still letting us read a same-origin,
  // non-redirect response — i.e. a 429 from the rate limiter — for real,
  // instead of blindly declaring success the way a bare iframe would.
  fetch( downloadUrl, { redirect: 'manual', cache: 'no-store' } )
    .then( function ( response ) {
      if ( response.type === 'opaqueredirect' ) {
        var iframe = document.createElement( 'iframe' );
        iframe.style.display = 'none';
        iframe.src = downloadUrl;
        document.body.appendChild( iframe );

        title.textContent = 'Your download has started';
        sub.textContent = 'Check your browser’s downloads.';
        return;
      }

      if ( response.status === 429 ) {
        title.textContent = 'Too many downloads from your network';
        sub.textContent = 'Please try again in about an hour.';
        var fallback = document.getElementById( 'ozp-dl-fallback' );
        if ( fallback ) {
          fallback.parentElement.style.display = 'none';
        }
        return;
      }

      // A real (non-429) error response from the same endpoint the manual
      // link points at — pointing the user back at it would just fail the
      // same way, so don't offer it.
      title.textContent = 'Download unavailable right now';
      sub.textContent = 'Please contact support.';
      var fallbackErr = document.getElementById( 'ozp-dl-fallback' );
      if ( fallbackErr ) {
        fallbackErr.parentElement.style.display = 'none';
      }
    } )
    .catch( function () {
      // fetch() itself failed (offline, blocked by an extension, etc.) rather
      // than the server responding — the manual link is a reasonable retry here.
      title.textContent = 'Download unavailable right now';
      sub.textContent = 'Please use the manual link below.';
    } );
})();
</script>

<script>
(function () {
    // Mobile nav toggle — mirrors the site-wide handler in wp_footer, which
    // this standalone page doesn't go through.
    var btn = document.getElementById( 'ozp-hamburger' );
    var nav = document.getElementById( 'ozp-nav' );
    if ( btn && nav ) {
        btn.addEventListener( 'click', function () {
            var open = nav.classList.toggle( 'open' );
            btn.classList.toggle( 'open', open );
            btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
            btn.setAttribute( 'aria-label', open ? 'Close menu' : 'Open menu' );
        } );
    }
})();
</script>

</body>
</html>
    <?php
    exit;
} );

// Breadcrumb for the article hero: Home > Category > Post title.
add_shortcode( 'ozp_breadcrumb', function (): string {
    $items = [ '<a href="' . esc_url( home_url( '/' ) ) . '">Home</a>' ];

    $categories = get_the_category();
    if ( ! empty( $categories ) ) {
        $items[] = '<a href="' . esc_url( get_category_link( $categories[0] ) ) . '">' . esc_html( $categories[0]->name ) . '</a>';
    }

    $items[] = '<span aria-current="page">' . esc_html( get_the_title() ) . '</span>';

    return '<nav class="ozp-article-breadcrumb" aria-label="Breadcrumb">'
        . implode( ' <span aria-hidden="true">/</span> ', $items )
        . '</nav>';
} );

// ── Article block patterns (quick summary / TOC / upsell) ───────────────────
// Reusable patterns writers insert into blog posts. Rules these support live
// in CONTENT.md — read that before changing the pattern content or markup.

add_action( 'init', function () {
    register_block_pattern_category( 'ozupay-articles', [
        'label' => __( 'OzuPay Articles', 'ozupay' ),
    ] );

    register_block_pattern( 'ozupay-theme/quick-summary', [
        'title'       => __( 'Article: Quick Summary (collapsed)', 'ozupay' ),
        'description' => __( 'Collapsed-by-default TL;DR box. Place directly under the title, before the table of contents.', 'ozupay' ),
        'categories'  => [ 'ozupay-articles' ],
        'content'     => <<<'HTML'
<!-- wp:html -->
<details class="ozp-article-summary">
  <summary>Quick summary</summary>
  <ul>
    <li>Replace this with the first takeaway.</li>
    <li>Replace this with the second takeaway.</li>
    <li>Replace this with the third takeaway.</li>
  </ul>
</details>
<!-- /wp:html -->
HTML
    ] );

    // No separate "table of contents" pattern — the sidebar TOC now lives directly
    // in templates/single.html so every post gets one automatically. See the
    // wp_footer script below for the JS that builds it from H2/H3 headings.

    register_block_pattern( 'ozupay-theme/upsell-trigger', [
        'title'       => __( 'Article: OzuPay Upsell (button + modal)', 'ozupay' ),
        'description' => __( 'Subtle CTA button that opens a modal. Edit the heading/body/link to match the section it sits under — do not reuse the same generic pitch across articles. Use at most 1-2 per article; if you add a second one in the same post, change every "ozp-upsell-modal-1" to "ozp-upsell-modal-2" so the IDs stay unique.', 'ozupay' ),
        'categories'  => [ 'ozupay-articles' ],
        'content'     => <<<'HTML'
<!-- wp:html -->
<div class="ozp-upsell">
  <button type="button" class="ozp-upsell-trigger" aria-haspopup="dialog" aria-controls="ozp-upsell-modal-1">
    See how the OzuPay M-Pesa plugin handles this automatically &rarr;
  </button>
  <div id="ozp-upsell-modal-1" class="ozp-upsell-modal" role="dialog" aria-modal="true" aria-labelledby="ozp-upsell-modal-1-title" hidden>
    <div class="ozp-upsell-modal-backdrop" data-ozp-modal-close></div>
    <div class="ozp-upsell-modal-panel">
      <button type="button" class="ozp-upsell-modal-close" data-ozp-modal-close aria-label="Close">&times;</button>
      <h3 id="ozp-upsell-modal-1-title">Replace with a specific headline</h3>
      <p>Replace with 2-3 sentences tying this feature directly to what the reader just read.</p>
      <a href="/shop/" class="ozp-upsell-modal-cta">See plans &rarr;</a>
    </div>
  </div>
</div>
<!-- /wp:html -->
HTML
    ] );
} );

// TOC builder + read time + upsell modal behaviour — only needed on single blog posts.
add_action( 'wp_footer', function (): void {
    if ( ! is_singular( 'post' ) ) {
        return;
    }

    // Computed server-side from the real post content so it can't drift from
    // what a client-side word count would see (e.g. shortcodes already expanded).
    $word_count = str_word_count( wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', get_queried_object_id() ) ) ) );
    $read_mins  = max( 1, (int) ceil( $word_count / 200 ) );
    ?>
    <script>window.__ozpReadMins = <?php echo (int) $read_mins; ?>;</script>
    <script>
    (function () {
        var readTimeEl = document.getElementById( 'ozp-read-time' );
        if ( readTimeEl && window.__ozpReadMins ) {
            readTimeEl.textContent = window.__ozpReadMins + ' min read';
        }

        // Build the table of contents from top-level (H2) headings inside the post content.
        // H3s still get anchor IDs assigned (in case something links to them directly) but
        // aren't listed — this mirrors a plain numbered "on this page" list, not a full outline.
        var content  = document.querySelector( '.wp-block-post-content, .entry-content' );
        var layout   = document.querySelector( '.ozp-article-layout' );
        var sidebar  = document.querySelector( '.ozp-article-sidebar' );
        var toc      = document.querySelector( '.ozp-toc' );
        var tocLinks = [];

        function hideToc() {
            if ( sidebar ) {
                sidebar.style.display = 'none';
            }
            if ( layout ) {
                layout.classList.add( 'ozp-no-toc' );
            }
        }

        if ( content && toc ) {
            var allHeadings = Array.prototype.filter.call(
                content.querySelectorAll( 'h2, h3' ),
                function ( heading ) { return ! heading.closest( '.ozp-upsell-modal' ); }
            );
            var seen   = {};
            var h2Count = allHeadings.filter( function ( h ) { return h.tagName === 'H2'; } ).length;

            if ( h2Count < 2 ) {
                hideToc();
            } else {
                var list = document.createElement( 'ul' );
                list.className = 'ozp-toc-list';
                toc.appendChild( list );

                allHeadings.forEach( function ( heading ) {
                    var slug = heading.textContent
                        .toLowerCase()
                        .trim()
                        .replace( /[^a-z0-9\s-]/g, '' )
                        .replace( /\s+/g, '-' );
                    if ( ! slug ) {
                        return;
                    }
                    var id = slug;
                    var i  = 2;
                    while ( seen[ id ] ) {
                        id = slug + '-' + i;
                        i++;
                    }
                    seen[ id ] = true;
                    if ( ! heading.id ) {
                        heading.id = id;
                    }

                    if ( heading.tagName !== 'H2' ) {
                        return; // gets an anchor ID above, but no TOC row
                    }

                    var li = document.createElement( 'li' );
                    var a  = document.createElement( 'a' );
                    a.href = '#' + heading.id;
                    var arrow = document.createElement( 'span' );
                    arrow.className   = 'ozp-toc-arrow';
                    arrow.textContent = '→';
                    arrow.setAttribute( 'aria-hidden', 'true' );
                    a.appendChild( arrow );
                    a.appendChild( document.createTextNode( heading.textContent ) );
                    li.appendChild( a );
                    list.appendChild( li );
                    tocLinks.push( { heading: heading, link: a } );
                } );

                // Highlight the TOC entry for whichever heading is currently in view.
                if ( 'IntersectionObserver' in window && tocLinks.length ) {
                    var observer = new IntersectionObserver( function ( entries ) {
                        entries.forEach( function ( entry ) {
                            var match = tocLinks.filter( function ( item ) { return item.heading === entry.target; } )[ 0 ];
                            if ( match && entry.isIntersecting ) {
                                tocLinks.forEach( function ( item ) { item.link.classList.remove( 'is-active' ); } );
                                match.link.classList.add( 'is-active' );
                            }
                        } );
                    }, { rootMargin: '-96px 0px -70% 0px' } );
                    tocLinks.forEach( function ( item ) { observer.observe( item.heading ); } );
                }
            }
        } else {
            hideToc();
        }

        // Upsell modal open/close, with a basic focus trap while open.
        document.querySelectorAll( '.ozp-upsell-trigger' ).forEach( function ( trigger ) {
            var modal = document.getElementById( trigger.getAttribute( 'aria-controls' ) );
            if ( ! modal ) {
                return;
            }
            var panel = modal.querySelector( '.ozp-upsell-modal-panel' );

            function close() {
                modal.hidden = true;
                trigger.focus();
            }

            trigger.addEventListener( 'click', function () {
                modal.hidden = false;
                var closeBtn = modal.querySelector( '.ozp-upsell-modal-close' );
                if ( closeBtn ) {
                    closeBtn.focus();
                }
            } );
            modal.querySelectorAll( '[data-ozp-modal-close]' ).forEach( function ( el ) {
                el.addEventListener( 'click', close );
            } );
            modal.addEventListener( 'keydown', function ( e ) {
                if ( e.key === 'Escape' ) {
                    close();
                    return;
                }
                if ( e.key !== 'Tab' || ! panel ) {
                    return;
                }
                var focusable = panel.querySelectorAll( 'button, a[href]' );
                if ( ! focusable.length ) {
                    return;
                }
                var first = focusable[ 0 ];
                var last  = focusable[ focusable.length - 1 ];
                if ( e.shiftKey && document.activeElement === first ) {
                    e.preventDefault();
                    last.focus();
                } else if ( ! e.shiftKey && document.activeElement === last ) {
                    e.preventDefault();
                    first.focus();
                }
            } );
        } );
    })();
    </script>
    <?php
}, 20 );

// Notice on My Account login page explaining accounts are created at checkout.
add_action( 'woocommerce_before_customer_login_form', function () {
    ?>
    <div class="ozp-login-notice">
        <strong>No account yet?</strong>
        An account is created automatically when you complete a purchase, and your login details are emailed to you.
        <a href="/shop/">Browse plans &rarr;</a>
    </div>
    <?php
} );

// Inject logout URL + logged-in state so the static header can wire up logout links.
// Also inject WooCommerce cart count so the cart badge updates server-side on each request.
add_action( 'wp_head', function () {
    $data = [];
    if ( is_user_logged_in() ) {
        $data['logout'] = wp_logout_url( home_url( '/' ) );
    }
    if ( function_exists( 'WC' ) && WC()->cart ) {
        $data['cartCount'] = WC()->cart->get_cart_contents_count();
    }
    if ( ! empty( $data ) ) {
        if ( isset( $data['logout'] ) ) {
            echo '<script>window._ozpLogout=' . wp_json_encode( $data['logout'] ) . ';document.documentElement.classList.add("ozp-logged-in");</script>' . "\n";
        }
        if ( isset( $data['cartCount'] ) ) {
            echo '<script>window._ozpCartCount=' . (int) $data['cartCount'] . ';</script>' . "\n";
        }
    }
}, 1 );

// Billing form for digital goods in the Kenyan market: email, name, phone.
// Not needed: street address, city, state/county, postcode, company,
// address 2, country — OzuPay only supports KES, so country is fixed below.
add_filter( 'woocommerce_checkout_fields', function ( array $fields ): array {
    // Remove fields irrelevant to a digital plugin purchase from Kenya.
    unset(
        $fields['billing']['billing_company'],
        $fields['billing']['billing_address_1'],
        $fields['billing']['billing_address_2'],
        $fields['billing']['billing_city'],
        $fields['billing']['billing_state'],
        $fields['billing']['billing_postcode'],
        $fields['billing']['billing_country']
    );

    // Name fields, side by side, between email and phone.
    if ( isset( $fields['billing']['billing_first_name'] ) ) {
        $fields['billing']['billing_first_name']['required'] = true;
        $fields['billing']['billing_first_name']['class']    = [ 'form-row-first' ];
        $fields['billing']['billing_first_name']['priority'] = 21;
    }
    if ( isset( $fields['billing']['billing_last_name'] ) ) {
        $fields['billing']['billing_last_name']['required'] = true;
        $fields['billing']['billing_last_name']['class']    = [ 'form-row-last' ];
        $fields['billing']['billing_last_name']['priority'] = 22;
    }

    // Relabel phone as the M-Pesa number and make it required.
    if ( isset( $fields['billing']['billing_phone'] ) ) {
        $fields['billing']['billing_phone']['label']       = __( 'M-Pesa Phone Number', 'ozupay' );
        $fields['billing']['billing_phone']['placeholder'] = '07XXXXXXXX';
        $fields['billing']['billing_phone']['required']    = true;
        $fields['billing']['billing_phone']['class']       = [ 'form-row-wide' ];
        $fields['billing']['billing_phone']['priority']    = 25; // after name
    }

    // Move email above name/phone so the order is: email → name → phone.
    if ( isset( $fields['billing']['billing_email'] ) ) {
        $fields['billing']['billing_email']['priority'] = 20;
    }

    // Remove order notes — not useful for a plugin purchase.
    unset( $fields['order']['order_comments'] );

    return $fields;
} );

// Pre-set country to Kenya so the customer doesn't have to change it.
add_filter( 'default_checkout_billing_country', function (): string { return 'KE'; } );

// Country field is hidden above; orders are always Kenyan (KES-only gateway),
// and the customer's name isn't collected — fall back to the email handle so
// admin order lists and emails still show something readable.
add_action( 'woocommerce_checkout_create_order', function ( $order ): void {
    $order->set_billing_country( 'KE' );

    if ( ! $order->get_billing_first_name() && $order->get_billing_email() ) {
        $handle = strtok( $order->get_billing_email(), '@' );
        $order->set_billing_first_name( $handle ?: 'Customer' );
    }
}, 20 );

// Custom heading for the billing fields, replacing WooCommerce's default
// (hidden via CSS) — "Billing address" matches the field set collected
// (email, name, phone) better than the default "Billing details".
add_action( 'woocommerce_before_checkout_billing_form', function (): void {
    echo '<h3 class="ozp-billing-heading">' . esc_html__( 'Billing address', 'ozupay' ) . '</h3>';
}, 5 );

// Disable WooCommerce product image zoom — image should not pan on cursor hover.
add_filter( 'woocommerce_single_product_zoom_enabled', '__return_false' );

// Blocks checkout: register phone as required and relabel it.
// WC Blocks keys address fields without the "billing_" prefix, so the hook is
// woocommerce_get_registered_address_field_phone, not billing_phone.
add_filter( 'woocommerce_get_registered_address_field_phone', function ( array $field ): array {
    $field['required'] = true;
    $field['label']    = __( 'M-Pesa Phone Number', 'ozupay' );
    return $field;
} );

// Mobile sticky CTA — output in footer so fixed positioning works correctly.
add_action( 'wp_footer', function () {
    if ( ! is_front_page() ) {
        return;
    }
    ?>
    <div class="ozp-mobile-cta" role="navigation" aria-label="Quick actions">
        <a href="/get-free/" class="ozp-btn ozp-btn-outline" target="_blank" rel="noopener">Free</a>
        <a href="#pricing" class="ozp-btn ozp-btn-primary">Get Pro &rarr;</a>
    </div>

    <!-- Feature modal overlay -->
    <div class="ozp-modal-overlay" id="ozp-feature-modal" hidden role="dialog" aria-modal="true" aria-labelledby="ozp-modal-title">
        <div class="ozp-modal">
            <button class="ozp-modal-close" id="ozp-modal-close" aria-label="Close">&times;</button>
            <div class="ozp-modal-title" id="ozp-modal-title"></div>
            <div class="ozp-modal-body" id="ozp-modal-body"></div>
        </div>
    </div>

    <!-- Hidden feature detail content -->
    <div id="fd-stk-push" data-title="STK Push" hidden>
        <p>When a customer selects OzuPay at checkout and places the order, the plugin sends an M-Pesa STK Push prompt directly to the phone number they entered. The customer opens their phone, enters their M-Pesa PIN, and the payment is confirmed instantly.</p>
        <p>The order status updates automatically when the Daraja confirmation callback is received — no admin action or manual reconciliation required. If the customer dismisses the prompt or the request times out, the order is held as pending and the customer can retry.</p>
        <p>Works with Safaricom Daraja 2.0. Compatible with both personal and business M-Pesa lines. Available in the free and Pro plans.</p>
    </div>

    <div id="fd-paybill-till" data-title="Paybill &amp; Till" hidden>
        <p>The plugin supports both C2B payment methods: <strong>Paybill</strong> (Pay Bill — customer enters a business number and account number) and <strong>Buy Goods / Till</strong> (customer enters a till number). Configure which type you use in WooCommerce &rarr; Settings &rarr; Payments &rarr; OzuPay.</p>
        <p>The plugin registers your shortcode with the Daraja C2B API and listens for confirmation callbacks. When a payment arrives, it matches the transaction to the correct WooCommerce order by amount and phone number, then updates the order status automatically.</p>
        <p>Useful as a fallback when the customer cannot receive the STK Push prompt — for example on a second SIM or a feature phone. Available in the free and Pro plans.</p>
    </div>

    <div id="fd-callback-security" data-title="Callback IP Security" hidden>
        <p>Every payment notification received from Daraja is validated against Safaricom's published IP address whitelist before any order data is read or modified. Payloads arriving from unknown IPs are rejected outright and the attempt is written to the WordPress error log.</p>
        <p>This prevents fraudulent callback injection — a common attack against M-Pesa integrations that skip IP validation. An attacker who knows your callback URL cannot trigger order status changes without passing the IP check.</p>
        <p>The IP whitelist is maintained inside the plugin and updated with each release. No manual configuration is needed. Available in the free and Pro plans.</p>
    </div>

    <div id="fd-hpos" data-title="WooCommerce HPOS" hidden>
        <p>OzuPay M-Pesa Payments Plugin is fully compatible with WooCommerce High-Performance Order Storage (HPOS) — the new dedicated orders table introduced in WooCommerce 7.1 and made the default in WooCommerce 8.2.</p>
        <p>All order queries use <code>wc_get_orders()</code>, never <code>get_posts()</code>. The plugin declares HPOS compatibility via the WooCommerce FeaturesUtil API, so you will not see the HPOS incompatibility warning in WooCommerce &rarr; Settings &rarr; Advanced &rarr; Features.</p>
        <p>Traditional post-based order storage (CPT mode) is also fully supported, so you can enable HPOS at your own pace. Available in the free and Pro plans.</p>
    </div>

    <div id="fd-sandbox" data-title="Sandbox &amp; Production Environments" hidden>
        <p>Switch between the Daraja sandbox and live production environments using a single dropdown in WooCommerce &rarr; Settings &rarr; Payments &rarr; OzuPay &rarr; Environment. The switch takes effect immediately — no page reload required.</p>
        <p>Sandbox mode uses your Daraja test credentials, but Safaricom's sandbox still sends a real STK Push to the phone number you test with — the amount can be deducted and is reversed automatically shortly after. Test the complete STK Push and C2B callback flow on a phone you control, confirm order statuses update correctly, and then flip the switch to go live.</p>
        <p>Separate credential fields are shown for sandbox and production so you never risk mixing test and live keys. Available in Pro only.</p>
    </div>

    <div id="fd-cod" data-title="M-Pesa on Delivery" hidden>
        <p>For Cash on Delivery orders, OzuPay can collect a configurable deposit via STK Push at the time of checkout — for example 30% of the order total — so the customer commits before delivery.</p>
        <p>When the delivery is completed and the WooCommerce order is marked as delivered, the plugin automatically sends a second STK Push for the remaining balance. The customer receives an M-Pesa prompt on their phone for each payment step.</p>
        <p>The deposit percentage and balance collection trigger are configurable in Settings. All collected amounts are recorded in the order timeline. Available in Pro only.</p>
    </div>

    <div id="fd-b2c" data-title="B2C Refunds" hidden>
        <p>Issue M-Pesa refunds directly from the WooCommerce order screen. Open the order, click Refund, enter the amount, and the plugin sends the money to the customer's M-Pesa number via the Daraja B2C API — no need to log into the Safaricom portal.</p>
        <p>The refund amount, Daraja transaction ID, and timestamp are saved as an order note so you have a full audit trail alongside the original payment record.</p>
        <p>Requires a Daraja B2C shortcode with the correct product type (SalaryPayment or BusinessPayment) configured in Settings. Available in Pro only.</p>
    </div>

    <div id="fd-analytics" data-title="Analytics Dashboard" hidden>
        <p>A dedicated OzuPay analytics screen in the WordPress admin gives you a real-time view of your M-Pesa payment activity:</p>
        <ul>
            <li>KPI cards: total revenue collected, payment count, success rate, average order value</li>
            <li>Revenue trend chart with a configurable date range</li>
            <li>STK Push success and failure breakdown</li>
            <li>Full transaction log with search, date filter, status filter, and a direct link to each WooCommerce order</li>
        </ul>
        <p>All data is read from the WooCommerce orders table — no separate analytics database or third-party service required. Available in Pro only.</p>
    </div>

    <div id="fd-webhooks" data-title="Webhooks" hidden>
        <p>OzuPay extends the WooCommerce Webhooks system with custom M-Pesa event topics. Create webhook endpoints from <strong>OzuPay &rarr; Webhooks</strong> in the admin and choose from:</p>
        <ul>
            <li><code>ozupay.payment.confirmed</code> — fires when an M-Pesa STK Push or C2B payment is confirmed</li>
            <li><code>ozupay.cod_collected</code> — fires when a COD deposit or delivery balance is collected</li>
            <li><code>ozupay.b2c_sent</code> — fires when a B2C refund is dispatched to the customer</li>
        </ul>
        <p>Each webhook delivers a JSON payload containing order ID, amount, customer phone number, and M-Pesa transaction reference. Use them to sync payments with your accounting tool, ERP, or any system that accepts HTTP callbacks. Available in Pro only.</p>
    </div>

    <div id="fd-autoupdates" data-title="Auto-Updates" hidden>
        <p>Licensed Pro sites receive plugin update notifications directly in the WordPress Dashboard &rarr; Updates screen, alongside your other plugins. Install new versions with a single click — no ZIP downloads, no FTP, no manual file replacement.</p>
        <p>Your license key is verified against the OzuPay license server on each update check. Updates are delivered for the duration of your active license year. When your license expires, existing functionality continues to work — only the update feed is paused until you renew.</p>
    </div>

    <div id="fd-tx-fee" data-title="Transaction Fee Passthrough" hidden>
        <p>When enabled, the plugin automatically adds the official Safaricom transaction fee to the WooCommerce order total at checkout — but only when the M-Pesa gateway is selected. The amount is looked up from the published Safaricom tariff based on the order total. No manual configuration is needed.</p>
        <p><strong>Paybill merchants:</strong> Safaricom charges a fixed fee per transaction band — for example KES 5 on orders between KES 101 and KES 500, rising to a maximum of KES 108 on orders above KES 45,000. The plugin applies the correct amount automatically.</p>
        <p><strong>Till (Buy Goods) merchants:</strong> Safaricom does not charge the customer on Till payments. The merchant pays 0.5% of the transaction amount capped at KES 200. Enabling this feature passes that merchant cost to the customer. Transactions of KES 200 and below are free — no fee is added.</p>
        <p>The fee appears as a named line item in the order totals table and is saved to the order record. Customers see it on the checkout page, order confirmation email, and order detail screen. Available in Pro only.</p>
    </div>

    <div id="fd-qr-code" data-title="M-Pesa QR Code" hidden>
        <p>Shows a scannable QR code automatically on the customer's payment-waiting page after checkout, as an alternative to STK Push. The plugin calls the Safaricom Daraja QR code endpoint and displays a scannable PNG image with the order amount and recipient shortcode pre-filled.</p>
        <p>The customer opens the Safaricom My One App, taps <em>Lipa na M-Pesa</em>, selects <em>Scan QR Code</em>, and scans — they only need to enter their PIN and confirm. No account number, no amount to type.</p>
        <p>The same QR code can also be generated, downloaded, and shared from the WooCommerce order edit screen — printed on an invoice, pasted into a WhatsApp message, or included in a quote PDF. Available in Pro only.</p>
    </div>

    <div id="fd-payment-links" data-title="Payment Links" hidden>
        <p>Adds a <strong>Generate Payment Link</strong> button to the WooCommerce order edit screen. Clicking it creates a unique, token-authenticated URL tied to that specific order.</p>
        <p>The merchant copies the link and sends it to the customer via WhatsApp, SMS, or email. When the customer opens the link, they land directly on the M-Pesa checkout page for that order — no browsing, no searching, no re-entering details.</p>
        <p>If the order has already been paid, the link redirects to the order confirmation page instead. A new link can be generated at any time from the order screen, which invalidates the previous one. Available in Pro only.</p>
    </div>
    <?php
} );

// Mobile hamburger + My Account dropdown toggles.
add_action( 'wp_footer', function () {
    ?>
    <script>
    (function(){
        // Hamburger
        var btn = document.getElementById('ozp-hamburger');
        var nav = document.getElementById('ozp-nav');
        if (btn && nav) {
            btn.addEventListener('click', function(){
                var open = nav.classList.toggle('open');
                btn.classList.toggle('open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            });
        }

        // My Account dropdown
        var accountBtn  = document.getElementById('ozp-account-btn');
        var accountWrap = document.getElementById('ozp-account-dropdown');
        if (accountBtn && accountWrap) {
            accountBtn.addEventListener('click', function(e){
                e.stopPropagation();
                var open = accountWrap.classList.toggle('open');
                accountBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        // Close both on outside click
        document.addEventListener('click', function(e){
            if (btn && nav && !btn.contains(e.target) && !nav.contains(e.target)) {
                nav.classList.remove('open');
                btn.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
                btn.setAttribute('aria-label', 'Open menu');
            }
            if (accountWrap && !accountWrap.contains(e.target)) {
                accountWrap.classList.remove('open');
                if (accountBtn) accountBtn.setAttribute('aria-expanded', 'false');
            }
        });

        // Feature card modals
        var modalOverlay = document.getElementById('ozp-feature-modal');
        var modalTitle   = document.getElementById('ozp-modal-title');
        var modalBody    = document.getElementById('ozp-modal-body');
        var modalClose   = document.getElementById('ozp-modal-close');

        function openFeatureModal(featureId) {
            var src = document.getElementById('fd-' + featureId);
            if (!src || !modalOverlay) return;
            modalTitle.textContent = src.getAttribute('data-title');
            modalBody.innerHTML    = src.innerHTML;
            modalOverlay.removeAttribute('hidden');
            document.body.style.overflow = 'hidden';
            if (modalClose) modalClose.focus();
        }

        function closeFeatureModal() {
            if (!modalOverlay) return;
            modalOverlay.setAttribute('hidden', '');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('.ozp-card-more').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openFeatureModal(btn.getAttribute('data-feature'));
            });
        });

        if (modalClose) modalClose.addEventListener('click', closeFeatureModal);
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function(e) {
                if (e.target === modalOverlay) closeFeatureModal();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalOverlay && !modalOverlay.hasAttribute('hidden')) {
                closeFeatureModal();
            }
        });

        // Wire logout links with the nonce URL injected by wp_head
        if (window._ozpLogout) {
            document.querySelectorAll('.ozp-logout-link').forEach(function(el) {
                el.href = window._ozpLogout;
            });
        }

        // Cart count badge
        var cartCountEl = document.getElementById('ozp-cart-count');
        if (cartCountEl && typeof window._ozpCartCount !== 'undefined') {
            var n = parseInt(window._ozpCartCount, 10);
            if (n > 0) {
                cartCountEl.textContent = n > 99 ? '99+' : n;
                cartCountEl.style.display = 'flex';
            } else {
                cartCountEl.style.display = 'none';
            }
        }



        // Docs page: TOC toggle (mobile) + active link highlighting
        var tocToggle = document.getElementById('ozp-toc-toggle');
        var tocMenu   = document.getElementById('ozp-toc');
        if (tocToggle && tocMenu) {
            tocToggle.addEventListener('click', function() {
                var open = tocMenu.classList.toggle('open');
                tocToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        // Docs: highlight active TOC link on scroll
        var docsSections = document.querySelectorAll('.ozp-docs-section, .ozp-docs-feature');
        var tocLinks     = document.querySelectorAll('.ozp-docs-toc a');
        if (docsSections.length && tocLinks.length) {
            function updateActiveTocLink() {
                var scrollY = window.scrollY + 100;
                var current = '';
                docsSections.forEach(function(sec) {
                    if (sec.id && sec.offsetTop <= scrollY) current = sec.id;
                });
                tocLinks.forEach(function(link) {
                    var href = link.getAttribute('href');
                    link.classList.toggle('active', href === '#' + current);
                });
            }
            window.addEventListener('scroll', updateActiveTocLink, { passive: true });
            updateActiveTocLink();
        }

        // Docs: search box — indexes headings/endpoints under .ozp-docs-content and
        // shows a dropdown of matches; click or Enter jumps to the section and flashes it.
        var docsSearchInput   = document.getElementById('ozp-docs-search-input');
        var docsSearchResults = document.getElementById('ozp-docs-search-results');
        var docsContent       = document.querySelector('.ozp-docs-content');
        if (docsSearchInput && docsSearchResults && docsContent) {
            var docsSearchIndex = [];
            docsContent.querySelectorAll('[id]').forEach(function(el) {
                var titleEl = el.matches('h2, h3, h4') ? el : el.querySelector('h2, h3, h4, .ozp-docs-endpoint-header');
                if (!titleEl) return;
                var title = titleEl.textContent.replace(/\s+/g, ' ').trim();
                if (!title) return;
                docsSearchIndex.push({
                    id: el.id,
                    title: title,
                    body: el.textContent.replace(/\s+/g, ' ').trim()
                });
            });

            var docsSearchActiveIndex = -1;

            function ozpDocsFlash(id) {
                var target = document.getElementById(id);
                if (!target) return;
                target.classList.add('ozp-docs-highlight');
                setTimeout(function() { target.classList.remove('ozp-docs-highlight'); }, 2000);
            }

            function ozpDocsRenderResults(query) {
                docsSearchResults.innerHTML = '';
                docsSearchActiveIndex = -1;
                if (!query) {
                    docsSearchResults.hidden = true;
                    return;
                }
                var q = query.toLowerCase();
                var matches = docsSearchIndex.filter(function(item) {
                    return item.title.toLowerCase().indexOf(q) !== -1 || item.body.toLowerCase().indexOf(q) !== -1;
                }).slice(0, 8);

                if (!matches.length) {
                    var empty = document.createElement('div');
                    empty.className = 'ozp-docs-search-empty';
                    empty.textContent = 'No results for "' + query + '"';
                    docsSearchResults.appendChild(empty);
                    docsSearchResults.hidden = false;
                    return;
                }

                matches.forEach(function(item) {
                    var bodyLower = item.body.toLowerCase();
                    var idx = bodyLower.indexOf(q);
                    var snippet = '';
                    if (idx !== -1) {
                        var start = Math.max(0, idx - 40);
                        snippet = (start > 0 ? '…' : '') + item.body.slice(start, idx + q.length + 60) + '…';
                    }

                    var a = document.createElement('a');
                    a.href = '#' + item.id;
                    a.className = 'ozp-docs-search-result';

                    var titleSpan = document.createElement('span');
                    titleSpan.className = 'ozp-docs-search-result-title';
                    titleSpan.textContent = item.title;
                    a.appendChild(titleSpan);

                    if (snippet) {
                        var snippetSpan = document.createElement('span');
                        snippetSpan.className = 'ozp-docs-search-result-snippet';
                        snippetSpan.textContent = snippet;
                        a.appendChild(snippetSpan);
                    }

                    a.addEventListener('click', function() {
                        docsSearchResults.hidden = true;
                        docsSearchInput.value = '';
                        ozpDocsFlash(item.id);
                    });
                    docsSearchResults.appendChild(a);
                });
                docsSearchResults.hidden = false;
            }

            var docsSearchDebounce;
            docsSearchInput.addEventListener('input', function() {
                clearTimeout(docsSearchDebounce);
                var val = docsSearchInput.value.trim();
                docsSearchDebounce = setTimeout(function() { ozpDocsRenderResults(val); }, 120);
            });

            docsSearchInput.addEventListener('keydown', function(e) {
                var results = docsSearchResults.querySelectorAll('.ozp-docs-search-result');
                if (!results.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    docsSearchActiveIndex = Math.min(docsSearchActiveIndex + 1, results.length - 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    docsSearchActiveIndex = Math.max(docsSearchActiveIndex - 1, 0);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    (results[docsSearchActiveIndex] || results[0]).click();
                    return;
                } else if (e.key === 'Escape') {
                    docsSearchResults.hidden = true;
                    docsSearchInput.blur();
                    return;
                } else {
                    return;
                }
                results.forEach(function(r, i) { r.classList.toggle('is-active', i === docsSearchActiveIndex); });
                results[docsSearchActiveIndex].scrollIntoView({ block: 'nearest' });
            });

            document.addEventListener('click', function(e) {
                if (e.target !== docsSearchInput && !docsSearchResults.contains(e.target)) {
                    docsSearchResults.hidden = true;
                }
            });
        }
    })();

    // Support & Feature Request form AJAX
    var ozpApiRoot = <?php echo wp_json_encode( esc_url_raw( rest_url( 'ozls/v1' ) ) ); ?>;
    (function() {
        function ozpHandleForm(formId, endpoint) {
            var form = document.getElementById(formId);
            if (!form) return;
            var rendering = false;
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                // novalidate suppresses the browser's own required-field UI, so enforce it here.
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                // Explicit Turnstile: render on first submit, wait for token, then re-submit.
                var widget = form.querySelector('.cf-turnstile');
                var tokenInput = widget && form.querySelector('[name="cf-turnstile-response"]');
                if (widget && window.turnstile && !rendering && (!tokenInput || !tokenInput.value)) {
                    rendering = true;
                    turnstile.render(widget, {
                        sitekey: widget.getAttribute('data-sitekey'),
                        callback: function() {
                            rendering = false;
                            form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                        },
                        'error-callback': function() { rendering = false; }
                    });
                    return;
                }
                rendering = false;

                var btn    = form.querySelector('[type=submit]');
                var notice = form.querySelector('.ozp-form-notice');
                var btnLbl = btn.querySelector('.ozp-ch-btn-lbl') || btn;
                var origText = btn.getAttribute('data-text') || 'Submit';
                // Collect all named fields, including cf-turnstile-response injected by Turnstile.
                var data = {};
                new FormData(form).forEach(function(value, key) { data[key] = value; });
                btn.disabled       = true;
                btnLbl.textContent = 'Sending…';
                if (notice) { notice.className = 'ozp-form-notice ozp-ch-notice'; notice.textContent = ''; }
                fetch(ozpApiRoot + endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                })
                .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, body: j }; }); })
                .then(function(res) {
                    if (res.ok) {
                        form.innerHTML = '<p class="ozp-ch-notice ozp-ch-notice--success">Your submission has been received. We will be in touch.</p>';
                    } else {
                        if (notice) {
                            notice.className   = 'ozp-form-notice ozp-ch-notice';
                            notice.textContent = res.body.message || 'Submission failed. Please try again.';
                        }
                        btn.disabled       = false;
                        btnLbl.textContent = origText;
                        if (window.turnstile) window.turnstile.reset();
                    }
                })
                .catch(function() {
                    if (notice) {
                        notice.className   = 'ozp-form-notice ozp-ch-notice';
                        notice.textContent = 'Network error. Please try again.';
                    }
                    btn.disabled       = false;
                    btnLbl.textContent = origText;
                    if (window.turnstile) window.turnstile.reset();
                });
            });
        }
        ozpHandleForm('ozp-support-form',       '/support');
        ozpHandleForm('ozp-contact-guest-form', '/support');
        ozpHandleForm('ozp-ticket-form',        '/support');
        ozpHandleForm('ozp-fr-form',            '/feature-request');
    })();
    </script>
    <?php
} );

// "Powered by" pill on checkout — a compact centered badge above the form,
// printed right after the steps nav (see woocommerce_before_checkout_form
// below), not a fixed bar overlapping the header.
function ozp_checkout_powered_by(): void {
    ?>
    <div class="ozp-checkout-powered-by-wrap">
        <div class="ozp-checkout-powered-by" role="note">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            checkout powered by <a href="/" target="_blank" rel="noopener">OzuPay M-Pesa Payments Pro</a>
        </div>
    </div>
    <?php
}

// Remove the "X has been added to your cart" notice — redundant on product pages.
add_filter( 'wc_add_to_cart_message_html', '__return_empty_string' );

// Register the classic cart form and totals as WC fragments so any AJAX
// fragment refresh returns fresh cart HTML without needing a page re-fetch.
// Also include the cart count so the header badge updates without a page reload.
add_filter( 'woocommerce_add_to_cart_fragments', function ( array $fragments ): array {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        return $fragments;
    }

    ob_start();
    woocommerce_cart_totals();
    $fragments['.cart_totals'] = '<div class="cart_totals">' . ob_get_clean() . '</div>';

    // Expose the cart count as a fragment so JS can read the authoritative value.
    $fragments['ozp_cart_count'] = WC()->cart->get_cart_contents_count();

    return $fragments;
} );

// AJAX add-to-cart: update header badge and show toast notification.
add_action( 'wp_footer', function (): void {
    if ( ! function_exists( 'WC' ) ) {
        return;
    }

    // No toast on the cart/checkout pages: the customer is already where the
    // toast's "Proceed to checkout" CTA points, and on mobile the toast sits
    // on top of the payment box. Clear the session flag too, so navigating
    // back to the shop doesn't resurrect a stale "added to cart" toast.
    if ( is_cart() || is_checkout() ) {
        ?>
<script>try { sessionStorage.removeItem( 'ozp_toast' ); } catch ( e ) {}</script>
        <?php
        return;
    }
    ?>
<div id="ozp-cart-toast" class="ozp-cart-toast" role="status" aria-live="polite">
    <div class="ozp-cart-toast-top">
        <span class="ozp-cart-toast-icon">
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M1.5 5L4 7.5L8.5 2.5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
        <span class="ozp-cart-toast-text">Licence added to cart</span>
        <button class="ozp-cart-toast-close" aria-label="Dismiss">&#x2715;</button>
    </div>
    <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="ozp-cart-toast-link">Proceed to checkout &#x2192;</a>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
(function ($) {
    var badge    = document.getElementById('ozp-cart-count');
    var toast    = document.getElementById('ozp-cart-toast');
    var toastTxt = toast ? toast.querySelector('.ozp-cart-toast-text') : null;
    var toastClose = toast ? toast.querySelector('.ozp-cart-toast-close') : null;

    function updateBadge(count) {
        if (!badge) return;
        var n = parseInt(count, 10);
        if (n > 0) {
            badge.textContent = n > 99 ? '99+' : n;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }

    var checkoutUrl = <?php echo wp_json_encode( wc_get_checkout_url() ); ?>;
    var cartIds     = <?php
        $ids = [];
        if ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                $ids[] = (int) $item['product_id'];
            }
        }
        echo wp_json_encode( $ids );
    ?>;

    function markButtonAdded( btn ) {
        if ( ! btn ) return;
        var el = ( btn.jquery || btn instanceof $ ) ? btn[0] : btn;
        if ( ! el ) return;
        el.classList.add( 'ozp-added' );
        Array.from( el.childNodes ).forEach( function ( node ) {
            if ( node.nodeType === 3 && node.textContent.trim() ) {
                node.textContent = ' In cart — Go to checkout  ';
            }
        } );
    }

    function showToast() {
        if ( ! toast ) return;
        toast.classList.add( 'is-visible' );
        try { sessionStorage.setItem( 'ozp_toast', '1' ); } catch(e) {}
    }

    function hideToast() {
        if ( ! toast ) return;
        toast.classList.remove( 'is-visible' );
        try { sessionStorage.removeItem( 'ozp_toast' ); } catch(e) {}
    }

    // Restore button states and toast on page load from server-rendered cart.
    if ( cartIds.length ) {
        cartIds.forEach( function ( id ) {
            var btn = document.querySelector( '.ajax_add_to_cart[data-product_id="' + id + '"]' );
            markButtonAdded( btn );
        } );
        try {
            if ( sessionStorage.getItem( 'ozp_toast' ) ) showToast();
        } catch(e) {}
    }

    if ( toastClose ) {
        toastClose.addEventListener( 'click', hideToast );
    }

    // Checkout link clears the toast session flag — user is leaving to pay.
    var toastLink = toast ? toast.querySelector( '.ozp-cart-toast-link' ) : null;
    if ( toastLink ) {
        toastLink.addEventListener( 'click', function () {
            try { sessionStorage.removeItem( 'ozp_toast' ); } catch(e) {}
        } );
    }

    // Re-clicking an already-added pricing button goes straight to checkout.
    $( document ).on( 'click', '.ajax_add_to_cart.ozp-added', function ( e ) {
        e.preventDefault();
        e.stopImmediatePropagation();
        window.location.assign( checkoutUrl );
    } );

    // WooCommerce classic jQuery event — fired after successful add-to-cart AJAX.
    // Fragments include our custom 'ozp_cart_count' key (registered server-side).
    // WC passes the clicked button as the 4th argument.
    $( document ).on( 'added_to_cart', function ( e, fragments, hash, button ) {
        if ( fragments && fragments['ozp_cart_count'] !== undefined ) {
            updateBadge( fragments['ozp_cart_count'] );
        } else {
            var cur = badge ? parseInt( badge.textContent || '0', 10 ) : 0;
            updateBadge( cur + 1 );
        }

        markButtonAdded( button || null );
        showToast();
    } );

    // WooCommerce Blocks fires this vanilla event in addition to the jQuery one.
    document.addEventListener( 'wc-blocks_added_to_cart', function () {
        if ( ! toast || toast.classList.contains( 'is-visible' ) ) return;
        var cur = badge ? parseInt( badge.textContent || '0', 10 ) : 0;
        updateBadge( cur + 1 );
        showToast();
    } );

}(jQuery));
});
</script>
    <?php
} );

// AJAX cart: quantity updates + instant item removal (no page reload).
add_action( 'wp_footer', function (): void {
    if ( ! is_cart() ) {
        return;
    }
    $cart_url = wc_get_cart_url();
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
    jQuery(function($) {
        var qtyTimer = null;
        var cartUrl  = <?php echo wp_json_encode( $cart_url ); ?>;

        var ajaxUrl = (typeof wc_cart_params !== 'undefined') ? wc_cart_params.ajax_url : '/wp-admin/admin-ajax.php';

        /* ── Parse a full HTML page response and swap in the cart sections ── */
        function applyCartHtml(html) {
            // DOMParser handles full HTML documents correctly ($.parseHTML cannot)
            var doc  = (new DOMParser()).parseFromString(html, 'text/html');
            var form = doc.querySelector('form.woocommerce-cart-form');

            if (form) {
                $('form.woocommerce-cart-form').replaceWith(form);
            } else {
                // Cart is now empty — find the empty-cart notice and show it
                var empty = doc.querySelector('.wc-empty-cart-message, .cart-empty, p.woocommerce-info');
                if (empty) {
                    $('form.woocommerce-cart-form').replaceWith(empty);
                }
            }

            var totals = doc.querySelector('.cart_totals');
            if (totals) { $('.cart_totals').replaceWith(totals); }

            // The page has more than one .woocommerce-notices-wrapper (the WC
            // Store Notices block plus a legacy classic wrapper). replaceWith()
            // on a multi-element jQuery collection clones the same notice into
            // every match, so "Cart updated." would render twice. Replace the
            // first wrapper and clear the rest instead of removing them, so
            // future notices still have somewhere to render.
            var notices = doc.querySelector('.woocommerce-notices-wrapper');
            if (notices) {
                var $wrappers = $('.woocommerce-notices-wrapper');
                $wrappers.first().replaceWith(notices);
                $wrappers.slice(1).empty();
            }

            $(document.body).trigger('updated_cart_totals');
            injectQtyButtons();

            // Refresh header cart count bubble. 'ozp_cart_count' is a bare
            // integer fragment (not a CSS selector), so it must be applied to
            // #ozp-cart-count explicitly — $(key).replaceWith(val) silently
            // no-ops for it, which left the badge stuck at its initial count.
            $.post(ajaxUrl, { action: 'woocommerce_get_refreshed_fragments' }, function(res) {
                if (res && res.fragments) {
                    $.each(res.fragments, function(key, val) {
                        if (key === 'ozp_cart_count') { return; }
                        $(key).replaceWith(val);
                    });
                    var badge = document.getElementById('ozp-cart-count');
                    if (badge && res.fragments['ozp_cart_count'] !== undefined) {
                        var n = parseInt(res.fragments['ozp_cart_count'], 10);
                        if (n > 0) {
                            badge.textContent = n > 99 ? '99+' : n;
                            badge.style.display = 'flex';
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                    $(document.body).trigger('wc_fragments_refreshed');
                }
            });
        }

        /* ── +/- stepper buttons ────────────────────────────────────────── */
        function injectQtyButtons() {
            $('form.woocommerce-cart-form .quantity').each(function() {
                var $wrap = $(this);
                if ($wrap.find('.ozp-qty-btn').length) return;
                var $input = $wrap.find('input.qty');
                if (!$input.length) return;
                $wrap.addClass('ozp-qty-wrap');
                $('<button type="button" class="ozp-qty-btn ozp-qty-minus" aria-label="Decrease quantity">−</button>').insertBefore($input);
                $('<button type="button" class="ozp-qty-btn ozp-qty-plus" aria-label="Increase quantity">+</button>').insertAfter($input);
            });
        }

        $(document).on('click', 'form.woocommerce-cart-form .ozp-qty-minus', function() {
            var $input = $(this).siblings('input.qty');
            var min  = parseFloat($input.attr('min') || 0);
            var step = parseFloat($input.attr('step') || 1);
            var val  = parseFloat($input.val()) || 0;
            var newVal = Math.max(min, val - step);
            if (newVal !== val) $input.val(newVal).trigger('change');
        });

        $(document).on('click', 'form.woocommerce-cart-form .ozp-qty-plus', function() {
            var $input = $(this).siblings('input.qty');
            var max  = parseFloat($input.attr('max'));
            var step = parseFloat($input.attr('step') || 1);
            var val  = parseFloat($input.val()) || 0;
            var newVal = isNaN(max) ? val + step : Math.min(max, val + step);
            $input.val(newVal).trigger('change');
        });

        /* ── Qty change → debounce → POST form → apply response HTML ────── */
        $(document).on('change', 'form.woocommerce-cart-form input.qty', function() {
            var $row = $(this).closest('tr');
            $row.css({ opacity: 0.5, pointerEvents: 'none' });
            clearTimeout(qtyTimer);
            qtyTimer = setTimeout(function() {
                var $form = $('form.woocommerce-cart-form');
                var data  = $form.serialize() + '&update_cart=Update+cart';
                fetch($form.attr('action') || cartUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Cache-Control': 'no-cache' },
                    body: data
                })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    // WC may redirect; the final response is the updated cart page
                    applyCartHtml(html);
                })
                .catch(function() { window.location.reload(); });
            }, 600);
        });

        /* ── AJAX item removal ───────────────────────────────────────────── */
        $(document).on('click', 'a.remove[href*="remove_item"]', function(e) {
            e.preventDefault();
            var href = $(this).attr('href');
            var $row = $(this).closest('tr');
            $row.css({ opacity: 0.25, pointerEvents: 'none', transition: 'opacity .15s' });

            // WC remove URL returns a redirect to the cart page.
            // fetch() follows the redirect; the final response is the updated cart.
            fetch(href, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'follow',
                headers: { 'Cache-Control': 'no-cache' }
            })
            .then(function(r) { return r.text(); })
            .then(function(html) { applyCartHtml(html); })
            .catch(function() { window.location.href = href; });
        });

        /* ── Block native form submit (Update Cart button) ──────────────── */
        $(document).on('submit', 'form.woocommerce-cart-form', function(e) {
            e.preventDefault();
            var $form = $(this);
            $form.css({ opacity: 0.5, pointerEvents: 'none' });
            var data = $form.serialize() + '&update_cart=Update+cart';
            fetch($form.attr('action') || cartUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Cache-Control': 'no-cache' },
                body: data
            })
            .then(function(r) { return r.text(); })
            .then(function(html) { applyCartHtml(html); $form.css({ opacity: '', pointerEvents: '' }); })
            .catch(function() { window.location.reload(); });
        });

        /* ── Re-inject +/- after any DOM update ─────────────────────────── */
        $(document.body).on('updated_cart_totals wc_fragments_refreshed', function() {
            injectQtyButtons();
        });

        injectQtyButtons();
    });
    });
    </script>
    <?php
} );

// Override WC Blocks "X in cart" button text — always show "Add to cart".
add_action( 'wp_footer', function (): void {
    if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
        return;
    }
    ?>
    <script>
    (function() {
        function resetAddToCartText() {
            document.querySelectorAll('.wc-block-components-product-button__button').forEach(function(btn) {
                if (/\d+\s+in\s+cart/i.test(btn.textContent.trim())) {
                    btn.textContent = 'Add to cart';
                }
            });
        }
        resetAddToCartText();
        new MutationObserver(resetAddToCartText).observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true
        });
    })();
    </script>
    <?php
} );

// ── Single product page ──────────────────────────────────────────────────────

// Replace the WooCommerce placeholder gallery with a styled plugin icon panel.
add_filter( 'render_block_woocommerce/product-image-gallery', function ( string $block_content ): string {
    if ( ! is_product() ) {
        return $block_content;
    }
    if ( strpos( $block_content, 'woocommerce-product-gallery--without-images' ) === false ) {
        return $block_content;
    }
    ob_start();
    ?>
    <div class="ozp-product-icon-panel" aria-hidden="true">
        <div class="ozp-product-icon-panel-inner">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80" fill="none">
                <rect width="80" height="80" rx="20" fill="#1a2e1c"/>
                <rect x="10" y="24" width="60" height="38" rx="7" fill="#2a4a2e"/>
                <rect x="10" y="33" width="60" height="11" fill="#3a6b40"/>
                <rect x="18" y="43" width="12" height="9" rx="2" fill="#4caf61" opacity=".9"/>
                <circle cx="56" cy="47" r="3" fill="#4caf61"/>
                <circle cx="65" cy="47" r="3" fill="#4caf61" opacity=".45"/>
            </svg>
            <div class="ozp-product-icon-panel-name">OzuPay</div>
        </div>
        <div class="ozp-product-icon-panel-badge">Pro</div>
    </div>
    <?php
    return ob_get_clean();
} );

// Inject a "what's included" list after the add-to-cart block.
add_filter( 'render_block_woocommerce/add-to-cart-form', function ( string $block_content ): string {
    if ( ! is_product() ) {
        return $block_content;
    }
    $features = [
        'STK Push, C2B Paybill, and Till (Buy Goods)',
        'M-Pesa on Delivery with configurable deposit collection',
        'B2C refunds sent directly from the WooCommerce order screen',
        'Analytics dashboard with revenue charts and transaction log',
        'Automatic plugin updates via the license server',
        '1-year license — number of sites set by your plan tier',
    ];
    ob_start();
    echo $block_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
    <div class="ozp-product-features">
        <ul class="ozp-product-features-list">
            <?php foreach ( $features as $feature ) : ?>
                <li><?php echo esc_html( $feature ); ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="ozp-product-guarantee">30-day money-back guarantee</p>
    </div>
    <?php
    return ob_get_clean();
} );

// Support page: redirect guests to login, then show form for logged-in users.
add_action( 'template_redirect', function (): void {
    if ( is_page( 'support' ) && ! is_user_logged_in() ) {
        wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) . '?redirect_to=' . rawurlencode( home_url( '/support/' ) ) );
        exit;
    }
} );

add_filter( 'the_content', function ( string $content ): string {
    if ( ! is_page( 'support' ) ) {
        return $content;
    }
    if ( ! is_user_logged_in() ) {
        return $content;
    }
    ob_start();
    ?>
    <div class="ozp-page-form-wrap">
        <h2 class="ozp-form-heading">Submit a support ticket</h2>
        <p class="ozp-form-subtext">Choose the licensed site this ticket relates to so we can assist you faster.</p>
        <form id="ozp-support-form" class="ozp-form" novalidate>
            <?php
            $current_user  = wp_get_current_user();
            $user_email    = esc_attr( $current_user->user_email ?? '' );
            $user_name     = esc_attr( trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name );
            $site_domains  = ozp_get_current_user_licensed_domains( $current_user->ID );
            ?>
            <div class="ozp-form-row ozp-form-row--2">
                <label class="ozp-form-field">
                    <span>Email <span class="ozp-req">*</span></span>
                    <input type="email" name="email" required readonly value="<?php echo $user_email; ?>">
                </label>
                <label class="ozp-form-field">
                    <span>Name <span class="ozp-req">*</span></span>
                    <input type="text" name="name" required readonly value="<?php echo $user_name; ?>">
                </label>
            </div>
            <label class="ozp-form-field">
                <span>Site URL <span class="ozp-req">*</span></span>
                <?php if ( $site_domains ) : ?>
                <select name="site_url" required>
                    <option value="" disabled selected>Select the site this ticket relates to&hellip;</option>
                    <?php foreach ( $site_domains as $domain ) :
                        $url = preg_match( '#^https?://#i', $domain ) ? $domain : 'https://' . $domain;
                        ?>
                        <option value="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $domain ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else : ?>
                <input type="url" name="site_url" required placeholder="https://yoursite.com">
                <?php endif; ?>
            </label>
            <label class="ozp-form-field">
                <span>Subject <span class="ozp-req">*</span></span>
                <input type="text" name="subject" required placeholder="Brief description of your issue">
            </label>
            <label class="ozp-form-field">
                <span>Message <span class="ozp-req">*</span></span>
                <textarea name="message" required rows="6" placeholder="Describe the issue in detail. Include any error messages you see."></textarea>
            </label>
            <div class="ozp-form-notice" aria-live="polite"></div>
            <?php if ( defined( 'OZP_TURNSTILE_SITE_KEY' ) ) : ?>
            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( OZP_TURNSTILE_SITE_KEY ); ?>" data-execution="explicit"></div>
            <?php endif; ?>
            <button type="submit" class="ozp-btn ozp-btn-primary">Submit ticket</button>
        </form>
    </div>
    <?php
    return ob_get_clean() . $content;
} );

// Distinct active site domains across every license belonging to a WP user,
// via the OzLS license server plugin's own repositories (read-only).
function ozp_get_current_user_licensed_domains( int $wp_user_id ): array {
    if ( ! $wp_user_id || ! class_exists( '\OzLS\Repository\CustomerRepository' ) || ! class_exists( '\OzLS\Repository\LicenseRepository' ) ) {
        return [];
    }

    $customer = ( new \OzLS\Repository\CustomerRepository() )->find_by_wp_user( $wp_user_id );
    if ( ! $customer ) {
        return [];
    }

    $licenses = ( new \OzLS\Repository\LicenseRepository() )->list( [
        'customer_id' => $customer->id,
        'per_page'    => 100,
    ] );

    $domains = [];
    foreach ( $licenses['data'] as $license ) {
        if ( empty( $license->active_domains ) ) {
            continue;
        }
        foreach ( explode( ', ', $license->active_domains ) as $domain ) {
            $domain = trim( $domain );
            if ( '' !== $domain ) {
                $domains[ $domain ] = true;
            }
        }
    }

    return array_keys( $domains );
}

// ── Cart / Checkout progress breadcrumb ─────────────────────────────────────

function ozp_checkout_steps( string $active ): void {
    $cart_url     = wc_get_cart_url();
    $checkout_url = wc_get_checkout_url();
    $steps = [
        'cart'     => [ 'label' => 'Cart',           'url' => $cart_url ],
        'checkout' => [ 'label' => 'Checkout',        'url' => $checkout_url ],
        'complete' => [ 'label' => 'Order Complete',  'url' => '' ],
    ];
    $order = array_keys( $steps );
    $active_idx = array_search( $active, $order, true );
    echo '<nav class="ozp-checkout-steps" aria-label="Checkout progress"><ol class="ozp-steps">';
    $i = 0;
    foreach ( $steps as $key => $step ) {
        $i++;
        $idx = array_search( $key, $order, true );
        if ( $idx < $active_idx ) {
            $badge = '<span class="ozp-step-badge ozp-step-badge--done" aria-hidden="true">&#10003;</span>';
            $class = 'ozp-step ozp-step--done';
            $inner = '<a href="' . esc_url( $step['url'] ) . '">' . $badge . esc_html( $step['label'] ) . '</a>';
        } else {
            $badge = '<span class="ozp-step-badge" aria-hidden="true">' . (int) $i . '</span>';
            if ( $key === $active ) {
                $class = 'ozp-step ozp-step--active';
                $inner = '<span>' . $badge . esc_html( $step['label'] ) . '</span>';
            } else {
                $class = 'ozp-step ozp-step--pending';
                $inner = '<span>' . $badge . esc_html( $step['label'] ) . '</span>';
            }
        }
        echo '<li class="' . $class . '">' . $inner . '</li>';
    }
    echo '</ol></nav>';
}

// Cart page — steps above the cart table.
add_action( 'woocommerce_before_cart', function (): void {
    ozp_checkout_steps( 'cart' );
} );

// Checkout page — steps above the form; remove default notice boxes;
// replace with minimal single-line coupon toggle.
add_action( 'woocommerce_before_checkout_form', function (): void {
    ozp_checkout_steps( 'checkout' );
    ozp_checkout_powered_by();
}, 5 );
// Guest checkout is disabled site-wide, so every order either uses an
// existing account or creates one automatically — tell first-time buyers
// that up front, before they hit the "Place order" button.
add_action( 'woocommerce_before_checkout_form', function (): void {
    if ( is_user_logged_in() ) {
        return;
    }
    ?>
    <p class="ozp-account-notice">
        <?php esc_html_e( "An account will be created for you automatically after purchase, and your login details will be emailed to you.", 'ozupay' ); ?>
    </p>
    <?php
}, 6 );
remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
add_action( 'woocommerce_before_checkout_form', function (): void {
    if ( ! wc_coupons_enabled() ) {
        return;
    }
    ?>
    <p class="ozp-coupon-toggle">
        <?php esc_html_e( 'Have a coupon?', 'ozupay' ); ?>
        <a href="#" class="showcoupon" aria-controls="woocommerce-checkout-form-coupon" aria-expanded="false"><?php esc_html_e( 'Click here to enter your code', 'ozupay' ); ?></a>
    </p>
    <form class="checkout_coupon woocommerce-form-coupon" method="post" style="display:none" id="woocommerce-checkout-form-coupon">
        <p class="form-row form-row-first">
            <label for="coupon_code" class="screen-reader-text"><?php esc_html_e( 'Coupon:', 'ozupay' ); ?></label>
            <input type="text" name="coupon_code" class="input-text" placeholder="<?php esc_attr_e( 'Coupon code', 'ozupay' ); ?>" id="coupon_code" value="" />
        </p>
        <p class="form-row form-row-last">
            <button type="submit" class="button" name="apply_coupon" value="<?php esc_attr_e( 'Apply coupon', 'ozupay' ); ?>"><?php esc_html_e( 'Apply coupon', 'ozupay' ); ?></button>
        </p>
        <div class="clear"></div>
    </form>
    <?php
}, 10 );

// ── Order received page — intro block (steps + account notice) ─────────────
//
// This site's order-received page is the WooCommerce *block* order
// confirmation template, not the classic thankyou.php. On that template every
// legacy `woocommerce_thankyou` callback is rendered by the
// order-confirmation-additional-information block, which is the LAST block on
// the page — so anything hooked there lands underneath the order totals, the
// M-Pesa payment details and the pay-balance card, no matter what priority it
// uses. That is how the progress breadcrumb and the account notice ended up at
// the very bottom of the page.
//
// templates/order-confirmation.html therefore calls this shortcode at the top
// of the page instead. The `woocommerce_thankyou` registrations further below
// are kept as a fallback for the classic template and skip themselves when the
// shortcode has already rendered, so nothing is ever output twice.

/**
 * Whether the order-received intro block has already been rendered on this
 * request. Call with `true` to mark it as rendered.
 */
function ozp_order_intro_rendered( bool $mark = false ): bool {
    static $rendered = false;
    if ( $mark ) {
        $rendered = true;
    }
    return $rendered;
}

/**
 * The order being viewed on the order-received page, but only for a viewer
 * allowed to see it. This is the same gate WooCommerce's classic thank-you
 * template applies: the order key in the URL is the credential, or the
 * logged-in customer owns the order.
 */
function ozp_order_received_order(): ?WC_Order {
    if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
        return null;
    }
    $order_id = absint( get_query_var( 'order-received' ) );
    if ( ! $order_id ) {
        return null;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return null;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display gate on a link the customer follows from their own order.
    $key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
    if ( '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
        return $order;
    }
    if ( is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id() ) {
        return $order;
    }
    return null;
}

add_shortcode( 'ozp_order_confirmation_intro', function (): string {
    $order = ozp_order_received_order();
    ozp_order_intro_rendered( true );

    ob_start();
    ozp_checkout_steps( 'complete' );
    if ( $order ) {
        ozp_order_account_notice( $order );
    }
    return (string) ob_get_clean();
} );

// Classic thank-you template fallback — no-op once the shortcode above has run.
add_action( 'woocommerce_thankyou', function (): void {
    if ( ozp_order_intro_rendered() ) {
        return;
    }
    ozp_checkout_steps( 'complete' );
}, 1 );

// Trust line below Place Order button.
add_action( 'woocommerce_review_order_after_submit', function (): void {
    ?>
    <p class="ozp-trust-line">
        <span>
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M8 1a5 5 0 0 0-5 5v1H2a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1h-1V6a5 5 0 0 0-5-5zm3 6V6a3 3 0 1 0-6 0v1h6z" fill="currentColor"/></svg>
            256-bit SSL encrypted
        </span>
        <span>
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 0c-.69 0-1.843.265-2.928.56-1.11.3-2.229.655-2.887.87a1.54 1.54 0 0 0-1.044 1.262c-.596 4.477.787 7.795 2.465 9.99a11.777 11.777 0 0 0 2.517 2.453c.386.273.744.482 1.048.625.28.132.581.24.829.24s.548-.108.829-.24a7.159 7.159 0 0 0 1.048-.625 11.775 11.775 0 0 0 2.517-2.453c1.678-2.195 3.061-5.513 2.465-9.99a1.541 1.541 0 0 0-1.044-1.263 62.467 62.467 0 0 0-2.887-.87C9.843.266 8.69 0 8 0z" fill="currentColor"/></svg>
            Money-back guarantee
        </span>
    </p>
    <?php
} );

// "View cart" button on the single product page.
//
// Strategy: always render the anchor with a product-specific class (.ozp-vcb-{id}).
// On the initial PHP render we show/hide it based on live cart state.
// WooCommerce's fragment refresh AJAX (runs on every page load, bypasses the page cache)
// calls woocommerce_add_to_cart_fragments, which returns the correct visible/hidden HTML,
// and WC JS swaps it in — so a cached page still reflects the real cart state.
add_action( 'woocommerce_after_add_to_cart_button', function (): void {
    global $product;
    if ( ! $product ) {
        return;
    }
    $pid     = (int) $product->get_id();
    $in_cart = false;
    if ( function_exists( 'WC' ) && WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( (int) $item['product_id'] === $pid ) {
                $in_cart = true;
                break;
            }
        }
    }
    printf(
        '<a href="%s" class="button ozp-view-cart-btn ozp-vcb-%d"%s>%s</a>',
        esc_url( wc_get_cart_url() ),
        $pid,
        $in_cart ? '' : ' style="display:none"',
        esc_html__( 'View cart', 'ozupay' )
    );
} );

// WC calls this filter on its fragment-refresh AJAX request (bypasses the page cache).
// Return the live View Cart button HTML for every product currently in the cart.
add_filter( 'woocommerce_add_to_cart_fragments', function ( array $fragments ): array {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        return $fragments;
    }
    $cart_url = wc_get_cart_url();
    $label    = esc_html__( 'View cart', 'ozupay' );
    foreach ( WC()->cart->get_cart() as $item ) {
        $pid                          = (int) $item['product_id'];
        $fragments[ '.ozp-vcb-' . $pid ] = sprintf(
            '<a href="%s" class="button ozp-view-cart-btn ozp-vcb-%d">%s</a>',
            esc_url( $cart_url ),
            $pid,
            $label
        );
    }
    return $fragments;
} );

// Add body class for contact page (used for overflow-x: hidden rule in CSS).
add_filter( 'body_class', function ( array $classes ): array {
    if ( is_page( 'contact' ) ) {
        $classes[] = 'ozp-contact-page';
    }
    return $classes;
} );

// On the contact page, strip the template's inline padding from the outer page-content
// wrapper and suppress the core/post-title block group — the hero replaces both.
add_filter( 'render_block', function ( string $block_content, array $block ): string {
    if ( ! is_page( 'contact' ) ) {
        return $block_content;
    }
    // Drop the group that wraps the core/post-title (it has a bottom border + margin).
    if ( 'core/group' === $block['blockName'] ) {
        if ( isset( $block['innerBlocks'] ) ) {
            foreach ( $block['innerBlocks'] as $inner ) {
                if ( 'core/post-title' === $inner['blockName'] ) {
                    return '';
                }
            }
        }
    }
    // Remove inline padding-top / padding-bottom from the outer alignfull page wrapper.
    if ( 'core/group' === $block['blockName']
        && isset( $block['attrs']['align'] ) && 'full' === $block['attrs']['align'] ) {
        $block_content = preg_replace( '/\bpadding-top:[^;";]+;?\s*/i', '', $block_content );
        $block_content = preg_replace( '/\bpadding-bottom:[^;";]+;?\s*/i', '', $block_content );
    }
    return $block_content;
}, 10, 2 );

// ── Contact page: redesigned tabbed layout matching the design mockup.
add_filter( 'the_content', function ( string $content ): string {
    if ( ! is_page( 'contact' ) ) {
        return $content;
    }

    $faqs_getting_started = [
        [ 'q' => 'How does the M-Pesa integration work?',    'a' => 'OzuPay connects your WooCommerce store directly to Safaricom\'s Daraja API. At checkout the customer receives an STK Push prompt on their phone and confirms with their M-Pesa PIN — the order is marked paid automatically when the Daraja callback arrives.' ],
        [ 'q' => 'Do I need a Safaricom Daraja account?',    'a' => 'Yes. You\'ll need your own Daraja app credentials (Consumer Key, Consumer Secret and Passkey) plus a Paybill or Till number. OzuPay guides you through setup, and you can test against the Daraja sandbox before going live.' ],
        [ 'q' => 'Is there a sandbox / test mode?',          'a' => 'Yes — the plugin ships with Daraja sandbox support. Switch between sandbox and production from the plugin settings. Note that Safaricom\'s sandbox still sends a real STK Push to the phone you test with, and the amount can be deducted before being reversed automatically a short time later — it is not a fully simulated flow, so use a test line you control.' ],
    ];

    $faqs_payments = [
        [ 'q' => 'What payment methods does OzuPay support?', 'a' => 'OzuPay supports M-Pesa STK Push, C2B Paybill, and Buy Goods / Till. Pro also adds M-Pesa on Delivery (deposit at checkout, balance on delivery) and B2C refunds sent directly from the WooCommerce order screen.' ],
        [ 'q' => 'What is M-Pesa on Delivery?',               'a' => 'M-Pesa on Delivery collects a configurable deposit via STK Push at checkout. The remaining balance is charged when the order is confirmed on delivery — no manual follow-up, no chasing the customer.' ],
        [ 'q' => 'How are payments confirmed automatically?', 'a' => 'Safaricom sends a confirmation callback to your site after the customer completes the STK Push. OzuPay receives that callback and updates the WooCommerce order status — no admin action needed.' ],
    ];

    $faqs_plans = [
        [ 'q' => 'What is the difference between Free and Pro?', 'a' => 'Free covers STK Push, Paybill, and Till and is available on WordPress.org. Pro adds M-Pesa on Delivery, B2C refunds, analytics, webhooks, QR codes, payment links, and automatic plugin updates. All Pro features are included on every paid plan — plans differ only by the number of site licences.' ],
        [ 'q' => 'Why is Pro billed annually?',                  'a' => 'Safaricom updates the Daraja API without notice. An active licence funds ongoing compatibility work and keeps updates flowing to your WordPress dashboard automatically. A lapsed licence won\'t break your store, but you stop receiving patches until you renew.' ],
        [ 'q' => 'Can I upgrade from Free to Pro?',              'a' => 'Yes. Install the Pro plugin, enter your licence key in the plugin settings, and Pro features are enabled immediately. All existing credentials and settings are retained automatically.' ],
    ];

    $user_email  = '';
    $user_name   = '';
    if ( is_user_logged_in() ) {
        $current_user = wp_get_current_user();
        $user_email   = esc_attr( $current_user->user_email ?? '' );
        $user_name    = esc_attr( trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name );
    }
    $turnstile_key = defined( 'OZP_TURNSTILE_SITE_KEY' ) ? OZP_TURNSTILE_SITE_KEY : '';

    ob_start();
    ?>
    <!-- Hero -->
    <div class="ozp-ch-bleed ozp-ch-hero">
        <div class="ozp-ch-hero-badge">
            <span class="ozp-ch-pulse"></span>
            Support &amp; Help Center
        </div>
        <h1 class="ozp-ch-hero-title">How can we help you?</h1>
        <p class="ozp-ch-hero-sub">Contact support, submit a feature idea, or browse our FAQ.</p>
    </div>

    <!-- Sticky tab bar -->
    <div class="ozp-ch-bleed ozp-ch-tabbar" id="ozp-contact-tabs">
        <div class="ozp-ch-tabbar-inner">
            <button class="ozp-tab active" role="tab" aria-selected="true"  aria-controls="ozp-tab-support" id="ozp-tabBtn-support">
                <svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true"><rect x="1" y="2.5" width="13" height="10" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M1 5l6.5 4.5L14 5" stroke="currentColor" stroke-width="1.4"/></svg>
                Contact Us
            </button>
            <button class="ozp-tab" role="tab" aria-selected="false" aria-controls="ozp-tab-ticket" id="ozp-tabBtn-ticket">
                <svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M1.5 5.5a1 1 0 011-1h9a1 1 0 011 1v1a1 1 0 000 2v1a1 1 0 01-1 1h-9a1 1 0 01-1-1v-1a1 1 0 000-2v-1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                Submit a Ticket
            </button>
            <button class="ozp-tab" role="tab" aria-selected="false" aria-controls="ozp-tab-fr" id="ozp-tabBtn-fr">
                <svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M7.5 1.5l1.68 4H13.5L10.17 8.05l1.25 4.45L7.5 9.95 3.58 12.5l1.25-4.45L1.5 5.5h4.32L7.5 1.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                Feature Request
            </button>
            <button class="ozp-tab" role="tab" aria-selected="false" aria-controls="ozp-tab-faq" id="ozp-tabBtn-faq">
                <svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true"><circle cx="7.5" cy="7.5" r="6" stroke="currentColor" stroke-width="1.4"/><path d="M5.8 5.8c0-.94.76-1.7 1.7-1.7s1.7.76 1.7 1.7c0 .63-.34 1.18-.85 1.47-.38.22-.55.55-.55.93" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="7.5" cy="10.8" r="0.75" fill="currentColor"/></svg>
                FAQ
            </button>
        </div>
    </div>

    <!-- Panels -->
    <div class="ozp-ch-bleed ozp-ch-body">
        <div class="ozp-ch-content">

            <!-- ── Contact Us ── -->
            <div class="ozp-tab-panel active" id="ozp-tab-support" role="tabpanel" aria-labelledby="ozp-tabBtn-support">
                <?php if ( is_user_logged_in() ) : ?>
                <div class="ozp-ch-card">
                    <div class="ozp-ch-card-hd">
                        <h2>Send us a message</h2>
                        <p>We respond to every message — usually within a few hours.</p>
                    </div>
                    <form id="ozp-support-form" class="ozp-ch-card-body" novalidate>
                        <div class="ozp-ch-grid">
                            <div class="ozp-ch-field">
                                <label for="ozp-ct-name">Name</label>
                                <input type="text" id="ozp-ct-name" name="name" class="ozp-ch-input"
                                    placeholder="Your full name" value="<?php echo $user_name; ?>">
                            </div>
                            <div class="ozp-ch-field">
                                <label for="ozp-ct-email">Email <span class="ozp-req">*</span></label>
                                <input type="email" id="ozp-ct-email" name="email" class="ozp-ch-input"
                                    placeholder="you@example.com" required value="<?php echo $user_email; ?>">
                            </div>
                        </div>
                        <div class="ozp-ch-field">
                            <label for="ozp-ct-topic">Topic <span class="ozp-req">*</span></label>
                            <select id="ozp-ct-topic" name="subject" class="ozp-ch-select" required>
                                <option value="" disabled selected>Choose a topic&hellip;</option>
                                <option>M-Pesa integration help</option>
                                <option>Billing &amp; plans</option>
                                <option>Account issue</option>
                                <option>Bug report</option>
                                <option>Plugin update</option>
                                <option>Other</option>
                            </select>
                        </div>
                        <div class="ozp-ch-field">
                            <label for="ozp-ct-msg">Message <span class="ozp-req">*</span></label>
                            <textarea id="ozp-ct-msg" name="message" class="ozp-ch-textarea"
                                rows="5" placeholder="Describe your issue or question in detail&hellip;" required></textarea>
                        </div>
                        <div class="ozp-form-notice ozp-ch-notice" aria-live="polite"></div>
                        <?php if ( $turnstile_key ) : ?>
                        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_key ); ?>" data-execution="explicit"></div>
                        <?php endif; ?>
                        <div class="ozp-ch-submit-row">
                            <button type="submit" class="ozp-ch-submit" data-text="Send Message">
                                <span class="ozp-ch-btn-lbl">Send Message</span>
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 8h12M10 4l4 4-4 4" stroke="#0d1b2a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <small>We never share your info with third parties.</small>
                        </div>
                    </form>
                </div>
                <div class="ozp-ch-info-grid">
                    <div class="ozp-ch-info-card">
                        <div class="ozp-ch-info-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="1.5" y="4" width="17" height="12" rx="2" stroke="#22c55e" stroke-width="1.4"/><path d="M1.5 7L10 12.5 18.5 7" stroke="#22c55e" stroke-width="1.4"/></svg>
                        </div>
                        <div>
                            <p>Email support</p>
                            <p><a href="mailto:support@ozulabs.com">support@ozulabs.com</a></p>
                        </div>
                    </div>
                    <div class="ozp-ch-info-card">
                        <div class="ozp-ch-info-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="8" stroke="#22c55e" stroke-width="1.4"/><path d="M10 6v4.5l3.5 2" stroke="#22c55e" stroke-width="1.4" stroke-linecap="round"/></svg>
                        </div>
                        <div>
                            <p>Response time</p>
                            <p>Usually within a few hours</p>
                        </div>
                    </div>
                </div>
                <?php else : ?>
                <div class="ozp-ch-card">
                    <div class="ozp-ch-card-hd">
                        <h2>Send us a message</h2>
                        <p>Pre-sales questions, partnership enquiries, or anything else — we read every message.</p>
                    </div>
                    <form id="ozp-contact-guest-form" class="ozp-ch-card-body" novalidate>
                        <div class="ozp-ch-grid">
                            <div class="ozp-ch-field">
                                <label for="ozp-g-name">Name</label>
                                <input type="text" id="ozp-g-name" name="name" class="ozp-ch-input"
                                    placeholder="Your full name">
                            </div>
                            <div class="ozp-ch-field">
                                <label for="ozp-g-email">Email <span class="ozp-req">*</span></label>
                                <input type="email" id="ozp-g-email" name="email" class="ozp-ch-input"
                                    placeholder="you@example.com" required>
                            </div>
                        </div>
                        <div class="ozp-ch-field">
                            <label for="ozp-g-topic">Topic <span class="ozp-req">*</span></label>
                            <select id="ozp-g-topic" name="subject" class="ozp-ch-select" required>
                                <option value="" disabled selected>Choose a topic&hellip;</option>
                                <option>Pre-sales question</option>
                                <option>Partnership / integration</option>
                                <option>Technical question</option>
                                <option>Billing &amp; plans</option>
                                <option>Other</option>
                            </select>
                        </div>
                        <div class="ozp-ch-field">
                            <label for="ozp-g-msg">Message <span class="ozp-req">*</span></label>
                            <textarea id="ozp-g-msg" name="message" class="ozp-ch-textarea"
                                rows="5" placeholder="What would you like to know?" required></textarea>
                        </div>
                        <div class="ozp-form-notice ozp-ch-notice" aria-live="polite"></div>
                        <?php if ( $turnstile_key ) : ?>
                        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_key ); ?>" data-execution="explicit"></div>
                        <?php endif; ?>
                        <div class="ozp-ch-submit-row">
                            <button type="submit" class="ozp-ch-submit" data-text="Send Message">
                                <span class="ozp-ch-btn-lbl">Send Message</span>
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 8h12M10 4l4 4-4 4" stroke="#0d1b2a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <small>We never share your info with third parties.</small>
                        </div>
                    </form>
                </div>
                <div class="ozp-ch-info-grid">
                    <div class="ozp-ch-info-card">
                        <div class="ozp-ch-info-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="1.5" y="4" width="17" height="12" rx="2" stroke="#22c55e" stroke-width="1.4"/><path d="M1.5 7L10 12.5 18.5 7" stroke="#22c55e" stroke-width="1.4"/></svg>
                        </div>
                        <div>
                            <p>Email support</p>
                            <p><a href="mailto:support@ozulabs.com">support@ozulabs.com</a></p>
                        </div>
                    </div>
                    <div class="ozp-ch-info-card">
                        <div class="ozp-ch-info-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="8" stroke="#22c55e" stroke-width="1.4"/><path d="M10 6v4.5l3.5 2" stroke="#22c55e" stroke-width="1.4" stroke-linecap="round"/></svg>
                        </div>
                        <div>
                            <p>Response time</p>
                            <p>Usually within a few hours</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Submit a Ticket ── -->
            <div class="ozp-tab-panel" id="ozp-tab-ticket" role="tabpanel" aria-labelledby="ozp-tabBtn-ticket" hidden>
                <?php if ( is_user_logged_in() ) :
                    $ticket_site_domains = ozp_get_current_user_licensed_domains( $current_user->ID );
                    ?>
                <div class="ozp-ch-card">
                    <div class="ozp-ch-card-hd">
                        <h2>Submit a support ticket</h2>
                        <p>Choose the licensed site this ticket relates to so we can assist you faster.</p>
                    </div>
                    <form id="ozp-ticket-form" class="ozp-ch-card-body" novalidate>
                        <div class="ozp-ch-grid">
                            <div class="ozp-ch-field">
                                <label for="ozp-tk-name">Name <span class="ozp-req">*</span></label>
                                <input type="text" id="ozp-tk-name" name="name" class="ozp-ch-input"
                                    required readonly value="<?php echo $user_name; ?>">
                            </div>
                            <div class="ozp-ch-field">
                                <label for="ozp-tk-email">Email <span class="ozp-req">*</span></label>
                                <input type="email" id="ozp-tk-email" name="email" class="ozp-ch-input"
                                    required readonly value="<?php echo $user_email; ?>">
                            </div>
                        </div>
                        <div class="ozp-ch-field">
                            <label for="ozp-tk-site">Site URL <span class="ozp-req">*</span></label>
                            <?php if ( $ticket_site_domains ) : ?>
                            <select id="ozp-tk-site" name="site_url" class="ozp-ch-select" required>
                                <option value="" disabled selected>Select the site this ticket relates to&hellip;</option>
                                <?php foreach ( $ticket_site_domains as $domain ) :
                                    $url = preg_match( '#^https?://#i', $domain ) ? $domain : 'https://' . $domain;
                                    ?>
                                    <option value="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $domain ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else : ?>
                            <input type="url" id="ozp-tk-site" name="site_url" class="ozp-ch-input"
                                required placeholder="https://yoursite.com">
                            <?php endif; ?>
                        </div>
                        <div class="ozp-ch-field">
                            <label for="ozp-tk-subject">Subject <span class="ozp-req">*</span></label>
                            <input type="text" id="ozp-tk-subject" name="subject" class="ozp-ch-input"
                                required placeholder="Brief description of your issue">
                        </div>
                        <div class="ozp-ch-field">
                            <label for="ozp-tk-msg">Message <span class="ozp-req">*</span></label>
                            <textarea id="ozp-tk-msg" name="message" class="ozp-ch-textarea"
                                rows="5" required placeholder="Describe the issue in detail. Include any error messages you see."></textarea>
                        </div>
                        <div class="ozp-form-notice ozp-ch-notice" aria-live="polite"></div>
                        <?php if ( $turnstile_key ) : ?>
                        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_key ); ?>" data-execution="explicit"></div>
                        <?php endif; ?>
                        <div class="ozp-ch-submit-row">
                            <button type="submit" class="ozp-ch-submit" data-text="Submit Ticket">
                                <span class="ozp-ch-btn-lbl">Submit Ticket</span>
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 8h12M10 4l4 4-4 4" stroke="#0d1b2a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <small>We never share your info with third parties.</small>
                        </div>
                    </form>
                </div>
                <?php else : ?>
                <div class="ozp-ch-card">
                    <div class="ozp-ch-card-hd">
                        <h2>Submit a support ticket</h2>
                        <p>Log in to your account to submit a ticket for one of your licensed sites.</p>
                    </div>
                    <div class="ozp-ch-card-body">
                        <a class="ozp-ch-submit" style="display:inline-flex;width:fit-content;text-decoration:none;"
                            href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) . '?redirect_to=' . rawurlencode( home_url( '/contact/#ticket' ) ) ); ?>">
                            Log in to continue
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Feature Request ── -->
            <div class="ozp-tab-panel" id="ozp-tab-fr" role="tabpanel" aria-labelledby="ozp-tabBtn-fr" hidden>
                <div class="ozp-ch-card">
                    <div class="ozp-ch-card-hd">
                        <div class="ozp-ch-badge">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M6 1L7.4 4.3H11L8.3 6.3l1 3.7L6 7.9 2.7 10l1-3.7L1 4.3h3.6L6 1z" fill="#d97706"/></svg>
                            Shape the product
                        </div>
                        <h2>Request a Feature</h2>
                        <p>Have an idea? Tell us — the product team reviews every request.</p>
                    </div>
                    <form id="ozp-fr-form" class="ozp-ch-card-body" novalidate>
                        <div class="ozp-ch-field">
                            <label for="ozp-fr-title">Feature title <span class="ozp-req">*</span></label>
                            <input type="text" id="ozp-fr-title" name="title" class="ozp-ch-input"
                                placeholder="e.g. Bulk M-Pesa payment disbursements" required>
                        </div>
                        <div class="ozp-ch-field">
                            <label for="ozp-fr-desc">Describe the problem it solves <span class="ozp-req">*</span></label>
                            <textarea id="ozp-fr-desc" name="description" class="ozp-ch-textarea"
                                rows="4" placeholder="What are you trying to accomplish, and what&#39;s getting in the way?" required></textarea>
                        </div>
                        <div class="ozp-ch-field">
                            <label for="ozp-fr-email">Your email <small style="font-weight:400;color:#94a3b8;">(optional — so we can follow up)</small></label>
                            <input type="email" id="ozp-fr-email" name="email" class="ozp-ch-input"
                                placeholder="you@example.com">
                        </div>
                        <div class="ozp-form-notice ozp-ch-notice" aria-live="polite"></div>
                        <?php if ( $turnstile_key ) : ?>
                        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_key ); ?>" data-execution="explicit"></div>
                        <?php endif; ?>
                        <button type="submit" class="ozp-ch-submit" style="align-self:flex-start;" data-text="Submit Request">
                            <span class="ozp-ch-btn-lbl">Submit Request</span>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 8h12M10 4l4 4-4 4" stroke="#0d1b2a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </form>
                </div>
            </div>

            <!-- ── FAQ ── -->
            <div class="ozp-tab-panel" id="ozp-tab-faq" role="tabpanel" aria-labelledby="ozp-tabBtn-faq" hidden>

                <p class="ozp-ch-faq-label">Getting Started</p>
                <div class="ozp-ch-faq-group">
                    <?php foreach ( $faqs_getting_started as $faq ) : ?>
                    <details class="ozp-ch-faq-item">
                        <summary>
                            <span><?php echo esc_html( $faq['q'] ); ?></span>
                            <svg class="ozp-ch-faq-chevron" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M4.5 7l4.5 4.5L13.5 7" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="ozp-ch-faq-body"><?php echo wp_kses_post( $faq['a'] ); ?></div>
                    </details>
                    <?php endforeach; ?>
                </div>

                <p class="ozp-ch-faq-label">Payments</p>
                <div class="ozp-ch-faq-group">
                    <?php foreach ( $faqs_payments as $faq ) : ?>
                    <details class="ozp-ch-faq-item">
                        <summary>
                            <span><?php echo esc_html( $faq['q'] ); ?></span>
                            <svg class="ozp-ch-faq-chevron" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M4.5 7l4.5 4.5L13.5 7" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="ozp-ch-faq-body"><?php echo wp_kses_post( $faq['a'] ); ?></div>
                    </details>
                    <?php endforeach; ?>
                </div>

                <p class="ozp-ch-faq-label">Plans &amp; Pricing</p>
                <div class="ozp-ch-faq-group">
                    <?php foreach ( $faqs_plans as $faq ) : ?>
                    <details class="ozp-ch-faq-item">
                        <summary>
                            <span><?php echo esc_html( $faq['q'] ); ?></span>
                            <svg class="ozp-ch-faq-chevron" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M4.5 7l4.5 4.5L13.5 7" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="ozp-ch-faq-body"><?php echo wp_kses_post( $faq['a'] ); ?></div>
                    </details>
                    <?php endforeach; ?>
                </div>

                <div class="ozp-ch-faq-cta">
                    <p>Still have a question?</p>
                    <p>The team is happy to help — just send us a message.</p>
                    <button type="button" class="ozp-ch-cta-btn"
                        onclick="document.getElementById('ozp-tabBtn-support').click();window.scrollTo({top:0,behavior:'smooth'});">
                        Contact Support
                        <svg width="15" height="15" viewBox="0 0 15 15" fill="none" aria-hidden="true"><path d="M1.5 7.5h12M9 3.5l4 4-4 4" stroke="#0d1b2a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>

            </div>
        </div>
    </div>
    <script>
    (function(){
        // Strip residual white background and block-gap margins from WP wrappers.
        (function() {
            // Outer alignfull group — set transparent so it doesn't show through.
            var outer = document.querySelector('.wp-block-group.alignfull');
            if (outer) { outer.style.backgroundColor = 'transparent'; outer.style.padding = '0'; }
            // Entry-content and its direct children — zero out IS-LAYOUT-CONSTRAINED margins.
            var ec = document.querySelector('.entry-content');
            if (ec) {
                ec.style.marginTop = '0';
                ec.style.marginBlockStart = '0';
                Array.prototype.forEach.call(ec.children, function(el) {
                    el.style.marginTop = '0';
                    el.style.marginBlockStart = '0';
                });
            }
        })();

        // Full-bleed: measure parent column left offset and apply correct width/position.
        function ozpApplyBleed() {
            var els = document.querySelectorAll('.ozp-ch-bleed');
            if (!els.length) return;
            var ref  = els[0].parentElement || document.body;
            var rect = ref.getBoundingClientRect();
            var vw   = window.innerWidth;
            els.forEach(function(el) {
                el.style.maxWidth = 'none';
                el.style.width = vw + 'px';
                // position:sticky ignores `left` as a layout offset (it's a stick threshold).
                // transform: translateX() shifts the visual position without affecting layout,
                // correctly breaking the sticky bar out of the content column.
                var pos = window.getComputedStyle(el).position;
                if (pos === 'sticky' || pos === '-webkit-sticky') {
                    el.style.transform = 'translateX(' + (-rect.left) + 'px)';
                } else {
                    el.style.left     = (-rect.left) + 'px';
                    el.style.position = 'relative';
                }
            });
        }
        ozpApplyBleed();
        window.addEventListener('resize', ozpApplyBleed);

        var tabs   = document.querySelectorAll('#ozp-contact-tabs .ozp-tab');
        var panels = document.querySelectorAll('.ozp-ch-body .ozp-tab-panel');
        tabs.forEach(function(tab){
            tab.addEventListener('click', function(){
                var target = tab.getAttribute('aria-controls');
                tabs.forEach(function(t){ t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
                panels.forEach(function(p){ p.classList.remove('active'); p.hidden = true; });
                tab.classList.add('active');
                tab.setAttribute('aria-selected','true');
                var panel = document.getElementById(target);
                if(panel){ panel.classList.add('active'); panel.hidden = false; }
            });
        });
        var hash = window.location.hash;
        if(hash === '#faq' && document.getElementById('ozp-tabBtn-faq')) document.getElementById('ozp-tabBtn-faq').click();
        if(hash === '#feature-request' && document.getElementById('ozp-tabBtn-fr')) document.getElementById('ozp-tabBtn-fr').click();
        if(hash === '#ticket' && document.getElementById('ozp-tabBtn-ticket')) document.getElementById('ozp-tabBtn-ticket').click();

    })();
    </script>
    <?php
    // Our tabbed layout replaces the entire page content — do not append $content.
    return ob_get_clean();
} );

// ── Feature Request page: inject form before post content.
add_filter( 'the_content', function ( string $content ): string {
    if ( ! is_page( 'feature-request' ) && ! is_page( 'feature-requests' ) ) {
        return $content;
    }
    ob_start();
    ?>
    <div class="ozp-page-form-wrap">
        <h2 class="ozp-form-heading">Suggest a feature</h2>
        <p class="ozp-form-subtext">Describe what you would like OzuPay to do. Be as specific as possible.</p>
        <form id="ozp-fr-form" class="ozp-form" novalidate>
            <div class="ozp-form-row ozp-form-row--2">
                <label class="ozp-form-field">
                    <span>Email <span class="ozp-req">*</span></span>
                    <input type="email" name="email" required placeholder="you@example.com">
                </label>
                <label class="ozp-form-field">
                    <span>Name</span>
                    <input type="text" name="name" placeholder="Your name">
                </label>
            </div>
            <label class="ozp-form-field">
                <span>Feature title <span class="ozp-req">*</span></span>
                <input type="text" name="title" required placeholder="Short title for your request">
            </label>
            <label class="ozp-form-field">
                <span>Description <span class="ozp-req">*</span></span>
                <textarea name="description" required rows="6" placeholder="What should it do? What problem does it solve? Are there similar tools that do this?"></textarea>
            </label>
            <div class="ozp-form-notice" aria-live="polite"></div>
            <?php if ( defined( 'OZP_TURNSTILE_SITE_KEY' ) ) : ?>
            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( OZP_TURNSTILE_SITE_KEY ); ?>" data-execution="explicit"></div>
            <?php endif; ?>
            <button type="submit" class="ozp-btn ozp-btn-primary">Submit request</button>
        </form>
    </div>
    <?php
    return ob_get_clean() . $content;
} );

// ── Copy-to-clipboard buttons next to all mailto links ───────────────────────
// Scans the page for <a href="mailto:…"> elements and injects a small icon
// button immediately after each one. Works on every page automatically:
// contact, privacy policy, terms, refund policy, etc.

add_action( 'wp_footer', function (): void {
    ?>
<script>
(function () {
    var copyIcon  = '<svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true"><rect x="4.5" y="4.5" width="8" height="8" rx="1.2" stroke="currentColor" stroke-width="1.3"/><path d="M3 9H2.2A1.2 1.2 0 011 7.8V2.2A1.2 1.2 0 012.2 1h5.6A1.2 1.2 0 019 2.2V3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>';
    var checkIcon = '<svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M2.5 7L5.5 10L11.5 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    document.querySelectorAll('a[href^="mailto:"]').forEach(function (link) {
        var email = decodeURIComponent(link.getAttribute('href').replace(/^mailto:/, ''));
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ozp-copy-email-btn';
        btn.setAttribute('aria-label', 'Copy ' + email);
        btn.setAttribute('title', 'Copy to clipboard');
        btn.innerHTML = copyIcon;

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var finish = function () {
                btn.innerHTML = checkIcon;
                btn.classList.add('copied');
                btn.setAttribute('aria-label', 'Copied!');
                setTimeout(function () {
                    btn.innerHTML = copyIcon;
                    btn.classList.remove('copied');
                    btn.setAttribute('aria-label', 'Copy ' + email);
                }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(email).then(finish).catch(finish);
            } else {
                var ta = document.createElement('textarea');
                ta.value = email;
                ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (err) {}
                document.body.removeChild(ta);
                finish();
            }
        });

        link.insertAdjacentElement('afterend', btn);
    });
})();
</script>
    <?php
} );

// ── Account creation on successful purchase ─────────────────────────────────
// Site-level behaviour, not OzuPay Pro plugin functionality — the plugin
// itself never creates WordPress accounts. M-Pesa payment is confirmed
// asynchronously (STK push callback), but the checkout page polls and only
// redirects to order-received once payment is confirmed (see the plugin's
// Checkout/class-payment-waiting.php), so payment_complete() has always
// already fired by the time woocommerce_thankyou runs below.
add_action( 'woocommerce_payment_complete', function ( $order_id ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $email = $order->get_billing_email();
    if ( ! $email || ! is_email( $email ) ) {
        return;
    }

    $existing = get_user_by( 'email', $email );

    if ( $existing ) {
        // Link the order to the existing account if it was placed as a guest,
        // so it shows up in their My Account order history.
        if ( ! $order->get_customer_id() ) {
            $order->set_customer_id( $existing->ID );
        }
        $order->update_meta_data( '_ozp_account_status', 'existing' );
        $order->save();
        return;
    }

    // Pass an empty password so WooCommerce generates its own — this is what
    // makes it mark the password as auto-generated internally, which is what
    // makes its "New account" email (Settings > Emails) include the
    // set-your-password link. Passing our own password here would suppress
    // that link, leaving the customer with no way to log in.
    //
    // wc_create_new_customer() fires the "New account" email synchronously
    // (via the woocommerce_created_customer action), before we get $user_id
    // back — so this is the only way to hand the order to the customized
    // email template (woocommerce/emails/customer-new-account.php) without
    // waiting on the order/customer link that happens further below.
    $GLOBALS['ozp_new_account_order'] = $order;
    $user_id = wc_create_new_customer( $email, '', '' );
    unset( $GLOBALS['ozp_new_account_order'] );

    if ( is_wp_error( $user_id ) ) {
        error_log( '[OzuPay account] Failed to create account for ' . $email . ' (order #' . $order_id . '): ' . $user_id->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        $order->update_meta_data( '_ozp_account_status', 'failed' );
        $order->save();
        return;
    }

    $order->set_customer_id( $user_id );
    $order->update_meta_data( '_ozp_account_status', 'created' );
    $order->save();
}, 20 );

// Thank-you page notice — tells the customer whether we just emailed them
// new account credentials, or that they already have an account to log into.
//
// This can't rely on the _ozp_account_status meta set by the
// woocommerce_payment_complete hook above: guest checkout is disabled
// site-wide, so WooCommerce creates the account itself during checkout
// (before payment, via WC_Checkout::process_checkout()) independently of
// that hook, which usually finds the account already existing by the time
// payment completes and marks the order 'existing' even on a customer's
// very first purchase. Instead, treat any account registered in roughly
// the same window as this order as "just created for this purchase" — this
// also covers a cancelled-then-retried STK push, where the account was
// created against an earlier, separate order.
// Rendered at the top of the order-received page by the
// [ozp_order_confirmation_intro] shortcode, and from the woocommerce_thankyou
// fallback below on the classic thank-you template.
function ozp_order_account_notice( WC_Order $order ): void {
    $customer_id = $order->get_customer_id();
    if ( ! $customer_id ) {
        return;
    }

    $user = get_userdata( $customer_id );
    if ( ! $user ) {
        return;
    }

    $just_registered = ( time() - strtotime( $user->user_registered . ' UTC' ) ) < 30 * MINUTE_IN_SECONDS;

    // WooCommerce logs the customer straight in when it creates their account
    // during checkout, and a returning customer has to be logged in to reach
    // checkout at all (guest checkout is disabled site-wide) — so on this page
    // the viewer is normally already signed in as the order's customer. Telling
    // them to "log in" in that state is wrong; point them at their licenses
    // instead. Anyone else reaching this page holds the order key rather than
    // the account (an admin, or the link opened in a browser that isn't signed
    // in), where the log-in prompt is still the right thing to show.
    $viewing_own_order = is_user_logged_in() && get_current_user_id() === (int) $customer_id;

    if ( $just_registered ) {
        $message = sprintf(
            /* translators: %s: customer's email address */
            __( 'Your account details have been emailed to you at <strong>%s</strong> — check your spam or junk folder if you don\'t see it within a few minutes.', 'ozupay' ),
            esc_html( $order->get_billing_email() )
        );
    } elseif ( $viewing_own_order ) {
        $message = sprintf(
            /* translators: %s: My Account licenses URL */
            __( 'Your license key and plugin download are in <a href="%s">your account</a>.', 'ozupay' ),
            esc_url( ozp_account_licenses_url() )
        );
    } else {
        $message = sprintf(
            /* translators: %s: My Account page URL */
            __( 'You already have an account — <a href="%s">log in</a> to access your license and download.', 'ozupay' ),
            esc_url( wc_get_page_permalink( 'myaccount' ) )
        );
    }

    printf( '<div class="ozp-order-notice" role="status">%s</div>', wp_kses_post( $message ) );
}

/**
 * My Account → Licenses (the OzuLicense customer portal endpoint), falling back
 * to the My Account root when that plugin isn't active on this install.
 */
function ozp_account_licenses_url(): string {
    if ( class_exists( 'OzLS\Portal\PortalEndpoint' ) && function_exists( 'wc_get_account_endpoint_url' ) ) {
        return wc_get_account_endpoint_url( 'ozls-licenses' );
    }
    return (string) wc_get_page_permalink( 'myaccount' );
}

// Classic thank-you template fallback — no-op once the intro shortcode has run.
add_action( 'woocommerce_thankyou', function ( $order_id ): void {
    if ( ozp_order_intro_rendered() || ! $order_id ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( $order ) {
        ozp_order_account_notice( $order );
    }
}, 5 );

// Order behind the account-creation email currently being sent — set by the
// woocommerce_payment_complete hook above for the duration of the
// wc_create_new_customer() call. Used by the customized
// woocommerce/emails/customer-new-account.php template to show what was
// purchased. Null outside that window (e.g. checkout-registration accounts,
// admin-created users) — the template falls back to generic copy.
function ozp_new_account_order(): ?WC_Order {
    return $GLOBALS['ozp_new_account_order'] ?? null;
}

// Product names from the order behind a new-account email, for display in
// the customized email template.
function ozp_new_account_product_names( WC_Order $order ): array {
    $names = array();
    foreach ( $order->get_items() as $item ) {
        $names[] = $item->get_name();
    }
    return $names;
}

add_filter( 'woocommerce_email_heading_customer_new_account', function (): string {
    return __( 'Your OzuPay account is ready', 'ozupay' );
} );

add_filter( 'woocommerce_email_subject_customer_new_account', function (): string {
    return __( 'Your OzuPay account is ready', 'ozupay' );
} );
