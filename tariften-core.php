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