<?php
/**
 * Plugin Name: Custom DataLayer (MU) — Shared (Full Woo) + Prefixed Blocks + Hashes
 * Description: Preloads an enriched data object into window.dataLayer BEFORE GTM Initialization (WP + WooCommerce: user, cart, orders, marketing, etc.). Pushes a canonical object (customDL) and flat, prefixed blocks with an explicit event. Includes Google-ready hashed fields.
 * Author: You
 * Version: 3.3.1
 */

defined("ABSPATH") || exit();

/**
 * Optional: set to true if you want to SKIP pushing data when an admin is viewing the site.
 * This avoids polluting analytics with staff browsing.
 */
if (!defined("CUSTOMDL_SKIP_FOR_ADMINS")) {
    define("CUSTOMDL_SKIP_FOR_ADMINS", false);
}

if (!function_exists("customdl_inject_custom_datalayer")) {
    /* ------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------- */

    function customdl_get_client_ip()
    {
        $candidates = [
            "HTTP_CF_CONNECTING_IP",
            "HTTP_X_REAL_IP",
            "HTTP_X_FORWARDED_FOR", // may contain a list
            "REMOTE_ADDR",
        ];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $val = $_SERVER[$key];
                if ($key === "HTTP_X_FORWARDED_FOR") {
                    $parts = array_map("trim", explode(",", $val));
                    foreach ($parts as $p) {
                        if ($p) {
                            return $p;
                        }
                    }
                } else {
                    return $val;
                }
            }
        }
        return null;
    }

    function customdl_lower($s)
    {
        $s = (string) $s;
        if (function_exists("mb_strtolower")) {
            return mb_strtolower($s, "UTF-8");
        }
        return strtolower($s);
    }
    function customdl_collapse_spaces($s)
    {
        return preg_replace("/\s+/", " ", trim((string) $s));
    }
    function customdl_norm_email($email)
    {
        return $email ? customdl_lower(customdl_collapse_spaces($email)) : null;
    }
    function customdl_norm_name($name)
    {
        return $name ? customdl_lower(customdl_collapse_spaces($name)) : null;
    }
    function customdl_norm_address1($addr)
    {
        if (!$addr) {
            return null;
        }
        $a = customdl_lower(customdl_collapse_spaces($addr));
        return preg_replace("/[^a-z0-9\s]/", "", $a);
    }
    function customdl_norm_city($city)
    {
        return $city ? customdl_lower(customdl_collapse_spaces($city)) : null;
    }
    function customdl_norm_region($region)
    {
        return $region
            ? customdl_lower(customdl_collapse_spaces($region))
            : null;
    }
    function customdl_norm_postcode($pc)
    {
        if (!$pc) {
            return null;
        }
        $pc = preg_replace("/[\s-]+/", "", (string) $pc);
        return strtoupper($pc);
    }
    function customdl_norm_country($cc)
    {
        return $cc ? strtoupper(trim((string) $cc)) : null;
    }
    function customdl_norm_phone($phone, $country2 = null)
    {
        if (!$phone) {
            return null;
        }
        $digits = preg_replace("/\D+/", "", (string) $phone);
        if ($digits === "") {
            return null;
        }
        $c = strtoupper((string) $country2);
        if (($c === "US" || $c === "CA") && strlen($digits) === 10) {
            $digits = "1" . $digits;
        }
        return "+" . $digits;
    }
    function customdl_sha256_or_null($normalized)
    {
        if ($normalized === null || $normalized === "") {
            return null;
        }
        return hash("sha256", $normalized);
    }

    function customdl_add_identity_hashes_to_contact_block(array $block)
    {
        $fn = $block["customDL_firstName"] ?? null;
        $ln = $block["customDL_lastName"] ?? null;
        $em = $block["customDL_email"] ?? null;
        $ph = $block["customDL_phone"] ?? null;
        $a1 = $block["customDL_address1"] ?? null;
        $ct = $block["customDL_city"] ?? null;
        $st = $block["customDL_state"] ?? null;
        $pc = $block["customDL_postcode"] ?? null;
        $co = $block["customDL_country"] ?? null;

        $em_n = customdl_norm_email($em);
        $ph_n = customdl_norm_phone($ph, $co);
        $fn_n = customdl_norm_name($fn);
        $ln_n = customdl_norm_name($ln);
        $a1_n = customdl_norm_address1($a1);
        $ct_n = customdl_norm_city($ct);
        $st_n = customdl_norm_region($st);
        $pc_n = customdl_norm_postcode($pc);
        $co_n = customdl_norm_country($co);

        $block["customDL_firstNameHash"] = customdl_sha256_or_null($fn_n);
        $block["customDL_lastNameHash"] = customdl_sha256_or_null($ln_n);
        $block["customDL_emailHash"] = customdl_sha256_or_null($em_n);
        $block["customDL_phoneHash"] = customdl_sha256_or_null($ph_n);
        $block["customDL_address1Hash"] = customdl_sha256_or_null($a1_n);
        $block["customDL_cityHash"] = customdl_sha256_or_null($ct_n);
        $block["customDL_stateHash"] = customdl_sha256_or_null($st_n);
        $block["customDL_postcodeHash"] = customdl_sha256_or_null($pc_n);
        $block["customDL_countryHash"] = customdl_sha256_or_null($co_n);

        return $block;
    }

    function customdl_product_terms_names($product_id, $taxonomy)
    {
        $names = [];
        $terms = wp_get_post_terms($product_id, $taxonomy, [
            "fields" => "names",
        ]);
        if (!is_wp_error($terms) && !empty($terms)) {
            $names = array_values(array_filter(array_map("strval", $terms)));
        }
        return $names;
    }

    function customdl_wc_context()
    {
        return [
            "customDL_isShop" => function_exists("is_shop") ? is_shop() : false,
            "customDL_isProduct" => function_exists("is_product")
                ? is_product()
                : false,
            "customDL_isCart" => function_exists("is_cart") ? is_cart() : false,
            "customDL_isCheckout" => function_exists("is_checkout")
                ? is_checkout()
                : false,
            "customDL_isAccount" => function_exists("is_account_page")
                ? is_account_page()
                : false,
            "customDL_isThankyou" => function_exists("is_order_received_page")
                ? is_order_received_page()
                : false,
            "customDL_currency" => function_exists("get_woocommerce_currency")
                ? get_woocommerce_currency()
                : null,
        ];
    }

    function customdl_marketing_params()
    {
        $g = $_GET ?? [];
        $keys = [
            "utm_source",
            "utm_medium",
            "utm_campaign",
            "utm_term",
            "utm_content",
            "utm_id",
            "gclid",
            "gbraid",
            "wbraid",
            "msclkid",
            "fbclid",
            "ttclid",
            "yclid",
        ];
        $out = [];
        foreach ($keys as $k) {
            if (isset($g[$k]) && $g[$k] !== "") {
                $out["customDL_" . $k] = sanitize_text_field(
                    wp_unslash($g[$k])
                );
            }
        }
        return $out;
    }

    function customdl_wc_ensure_cart()
    {
        if (!function_exists("WC")) {
            return;
        }
        if (null === WC()->cart && function_exists("wc_load_cart")) {
            wc_load_cart();
        }
    }

    function customdl_wc_cart_snapshot()
    {
        if (!function_exists("WC") || !WC()->cart) {
            return null;
        }

        $cart = WC()->cart;
        $currency = function_exists("get_woocommerce_currency")
            ? get_woocommerce_currency()
            : null;

        $items = [];
        foreach ((array) $cart->get_cart() as $key => $ci) {
            $product = $ci["data"] ?? null;
            $product_id = isset($ci["product_id"])
                ? (int) $ci["product_id"]
                : ($product
                    ? $product->get_id()
                    : 0);
            $variation_id = isset($ci["variation_id"])
                ? (int) $ci["variation_id"]
                : 0;
            $qty = isset($ci["quantity"]) ? (int) $ci["quantity"] : 0;
            $line_subtotal = isset($ci["line_subtotal"])
                ? (float) $ci["line_subtotal"]
                : 0.0;
            $line_total = isset($ci["line_total"])
                ? (float) $ci["line_total"]
                : 0.0;
            $line_tax = isset($ci["line_tax"]) ? (float) $ci["line_tax"] : 0.0;

            $sku =
                $product && method_exists($product, "get_sku")
                    ? $product->get_sku()
                    : null;
            $name =
                $product && method_exists($product, "get_name")
                    ? $product->get_name()
                    : null;
            $type =
                $product && method_exists($product, "get_type")
                    ? $product->get_type()
                    : null;
            $price =
                $product && method_exists($product, "get_price")
                    ? (float) $product->get_price()
                    : null;
            $cats = $product_id
                ? customdl_product_terms_names($product_id, "product_cat")
                : [];
            $tags = $product_id
                ? customdl_product_terms_names($product_id, "product_tag")
                : [];
            $attributes = [];

            if (!empty($ci["variation"]) && is_array($ci["variation"])) {
                foreach ($ci["variation"] as $attr_key => $attr_val) {
                    $attributes["customDL_" . $attr_key] = is_array($attr_val)
                        ? implode(",", $attr_val)
                        : (string) $attr_val;
                }
            }

            $items[] = [
                "customDL_key" => $key,
                "customDL_productId" => $product_id,
                "customDL_variationId" => $variation_id,
                "customDL_sku" => $sku,
                "customDL_name" => $name,
                "customDL_type" => $type,
                "customDL_qty" => $qty,
                "customDL_price" => $price,
                "customDL_lineSubtotal" => $line_subtotal,
                "customDL_lineTotal" => $line_total,
                "customDL_lineTax" => $line_tax,
                "customDL_categories" => $cats,
                "customDL_tags" => $tags,
                "customDL_attributes" => $attributes,
            ];
        }

        $totals = method_exists($cart, "get_totals")
            ? (array) $cart->get_totals()
            : [];
        $pref_totals = [];
        foreach ($totals as $k => $v) {
            $pref_totals["customDL_" . $k] = $v;
        }

        $coupons = method_exists($cart, "get_applied_coupons")
            ? (array) $cart->get_applied_coupons()
            : [];
        $coupon_details = [];
        if (!empty($coupons)) {
            foreach ($coupons as $code) {
                $amount = method_exists($cart, "get_coupon_discount_amount")
                    ? (float) $cart->get_coupon_discount_amount($code, false)
                    : null;
                $coupon_details[] = [
                    "customDL_code" => $code,
                    "customDL_amount" => $amount,
                ];
            }
        }

        $fees_out = [];
        if (method_exists($cart, "get_fees")) {
            foreach ($cart->get_fees() as $fee) {
                $fees_out[] = [
                    "customDL_name" => $fee->name,
                    "customDL_total" => (float) $fee->total,
                    "customDL_tax" => (float) $fee->tax,
                ];
            }
        }

        $chosen_methods = null;
        if (function_exists("WC") && WC()->session) {
            $cm = WC()->session->get("chosen_shipping_methods");
            if (is_array($cm)) {
                $chosen_methods = array_values($cm);
            }
        }

        return [
            "customDL_currency" => $currency,
            "customDL_hash" => method_exists($cart, "get_cart_hash")
                ? (string) $cart->get_cart_hash()
                : null,
            "customDL_itemsCount" => method_exists(
                $cart,
                "get_cart_contents_count"
            )
                ? (int) $cart->get_cart_contents_count()
                : null,
            "customDL_itemsWeight" => method_exists(
                $cart,
                "get_cart_contents_weight"
            )
                ? (float) $cart->get_cart_contents_weight()
                : null,
            "customDL_needsShipping" => method_exists($cart, "needs_shipping")
                ? (bool) $cart->needs_shipping()
                : null,
            "customDL_items" => $items,
            "customDL_coupons" => $coupon_details,
            "customDL_fees" => $fees_out,
            "customDL_totals" => $pref_totals,
            "customDL_chosenShippingMethods" => $chosen_methods,
        ];
    }

    function customdl_wc_user_enrichment($user_id)
    {
        if (!$user_id || !function_exists("WC")) {
            return [];
        }

        $out = [];

        if (class_exists("WC_Customer")) {
            try {
                $customer = new WC_Customer($user_id);
                $billing = [
                    "customDL_firstName" => $customer->get_billing_first_name(),
                    "customDL_lastName" => $customer->get_billing_last_name(),
                    "customDL_company" => $customer->get_billing_company(),
                    "customDL_email" => $customer->get_billing_email(),
                    "customDL_phone" => $customer->get_billing_phone(),
                    "customDL_address1" => $customer->get_billing_address_1(),
                    "customDL_address2" => $customer->get_billing_address_2(),
                    "customDL_city" => $customer->get_billing_city(),
                    "customDL_state" => $customer->get_billing_state(),
                    "customDL_postcode" => $customer->get_billing_postcode(),
                    "customDL_country" => $customer->get_billing_country(),
                ];
                $shipping = [
                    "customDL_firstName" => $customer->get_shipping_first_name(),
                    "customDL_lastName" => $customer->get_shipping_last_name(),
                    "customDL_company" => $customer->get_shipping_company(),
                    "customDL_phone" => method_exists(
                        $customer,
                        "get_shipping_phone"
                    )
                        ? $customer->get_shipping_phone()
                        : null,
                    "customDL_address1" => $customer->get_shipping_address_1(),
                    "customDL_address2" => $customer->get_shipping_address_2(),
                    "customDL_city" => $customer->get_shipping_city(),
                    "customDL_state" => $customer->get_shipping_state(),
                    "customDL_postcode" => $customer->get_shipping_postcode(),
                    "customDL_country" => $customer->get_shipping_country(),
                ];
                $out[
                    "customDL_billing"
                ] = customdl_add_identity_hashes_to_contact_block($billing);
                $out[
                    "customDL_shipping"
                ] = customdl_add_identity_hashes_to_contact_block($shipping);
            } catch (\Throwable $e) {
                $out["customDL_error_customer"] = $e->getMessage();
            }
        }

        if (function_exists("wc_get_customer_total_spent")) {
            $out["customDL_orders"][
                "customDL_totalSpent"
            ] = (float) wc_get_customer_total_spent($user_id);
        }
        if (function_exists("wc_get_customer_order_count")) {
            $out["customDL_orders"][
                "customDL_orderCount"
            ] = (int) wc_get_customer_order_count($user_id);
        }

        if (function_exists("wc_get_orders")) {
            try {
                $orders = wc_get_orders([
                    "customer_id" => $user_id,
                    "limit" => 1,
                    "orderby" => "date",
                    "order" => "DESC",
                    "return" => "objects",
                ]);
                if (!empty($orders)) {
                    $last = $orders[0];
                    $entry = [
                        "customDL_id" => $last->get_id(),
                        "customDL_number" => $last->get_order_number(),
                        "customDL_date" => $last->get_date_created()
                            ? $last->get_date_created()->date("c")
                            : null,
                        "customDL_status" => $last->get_status(),
                        "customDL_currency" => $last->get_currency(),
                        "customDL_total" => (float) $last->get_total(),
                        "customDL_items" => [],
                    ];
                    foreach ($last->get_items() as $item) {
                        $entry["customDL_items"][] = [
                            "customDL_productId" => (int) $item->get_product_id(),
                            "customDL_variationId" => (int) $item->get_variation_id(),
                            "customDL_name" => $item->get_name(),
                            "customDL_qty" => (int) $item->get_quantity(),
                            "customDL_total" => (float) $item->get_total(),
                        ];
                    }
                    $out["customDL_lastOrder"] = $entry;
                }
            } catch (\Throwable $e) {
                $out["customDL_error_last_order"] = $e->getMessage();
            }
        }

        if (function_exists("wcs_get_users_subscriptions")) {
            try {
                $subs = wcs_get_users_subscriptions($user_id);
                $summary = [
                    "customDL_active" => 0,
                    "customDL_onHold" => 0,
                    "customDL_cancelled" => 0,
                    "customDL_total" => 0,
                    "customDL_examples" => [],
                ];
                foreach ($subs as $sub) {
                    $status = $sub->get_status();
                    $summary["customDL_total"]++;
                    if ($status === "active") {
                        $summary["customDL_active"]++;
                    }
                    if ($status === "on-hold") {
                        $summary["customDL_onHold"]++;
                    }
                    if ($status === "cancelled") {
                        $summary["customDL_cancelled"]++;
                    }
                    if (count($summary["customDL_examples"]) < 3) {
                        $summary["customDL_examples"][] = [
                            "customDL_id" => $sub->get_id(),
                            "customDL_status" => $status,
                            "customDL_currency" => $sub->get_currency(),
                            "customDL_total" => (float) $sub->get_total(),
                            "customDL_nextPayment" => $sub->get_date(
                                "next_payment"
                            ),
                        ];
                    }
                }
                $out["customDL_subscriptions"] = $summary;
            } catch (\Throwable $e) {
                $out["customDL_error_subscriptions"] = $e->getMessage();
            }
        }

        if (function_exists("wc_memberships_get_user_memberships")) {
            try {
                $ms = wc_memberships_get_user_memberships($user_id);
                $plans = [];
                foreach ($ms as $m) {
                    $plans[] = [
                        "customDL_planId" => $m->get_plan_id(),
                        "customDL_status" => $m->get_status(),
                        "customDL_start" => $m->get_start_date("c"),
                        "customDL_end" => $m->get_end_date("c"),
                    ];
                }
                $out["customDL_memberships"] = $plans;
            } catch (\Throwable $e) {
                $out["customDL_error_memberships"] = $e->getMessage();
            }
        }

        if (function_exists("wc_points_rewards_get_points_balance")) {
            try {
                $out["customDL_pointsRewards"] = [
                    "customDL_points" => (int) wc_points_rewards_get_points_balance(
                        $user_id
                    ),
                ];
            } catch (\Throwable $e) {
                $out["customDL_error_points"] = $e->getMessage();
            }
        }

        if (function_exists("yith_wcwl_count_products")) {
            try {
                $out["customDL_wishlist"] = [
                    "customDL_count" => (int) yith_wcwl_count_products(),
                ];
            } catch (\Throwable $e) {
                $out["customDL_error_wishlist"] = $e->getMessage();
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------
     * Main injector
     * ---------------------------------------------------------- */
    function customdl_inject_custom_datalayer()
    {
        static $did = false;
        if ($did) {
            return;
        }
        $did = true; // print-once guard

        // Front-end only
        if (
            is_admin() ||
            wp_doing_ajax() ||
            wp_doing_cron() ||
            (defined("REST_REQUEST") && REST_REQUEST)
        ) {
            return;
        }

        // Optional: skip admins if toggle is enabled
        if (
            CUSTOMDL_SKIP_FOR_ADMINS &&
            is_user_logged_in() &&
            current_user_can("manage_options")
        ) {
            return;
        }

        global $post, $wp_query;

        // Basic page context
        $pageTitle = wp_get_document_title();
        $pageAttributes = [];
        $pageCategory = [];
        $postCountOnPage = 0;
        $postCountTotal = 0;
        $siteSearchTerm = "";
        $siteSearchFrom = "";
        $siteSearchResults = 0;

        if (is_singular() && $post instanceof WP_Post) {
            $tags = get_the_tags($post->ID);
            if ($tags && !is_wp_error($tags)) {
                foreach ($tags as $tag) {
                    $pageAttributes[] = $tag->name;
                }
            }
            $cats = get_the_category($post->ID);
            if ($cats && !is_wp_error($cats)) {
                foreach ($cats as $cat) {
                    $pageCategory[] = $cat->name;
                }
            }
        }

        $author =
            isset($post) && $post instanceof WP_Post
                ? get_userdata($post->post_author)
                : null;
        $postDate =
            isset($post) && $post instanceof WP_Post
                ? get_the_date("", $post)
                : "";
        $postDateYear =
            isset($post) && $post instanceof WP_Post
                ? get_the_date("Y", $post)
                : "";
        $postDateMonth =
            isset($post) && $post instanceof WP_Post
                ? get_the_date("m", $post)
                : "";
        $postDateDay =
            isset($post) && $post instanceof WP_Post
                ? get_the_date("d", $post)
                : "";

        $pagePostType = "";
        $pagePostType2 = "";
        if (is_front_page()) {
            $pagePostType = "frontpage";
            $pagePostType2 = "frontpage";
        } elseif (is_home()) {
            $pagePostType = "bloghome";
            $pagePostType2 = "bloghome";
        } elseif (isset($post) && $post instanceof WP_Post) {
            $type = get_post_type($post);
            $pagePostType = $type ?: "";
            $pagePostType2 = $type ? "single-" . $type : "";
        } elseif (is_category()) {
            $pagePostType = "category";
            $pagePostType2 = "category-" . get_query_var("cat");
        } elseif (is_tag()) {
            $pagePostType = "tag";
            $pagePostType2 = "tag-" . get_query_var("tag");
        } elseif (is_tax()) {
            $taxonomy = get_queried_object();
            $pagePostType = "tax";
            $pagePostType2 = isset($taxonomy->taxonomy)
                ? "tax-" . $taxonomy->taxonomy
                : "tax";
        } elseif (is_author()) {
            $pagePostType = "author";
            $pagePostType2 = "author-" . get_query_var("author");
        } elseif (is_year()) {
            $pagePostType = "year";
            $pagePostType2 = "year-" . get_query_var("year");
        } elseif (is_month()) {
            $pagePostType = "month";
            $pagePostType2 = "month-" . get_query_var("monthnum");
        } elseif (is_day()) {
            $pagePostType = "day";
            $pagePostType2 = "day-" . get_query_var("day");
        }

        if (is_category() || is_tag() || is_tax()) {
            $postCountOnPage = isset($wp_query->post_count)
                ? (int) $wp_query->post_count
                : 0;
            $postCountTotal = isset($wp_query->found_posts)
                ? (int) $wp_query->found_posts
                : 0;
        }

        if (is_search()) {
            $siteSearchTerm = get_search_query();
            $siteSearchFrom = wp_get_referer() ?: "";
            $siteSearchResults = isset($wp_query->found_posts)
                ? (int) $wp_query->found_posts
                : 0;
        }

        // User
        $current_user = wp_get_current_user();
        $is_logged_in = is_user_logged_in();
        $userData = [
            "customDL_id" => $is_logged_in ? (int) $current_user->ID : null,
            "customDL_username" => $is_logged_in
                ? $current_user->user_login
                : null,
            "customDL_email" => $is_logged_in
                ? $current_user->user_email
                : null,
            "customDL_emailHash" =>
                $is_logged_in && $current_user->user_email
                    ? customdl_sha256_or_null(
                        customdl_norm_email($current_user->user_email)
                    )
                    : null,
            "customDL_displayName" => $is_logged_in
                ? $current_user->display_name
                : null,
            "customDL_roles" => $is_logged_in
                ? (array) $current_user->roles
                : [],
            "customDL_registered" => $is_logged_in
                ? strtotime($current_user->user_registered)
                : null,
            "customDL_loginState" => $is_logged_in ? "logged-in" : "logged-out",
            "customDL_ip" => customdl_get_client_ip(), // do NOT send to Google
        ];

        // Woo
        $wc = null;
        $cart = null;
        if (function_exists("WC")) {
            $wc = customdl_wc_context();
            if ($is_logged_in) {
                $enrich = customdl_wc_user_enrichment($current_user->ID);
                if (!empty($enrich)) {
                    $userData = array_merge($userData, $enrich);
                }
            }
            customdl_wc_ensure_cart();
            if (WC()->cart) {
                $cart = customdl_wc_cart_snapshot();
            }
        }

        // Site/Theme/Device/Marketing
        $theme = wp_get_theme();
        $site = [
            "customDL_blogId" => get_current_blog_id(),
            "customDL_name" => get_bloginfo("name"),
            "customDL_locale" => get_locale(),
            "customDL_home" => home_url("/"),
            "customDL_siteurl" => site_url("/"),
        ];
        $themeInfo = [
            "customDL_name" => $theme ? $theme->get("Name") : null,
            "customDL_version" => $theme ? $theme->get("Version") : null,
            "customDL_stylesheet" => $theme ? $theme->get_stylesheet() : null,
            "customDL_template" => $theme ? $theme->get_template() : null,
        ];
        $device = [
            "customDL_isMobile" => function_exists("wp_is_mobile")
                ? wp_is_mobile()
                : null,
            "customDL_userAgent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
            "customDL_language" => $_SERVER["HTTP_ACCEPT_LANGUAGE"] ?? null,
        ];
        $marketing_arr = customdl_marketing_params();
        $marketing = (object) $marketing_arr; // keep as object even when empty

        // Canonical object
        $data = [
            "customDL_pageTitle" => $pageTitle,
            "customDL_pageAttributes" => $pageAttributes,
            "customDL_pageCategory" => $pageCategory,
            "customDL_pagePostAuthor" => $author ? $author->display_name : "",
            "customDL_pagePostAuthorID" => $author ? (int) $author->ID : 0,
            "customDL_pagePostDate" => $postDate,
            "customDL_pagePostDateYear" => $postDateYear,
            "customDL_pagePostDateMonth" => $postDateMonth,
            "customDL_pagePostDateDay" => $postDateDay,
            "customDL_pagePostType" => $pagePostType,
            "customDL_pagePostType2" => $pagePostType2,
            "customDL_postCountOnPage" => $postCountOnPage,
            "customDL_postCountTotal" => $postCountTotal,
            "customDL_siteSearchTerm" => $siteSearchTerm,
            "customDL_siteSearchFrom" => $siteSearchFrom,
            "customDL_siteSearchResults" => $siteSearchResults,

            "meta" => [
                "customDL_version" => "3.3.1",
                "customDL_generatedAt" => time(),
            ],
            "site" => $site,
            "theme" => $themeInfo,
            "device" => $device,
            "marketing" => $marketing,
            "user" => $userData,
            "wc" => $wc ?: null,
            "cart" => $cart,
        ];

        $data = apply_filters("customdl/data", $data);

        // Flattened payload for dataLayer
        $payload = [
            "event" => "customdl_init",
            "customDL" => $data,
            "customDL_meta" => $data["meta"] ?? null,
            "customDL_site" => $data["site"] ?? null,
            "customDL_theme" => $data["theme"] ?? null,
            "customDL_device" => $data["device"] ?? null,
            "customDL_marketing" => $data["marketing"] ?? null,
            "customDL_user" => $data["user"] ?? null,
            "customDL_wc" => $data["wc"] ?? null,
            "customDL_cart" => $data["cart"] ?? null,
        ];

        $legacy_keys = [
            "customDL_pageTitle",
            "customDL_pageAttributes",
            "customDL_pageCategory",
            "customDL_pagePostAuthor",
            "customDL_pagePostAuthorID",
            "customDL_pagePostDate",
            "customDL_pagePostDateYear",
            "customDL_pagePostDateMonth",
            "customDL_pagePostDateDay",
            "customDL_pagePostType",
            "customDL_pagePostType2",
            "customDL_postCountOnPage",
            "customDL_postCountTotal",
            "customDL_siteSearchTerm",
            "customDL_siteSearchFrom",
            "customDL_siteSearchResults",
        ];
        foreach ($legacy_keys as $k) {
            if (array_key_exists($k, $data)) {
                $payload[$k] = $data[$k];
            }
        }

        $payload = apply_filters("customdl/payload", $payload, $data);

        // Output (with one-time replay if something wipes dataLayer later)
        echo '<script data-no-optimize="1" id="customdl-init">';
        echo "window.dataLayer=window.dataLayer||[];window.customDL=window.customDL||[];";
        echo "(function(d,p){";
        echo "  window.customDL.push(d);";
        echo "  window.dataLayer.push(p);";
        echo "  document.addEventListener('DOMContentLoaded',function(){try{";
        echo "    var ok=Array.isArray(window.dataLayer)&&window.dataLayer.some(function(e){return e&&e.event==='customdl_init';});";
        echo "    if(!ok){window.dataLayer.push(p);console.info('customDL: re-pushed customdl_init');}";
        echo "  }catch(e){}});";
        echo "  try{console.log('✅ customDL v'+(d.meta&&d.meta.customDL_version?d.meta.customDL_version:'?')+' init',p);}catch(e){}";
        echo "})( " .
            wp_json_encode($data) .
            ", " .
            wp_json_encode($payload) .
            " );";
        echo "</script>";
    }

    // Hook super-early in head
    add_action("wp_head", "customdl_inject_custom_datalayer", -999);
}

// touch 2025-09-05T05:09:08+00:00
// test-trigger 2025-09-05T05:09:36+00:00
// trigger 2025-09-05T05:11:12+00:00
