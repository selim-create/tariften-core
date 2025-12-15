<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tariften_DB {

    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        // 1. Dolap Tablosu
        $table_pantry = $wpdb->prefix . 'tariften_pantry';
        $sql_pantry = "CREATE TABLE $table_pantry (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            ingredient_name varchar(100) NOT NULL,
            quantity varchar(50) DEFAULT '',
            unit varchar(20) DEFAULT '',
            status varchar(20) DEFAULT 'fresh',
            expiry_date varchar(50) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta( $sql_pantry );

        // 2. Etkileşimler Tablosu (YENİ - Favoriler ve Pişirilenler)
        $table_interactions = $wpdb->prefix . 'tariften_interactions';
        $sql_interactions = "CREATE TABLE $table_interactions (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            recipe_id bigint(20) NOT NULL,
            type varchar(20) NOT NULL, -- 'favorite' veya 'cooked'
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY recipe_id (recipe_id),
            UNIQUE KEY user_recipe_type (user_id, recipe_id, type) 
        ) $charset_collate;";
        dbDelta( $sql_interactions );

        // 3. Log Tablosu
        $table_logs = $wpdb->prefix . 'tariften_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            session_id varchar(100) DEFAULT '',
            plan_id varchar(20) DEFAULT 'free',
            searched_ingredients longtext,
            filters longtext,
            source varchar(10) DEFAULT 'db',
            cost_usd decimal(10,5) DEFAULT 0,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta( $sql_logs );

        // 4. Kredi Defteri
        $table_credits = $wpdb->prefix . 'tariften_credits_ledger';
        $sql_credits = "CREATE TABLE $table_credits (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            event_type varchar(50) NOT NULL,
            credits_delta int(11) NOT NULL,
            meta longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";
        dbDelta( $sql_credits );

        // 5. AI Cache
        $table_cache = $wpdb->prefix . 'tariften_ai_cache';
        $sql_cache = "CREATE TABLE $table_cache (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            recipe_json longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cache_key (cache_key)
        ) $charset_collate;";
        dbDelta( $sql_cache );

        update_option( 'tariften_db_version', TARIFTEN_DB_VERSION );
    }
}