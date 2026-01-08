<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Tariften_Admin' ) ) :

class Tariften_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_newsletter_csv_export' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            'Tariften Core',
            'Tariften Core',
            'manage_options',
            'tariften_core',
            array( $this, 'settings_page_html' ),
            'dashicons-superhero',
            60
        );

        add_submenu_page(
            'tariften_core',
            'Bülten Aboneleri',
            'Bülten Aboneleri',
            'manage_options',
            'tariften_newsletter',
            array($this, 'newsletter_page_html')
        );
    }

    public function register_settings() {
        // Yapay Zeka
        register_setting( 'tariften_options_group', 'tariften_openai_key' );
        register_setting( 'tariften_options_group', 'tariften_ai_model' );
        
        // Görsel Servisleri
        register_setting( 'tariften_options_group', 'tariften_unsplash_key' ); // Unsplash Access Key
        register_setting( 'tariften_options_group', 'tariften_pexels_key' );   // Pexels API Key

        // Google Ayarları (YENİ)
        register_setting('tariften_options_group', 'tariften_google_client_id');
    }

    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>Tariften Core Ayarları</h1>
            <p>Yapay zeka ve görsel servislerinin anahtarlarını buradan yönetebilirsiniz.</p>
            
            <form method="post" action="options.php">
                <?php settings_fields( 'tariften_options_group' ); ?>
                
                <h2 class="title">Yapay Zeka (OpenAI)</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">OpenAI API Key</th>
                        <td>
                            <input type="password" name="tariften_openai_key" value="<?php echo esc_attr( get_option('tariften_openai_key') ); ?>" class="regular-text" />
                            <p class="description">sk-... ile başlayan gizli anahtarınız.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">AI Modeli</th>
                        <td>
                            <select name="tariften_ai_model">
                                <option value="gpt-4o-mini" <?php selected( get_option('tariften_ai_model'), 'gpt-4o-mini' ); ?>>GPT-4o Mini (Hızlı/Ucuz)</option>
                                <option value="gpt-4o" <?php selected( get_option('tariften_ai_model'), 'gpt-4o' ); ?>>GPT-4o (Akıllı/Pahalı)</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Görsel Servisleri (Hibrit Yapı)</h2>
                <p class="description">Tarif görselleri için öncelik sırasına göre API anahtarlarını girin.</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">1. Unsplash Access Key</th>
                        <td>
                            <input type="text" name="tariften_unsplash_key" value="<?php echo esc_attr( get_option('tariften_unsplash_key') ); ?>" class="regular-text" />
                            <p class="description"><a href="https://unsplash.com/developers" target="_blank">Unsplash Developers</a> panelinden alabilirsiniz.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">2. Pexels API Key</th>
                        <td>
                            <input type="text" name="tariften_pexels_key" value="<?php echo esc_attr( get_option('tariften_pexels_key') ); ?>" class="regular-text" />
                            <p class="description"><a href="https://www.pexels.com/api/" target="_blank">Pexels API</a> panelinden alabilirsiniz.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Google Entegrasyonu</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Google OAuth Client ID</th>
                        <td>
                            <input type="text" name="tariften_google_client_id" value="<?php echo esc_attr( get_option('tariften_google_client_id') ); ?>" class="regular-text" placeholder="123...apps.googleusercontent.com" />
                            <p class="description">Google Cloud Console'dan aldığınız OAuth 2.0 Client ID.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function newsletter_page_html() {
        global $wpdb;
        $table = $wpdb->prefix . 'tariften_newsletter';
        // Note: Table name is safe - constructed from wpdb->prefix (WordPress core) + hardcoded 'tariften_newsletter'
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $subscribers = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");
        ?>
        <div class="wrap">
            <h1>Bülten Aboneleri</h1>
            <p>Toplam <?php echo esc_html(count($subscribers)); ?> abone</p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>E-posta</th>
                        <th>Kaynak</th>
                        <th>Durum</th>
                        <th>Kayıt Tarihi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscribers)): ?>
                        <tr><td colspan="5">Henüz abone yok.</td></tr>
                    <?php else: ?>
                        <?php foreach ($subscribers as $sub): ?>
                            <tr>
                                <td><?php echo esc_html($sub->id); ?></td>
                                <td><strong><?php echo esc_html($sub->email); ?></strong></td>
                                <td><?php echo esc_html($sub->source); ?></td>
                                <td>
                                    <span class="<?php echo $sub->status === 'active' ? 'dashicons dashicons-yes' : 'dashicons dashicons-no'; ?>"></span>
                                    <?php echo esc_html($sub->status); ?>
                                </td>
                                <td><?php echo esc_html($sub->created_at); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if (!empty($subscribers)): ?>
            <p style="margin-top: 20px;">
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=tariften_newsletter&export=csv'), 'tariften_export_csv')); ?>" class="button">
                    CSV Olarak İndir
                </a>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle CSV Export
     */
    public function handle_newsletter_csv_export() {
        // Check if export parameter is set
        if (!isset($_GET['page']) || $_GET['page'] !== 'tariften_newsletter') {
            return;
        }

        if (!isset($_GET['export']) || $_GET['export'] !== 'csv') {
            return;
        }

        // Verify nonce for security
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tariften_export_csv')) {
            wp_die('Güvenlik kontrolü başarısız.');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Bu işlemi gerçekleştirme yetkiniz yok.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tariften_newsletter';
        
        // Note: Table name is safe - constructed from wpdb->prefix (WordPress core) + hardcoded 'tariften_newsletter'
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $subscribers = $wpdb->get_results("SELECT email, status, created_at FROM {$table} ORDER BY created_at DESC", ARRAY_A);

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=bulten-aboneleri-' . date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for proper Excel encoding
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Write CSV headers
        fputcsv($output, array('E-posta', 'Kayıt Tarihi', 'Durum'));

        // Write data rows
        if (!empty($subscribers)) {
            foreach ($subscribers as $sub) {
                // Format date in Turkish style: DD.MM.YYYY HH:MM
                $formatted_date = '';
                if (!empty($sub['created_at'])) {
                    $timestamp = strtotime($sub['created_at']);
                    $formatted_date = date('d.m.Y H:i', $timestamp);
                }

                // Translate status
                $status_tr = $sub['status'] === 'active' ? 'Aktif' : 'Pasif';

                fputcsv($output, array(
                    $sub['email'],
                    $formatted_date,
                    $status_tr
                ));
            }
        }

        fclose($output);
        exit;
    }
}

endif;