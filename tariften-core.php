<?php
/**
 * Plugin Name: Tariften Core
 * Description: Tariften.com Headless mimarisi için CPT, Veritabanı, API ve AI mantığını yöneten çekirdek eklenti.
 * Version: 1.0.0
 * Author: Tariften Tech
 * Text Domain: tariften-core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Sabitleri Tanımla
define( 'TARIFTEN_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TARIFTEN_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'TARIFTEN_DB_VERSION', '1.0' );

// Sınıfları Dahil Et
require_once TARIFTEN_CORE_PATH . 'includes/class-tariften-db.php';
require_once TARIFTEN_CORE_PATH . 'includes/class-tariften-cpt.php';
require_once TARIFTEN_CORE_PATH . 'includes/api/class-tariften-api.php';
require_once TARIFTEN_CORE_PATH . 'includes/admin/class-tariften-admin.php';

class Tariften_Core {

    public function __construct() {
        // CPT ve Taksonomileri Başlat
        $cpt = new Tariften_CPT();
        add_action( 'init', array( $cpt, 'register_all' ) );

        // API Rotalarını Başlat
        $api = new Tariften_API();
        add_action( 'rest_api_init', array( $api, 'register_routes' ) );

        // Admin Ayarlarını Başlat
        if ( is_admin() ) {
            new Tariften_Admin();
        }

        // Veritabanı Tablolarını Kur (Eklenti Aktif Edilince)
        register_activation_hook( __FILE__, array( 'Tariften_DB', 'install' ) );
    }
}

// Eklentiyi Ateşle
new Tariften_Core();

/**
 * Headless Frontend Yönlendirmesi
 * * API sitesinin ön yüzüne (Homepage, Single Post vb.) gelen tüm istekleri
 * ana frontend domainine (tariften.com) yönlendirir.
 * * Hariç Tutulanlar:
 * - Admin Paneli (/wp-admin)
 * - Login Ekranı (wp-login.php)
 * - REST API (/wp-json)
 * - Cron İşlemleri
 */
function tariften_headless_redirect() {
    // 1. Admin paneli, Login sayfası veya Cron çalışıyorsa müdahale etme
    if ( is_admin() || 'wp-login.php' === $GLOBALS['pagenow'] || defined( 'DOING_CRON' ) ) {
        return;
    }

    // 2. Eğer istek REST API'ye geliyorsa müdahale etme (Frontend buradan besleniyor!)
    $path = $_SERVER['REQUEST_URI'];
    if ( strpos( $path, '/wp-json/' ) !== false || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }

    // 3. RSS Feed'leri de yönlendir (Opsiyonel, SEO için iyi)
    if ( is_feed() ) {
        // İstersen return; diyerek feedleri açık bırakabilirsin.
    }

    // 4. Hedef Domain (Frontend)
    $frontend_url = 'https://tariften.com'; 

    // Seçenek A: Direkt Ana Sayfaya Yönlendir (En Güvenlisi)
    wp_redirect( $frontend_url, 301 );
    
    // Seçenek B: Link yapısı aynıysa (örn: api.com/tarif/x -> tariften.com/tarif/x)
    // wp_redirect( $frontend_url . $path, 301 );

    exit;
}

// WordPress şablonları yüklenmeden hemen önce çalıştır
add_action( 'template_redirect', 'tariften_headless_redirect' );