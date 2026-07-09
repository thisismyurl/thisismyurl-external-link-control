<?php
/**
 * Plugin Name:       This Is My URL - External Link Control
 * Plugin URI:        https://thisismyurl.com/external-link-control
 * Description:       Globally manage external link behavior, including nofollow and target attributes.
 * Version:           1.6190.1000
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * Text Domain:       thisismyurl-external-link-control
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Donate link:       https://github.com/sponsors/thisismyurl
 * @package TIMU_ELC
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-elc-host.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-elc-domain-rules.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-elc-link-processor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-elc-rest.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-elc-link-checker.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/abilities.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-elc-cli.php';
}

class TIMU_ELC {

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Acquire (and lazily create) the singleton instance.
     *
     * @return self
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Hook screen ID for the tools page, captured from add_management_page()
     * so admin asset enqueue can scope itself to this screen only.
     *
     * @var string
     */
    private $admin_hook = '';

    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_plugin_settings' ) );
        add_action( 'admin_menu', array( $this, 'create_tools_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );

        new TIMU_ELC_Link_Checker();
        add_action( 'wp_ajax_timu_elc_inventory', array( $this, 'ajax_inventory' ) );
        $priority = (int) apply_filters( 'timu_elc_priority', 99 );

        add_filter( 'the_content', array( $this, 'modify_external_links' ), $priority );
        add_filter( 'the_excerpt', array( $this, 'modify_external_links' ), $priority );
        add_filter( 'widget_text_content', array( $this, 'modify_external_links' ), $priority );
        add_filter( 'widget_block_content', array( $this, 'modify_external_links' ), $priority );
        add_filter( 'comment_text', array( $this, 'modify_comment_links' ), $priority );

        // FSE / block themes: render_block applies to every block, including
        // navigation, query-loop, post-content, and link blocks. We filter
        // only the block types whose output is HTML containing <a> tags we
        // would otherwise miss when the_content/the_excerpt are not the
        // outer template surface.
        add_filter( 'render_block', array( $this, 'modify_block_links' ), $priority, 2 );

        // Set defaults upon activation.
        register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );
        register_deactivation_hook( __FILE__, array( 'TIMU_ELC_Link_Checker', 'deactivate' ) );
    }

    /**
     * Activate Plugin Defaults.
     *
     * Least-surprise on activation: the master switch ships OFF, so activating
     * the plugin does NOT silently rewrite every external link sitewide. The
     * owner opts in from Tools > Link Control. The new-tab / nofollow / UGC
     * toggles default ON only so that the moment the owner flips the master
     * switch the sensible behaviour is already in place — none of them do
     * anything while `enabled` is 0.
     *
     * The broken-link checker still schedules its weekly outbound crawler on
     * activation (see TIMU_ELC_Link_Checker::schedule_if_needed); that behaviour is
     * disclosed in the readme Description.
     */
    public function activate_plugin_defaults() {
        if ( false === get_option( 'timu_elc_options' ) ) {
            add_option( 'timu_elc_options', array(
                'enabled'      => 0,
                'new_tab'      => 1,
                'noreferrer'   => 1,
                'nofollow'     => 1,
                'comment_ugc'  => 1,
            ), '', 'no' );
        }
    }

    public function add_plugin_action_links( $links ) {
        $custom_links = array(
            '<a href="' . esc_url( admin_url( 'tools.php?page=thisismyurl-external-link-control' ) ) . '">' . esc_html__( 'Settings', 'thisismyurl-external-link-control' ) . '</a>',
            '<a href="' . esc_url( 'https://github.com/sponsors/thisismyurl' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sponsor', 'thisismyurl-external-link-control' ) . '</a>',
        );
        return array_merge( $custom_links, $links );
    }

    /**
     * Enqueue the small admin stylesheet on our own settings screen.
     * Replaces the inline `style=` attributes that 0.6112 sprinkled
     * through the plugin row link and the settings UI.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_admin_assets( $hook ) {
        // Plugin-row donate-link styling is light enough to apply globally
        // on plugins.php; the rest of the styles only matter on our screen.
        if ( 'plugins.php' === $hook || ( '' !== $this->admin_hook && $hook === $this->admin_hook ) ) {
            wp_enqueue_style(
                'timu-elc-admin',
                plugins_url( 'assets/css/admin.css', __FILE__ ),
                array(),
                '1.6190.1000'
            );
        }

        if ( '' !== $this->admin_hook && $hook === $this->admin_hook ) {
            wp_enqueue_script(
                'timu-elc-admin',
                plugins_url( 'assets/js/admin.js', __FILE__ ),
                array(),
                '1.6190.1000',
                true
            );
            $rules         = TIMU_ELC_Domain_Rules::all();
            $ruled_domains = array_values( array_filter( array_column( $rules, 'domain' ) ) );
            wp_localize_script(
                'timu-elc-admin',
                'timuElcAdminPage',
                array(
                    'inventoryUrl'  => rest_url( 'timu-elc/v1/inventory' ),
                    'restNonce'     => wp_create_nonce( 'wp_rest' ),
                    'ruledDomains'  => $ruled_domains,
                    'i18n'          => array(
                        'loading'    => __( 'Loading…', 'thisismyurl-external-link-control' ),
                        'loadError'  => __( 'Could not load inventory. Try again after refreshing.', 'thisismyurl-external-link-control' ),
                        'noLinks'    => __( 'No external links found in published content.', 'thisismyurl-external-link-control' ),
                        'hasRule'    => __( 'Has rule', 'thisismyurl-external-link-control' ),
                        'noRule'     => __( '—', 'thisismyurl-external-link-control' ),
                        'remove'     => __( 'Remove', 'thisismyurl-external-link-control' ),
                        'inherit'    => __( 'Inherit', 'thisismyurl-external-link-control' ),
                        'domain'     => __( 'Domain', 'thisismyurl-external-link-control' ),
                    ),
                )
            );
        }
    }

    public function register_plugin_settings() {
        register_setting(
            'timu_elc_settings_group',
            'timu_elc_options',
            array( 'sanitize_callback' => array( $this, 'sanitize_plugin_options' ) )
        );
        register_setting(
            'timu_elc_settings_group',
            TIMU_ELC_Domain_Rules::OPTION_KEY,
            array( 'sanitize_callback' => array( $this, 'sanitize_domain_rules' ) )
        );
    }

    /**
     * Sanitize the per-domain rules table coming from the settings form.
     *
     * Form shape: timu_elc_domain_rules[N][domain|target|dofollow|sponsored|allowlist].
     *
     * @param mixed $input Raw POSTed value.
     * @return array<int,array<string,mixed>>
     */
    public function sanitize_domain_rules( $input ) {
        if ( ! is_array( $input ) ) {
            return array();
        }
        $out = array();
        foreach ( $input as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $candidate = array(
                'domain'    => isset( $row['domain'] ) ? sanitize_text_field( $row['domain'] ) : '',
                'target'    => isset( $row['target'] ) ? sanitize_text_field( $row['target'] ) : '',
                'dofollow'  => ! empty( $row['dofollow'] ),
                'sponsored' => ! empty( $row['sponsored'] ),
                'allowlist' => ! empty( $row['allowlist'] ),
            );
            $normalised = TIMU_ELC_Domain_Rules::normalise_rule( $candidate );
            if ( null !== $normalised ) {
                $out[] = $normalised;
            }
        }
        TIMU_ELC_Domain_Rules::reset_cache();
        return $out;
    }

    public function sanitize_plugin_options( $input ) {
        $new_input = array();
        $new_input['enabled']     = isset( $input['enabled'] ) ? 1 : 0;
        $new_input['new_tab']     = isset( $input['new_tab'] ) ? 1 : 0;
        $new_input['noreferrer']  = isset( $input['noreferrer'] ) ? 1 : 0;
        $new_input['nofollow']    = isset( $input['nofollow'] ) ? 1 : 0;
        $new_input['comment_ugc'] = isset( $input['comment_ugc'] ) ? 1 : 0;

        // Same-tab domain exceptions: one domain per line, stored as a
        // comma-separated string. Each entry is stripped with sanitize_text_field()
        // and lowercased so matching is case-insensitive.
        $raw_domains = isset( $input['target_same_tab_domains'] ) ? (string) $input['target_same_tab_domains'] : '';
        $lines       = preg_split( '/[\r\n,]+/', $raw_domains );
        $domains     = array();
        if ( is_array( $lines ) ) {
            foreach ( $lines as $line ) {
                $clean = strtolower( sanitize_text_field( $line ) );
                if ( '' !== $clean ) {
                    $domains[] = $clean;
                }
            }
        }
        $new_input['target_same_tab_domains'] = implode( ',', array_unique( $domains ) );

        return $new_input;
    }

    public function create_tools_page() {
        $this->admin_hook = (string) add_management_page(
            __( 'External Link Control', 'thisismyurl-external-link-control' ),
            __( 'Link Control', 'thisismyurl-external-link-control' ),
            'manage_options',
            'thisismyurl-external-link-control',
            array( $this, 'render_plugin_admin_ui' )
        );
    }

    /**
     * Determine whether external-link processing should run in the current context.
     *
     * Returns the boolean result of the `timu_elc_should_filter` filter,
     * which receives the current WP_Post (or null) and defaults to true.
     *
     * Usage examples:
     *
     *   // Disable processing on all attachment post type pages.
     *   add_filter( 'timu_elc_should_filter', function ( $should, $post ) {
     *       return $post && 'attachment' !== $post->post_type;
     *   }, 10, 2 );
     *
     *   // Disable processing for a specific post ID.
     *   add_filter( 'timu_elc_should_filter', function ( $should, $post ) {
     *       return $post && 42 !== (int) $post->ID;
     *   }, 10, 2 );
     *
     * @param WP_Post|null $post Current post object, if available.
     * @return bool
     */
    private function should_filter_post( $post ) {
        return (bool) apply_filters( 'timu_elc_should_filter', true, $post );
    }

    public function modify_external_links( $content ) {
        $processor = new TIMU_ELC_Link_Processor();
        if ( ! $processor->enabled() ) {
            return $content;
        }
        $post = get_post();
        $post = $post instanceof WP_Post ? $post : null;
        if ( ! $this->should_filter_post( $post ) ) {
            return $content;
        }
        return $processor->process( $content, $post );
    }

    /**
     * Apply external-link rules to a rendered block on FSE / block themes.
     *
     * Filters the rendered HTML for the small set of core blocks whose
     * <a> output bypasses the_content / the_excerpt:
     *
     *   - core/navigation, core/navigation-link, core/navigation-submenu
     *   - core/post-content (when used inside a synced pattern, query loop, etc.)
     *   - core/query, core/post-template, core/latest-posts
     *   - core/social-link (rel=me / sameAs profile links)
     *   - core/site-logo, core/post-title (link-on-title variants)
     *
     * Filterable via `timu_elc_block_types` so a site owner or a
     * downstream plugin can add or remove block names from the list
     * without forking this file.
     */
    public function modify_block_links( $block_content, $block ) {
        if ( ! is_string( $block_content ) || '' === $block_content ) {
            return $block_content;
        }
        if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
            return $block_content;
        }

        $eligible = (array) apply_filters(
            'timu_elc_block_types',
            array(
                'core/navigation',
                'core/navigation-link',
                'core/navigation-submenu',
                'core/post-content',
                'core/query',
                'core/post-template',
                'core/latest-posts',
                'core/social-link',
                'core/site-logo',
                'core/post-title',
            )
        );
        if ( ! in_array( $block['blockName'], $eligible, true ) ) {
            return $block_content;
        }

        $processor = new TIMU_ELC_Link_Processor();
        if ( ! $processor->enabled() ) {
            return $block_content;
        }

        $post = get_post();
        if ( ! $this->should_filter_post( $post instanceof WP_Post ? $post : null ) ) {
            return $block_content;
        }

        // Block render output is per-block, not per-post; cache key would
        // need a block-content-hash dimension. Skip the cache and run direct.
        return $processor->transform( $block_content );
    }

    /**
     * Apply external-link rules to a single comment, with `ugc` forced
     * onto every external link per Google's webmaster guidance for
     * user-generated content.
     */
    public function modify_comment_links( $content ) {
        $processor = new TIMU_ELC_Link_Processor();
        if ( ! $processor->enabled() ) {
            return $content;
        }

        // Comments are not bound to a single WP_Post in the same loop sense;
        // pass null so the should_filter hook can still run.
        if ( ! $this->should_filter_post( get_post() instanceof WP_Post ? get_post() : null ) ) {
            return $content;
        }

        $options = get_option( 'timu_elc_options', array() );
        if ( ! empty( $options['comment_ugc'] ) ) {
            $processor->force_rel( array( 'ugc' ) );
        }

        // Comments don't have a stable post_modified_gmt of their own usable
        // here; pass null to skip the post-keyed cache.
        return $processor->transform( $content );
    }

    public function render_plugin_admin_ui() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options      = get_option( 'timu_elc_options', array() );
        $link_results = get_option( TIMU_ELC_Link_Checker::RESULTS_OPTION, array() );
        $broken_count = ! empty( $link_results['broken'] ) ? count( $link_results['broken'] ) : 0;
        $checked_at   = ! empty( $link_results['checked_at'] ) ? (int) $link_results['checked_at'] : 0;
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'External Link Control', 'thisismyurl-external-link-control' ); ?>
                <span class="timu-elc-byline">
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: brand name "thisismyurl.com" rendered as a link. */
                            __( 'by %s', 'thisismyurl-external-link-control' ),
                            '<a href="https://thisismyurl.com/" target="_blank" rel="noopener noreferrer" class="timu-elc-byline-link">thisismyurl.com</a>'
                        ),
                        array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array(), 'class' => array() ) )
                    );
                    ?>
                </span>
            </h1>

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">

                        <!-- Global settings form -->
                        <div class="postbox">
                            <div class="inside">
                                <form method="post" action="options.php">
                                    <?php settings_fields( 'timu_elc_settings_group' ); ?>
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Master Switch', 'thisismyurl-external-link-control' ); ?></th>
                                            <td>
                                                <input type="checkbox" id="timu_elc_enabled" name="timu_elc_options[enabled]" value="1" <?php checked( 1, isset( $options['enabled'] ) ? $options['enabled'] : 0 ); ?> />
                                                <label for="timu_elc_enabled"><?php esc_html_e( 'Activate link filtering.', 'thisismyurl-external-link-control' ); ?></label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Open in New Tab', 'thisismyurl-external-link-control' ); ?></th>
                                            <td>
                                                <input type="checkbox" id="timu_elc_new_tab" name="timu_elc_options[new_tab]" value="1" <?php checked( 1, isset( $options['new_tab'] ) ? $options['new_tab'] : 0 ); ?> />
                                                <label for="timu_elc_new_tab"><?php esc_html_e( 'Open external links in a new tab (adds target="_blank").', 'thisismyurl-external-link-control' ); ?></label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Strip Referrer', 'thisismyurl-external-link-control' ); ?></th>
                                            <td>
                                                <input type="checkbox" id="timu_elc_noreferrer" name="timu_elc_options[noreferrer]" value="1" <?php checked( 1, isset( $options['noreferrer'] ) ? $options['noreferrer'] : 1 ); ?> />
                                                <label for="timu_elc_noreferrer"><?php esc_html_e( 'Add rel="noreferrer" on new-tab links.', 'thisismyurl-external-link-control' ); ?></label>
                                                <p class="description"><?php esc_html_e( 'Prevents the destination site from seeing your site as the referrer. Turn this off if you need referral tracking to work — affiliate dashboards, partner analytics, and editorial credit links all depend on the Referer header being present. noopener is always added regardless of this setting.', 'thisismyurl-external-link-control' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'SEO Nofollow', 'thisismyurl-external-link-control' ); ?></th>
                                            <td>
                                                <input type="checkbox" id="timu_elc_nofollow" name="timu_elc_options[nofollow]" value="1" <?php checked( 1, isset( $options['nofollow'] ) ? $options['nofollow'] : 0 ); ?> />
                                                <label for="timu_elc_nofollow"><?php esc_html_e( "Add rel="nofollow" to external links.", 'thisismyurl-external-link-control' ); ?></label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Comment UGC', 'thisismyurl-external-link-control' ); ?></th>
                                            <td>
                                                <input type="checkbox" id="timu_elc_comment_ugc" name="timu_elc_options[comment_ugc]" value="1" <?php checked( 1, isset( $options['comment_ugc'] ) ? $options['comment_ugc'] : 0 ); ?> />
                                                <label for="timu_elc_comment_ugc"><?php esc_html_e( "Add rel="ugc" to external links in comments (Google's recommended attribute for user-generated content).", 'thisismyurl-external-link-control' ); ?></label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="timu_elc_target_same_tab_domains"><?php esc_html_e( 'Same-Tab Exceptions', 'thisismyurl-external-link-control' ); ?></label>
                                            </th>
                                            <td>
                                                <?php
                                                $same_tab_raw   = isset( $options['target_same_tab_domains'] ) ? (string) $options['target_same_tab_domains'] : '';
                                                $same_tab_lines = '' !== $same_tab_raw ? implode( "
", explode( ',', $same_tab_raw ) ) : '';
                                                ?>
                                                <textarea
                                                    id="timu_elc_target_same_tab_domains"
                                                    name="timu_elc_options[target_same_tab_domains]"
                                                    rows="4"
                                                    class="large-text"
                                                    placeholder="docs.example.com"
                                                ><?php echo esc_textarea( $same_tab_lines ); ?></textarea>
                                                <p class="description">
                                                    <?php esc_html_e( 'One domain per line. Links to these domains stay in the same tab even when Open in New Tab is on. Rel attributes still apply.', 'thisismyurl-external-link-control' ); ?>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>

                                    <h2 class="title"><?php esc_html_e( 'Per-Domain Rules', 'thisismyurl-external-link-control' ); ?></h2>
                                    <p class="description">
                                        <?php esc_html_e( 'Override global behaviour for specific domains. Use this for rel="me" profiles you want left dofollow, paid placements that should carry rel="sponsored", or partner sites you want to open in the same tab.', 'thisismyurl-external-link-control' ); ?>
                                    </p>
                                    <table class="widefat striped timu-elc-domain-rules">
                                        <thead>
                                            <tr>
                                                <th scope="col"><?php esc_html_e( 'Domain', 'thisismyurl-external-link-control' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Dofollow', 'thisismyurl-external-link-control' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Target', 'thisismyurl-external-link-control' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Sponsored', 'thisismyurl-external-link-control' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Allowlist', 'thisismyurl-external-link-control' ); ?></th>
                                                <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'thisismyurl-external-link-control' ); ?></span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $rules      = TIMU_ELC_Domain_Rules::all();
                                            $blank_rows = max( 0, 3 - count( $rules ) );
                                            for ( $i = 0; $i < $blank_rows; $i++ ) {
                                                $rules[] = array(
                                                    'domain'    => '',
                                                    'dofollow'  => false,
                                                    'target'    => '',
                                                    'sponsored' => false,
                                                    'allowlist' => false,
                                                );
                                            }
                                            foreach ( $rules as $i => $rule ) :
                                                $name_prefix = 'timu_elc_domain_rules[' . (int) $i . ']';
                                                ?>
                                                <tr>
                                                    <td>
                                                        <label class="screen-reader-text" for="<?php echo esc_attr( 'timu_elc_dr_domain_' . $i ); ?>"><?php esc_html_e( 'Domain', 'thisismyurl-external-link-control' ); ?></label>
                                                        <input type="text" id="<?php echo esc_attr( 'timu_elc_dr_domain_' . $i ); ?>" name="<?php echo esc_attr( $name_prefix . '[domain]' ); ?>" value="<?php echo esc_attr( $rule['domain'] ); ?>" placeholder="example.com" class="regular-text" />
                                                    </td>
                                                    <td>
                                                        <label class="screen-reader-text" for="<?php echo esc_attr( 'timu_elc_dr_dofollow_' . $i ); ?>"><?php esc_html_e( 'Dofollow', 'thisismyurl-external-link-control' ); ?></label>
                                                        <input type="checkbox" id="<?php echo esc_attr( 'timu_elc_dr_dofollow_' . $i ); ?>" name="<?php echo esc_attr( $name_prefix . '[dofollow]' ); ?>" value="1" <?php checked( true, ! empty( $rule['dofollow'] ) ); ?> />
                                                    </td>
                                                    <td>
                                                        <label class="screen-reader-text" for="<?php echo esc_attr( 'timu_elc_dr_target_' . $i ); ?>"><?php esc_html_e( 'Target', 'thisismyurl-external-link-control' ); ?></label>
                                                        <select id="<?php echo esc_attr( 'timu_elc_dr_target_' . $i ); ?>" name="<?php echo esc_attr( $name_prefix . '[target]' ); ?>">
                                                            <option value="" <?php selected( '', $rule['target'] ); ?>><?php esc_html_e( 'Inherit', 'thisismyurl-external-link-control' ); ?></option>
                                                            <option value="_blank" <?php selected( '_blank', $rule['target'] ); ?>>_blank</option>
                                                            <option value="_self" <?php selected( '_self', $rule['target'] ); ?>>_self</option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <label class="screen-reader-text" for="<?php echo esc_attr( 'timu_elc_dr_sponsored_' . $i ); ?>"><?php esc_html_e( 'Sponsored', 'thisismyurl-external-link-control' ); ?></label>
                                                        <input type="checkbox" id="<?php echo esc_attr( 'timu_elc_dr_sponsored_' . $i ); ?>" name="<?php echo esc_attr( $name_prefix . '[sponsored]' ); ?>" value="1" <?php checked( true, ! empty( $rule['sponsored'] ) ); ?> />
                                                    </td>
                                                    <td>
                                                        <label class="screen-reader-text" for="<?php echo esc_attr( 'timu_elc_dr_allowlist_' . $i ); ?>"><?php esc_html_e( 'Allowlist', 'thisismyurl-external-link-control' ); ?></label>
                                                        <input type="checkbox" id="<?php echo esc_attr( 'timu_elc_dr_allowlist_' . $i ); ?>" name="<?php echo esc_attr( $name_prefix . '[allowlist]' ); ?>" value="1" <?php checked( true, ! empty( $rule['allowlist'] ) ); ?> />
                                                    </td>
                                                    <td>
                                                        <button type="button" class="button-link timu-elc-remove-row" aria-label="<?php esc_attr_e( 'Remove this rule', 'thisismyurl-external-link-control' ); ?>"><?php esc_html_e( 'Remove', 'thisismyurl-external-link-control' ); ?></button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <!-- Row template: cloned by JS for "Add row". Not submitted (inputs disabled). -->
                                    <template id="timu-elc-row-template">
                                        <tr>
                                            <td>
                                                <label class="screen-reader-text" for="timu_elc_dr_domain___IDX__"><?php esc_html_e( 'Domain', 'thisismyurl-external-link-control' ); ?></label>
                                                <input type="text" id="timu_elc_dr_domain___IDX__" name="timu_elc_domain_rules[__IDX__][domain]" value="" placeholder="example.com" class="regular-text" />
                                            </td>
                                            <td>
                                                <label class="screen-reader-text" for="timu_elc_dr_dofollow___IDX__"><?php esc_html_e( 'Dofollow', 'thisismyurl-external-link-control' ); ?></label>
                                                <input type="checkbox" id="timu_elc_dr_dofollow___IDX__" name="timu_elc_domain_rules[__IDX__][dofollow]" value="1" />
                                            </td>
                                            <td>
                                                <label class="screen-reader-text" for="timu_elc_dr_target___IDX__"><?php esc_html_e( 'Target', 'thisismyurl-external-link-control' ); ?></label>
                                                <select id="timu_elc_dr_target___IDX__" name="timu_elc_domain_rules[__IDX__][target]">
                                                    <option value=""><?php esc_html_e( 'Inherit', 'thisismyurl-external-link-control' ); ?></option>
                                                    <option value="_blank">_blank</option>
                                                    <option value="_self">_self</option>
                                                </select>
                                            </td>
                                            <td>
                                                <label class="screen-reader-text" for="timu_elc_dr_sponsored___IDX__"><?php esc_html_e( 'Sponsored', 'thisismyurl-external-link-control' ); ?></label>
                                                <input type="checkbox" id="timu_elc_dr_sponsored___IDX__" name="timu_elc_domain_rules[__IDX__][sponsored]" value="1" />
                                            </td>
                                            <td>
                                                <label class="screen-reader-text" for="timu_elc_dr_allowlist___IDX__"><?php esc_html_e( 'Allowlist', 'thisismyurl-external-link-control' ); ?></label>
                                                <input type="checkbox" id="timu_elc_dr_allowlist___IDX__" name="timu_elc_domain_rules[__IDX__][allowlist]" value="1" />
                                            </td>
                                            <td>
                                                <button type="button" class="button-link timu-elc-remove-row" aria-label="<?php esc_attr_e( 'Remove this rule', 'thisismyurl-external-link-control' ); ?>"><?php esc_html_e( 'Remove', 'thisismyurl-external-link-control' ); ?></button>
                                            </td>
                                        </tr>
                                    </template>

                                    <p class="timu-elc-domain-rules-meta description">
                                        <?php esc_html_e( 'Subdomains inherit the parent rule — a rule on linkedin.com applies to www.linkedin.com automatically. A more-specific rule (jobs.linkedin.com) takes precedence.', 'thisismyurl-external-link-control' ); ?>
                                        <br><strong><?php esc_html_e( 'Sponsored:', 'thisismyurl-external-link-control' ); ?></strong>
                                        <?php esc_html_e( 'Sets rel="sponsored" for paid placements. Google treats sponsored as equivalent to nofollow — no need to check both. Do not combine with Allowlist, which suppresses rel attributes entirely.', 'thisismyurl-external-link-control' ); ?>
                                    </p>

                                    <p>
                                        <button type="button" id="timu-elc-add-row" class="button"><?php esc_html_e( '+ Add row', 'thisismyurl-external-link-control' ); ?></button>
                                    </p>

                                    <?php submit_button( __( 'Save Link Settings', 'thisismyurl-external-link-control' ) ); ?>
                                </form>
                            </div>
                        </div>

                        <!-- Link inventory panel -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Link Inventory', 'thisismyurl-external-link-control' ); ?></span></h2>
                            <div class="inside">
                                <p><?php esc_html_e( 'All external domains found in your published content, sorted by link count. Use this to spot domains that should have a per-domain rule configured.', 'thisismyurl-external-link-control' ); ?></p>
                                <p>
                                    <button type="button" id="timu-elc-load-inventory" class="button"><?php esc_html_e( 'Load inventory', 'thisismyurl-external-link-control' ); ?></button>
                                    <span class="timu-elc-inventory-status"></span>
                                </p>
                                <div id="timu-elc-inventory-results" style="display:none;margin-top:1em"></div>
                            </div>
                        </div>

                    </div><!-- #post-body-content -->

                    <div id="postbox-container-1" class="postbox-container">

                        <!-- Broken-link status -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Link Checker', 'thisismyurl-external-link-control' ); ?></span></h2>
                            <div class="inside">
                                <?php if ( $broken_count > 0 ) : ?>
                                    <p class="timu-elc-status-broken">
                                        <?php
                                        echo wp_kses(
                                            sprintf(
                                                /* translators: %d: number of broken links found. */
                                                _n(
                                                    '<strong>%d broken link</strong> found.',
                                                    '<strong>%d broken links</strong> found.',
                                                    $broken_count,
                                                    'thisismyurl-external-link-control'
                                                ),
                                                $broken_count
                                            ),
                                            array( 'strong' => array() )
                                        );
                                        ?>
                                        &nbsp;<a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>"><?php esc_html_e( 'View on Dashboard', 'thisismyurl-external-link-control' ); ?></a>
                                    </p>
                                <?php elseif ( $checked_at > 0 ) : ?>
                                    <p class="timu-elc-status-ok"><?php esc_html_e( 'No broken links found.', 'thisismyurl-external-link-control' ); ?></p>
                                <?php else : ?>
                                    <p class="description"><?php esc_html_e( 'No scan run yet. The weekly scan runs automatically, or you can trigger it from the Dashboard widget.', 'thisismyurl-external-link-control' ); ?></p>
                                <?php endif; ?>
                                <?php if ( $checked_at > 0 ) : ?>
                                    <p class="description">
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                /* translators: %s: human-readable time difference. */
                                                __( 'Last scan: %s ago.', 'thisismyurl-external-link-control' ),
                                                human_time_diff( $checked_at )
                                            )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Documentation -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Documentation', 'thisismyurl-external-link-control' ); ?></span></h2>
                            <div class="inside">
                                <p><?php esc_html_e( 'Links are modified at render time. Your stored post content is never rewritten.', 'thisismyurl-external-link-control' ); ?></p>
                                <hr />
                                <p><strong><?php esc_html_e( 'WP-CLI', 'thisismyurl-external-link-control' ); ?></strong></p>
                                <p>
                                    <code>wp elc audit</code> &mdash; <?php esc_html_e( 'list all external domains', 'thisismyurl-external-link-control' ); ?><br>
                                    <code>wp elc rewrite --post_id=123 --dry-run</code> &mdash; <?php esc_html_e( 'preview what would change on a post', 'thisismyurl-external-link-control' ); ?>
                                </p>
                                <hr />
                                <p><a href="<?php echo esc_url( 'https://github.com/thisismyurl/thisismyurl-external-link-control' ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e( 'GitHub', 'thisismyurl-external-link-control' ); ?></a>
                                <a href="<?php echo esc_url( 'https://github.com/sponsors/thisismyurl' ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e( 'Sponsor', 'thisismyurl-external-link-control' ); ?></a></p>
                            </div>
                        </div>

                    </div><!-- #postbox-container-1 -->
                </div><!-- #post-body -->
            </div><!-- #poststuff -->
        </div><!-- .wrap -->
        <?php
    }

    /**
     * AJAX handler: return the link inventory as a JSON response.
     *
     * Delegates to the registered REST route so the transient cache is
     * shared between REST and AJAX callers.
     *
     * @return void
     */
    public function ajax_inventory() {
        check_ajax_referer( 'wp_rest', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( null, 403 );
        }

        $request = new WP_REST_Request( 'GET', '/timu-elc/v1/inventory' );
        $request->set_param( 'orderby', 'count' );
        $request->set_param( 'order', 'desc' );
        $request->set_param( 'limit', 100 );
        $response = rest_do_request( $request );

        if ( $response->is_error() ) {
            wp_send_json_error( null, 500 );
        }

        wp_send_json_success( $response->get_data() );
    }
}

TIMU_ELC::instance();
