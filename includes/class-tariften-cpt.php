<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tariften_CPT {

    public function register_all() {
        $this->register_recipes();
        $this->register_ingredients();
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

        // YENİ: Koleksiyonlar (Editör Seçimi, Vitrin vb. için)
        register_taxonomy( 'collection', 'recipe', array(
            'label'        => 'Koleksiyonlar',
            'rewrite'      => array( 'slug' => 'koleksiyon' ),
            'hierarchical' => true, // Checkbox gibi seçilsin
            'show_in_rest' => true,
            'description'  => 'Anasayfa vitrin alanları için etiketleme (Örn: Editörün Seçimi, Popüler)',
        ) );
    }
}