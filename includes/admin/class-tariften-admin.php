<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Tariften_Admin' ) ) :

class Tariften_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
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
    }

    public function register_settings() {
        // Yapay Zeka
        register_setting( 'tariften_options_group', 'tariften_openai_key' );
        register_setting( 'tariften_options_group', 'tariften_ai_model' );
        
        // Görsel Servisleri
        register_setting( 'tariften_options_group', 'tariften_unsplash_key' ); // Unsplash Access Key
        register_setting( 'tariften_options_group', 'tariften_pexels_key' );   // Pexels API Key
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

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

endif;