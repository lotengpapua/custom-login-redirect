<?php

/**
 * Plugin Name:          Custom Login & Redirect Manager
 * Plugin URI:           https://loteng.net/
 * Description:          Login page kustom dengan nama template & slug login yang bisa diatur dari Settings. Semua pengaturan dinamis tanpa perlu edit kode. Integrasi WPS Hide Login.
 * Version:              3.0.0
 * Author:               Loteng Anova Teknologi
 * Author URI:           https://loteng.net
 * Requires License:     yes
 * Text Domain:          custom-login-redirect
 * Requires at least:    4.1
 * Requires PHP:         7.0
 * UpdatePulse Package:  custom-login-redirect
 * UpdatePulse Server:   https://loteng.net/wp-json/updatepulse-server/v1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CLR_VERSION',    '3.0.0' );
define( 'CLR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CLR_OPTION_KEY', 'clr_settings' );

class CLR_Plugin {

    const OPTION_KEY = CLR_OPTION_KEY;

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_head',    [ $this, 'inject_plugin_icon_css' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
        add_action( 'admin_post_clr_install_wps_hide_login', [ $this, 'handle_install_wps_hide_login' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_styles' ] );

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );

        // Template dinamis
        add_filter( 'theme_page_templates', [ $this, 'register_page_template' ], 10, 3 );
        add_filter( 'theme_post_templates', [ $this, 'register_page_template' ], 10, 3 );
        add_filter( 'template_include',     [ $this, 'load_custom_template' ], 99 );

        // Redirect
        add_filter( 'login_redirect',  [ $this, 'redirect_after_login' ], 10, 3 );
        add_filter( 'logout_redirect', [ $this, 'redirect_after_logout' ], 10, 3 );

        // Blokir wp-admin
        add_action( 'admin_init', [ $this, 'block_wp_admin' ] );

        // Homepage default
        add_filter( 'option_home', [ $this, 'maybe_change_home' ] );

        // Toolbar logout
        add_action( 'admin_bar_menu', [ $this, 'add_logout_toolbar_node' ], 999 );

        // Shortcode
        add_shortcode( 'clr_logout_button', [ $this, 'shortcode_logout_button' ] );
    }

    /* =====================================================================
     *  DEFAULTS
     * =================================================================== */

    public function defaults() {
        return [
            'template_name'         => 'Login Page Template',
            'template_slug'         => 'login-page-template',
            'login_url_slug'        => 'login-admin-web',
            'after_login_url'       => 'dashboard-admin',
            'after_logout_url'      => '',
            'default_homepage'      => '',
            'enable_wpadmin_block'  => 1,
            'wpadmin_redirect'      => '404',
            'wpadmin_custom'        => '',
            'enable_login_template' => 1,
            'sync_wps_hide_login'   => 1,
        ];
    }

    public function get_settings() {
        return wp_parse_args( get_option( self::OPTION_KEY, [] ), $this->defaults() );
    }

    public function get_template_name() {
        $opts = $this->get_settings();
        $name = trim( $opts['template_name'] );
        return $name !== '' ? $name : 'Login Page Template';
    }

    public function get_template_slug() {
        $opts = $this->get_settings();
        $slug = ! empty( $opts['template_slug'] ) ? $opts['template_slug'] : sanitize_title( $this->get_template_name() );
        return $slug;
    }

    /* =====================================================================
     *  HELPER IKON
     * =================================================================== */

    public function get_icon_url( $name ) {
        $svg = CLR_PLUGIN_DIR . "assets/{$name}.svg";
        $png = CLR_PLUGIN_DIR . "assets/{$name}.png";
        if ( file_exists( $svg ) ) return CLR_PLUGIN_URL . "assets/{$name}.svg";
        if ( file_exists( $png ) ) return CLR_PLUGIN_URL . "assets/{$name}.png";
        return '';
    }

    /* =====================================================================
     *  ADMIN MENU & SETTINGS
     * =================================================================== */

    public function add_admin_menu() {
        add_options_page(
            'Custom Login & Redirect',
            'Custom Login & Redirect',
            'manage_options',
            'custom-login-redirect',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'clr_settings_group', self::OPTION_KEY, [ $this, 'sanitize_settings' ] );
    }

    public function sanitize_settings( $input ) {
        $old   = get_option( self::OPTION_KEY, [] );
        $clean = [];

        // Nama Template
        $clean['template_name'] = sanitize_text_field( $input['template_name'] ?? 'Login Page Template' );
        if ( $clean['template_name'] === '' ) {
            $clean['template_name'] = 'Login Page Template';
        }
        // Slug otomatis dari nama
        $clean['template_slug'] = sanitize_title( $clean['template_name'] );

        // Login URL Slug
        $clean['login_url_slug'] = sanitize_title( $input['login_url_slug'] ?? '' );
        if ( $clean['login_url_slug'] === '' ) {
            $clean['login_url_slug'] = 'login-admin-web';
        }

        // Redirect
        $clean['after_login_url']  = esc_url_raw( $input['after_login_url']  ?? '' );
        $clean['after_logout_url'] = esc_url_raw( $input['after_logout_url'] ?? '' );
        $clean['default_homepage'] = esc_url_raw( $input['default_homepage'] ?? '' );

        // Blokir wp-admin
        $clean['enable_wpadmin_block'] = ! empty( $input['enable_wpadmin_block'] ) ? 1 : 0;
        $clean['wpadmin_redirect']     = in_array( $input['wpadmin_redirect'] ?? '404', [ '404', 'homepage', 'custom' ], true )
            ? $input['wpadmin_redirect'] : '404';
        $clean['wpadmin_custom']       = esc_url_raw( $input['wpadmin_custom'] ?? '' );

        // Toggle
        $clean['enable_login_template'] = ! empty( $input['enable_login_template'] ) ? 1 : 0;
        $clean['sync_wps_hide_login']   = ! empty( $input['sync_wps_hide_login'] )   ? 1 : 0;

        // Sinkron ke WPS Hide Login
        if ( ! empty( $clean['sync_wps_hide_login'] ) && $this->is_wps_hide_login_active() ) {
            update_option( 'whl_page', $clean['login_url_slug'] );
        }

        // Notice jika slug template berubah
        if ( ! empty( $old['template_slug'] ) && $old['template_slug'] !== $clean['template_slug'] ) {
            set_transient( 'clr_template_changed', 1, 60 );
        }

        return $clean;
    }

    public function plugin_action_links( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=custom-login-redirect' ) ) . '">Pengaturan</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /* =====================================================================
     *  IKON & CSS
     * =================================================================== */

    public function inject_plugin_icon_css() {
        $screen = get_current_screen();
        if ( ! $screen || 'plugins' !== $screen->id ) return;
        $icon = $this->get_icon_url( 'icon-plugin' );
        if ( empty( $icon ) ) return;
        $slug = 'custom-login-redirect';
        ?>
        <style>
            tr[data-slug="<?php echo esc_js( $slug ); ?>"] .plugin-title strong::before {
                content: "";
                display: inline-block;
                width: 20px; height: 20px;
                margin-right: 6px;
                vertical-align: middle;
                background-image: url("<?php echo esc_url( $icon ); ?>");
                background-size: contain;
                background-repeat: no-repeat;
                background-position: center;
            }
        </style>
        <?php
    }

    public function admin_styles() {
        $logout_icon = $this->get_icon_url( 'icon-logout' );
        if ( empty( $logout_icon ) ) return;
        $css = "
            #wpadminbar #wp-admin-bar-clr-logout > .ab-item::before {
                content: '';
                display: inline-block;
                width: 16px; height: 16px;
                margin-right: 6px;
                vertical-align: -3px;
                background-image: url('{$logout_icon}');
                background-size: contain;
                background-repeat: no-repeat;
                background-position: center;
            }
        ";
        wp_register_style( 'clr-admin-inline', false );
        wp_enqueue_style( 'clr-admin-inline' );
        wp_add_inline_style( 'clr-admin-inline', $css );
    }

    /* =====================================================================
     *  ADMIN NOTICES
     * =================================================================== */

    public function admin_notices() {
        // Notice template berubah
        if ( get_transient( 'clr_template_changed' ) ) {
            delete_transient( 'clr_template_changed' );
            ?>
            <div class="notice notice-info is-dismissible">
                <p>Nama template login berhasil diubah. Silakan refresh halaman editor (F5) untuk melihat nama baru di <strong>Page Attributes → Template</strong>.</p>
            </div>
            <?php
        }

        // Notice WPS Hide Login
        if ( $this->is_wps_hide_login_active() ) return;
        if ( ! current_user_can( 'install_plugins' ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || 'settings_page_custom-login-redirect' !== $screen->id ) return;
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong>Integrasi WPS Hide Login:</strong> Plugin <em>Custom Login &amp; Redirect</em> bekerja optimal jika <em>WPS Hide Login</em> juga aktif.
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clr_install_wps_hide_login' ), 'clr_install_wps' ) ); ?>">Install &amp; Aktifkan sekarang</a>.
            </p>
        </div>
        <?php
    }

    /* =====================================================================
     *  HALAMAN PENGATURAN
     * =================================================================== */

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $opts = $this->get_settings();

        $icon_plugin = $this->get_icon_url( 'icon-plugin' );
        $icon_login  = $this->get_icon_url( 'icon-login' );
        $icon_logout = $this->get_icon_url( 'icon-logout' );

        $current_template_name = $this->get_template_name();
        $current_template_slug = $this->get_template_slug();
        ?>
        <div class="wrap">
            <h1>Custom Login &amp; Redirect Manager</h1>

            <?php if ( ! $this->is_wps_hide_login_active() ) : ?>
                <div class="notice notice-warning">
                    <p><strong>Rekomendasi:</strong> Aktifkan plugin <em>WPS Hide Login</em> untuk keamanan maksimal.</p>
                    <p>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clr_install_wps_hide_login' ), 'clr_install_wps' ) ); ?>"
                           class="button button-primary">Install &amp; Aktifkan WPS Hide Login</a>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-success">
                    <p>✅ WPS Hide Login terdeteksi. URL login akan otomatis disinkronkan.</p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info">
                <p>
                    <strong>Template saat ini:</strong> <code><?php echo esc_html( $current_template_name ); ?></code>
                    <br>
                    Muncul di <strong>Pages → Add/Edit → Page Attributes → Template</strong>.
                    Ubah namanya di kolom <strong>Nama Template</strong> di bawah — slug dibuat otomatis.
                </p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'clr_settings_group' ); ?>

                <h2 class="title">Template Login</h2>
                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row">Aktifkan Template Login</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_login_template]"
                                       value="1" <?php checked( $opts['enable_login_template'], 1 ); ?>>
                                Ya, aktifkan template login kustom
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="clr_template_name">Nama Template</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="clr_template_name"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[template_name]"
                                   value="<?php echo esc_attr( $current_template_name ); ?>"
                                   class="regular-text"
                                   placeholder="Login Page Template"
                                   required>
                            <p class="description">
                                Nama yang muncul di dropdown <strong>Page Attributes → Template</strong>.<br>
                                Slug internal saat ini: <code><?php echo esc_html( $current_template_slug ); ?></code><br>
                                💡 <strong>Slug dibuat otomatis</strong> dari nama ini — tidak perlu diisi manual.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="clr_login_slug">Login URL Slug</label>
                        </th>
                        <td>
                            <code><?php echo esc_html( home_url( '/' ) ); ?></code>
                            <input type="text"
                                   id="clr_login_slug"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[login_url_slug]"
                                   value="<?php echo esc_attr( $opts['login_url_slug'] ); ?>"
                                   class="regular-text"
                                   placeholder="login-admin-web"
                                   required>
                            <p class="description">
                                URL login Anda: <strong><?php echo esc_html( home_url( '/' . $opts['login_url_slug'] . '/' ) ); ?></strong><br>
                                Jika WPS Hide Login aktif, nilai ini akan <strong>otomatis disinkronkan</strong>.
                            </p>
                        </td>
                    </tr>

                    <?php if ( $this->is_wps_hide_login_active() ) : ?>
                    <tr>
                        <th scope="row">Sinkron WPS Hide Login</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_wps_hide_login]"
                                       value="1" <?php checked( $opts['sync_wps_hide_login'], 1 ); ?>>
                                Sinkronkan Login URL Slug ke pengaturan WPS Hide Login
                            </label>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <h2 class="title">Redirect</h2>
                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row"><label for="clr_after_login">Redirect After Login</label></th>
                        <td>
                            <input type="text"
                                   id="clr_after_login"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[after_login_url]"
                                   value="<?php echo esc_attr( $opts['after_login_url'] ); ?>"
                                   class="regular-text"
                                   placeholder="dashboard-admin">
                            <p class="description">Contoh: <code>dashboard-admin</code> (relatif) atau <code>https://situs.com/dashboard/</code>.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="clr_after_logout">Redirect After Logout</label></th>
                        <td>
                            <input type="text"
                                   id="clr_after_logout"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[after_logout_url]"
                                   value="<?php echo esc_attr( $opts['after_logout_url'] ); ?>"
                                   class="regular-text"
                                   placeholder="Kosongkan = homepage">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="clr_default_home">Default Homepage</label></th>
                        <td>
                            <input type="text"
                                   id="clr_default_home"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_homepage]"
                                   value="<?php echo esc_attr( $opts['default_homepage'] ); ?>"
                                   class="regular-text"
                                   placeholder="Kosongkan = tidak diubah">
                        </td>
                    </tr>
                </table>

                <h2 class="title">Blokir wp-admin</h2>
                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row">Aktifkan Blokir</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_wpadmin_block]"
                                       value="1" <?php checked( $opts['enable_wpadmin_block'], 1 ); ?>>
                                Blokir akses <code>/login-admin/</code> untuk user yang belum login
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="clr_wpadmin">Redirect wp-admin</label></th>
                        <td>
                            <select id="clr_wpadmin" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[wpadmin_redirect]">
                                <option value="404"      <?php selected( $opts['wpadmin_redirect'], '404' ); ?>>404 Not Found</option>
                                <option value="homepage" <?php selected( $opts['wpadmin_redirect'], 'homepage' ); ?>>Homepage</option>
                                <option value="custom"   <?php selected( $opts['wpadmin_redirect'], 'custom' ); ?>>Custom URL</option>
                            </select>
                            <input type="text"
                                   name="<?php echo esc_attr( self::OPTION_KEY ); ?>[wpadmin_custom]"
                                   value="<?php echo esc_attr( $opts['wpadmin_custom'] ); ?>"
                                   class="regular-text"
                                   placeholder="https://..."
                                   style="margin-top:6px;">
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Shortcode Logout</h2>
            <p>Gunakan shortcode berikut di halaman/post mana pun:</p>
            <p><code>[clr_logout_button text="Logout"]</code></p>
        </div>
        <?php
    }

    /* =====================================================================
     *  TEMPLATE DINAMIS
     * =================================================================== */

    public function register_page_template( $post_templates, $theme = null, $post = null ) {
        $opts = $this->get_settings();
        if ( empty( $opts['enable_login_template'] ) ) {
            return $post_templates;
        }
        $name = $this->get_template_name();
        $slug = $this->get_template_slug();
        $post_templates[ $slug . '.php' ] = $name;
        return $post_templates;
    }

    public function load_custom_template( $template ) {
        $opts = $this->get_settings();
        if ( empty( $opts['enable_login_template'] ) ) {
            return $template;
        }
        if ( ! is_page() && ! is_singular() ) {
            return $template;
        }

        $post_id       = get_the_ID();
        $page_template = get_post_meta( $post_id, '_wp_page_template', true );
        $current_slug  = $this->get_template_slug();

        $is_our_template = (
            $page_template === $current_slug . '.php' ||
            $page_template === $current_slug ||
            in_array( $page_template, [
                'custom-circles-login',
                'login-page-template.php',
                'login-page-template',
            ], true )
        );

        if ( ! $is_our_template ) {
            return $template;
        }

        if ( is_user_logged_in() ) {
            wp_safe_redirect( $this->get_after_login_url() );
            exit;
        }

        $login_error = '';
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['circles_login_nonce'] ) ) {
            if ( wp_verify_nonce( $_POST['circles_login_nonce'], 'circles_login_action' ) ) {
                $creds = [
                    'user_login'    => sanitize_text_field( $_POST['log'] ?? '' ),
                    'user_password' => $_POST['pwd'] ?? '',
                    'remember'      => isset( $_POST['rememberme'] ),
                ];
                $user = wp_signon( $creds, is_ssl() );
                if ( is_wp_error( $user ) ) {
                    $login_error = $user->get_error_message();
                } else {
                    wp_safe_redirect( $this->get_after_login_url() );
                    exit;
                }
            }
        }

        $this->render_login_page( $login_error );
        exit;
    }

    /* =====================================================================
     *  RENDER LOGIN
     * =================================================================== */

    private function render_login_page( $login_error = '' ) {
        $site_tagline = get_bloginfo( 'description' );
        if ( empty( $site_tagline ) ) {
            $site_tagline = 'Selamat Datang di Portal Sekretariat DPR Papua Tengah';
        }
        $icon_login = $this->get_icon_url( 'icon-login' );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Masuk ke Dashboard - <?php bloginfo( 'name' ); ?></title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
            <?php wp_head(); ?>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
                body { background-color: #040d8a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
                .login-wrapper {
                    position: relative; background: #020b2d;
                    background: radial-gradient(circle at top left, #1a1052, #020b2d 70%);
                    width: 100%; max-width: 1100px; min-height: 600px;
                    border-radius: 24px; padding: 40px; display: flex; flex-direction: column;
                    justify-content: space-between; overflow: hidden;
                    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
                }
                .login-wrapper::before, .login-wrapper::after {
                    content: ''; position: absolute; border-radius: 50%;
                    border: 80px solid rgba(255,255,255,0.03); pointer-events: none;
                }
                .login-wrapper::before { width: 500px; height: 500px; top: -150px; left: -100px; }
                .login-wrapper::after  { width: 600px; height: 600px; bottom: -200px; left: 100px; }
                .brand-header { display: flex; align-items: center; gap: 12px; color: #fff; font-size: 22px; font-weight: 700; z-index: 2; }
                .brand-header img { max-height: 50px; width: auto; object-fit: contain; }
                .main-content { display: flex; align-items: center; justify-content: space-between; gap: 40px; margin: 40px 0; z-index: 2; }
                .hero-text { color: #fff; max-width: 420px; }
                .hero-text h1 { color: #fff; font-size: 38px; font-weight: 700; line-height: 1.2; margin-bottom: 12px; }
                .hero-text p { color: #94a3b8; font-size: 16px; line-height: 1.5; }
                .card-form { background: #fff; border-radius: 16px; padding: 36px; width: 100%; max-width: 420px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
                .form-group { margin-bottom: 20px; position: relative; }
                .form-group label { display: block; font-size: 13px; color: #475569; margin-bottom: 8px; font-weight: 500; }
                .input-wrapper { position: relative; }
                .form-group input[type="text"], .form-group input[type="password"] {
                    width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 8px;
                    font-size: 14px; outline: none; transition: border-color 0.2s;
                }
                .form-group input:focus { border-color: #2563eb; }
                .toggle-password {
                    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
                    background: none; border: none; cursor: pointer; color: #64748b; font-size: 13px; font-weight: 500;
                }
                .remember-me { display: flex; align-items: center; gap: 8px; margin-bottom: 24px; font-size: 13px; color: #475569; cursor: pointer; }
                .remember-me input { width: 16px; height: 16px; accent-color: #2563eb; }
                .error-msg { background: #fee2e2; color: #dc2626; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 15px; }
                .btn-submit {
                    background: #2563eb; color: #fff; border: none; padding: 12px 32px; border-radius: 8px;
                    font-weight: 600; font-size: 14px; cursor: pointer; width: 100%; transition: background .2s;
                    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
                }
                .btn-submit:hover { background: #1d4ed8; }
                .btn-submit .icon-login { width: 18px; height: 18px; display: inline-block; }
                .copyright { color: #94a3b8; font-size: 12px; text-align: center; z-index: 2; }
                @media (max-width: 768px) {
                    .login-wrapper { padding: 24px; min-height: auto; }
                    .main-content { flex-direction: column; align-items: flex-start; gap: 24px; }
                    .hero-text h1 { font-size: 28px; }
                    .card-form { max-width: 100%; padding: 24px; }
                }
            </style>
        </head>
        <body>
        <div class="login-wrapper">
            <div class="brand-header">
                <?php if ( has_custom_logo() ) { the_custom_logo(); } else { echo '<span>' . esc_html( get_bloginfo( 'name' ) ) . '</span>'; } ?>
            </div>
            <div class="main-content">
                <div class="hero-text">
                    <h1>Masuk ke Dashboard</h1>
                    <p><?php echo esc_html( $site_tatitle ); ?></p>
                </div>
                <div class="card-form">
                    <?php if ( ! empty( $login_error ) ) : ?>
                        <div class="error-msg"><?php echo wp_kses_post( $login_error ); ?></div>
                    <?php endif; ?>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'circles_login_action', 'circles_login_nonce' ); ?>
                        <div class="form-group">
                            <label for="log">Username / Email</label>
                            <input type="text" name="log" id="log" placeholder="Masukkan username atau email" required>
                        </div>
                        <div class="form-group">
                            <label for="pwd">Password</label>
                            <div class="input-wrapper">
                                <input type="password" name="pwd" id="pwd" placeholder="Masukkan password" required>
                                <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">Tampilkan</button>
                            </div>
                        </div>
                        <label class="remember-me">
                            <input type="checkbox" name="rememberme" value="forever">
                            Remember me
                        </label>
                        <button type="submit" class="btn-submit">
                            <?php if ( ! empty( $icon_login ) ) : ?>
                                <img src="<?php echo esc_url( $icon_login ); ?>" alt="" class="icon-login">
                            <?php endif; ?>
                            Login
                        </button>
                    </form>
                </div>
            </div>
            <div class="copyright">
              Copyright &copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>. 
              All Rights Reserved. 
            Build by <a href="https://loteng.net" target="_blank" rel="noopener noreferrer">Loteng Kreatif</a>.
          </div>
        </div>
        <script>
            function togglePasswordVisibility() {
                const p = document.getElementById('pwd');
                const b = document.querySelector('.toggle-password');
                if (p.type === 'password') { p.type = 'text'; b.textContent = 'Sembunyikan'; }
                else { p.type = 'password'; b.textContent = 'Tampilkan'; }
            }
        </script>
        <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    /* =====================================================================
     *  REDIRECT HELPER
     * =================================================================== */

    private function resolve_url( $value ) {
        if ( empty( $value ) ) return '';
        if ( preg_match( '#^https?://#i', $value ) ) return $value;
        return home_url( '/' . ltrim( $value, '/' ) );
    }

    public function get_after_login_url() {
        $opts = $this->get_settings();
        $url  = $this->resolve_url( $opts['after_login_url'] );
        return $url ? $url : admin_url();
    }

    public function get_after_logout_url() {
        $opts = $this->get_settings();
        $url  = $this->resolve_url( $opts['after_logout_url'] );
        return $url ? $url : home_url( '/' );
    }

    public function redirect_after_login( $redirect_to, $requested, $user ) {
        if ( is_wp_error( $user ) ) return $redirect_to;
        $opts = $this->get_settings();
        if ( ! empty( $opts['after_login_url'] ) ) {
            return $this->get_after_login_url();
        }
        return $redirect_to;
    }

    public function redirect_after_logout( $redirect_to, $requested, $user ) {
        return $this->get_after_logout_url();
    }

    /* =====================================================================
     *  BLOKIR WP-ADMIN
     * =================================================================== */

    public function block_wp_admin() {
        $opts = $this->get_settings();
        if ( empty( $opts['enable_wpadmin_block'] ) ) return;
        if ( is_user_logged_in() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) return;
        if ( isset( $GLOBALS['pagenow'] ) && in_array( $GLOBALS['pagenow'], [ 'wp-login.php', 'wp-register.php' ], true ) ) return;

        $mode = $opts['wpadmin_redirect'];
        if ( 'homepage' === $mode ) { wp_safe_redirect( home_url( '/' ) ); exit; }
        if ( 'custom' === $mode && ! empty( $opts['wpadmin_custom'] ) ) { wp_safe_redirect( $opts['wpadmin_custom'] ); exit; }

        status_header( 404 );
        nocache_headers();
        $template = get_404_template();
        if ( $template ) { include $template; } else { wp_die( 'Not Found', '', [ 'response' => 404 ] ); }
        exit;
    }

    /* =====================================================================
     *  HOMEPAGE
     * =================================================================== */

    public function maybe_change_home( $value ) {
        $opts = $this->get_settings();
        if ( ! empty( $opts['default_homepage'] ) && ! is_admin() ) {
            return $opts['default_homepage'];
        }
        return $value;
    }

    /* =====================================================================
     *  WPS HIDE LOGIN
     * =================================================================== */

    public function is_wps_hide_login_active() {
        return class_exists( 'WPSHideLogin' ) || defined( 'WPS_HIDE_LOGIN_VERSION' );
    }

    public function handle_install_wps_hide_login() {
        if ( ! current_user_can( 'install_plugins' ) ) wp_die( 'Akses ditolak.' );
        check_admin_referer( 'clr_install_wps' );

        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        $slug = 'wps-hide-login';
        $api  = plugins_api( 'plugin_information', [ 'slug' => $slug, 'fields' => [ 'sections' => false ] ] );
        if ( is_wp_error( $api ) ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=custom-login-redirect&clr_error=' . urlencode( $api->get_error_message() ) ) );
            exit;
        }

        $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
        $result   = $upgrader->install( $api->download_link );
        if ( is_wp_error( $result ) || ! $result ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=custom-login-redirect&clr_error=install_failed' ) );
            exit;
        }

        $activate = activate_plugin( 'wps-hide-login/wps-hide-login.php' );
        if ( is_wp_error( $activate ) ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=custom-login-redirect&clr_error=' . urlencode( $activate->get_error_message() ) ) );
            exit;
        }

        $opts = $this->get_settings();
        if ( ! empty( $opts['login_url_slug'] ) ) {
            update_option( 'whl_page', $opts['login_url_slug'] );
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=custom-login-redirect&clr_success=1' ) );
        exit;
    }

    /* =====================================================================
     *  TOOLBAR & SHORTCODE
     * =================================================================== */

    public function add_logout_toolbar_node( $wp_admin_bar ) {
        if ( ! is_user_logged_in() ) return;
        $wp_admin_bar->remove_node( 'logout' );
        $wp_admin_bar->add_node( [
            'id'    => 'clr-logout',
            'title' => 'Logout',
            'href'  => wp_logout_url( $this->get_after_logout_url() ),
            'meta'  => [ 'title' => 'Logout', 'class' => 'clr-logout-node' ],
        ] );
    }

    public function shortcode_logout_button( $atts ) {
        $atts = shortcode_atts( [
            'text'  => 'Logout',
            'class' => 'clr-logout-btn',
        ], $atts );

        if ( ! is_user_logged_in() ) return '';

        $url         = wp_logout_url( $this->get_after_logout_url() );
        $icon_logout = $this->get_icon_url( 'icon-logout' );

        $icon_html = '';
        if ( ! empty( $icon_logout ) ) {
            $icon_html = '<img src="' . esc_url( $icon_logout ) . '" alt="" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;display:inline-block;">';
        }

        return sprintf(
            '<a href="%s" class="%s" style="display:inline-flex;align-items:center;text-decoration:none;">%s%s</a>',
            esc_url( $url ),
            esc_attr( $atts['class'] ),
            $icon_html,
            esc_html( $atts['text'] )
        );
    }
}

new CLR_Plugin();

/* =====================================================================
 *  AKTIVASI
 * =================================================================== */

register_activation_hook( __FILE__, function () {
    $defaults = [
        'template_name'         => 'Login Page Template',
        'template_slug'         => 'login-page-template',
        'login_url_slug'        => 'login-admin-web',
        'after_login_url'       => 'dashboard-admin',
        'after_logout_url'      => '',
        'default_homepage'      => '',
        'enable_wpadmin_block'  => 1,
        'wpadmin_redirect'      => '404',
        'wpadmin_custom'        => '',
        'enable_login_template' => 1,
        'sync_wps_hide_login'   => 1,
    ];
    $existing = get_option( CLR_OPTION_KEY, [] );
    update_option( CLR_OPTION_KEY, wp_parse_args( $existing, $defaults ) );

    if ( class_exists( 'WPSHideLogin' ) ) {
        update_option( 'whl_page', $defaults['login_url_slug'] );
    }

    $assets_dir = CLR_PLUGIN_DIR . 'assets';
    if ( ! file_exists( $assets_dir ) ) {
        wp_mkdir_p( $assets_dir );
    }
} );
