<?php
/**
 * Plugin Name:       External Link Control by thisismyurl.com
 * Plugin URI:        https://thisismyurl.com/external-link-control
 * Description:       Globally manage external link behavior, including nofollow and target attributes.
 * Version:           1.6143
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * Text Domain:       thisismyurl-external-link-control
 * License:           GPL-2.0-or-later
 * Donate link:       https://thisismyurl.com/donate/
 * GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-external-link-control
 * Primary Branch:    main
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

        new ELC_Link_Checker();
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
        register_deactivation_hook( __FILE__, array( 'ELC_Link_Checker', 'deactivate' ) );
    }

    /**
     * Activate Plugin Defaults:
     * Sets Master Switch, New Tab, and Nofollow to '1' by default.
     */
    public function activate_plugin_defaults() {
        if ( false === get_option( 'timu_elc_options' ) ) {
            add_option( 'timu_elc_options', array(
                'enabled'      => 1,
                'new_tab'      => 1,
                'nofollow'     => 1,
                'comment_ugc'  => 1,
            ), '', 'no' );
        }
    }

    public function add_plugin_action_links( $links ) {
        $custom_links = array(
            '<a href="' . esc_url( admin_url( 'tools.php?page=thisismyurl-external-link-control' ) ) . '">' . esc_html__( 'Settings', 'thisismyurl-external-link-control' ) . '</a>',
            '<a href="https://thisismyurl.com/donate/" target="_blank" rel="noopener noreferrer" class="timu-elc-donate-link">' . esc_html__( 'Donate', 'thisismyurl-external-link-control' ) . '</a>',
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
                '1.6143'
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
        $new_input['nofollow']    = isset( $input['nofollow'] ) ? 1 : 0;
        $new_input['comment_ugc'] = isset( $input['comment_ugc'] ) ? 1 : 0;
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

        $options = get_option( 'timu_elc_options' );
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'External Link Control', 'thisismyurl-external-link-control' ); ?>
                <span class="timu-elc-byline">
                    <?php
                    printf(
                        /* translators: %s: brand name "thisismyurl.com" rendered as a link. */
                        esc_html__( 'by %s', 'thisismyurl-external-link-control' ),
                        '<a href="https://thisismyurl.com/" target="_blank" rel="noopener noreferrer" class="timu-elc-byline-link">thisismyurl.com</a>'
                    );
                    ?>
                </span>
            </h1>
            
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
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
                                            <th scope="row"><?php esc_html_e( 'Force New Tab', 'thisismyurl-external-link-control' ); ?></th>
                                            <td>
                                                <input type="checkbox" id="timu_elc_new_tab" name="timu_elc_options[new_tab]" value="1" <?php checked( 1, isset( $options['new_tab'] ) ? $options['new_tab'] : 0 ); ?> />
                                                <label for="timu_elc_new_tab"><?php esc_html_e( 'Open external links in a new window.', 'thisismyurl-external-link-control' ); ?></label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'SEO Nofollow', 'thisismyurl-external-link-control' ); ?></th>
                                            <td>
                                                <input type="checkbox" id="timu_elc_nofollow" name="timu_elc_options[nofollow]" value="1" <?php checked( 1, isset( $options['nofollow'] ) ? $options['nofollow'] : 0 ); ?> />
                                                <label for="timu_elc_nofollow"><?php esc_html_e( "Protect link equity with 'nofollow'.", 'thisismyurl-external-link-control' ); ?></label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Comment UGC', 'thisismyurl-external-link-control' ); ?></th>
                                            <td>
                                                <input type="checkbox" id="timu_elc_comment_ugc" name="timu_elc_options[comment_ugc]" value="1" <?php checked( 1, isset( $options['comment_ugc'] ) ? $options['comment_ugc'] : 0 ); ?> />
                                                <label for="timu_elc_comment_ugc"><?php esc_html_e( "Add rel='ugc' to external links inside comments (Google's recommended attribute for user-generated content).", 'thisismyurl-external-link-control' ); ?></label>
                                            </td>
                                        </tr>
                                    </table>
                                    <h2 class="title"><?php esc_html_e( 'Per-Domain Rules', 'thisismyurl-external-link-control' ); ?></h2>
                                    <p class="description">
                                        <?php esc_html_e( 'Override the global behaviour for specific domains. Use this for rel="me" / sameAs profiles you want left dofollow, paid placements that should always carry rel="sponsored", or first-party properties you want to open in the same tab.', 'thisismyurl-external-link-control' ); ?>
                                    </p>
                                    <table class="widefat striped timu-elc-domain-rules">
                                        <thead>
                                            <tr>
                                                <th scope="col"><?php esc_html_e( 'Domain', 'thisismyurl-external-link-control' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Dofollow', 'thisismyurl-external-link-control' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Target', 'thisismyurl-external-link-control' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Sponsored', 'thisismyurl-external-link-control' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Allowlist (rel=me / sameAs)', 'thisismyurl-external-link-control' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $rules = TIMU_ELC_Domain_Rules::all();
                                            // Always render at least three blank rows so the form is usable on a fresh install.
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
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <p class="description">
                                        <?php esc_html_e( 'Tip: leave a row\'s domain blank to delete it on save. Subdomains inherit the parent rule (a rule on linkedin.com applies to www.linkedin.com automatically).', 'thisismyurl-external-link-control' ); ?>
                                    </p>
                                    <?php submit_button( __( 'Update Link Settings', 'thisismyurl-external-link-control' ) ); ?>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Documentation', 'thisismyurl-external-link-control' ); ?></span></h2>
                            <div class="inside">
                                <p><?php esc_html_e( 'This plugin modifies links dynamically during page render, keeping your database clean.', 'thisismyurl-external-link-control' ); ?></p>
                                <hr />
                                <p><a href="https://thisismyurl.com/donate/" class="button button-secondary" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Donate to Development', 'thisismyurl-external-link-control' ); ?></a></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

TIMU_ELC::instance();

/**
 * GitHub Updater Integration.
 */
add_action( 'plugins_loaded', function() {
    $updater_path = plugin_dir_path( __FILE__ ) . 'updater.php';
    if ( file_exists( $updater_path ) ) {
        require_once $updater_path;
        if ( class_exists( 'FWO_GitHub_Updater' ) ) {
            new FWO_GitHub_Updater( array(
                'slug'               => 'thisismyurl-external-link-control',
                'proper_folder_name' => 'thisismyurl-external-link-control',
                'api_url'            => 'https://api.github.com/repos/thisismyurl/thisismyurl-external-link-control/releases/latest',
                'github_url'         => 'https://github.com/thisismyurl/thisismyurl-external-link-control',
                'plugin_file'        => __FILE__,
            ) );
        }
    }
});