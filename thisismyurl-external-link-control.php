<?php
/**
 * Plugin Name:       External Link Control by thisismyurl.com
 * Plugin URI:        https://thisismyurl.com/external-link-control
 * Description:       Globally manage external link behavior, including nofollow and target attributes.
 * Version:           0.6123
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * Text Domain:       thisismyurl-external-link-control
 * License:           GPLv2 or later
 * Donate link:       https://thisismyurl.com/donate/
 * GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-external-link-control
 * Primary Branch:    main
 * * @package TIMU_ELC
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TIMU_ELC {

    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_plugin_settings' ) );
        add_action( 'admin_menu', array( $this, 'create_tools_page' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
        add_filter( 'the_content', array( $this, 'modify_external_links' ), 99 );

        // Set defaults upon activation.
        register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );
    }

    /**
     * Activate Plugin Defaults:
     * Sets Master Switch, New Tab, and Nofollow to '1' by default.
     */
    public function activate_plugin_defaults() {
        if ( false === get_option( 'timu_elc_options' ) ) {
            update_option( 'timu_elc_options', array(
                'enabled'  => 1,
                'new_tab'  => 1,
                'nofollow' => 1,
            ) );
        }
    }

    public function add_plugin_action_links( $links ) {
        $custom_links = array(
            '<a href="' . admin_url( 'tools.php?page=thisismyurl-external-link-control' ) . '">' . esc_html__( 'Settings', 'thisismyurl-external-link-control' ) . '</a>',
            '<a href="https://thisismyurl.com/donate/" target="_blank" style="color: #2271b1; font-weight: bold;">' . esc_html__( 'Donate', 'thisismyurl-external-link-control' ) . '</a>',
        );
        return array_merge( $custom_links, $links );
    }

    public function register_plugin_settings() {
        register_setting( 
            'timu_elc_settings_group', 
            'timu_elc_options', 
            array( 'sanitize_callback' => array( $this, 'sanitize_plugin_options' ) )
        );
    }

    public function sanitize_plugin_options( $input ) {
        $new_input = array();
        $new_input['enabled']   = isset( $input['enabled'] ) ? 1 : 0;
        $new_input['new_tab']   = isset( $input['new_tab'] ) ? 1 : 0;
        $new_input['nofollow']  = isset( $input['nofollow'] ) ? 1 : 0;
        return $new_input;
    }

    public function create_tools_page() {
        add_management_page(
            __( 'External Link Control', 'thisismyurl-external-link-control' ),
            __( 'Link Control', 'thisismyurl-external-link-control' ),
            'manage_options',
            'thisismyurl-external-link-control',
            array( $this, 'render_plugin_admin_ui' )
        );
    }

    public function modify_external_links( $content ) {
        $options = get_option( 'timu_elc_options' );
        if ( empty( $options['enabled'] ) ) {
            return $content;
        }

        return preg_replace_callback( '/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>/i', function( $matches ) use ( $options ) {
            $link_html = $matches[0];
            $url       = $matches[1];
            $site_url  = get_site_url();

            if ( strpos( $url, $site_url ) === false && strpos( $url, 'http' ) === 0 ) {
                if ( ! empty( $options['new_tab'] ) && false === strpos( $link_html, 'target=' ) ) {
                    $link_html = str_replace( '<a ', '<a target="_blank" ', $link_html );
                }
                if ( ! empty( $options['nofollow'] ) && false === strpos( $link_html, 'rel=' ) ) {
                    $link_html = str_replace( '<a ', '<a rel="nofollow noopener noreferrer" ', $link_html );
                }
            }
            return $link_html;
        }, $content );
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
                <span style="font-size: 0.5em; font-weight: normal; vertical-align: middle; margin-left: 10px; color: #646970;">
                    <?php printf( 
                        esc_html__( 'by %s', 'thisismyurl-external-link-control' ), 
                        '<a href="https://thisismyurl.com/" target="_blank" style="text-decoration: none; color: inherit;">thisismyurl.com</a>' 
                    ); ?>
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
                                    </table>
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
                                <p><a href="https://thisismyurl.com/donate/" class="button button-secondary" target="_blank"><?php esc_html_e( 'Donate to Development', 'thisismyurl-external-link-control' ); ?></a></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

new TIMU_ELC();

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