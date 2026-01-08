<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tariften_CPT {

    public function register_all() {
        $this->register_recipes();
        $this->register_ingredients();
        $this->register_menus(); // YENİ: Menüleri kaydet
        $this->register_taxonomies();
    }

    private function register_recipes() {
        register_post_type( 'recipe', array(
            'labels'      => array( 'name' => 'Tarifler', 'singular_name' => 'Tarif' ),
            'public'      => true,
            'show_in_rest'=> true, 
            'supports'    => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'author' ),
            'menu_icon'   => 'dashicons-carrot',
            'rewrite'     => array( 'slug' => 'tarif' ),
        ) );
    }

    private function register_ingredients() {
        register_post_type( 'ingredient', array(
            'labels'      => array( 'name' => 'Malzemeler', 'singular_name' => 'Malzeme' ),
            'public'      => true,
            'show_in_rest'=> true,
            'supports'    => array( 'title', 'thumbnail' ),
            'menu_icon'   => 'dashicons-products',
        ) );
    }

    // YENİ: Menü CPT Tanımı
    private function register_menus() {
        register_post_type( 'menu', array(
            'labels'      => array( 'name' => 'Menüler', 'singular_name' => 'Menü' ),
            'public'      => true,
            'show_in_rest'=> true,
            'supports'    => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'author' ),
            'menu_icon'   => 'dashicons-clipboard',
            'rewrite'     => array( 'slug' => 'menu' ),
        ) );
    }

    private function register_taxonomies() {
        // Mevcutlar
        $taxs = [
            'cuisine' => 'Mutfak',
            'diet' => 'Diyet',
            'meal_type' => 'Öğün',
            'difficulty' => 'Zorluk'
        ];

        foreach ($taxs as $slug => $label) {
            register_taxonomy( $slug, 'recipe', array(
                'label'        => $label,
                'rewrite'      => array( 'slug' => $slug ),
                'hierarchical' => true,
                'show_in_rest' => true,
            ) );
        }

      // KOLEKSİYONLAR: Hem Tarifler HEM DE Menüler için aktif ediyoruz.
        // Bu sayede WP Admin panelinde Menü düzenlerken sağda "Koleksiyonlar" kutusu çıkacak.
        register_taxonomy( 'collection', ['recipe', 'menu'], array(
            'label'        => 'Koleksiyonlar',
            'rewrite'      => array( 'slug' => 'koleksiyon' ),
            'hierarchical' => true,
            'show_in_rest' => true,
            'description'  => 'Anasayfa vitrin alanları için etiketleme (Örn: Vitrin, Editörün Seçimi)',
        ) );
    }
}