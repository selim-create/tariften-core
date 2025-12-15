<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tariften_API {

    public function register_routes() {
        $namespace = 'tariften/v1';

        // 1. Tarif Arama ve Getirme
        register_rest_route( $namespace, '/recipes/search', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'search_recipes' ),
            'permission_callback' => '__return_true',
        ) );

        // 2. Tarif Oluşturma
        register_rest_route( $namespace, '/recipes/create', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'create_recipe' ),
            'permission_callback' => array( $this, 'is_user_logged_in' ),
        ) );

        // 3. Tarif Güncelleme
        register_rest_route( $namespace, '/recipes/update', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'update_recipe' ),
            'permission_callback' => array( $this, 'is_user_logged_in' ),
        ) );

        // 4. Kategori (Term) Listesi
        register_rest_route( $namespace, '/terms', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_terms_data' ),
            'permission_callback' => '__return_true',
        ) );
        
        // 5. AI Tarif Üretimi (GÜÇLENDİRİLDİ)
        register_rest_route( $namespace, '/ai/generate', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'generate_ai_recipe' ),
            'permission_callback' => array( $this, 'check_auth_and_credits' ),
        ) );
  
        // Dolap Rotaları
        register_rest_route( $namespace, '/pantry', [
        'methods'  => 'GET',
        'callback' => [ $this, 'get_pantry' ],
        'permission_callback' => [ $this, 'is_user_logged_in' ],
        ] );

        register_rest_route( $namespace, '/pantry/update', [
        'methods'  => 'POST',
        'callback' => [ $this, 'update_pantry' ],
        'permission_callback' => [ $this, 'is_user_logged_in' ],
        ] );
        register_rest_route( $namespace, '/pantry/analyze', array('methods' => 'POST', 'callback' => array( $this, 'analyze_pantry_input' ), 'permission_callback' => array( $this, 'check_auth_and_credits' )) );

        // 7. Etkileşimler
        register_rest_route( $namespace, '/interactions', array(
            'methods'  => 'POST', 'callback' => array( $this, 'toggle_interaction' ), 'permission_callback' => array( $this, 'is_user_logged_in' ),
        ) );
        register_rest_route( $namespace, '/interactions/list', array(
            'methods'  => 'GET', 'callback' => array( $this, 'get_user_interactions' ), 'permission_callback' => array( $this, 'is_user_logged_in' ),
        ) );
        register_rest_route( $namespace, '/interactions/check', array(
            'methods'  => 'GET', 'callback' => array( $this, 'check_interaction_status' ), 'permission_callback' => array( $this, 'is_user_logged_in' ),
        ) );
    }

    /**
     * AI Tarif Üretimi (SEO MODÜLÜ EKLENDİ)
     */
    public function generate_ai_recipe( $request ) {
        $params = $request->get_json_params();
        $ingredients = isset($params['ingredients']) ? sanitize_text_field($params['ingredients']) : ''; 
        $prompt_type = isset($params['type']) ? sanitize_text_field($params['type']) : 'suggest'; 

        $api_key = get_option( 'tariften_openai_key' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'OpenAI API anahtarı ayarlanmamış.', array( 'status' => 500 ) );
        }

        // --- ADIM 1: KATEGORİLER ---
        $available_cuisines = get_terms(['taxonomy' => 'cuisine', 'fields' => 'names', 'hide_empty' => false]);
        $available_diets = get_terms(['taxonomy' => 'diet', 'fields' => 'names', 'hide_empty' => false]);
        $available_meals = get_terms(['taxonomy' => 'meal_type', 'fields' => 'names', 'hide_empty' => false]);
        $available_difficulties = get_terms(['taxonomy' => 'difficulty', 'fields' => 'names', 'hide_empty' => false]);

        if (empty($available_cuisines)) $available_cuisines = ['Türk Mutfağı', 'İtalyan', 'Meksika', 'Dünya Mutfağı'];
        if (empty($available_diets)) $available_diets = ['Normal', 'Vegan', 'Vejetaryen', 'Glutensiz'];
        if (empty($available_meals)) $available_meals = ['Kahvaltı', 'Öğle Yemeği', 'Akşam Yemeği', 'Atıştırmalık', 'Tatlı'];
        if (empty($available_difficulties)) $available_difficulties = ['Kolay', 'Orta', 'Zor', 'Şef'];

        // --- ADIM 2: SEO DESTEKLİ JSON ŞEMASI ---
        $json_structure = '{
            "title": "Tarif Adı (Cezbedici)",
            "image_search_query": "Stok fotoğraf için 3-5 kelimelik İNGİLİZCE görsel tanımı (Sadece yemeğin görüntüsünü tarif et)",
            "excerpt": "Tarif özeti (150-160 karakter)",
            "prep_time": "Sadece sayı (dk)",
            "cook_time": "Sadece sayı (dk)",
            "calories": "Sadece sayı (kcal)",
            "servings": "Sadece sayı (kişi)",
            "cuisine": ["Listeden EN UYGUN tek bir mutfak"],
            "meal_type": ["Listeden EN UYGUN tek bir öğün"],
            "difficulty": ["Listeden zorluk"],
            "diet": ["Uygunsa listeden seç, yoksa boş array []"],
            "ingredients": [ 
                {"name": "Malzeme adı", "amount": "Miktar", "unit": "Birim"} 
            ],
            "steps": ["Adım 1", "Adım 2"],
            "seo": {
                "title": "Google arama sonuçlarında çıkacak, tıklamaya teşvik eden SEO Başlığı (Max 60 karakter)",
                "description": "Google açıklaması için anahtar kelimeleri içeren özet (Max 160 karakter)",
                "focus_keywords": "Virgülle ayrılmış en önemli 3 anahtar kelime"
            }
        }';

        // --- ADIM 3: SEO UZMANI SİSTEM PROMPTU ---
        $system_prompt = "Sen Tariften.com’un hem 'Tarif Üretim Şefi' hem de 'SEO Uzmanı'sın.
        Hedef kitle: Türkiye’de yaşayan ev kullanıcıları.

        GÖREVLER:
        1. LEZZET: Kullanıcının malzemeleriyle en mantıklı, lezzetli ve uygulanabilir tarifi oluştur.
        2. SEO OPTİMİZASYONU (Çok Önemli): Tarifi oluştururken Google aramalarında üst sıralara çıkacak şekilde başlık ve açıklama yaz. İnsanların arama motoruna yazacağı terimleri 'seo' objesi içinde kullan.
        3. GÖRSEL ARAMA: 'image_search_query' alanına, yemeğin görselini stok sitelerinde bulmamızı sağlayacak, içinde ASLA özel isim (Hünkarbeğendi vb.) geçmeyen, sadece tabağın görüntüsünü anlatan İngilizce bir cümle yaz (Örn: 'eggplant puree with meat cubes on white plate').
        
        KATEGORİLER:
        - Mutfaklar: " . implode(', ', (array)$available_cuisines) . "
        - Diyetler: " . implode(', ', (array)$available_diets) . "
        - Öğünler: " . implode(', ', (array)$available_meals) . "
        - Zorluk: " . implode(', ', (array)$available_difficulties) . "

        ÇIKTI FORMATI: SADECE saf JSON. Markdown yok.
        ŞEMA:
        $json_structure";

        // --- ADIM 4: KULLANICI PROMPTU ---
        $user_prompt = "Elimdeki malzemeler / İsteklerim: " . $ingredients . ". ";
        $temperature = 0.7; 

        if ($prompt_type === 'rescue') {
            $user_prompt .= "Bu malzemeler bozulmak üzere. Pratik bir 'Kurtarıcı Tarif' ver.";
            $temperature = 0.4;
        } else if ($prompt_type === 'plan') {
            $user_prompt .= "Bu malzemeleri kullanarak dengeli bir ana öğün planla.";
            $temperature = 0.5;
        } else {
            $user_prompt .= "Bu malzemelerle yapabileceğim en etkileyici yemeği öner.";
            $temperature = 0.7;
        }

        // OpenAI İsteği
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => json_encode( array(
                'model'       => get_option('tariften_ai_model', 'gpt-4o-mini'),
                'messages'    => array(
                    array( 'role' => 'system', 'content' => $system_prompt ),
                    array( 'role' => 'user', 'content' => $user_prompt ),
                ),
                'response_format' => array( 'type' => 'json_object' ),
                'temperature' => $temperature,
                'max_tokens'  => 2500,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) return $response;
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['error'] ) ) {
            $error_msg = $data['error']['code'] === 'insufficient_quota' ? 'Şefimiz şu an çok yoğun.' : $data['error']['message'];
            return new WP_REST_Response( array( 'success' => false, 'message' => $error_msg ), 500 );
        }

        $ai_content = $data['choices'][0]['message']['content'];
        $recipe_json = json_decode( $ai_content, true );

        if ( json_last_error() !== JSON_ERROR_NONE || !is_array($recipe_json) ) {
            return new WP_REST_Response(['success'=>false, 'message'=>'AI geçersiz veri üretti.'], 500);
        }
        if ( empty($recipe_json['title']) ) {
            return new WP_REST_Response(['success'=>false, 'message'=>'AI eksik tarif üretti.'], 500);
        }

        // --- GÖRSEL MANTIĞI (UNSPLASH > PEXELS > PLACEHOLDER) ---
        $search_query = !empty($recipe_json['image_search_query']) ? $recipe_json['image_search_query'] : ($recipe_json['title'] . " food");
        if (strpos($search_query, 'food') === false && strpos($search_query, 'dish') === false && strpos($search_query, 'plate') === false) {
             $search_query .= " food photography"; 
        }

        $image_url = '';
        $unsplash_key = get_option('tariften_unsplash_key');
        $pexels_key = get_option('tariften_pexels_key');

        if ( !empty($unsplash_key) ) {
            $unsplash_response = wp_remote_get( "https://api.unsplash.com/search/photos?query=" . urlencode($search_query) . "&per_page=1&orientation=landscape&client_id=" . $unsplash_key );
            if ( !is_wp_error($unsplash_response) && wp_remote_retrieve_response_code($unsplash_response) === 200 ) {
                $unsplash_body = json_decode( wp_remote_retrieve_body($unsplash_response), true );
                if ( !empty($unsplash_body['results'][0]['urls']['regular']) ) {
                    $image_url = $unsplash_body['results'][0]['urls']['regular'];
                }
            }
        }

        if ( empty($image_url) && !empty($pexels_key) ) {
            $pexels_response = wp_remote_get( "https://api.pexels.com/v1/search?query=" . urlencode($search_query) . "&per_page=1&orientation=landscape", array(
                'headers' => array( 'Authorization' => $pexels_key )
            ));
            if ( !is_wp_error($pexels_response) && wp_remote_retrieve_response_code($pexels_response) === 200 ) {
                $pexels_body = json_decode( wp_remote_retrieve_body($pexels_response), true );
                if ( !empty($pexels_body['photos'][0]['src']['landscape']) ) {
                    $image_url = $pexels_body['photos'][0]['src']['landscape'];
                }
            }
        }

        if ( empty($image_url) ) {
            $image_url = 'https://placehold.co/800x600/db4c3f/ffffff?text=' . urlencode($recipe_json['title']);
        }

        $recipe_json['image'] = $image_url;

        return new WP_REST_Response( array( 
            'success' => true, 
            'recipe' => $recipe_json 
        ), 200 );
    }
    
    public function create_recipe( $request ) {
        $params = $request->get_json_params();
        $user_id = get_current_user_id();

        if ( empty($params['title']) ) {
            return new WP_Error( 'missing_title', 'Tarif başlığı zorunludur.', array( 'status' => 400 ) );
        }

        $content = '';
        if ( !empty( $params['steps'] ) && is_array( $params['steps'] ) ) {
            $content .= '<ul>';
            foreach ( $params['steps'] as $step ) {
                $content .= '<li>' . sanitize_text_field( $step ) . '</li>';
            }
            $content .= '</ul>';
        }

        $post_data = array(
            'post_title'   => sanitize_text_field( $params['title'] ),
            'post_content' => $content,
            'post_excerpt' => sanitize_textarea_field( $params['excerpt'] ?? '' ),
            'post_status'  => 'publish',
            'post_type'    => 'recipe',
            'post_author'  => $user_id,
        );

        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $this->save_recipe_meta($post_id, $params);
        $post = get_post($post_id);

        return new WP_REST_Response( array( 
            'success' => true, 
            'id' => $post_id,
            'slug' => $post->post_name,
            'message' => 'Tarif başarıyla oluşturuldu.' 
        ), 201 );
    }

    public function update_recipe( $request ) {
        $params = $request->get_json_params();
        $user_id = get_current_user_id();
        $post_id = isset($params['id']) ? intval($params['id']) : 0;

        if ( !$post_id ) return new WP_Error( 'missing_id', 'ID gerekli.', array( 'status' => 400 ) );

        $post = get_post($post_id);
        if ( !$post ) return new WP_Error( 'not_found', 'Tarif yok.', array( 'status' => 404 ) );

        if ( $post->post_author != $user_id && !current_user_can('manage_options') ) {
            return new WP_Error( 'forbidden', 'Yetkisiz işlem.', array( 'status' => 403 ) );
        }

        $content = '';
        if ( !empty( $params['steps'] ) && is_array( $params['steps'] ) ) {
            $content .= '<ul>';
            foreach ( $params['steps'] as $step ) {
                $content .= '<li>' . sanitize_text_field( $step ) . '</li>';
            }
            $content .= '</ul>';
        }

        $post_data = array(
            'ID'           => $post_id,
            'post_title'   => sanitize_text_field( $params['title'] ),
            'post_content' => $content,
            'post_excerpt' => sanitize_textarea_field( $params['excerpt'] ?? '' ),
        );

        wp_update_post( $post_data );
        $this->save_recipe_meta($post_id, $params);
        $updated_post = get_post($post_id);

        return new WP_REST_Response( array( 'success' => true, 'id' => $post_id, 'slug' => $updated_post->post_name, 'message' => 'Güncellendi.' ), 200 );
    }

    /**
     * Ortak Meta Kayıt (OTOMATİK SEO DESTEKLİ)
     */
    private function save_recipe_meta($post_id, $params) {
        $simple_metas = ['prep_time', 'cook_time', 'calories', 'servings'];
        foreach ($simple_metas as $meta) {
            if ( isset( $params[$meta] ) ) update_post_meta( $post_id, $meta, sanitize_text_field( $params[$meta] ) );
        }
        
        if ( !empty( $params['image'] ) ) {
            if ( is_numeric( $params['image'] ) ) {
                set_post_thumbnail( $post_id, $params['image'] );
            } else {
                update_post_meta( $post_id, 'tariften_image_url', $params['image'] );
            }
        }
        
        if ( !empty( $params['ingredients'] ) && is_array( $params['ingredients'] ) ) {
            $clean_ingredients = array();
            foreach ( $params['ingredients'] as $item ) {
                $raw_name = sanitize_text_field( $item['name'] ?? '' );
                if ( empty($raw_name) ) continue;

                $existing_ingredient = get_page_by_title( $raw_name, OBJECT, 'ingredient' );
                $ingredient_id = $existing_ingredient ? $existing_ingredient->ID : wp_insert_post( array('post_title'=>$raw_name, 'post_type'=>'ingredient', 'post_status'=>'publish') );
                $clean_ingredients[] = array( 'ingredient_id' => $ingredient_id, 'name' => $raw_name, 'amount' => sanitize_text_field( $item['amount'] ?? '' ), 'unit' => sanitize_text_field( $item['unit'] ?? '' ) );
            }
            update_post_meta( $post_id, 'tariften_ingredients', $clean_ingredients );
        }
        
        if ( !empty( $params['steps'] ) && is_array( $params['steps'] ) ) {
            update_post_meta( $post_id, 'tariften_steps', array_map('sanitize_text_field', $params['steps']) );
        }

        // YENİ: Collection da kaydedilmeli
        foreach (['cuisine', 'meal_type', 'difficulty', 'diet', 'collection'] as $tax) {
            if ( !empty( $params[$tax] ) ) wp_set_object_terms( $post_id, $params[$tax], $tax );
        }

        // --- AKILLI SEO YÖNETİMİ ---
        
        // 1. Durum: AI tarafından üretilmiş ve SEO verisi gelmiş
        if ( !empty($params['seo']) && is_array($params['seo']) ) {
            if (!empty($params['seo']['title'])) update_post_meta( $post_id, 'rank_math_title', sanitize_text_field($params['seo']['title']) );
            if (!empty($params['seo']['description'])) update_post_meta( $post_id, 'rank_math_description', sanitize_text_field($params['seo']['description']) );
            if (!empty($params['seo']['focus_keywords'])) update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field($params['seo']['focus_keywords']) );
        } 
        // 2. Durum: Manuel oluşturulmuş (Frontend'den SEO verisi gelmemiş)
        else {
            // Başlığı SEO başlığı yap
            if (!empty($params['title'])) {
                update_post_meta( $post_id, 'rank_math_title', sanitize_text_field($params['title']) );
                // Basit bir odak kelime tahmini (Başlığın kendisi)
                update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field($params['title']) );
            }
            // Spot'u SEO açıklaması yap
            if (!empty($params['excerpt'])) {
                update_post_meta( $post_id, 'rank_math_description', sanitize_textarea_field($params['excerpt']) );
            }
        }
    }

    /**
     * Arama Fonksiyonu (SAYFALAMA EKLENDİ)
     */
    public function search_recipes( $request ) {
        $ingredients = $request->get_param( 'ingredients' ); 
        $slug = $request->get_param( 'slug' );
        $id = $request->get_param( 'id' );
        $page = $request->get_param( 'page' ) ? intval($request->get_param( 'page' )) : 1; // YENİ: Sayfa numarası
        
        // Filtreler
        $cuisine = $request->get_param( 'cuisine' );
        $diet = $request->get_param( 'diet' );
        $meal_type = $request->get_param( 'meal_type' );
        $difficulty = $request->get_param( 'difficulty' );
        $collection = $request->get_param( 'collection' );
        $sort = $request->get_param( 'orderby' );

        $args = array(
            'post_type'      => 'recipe',
            'posts_per_page' => 10, // Sayfa başı 10 tarif
            'paged'          => $page, // YENİ: Hangi sayfa
            'post_status'    => 'publish',
        );

        if ( !empty( $slug ) ) { $args['name'] = $slug; $args['posts_per_page'] = 1; } 
        elseif ( !empty( $id ) ) { $args['p'] = $id; } 
        elseif ( !empty($ingredients) ) { $args['s'] = $ingredients; }

        $tax_query = array();
        $filters = [ 
            'cuisine' => $cuisine, 
            'diet' => $diet, 
            'meal_type' => $meal_type, 
            'difficulty' => $difficulty,
            'collection' => $collection 
        ];

        foreach ($filters as $tax => $values) {
            if ( !empty($values) ) {
                $term_list = explode(',', $values);
                $tax_query[] = array( 'taxonomy' => $tax, 'field' => 'name', 'terms' => $term_list, 'operator' => 'IN' );
            }
        }
        if ( count($tax_query) > 0 ) { $tax_query['relation'] = 'AND'; $args['tax_query'] = $tax_query; }

        if ( $sort ) {
            if ( $sort === 'En Yeniler' ) { $args['orderby'] = 'date'; $args['order'] = 'DESC'; } 
            elseif ( $sort === 'Hazırlama Süresi' ) { $args['meta_key'] = 'prep_time'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'ASC'; }
        }

        $query = new WP_Query( $args );
        $results = array();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $results[] = $this->format_recipe_for_api( get_post() );
            }
        }
        
        // Toplam sayfa sayısını da dönelim ki frontend bilsin
        return new WP_REST_Response( array( 
            'source' => 'db', 
            'count' => $query->found_posts, 
            'pages' => $query->max_num_pages,
            'data' => $results 
        ), 200 );
    }

    /**
     * Kategorileri Getir (SIRALAMA GÜNCELLENDİ)
     */
    public function get_terms_data( $request ) {
        $data = [];
        foreach(['cuisine','meal_type','difficulty','diet'] as $t) {
            $terms = get_terms([
                'taxonomy'   => $t, 
                'hide_empty' => true, // Sadece içinde tarif olanları getir
                'orderby'    => 'count', // YENİ: Yazı sayısına göre sırala
                'order'      => 'DESC'   // YENİ: En çoktan en aza
            ]);
            $data[$t] = !is_wp_error($terms) ? array_map(function($x){return $x->name;}, $terms) : [];
        }
        return new WP_REST_Response($data, 200);
    }
 

    /**
     * Dolap Verilerini Çek
     */
    public function get_pantry( $request ) {
        global $wpdb;
        $user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'tariften_pantry';
        
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE user_id = %d ORDER BY id DESC", $user_id ) );
        
        $formatted_items = array_map(function($item) {
            return array(
                'id' => (string)$item->id,
                'name' => $item->ingredient_name,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'status' => $item->status,
                'expiresIn' => $item->expiry_date
            );
        }, $items);

        return new WP_REST_Response( $formatted_items, 200 );
    }

    /**
     * Dolap Güncelleme (HATA AYIKLAMA MODU)
     */
    public function update_pantry( $request ) {
        global $wpdb;
        $user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'tariften_pantry';
        
        $params = $request->get_json_params();
        $items = isset($params['items']) ? $params['items'] : array();

        // LOGLAMA: Gelen veriyi kontrol et
        error_log("Pantry Update: User $user_id - Items count: " . count($items));

        // Önce temizle
        $delete_result = $wpdb->delete( $table_name, array( 'user_id' => $user_id ) );
        
        if ($delete_result === false) {
             error_log("Pantry Delete Error: " . $wpdb->last_error);
             return new WP_REST_Response( array( 'success' => false, 'message' => 'Silme hatası' ), 500 );
        }

        if (!empty($items)) {
            foreach ($items as $item) {
                $expiry = !empty($item['expiresIn']) ? sanitize_text_field($item['expiresIn']) : '';
                
                $result = $wpdb->insert( 
                    $table_name, 
                    array( 
                        'user_id' => $user_id,
                        'ingredient_name' => sanitize_text_field($item['name']),
                        'quantity' => sanitize_text_field($item['quantity'] ?? ''),
                        'unit' => sanitize_text_field($item['unit'] ?? ''),
                        'status' => sanitize_text_field($item['status'] ?? 'fresh'),
                        'expiry_date' => $expiry,
                    ),
                    array( '%d', '%s', '%s', '%s', '%s', '%s' )
                );
                
                if ($result === false) {
                    error_log("Pantry Insert Error: " . $wpdb->last_error); // HATA BURADA GÖRÜNECEK
                    return new WP_REST_Response( array( 
                        'success' => false, 
                        'message' => 'DB Hatası: ' . $wpdb->last_error 
                    ), 500 );
                }
            }
        }
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }
    public function analyze_pantry_input($r){$p=$r->get_json_params();$k=get_option('tariften_openai_key');if(!$k)return new WP_Error('k','Key',array('status'=>500));$img=$p['image']??'';$txt=$p['text']??'';$msg=[['role'=>'system','content'=>'Sen mutfak asistanısın. Metni/Görseli analiz et. JSON dön: {items:[{name,expiry_date:YYYY-MM-DD}]}']];if($img){$msg[]=['role'=>'user','content'=>[['type'=>'text','text'=>'Listele'],['type'=>'image_url','image_url'=>['url'=>$img]]]];$mdl='gpt-4o';}else{$msg[]=['role'=>'user','content'=>$txt];$mdl='gpt-4o-mini';}$res=wp_remote_post('https://api.openai.com/v1/chat/completions',['headers'=>['Authorization'=>'Bearer '.$k,'Content-Type'=>'application/json'],'body'=>json_encode(['model'=>$mdl,'messages'=>$msg,'response_format'=>['type'=>'json_object']])]);if(is_wp_error($res))return $res;$b=json_decode(wp_remote_retrieve_body($res),true);$c=$b['choices'][0]['message']['content']??'{}';return new WP_REST_Response(json_decode($c,true),200);}
    public function toggle_interaction($r) { global $wpdb; $u = get_current_user_id(); $p = $r->get_json_params(); $rid = intval($p['recipe_id']); $t = sanitize_text_field($p['type']); $tbl = $wpdb->prefix . 'tariften_interactions'; $ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE user_id=%d AND recipe_id=%d AND type=%s", $u, $rid, $t)); if($ex){ $wpdb->delete($tbl, ['id'=>$ex]); return new WP_REST_Response(['status'=>'removed'],200); } else { $wpdb->insert($tbl, ['user_id'=>$u, 'recipe_id'=>$rid, 'type'=>$t]); return new WP_REST_Response(['status'=>'added'],200); } }
    public function get_user_interactions($r) { global $wpdb; $u = get_current_user_id(); $t = $r->get_param('type')?:'favorite'; $ids = $wpdb->get_col($wpdb->prepare("SELECT recipe_id FROM {$wpdb->prefix}tariften_interactions WHERE user_id=%d AND type=%s ORDER BY created_at DESC", $u, $t)); if(empty($ids)) return new WP_REST_Response([],200); $q = new WP_Query(['post_type'=>'recipe', 'post__in'=>$ids, 'posts_per_page'=>-1, 'orderby'=>'post__in']); $recipes = []; while($q->have_posts()){ $q->the_post(); $recipes[] = $this->format_recipe_for_api(get_post()); } return new WP_REST_Response($recipes, 200); }
    public function check_interaction_status($r) { global $wpdb; $u = get_current_user_id(); $rid = $r->get_param('recipe_id'); $tbl = $wpdb->prefix . 'tariften_interactions'; $f = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE user_id=%d AND recipe_id=%d AND type='favorite'", $u, $rid)); $c = $wpdb->get_var($wpdb->prepare("SELECT id FROM $tbl WHERE user_id=%d AND recipe_id=%d AND type='cooked'", $u, $rid)); return new WP_REST_Response(['favorite'=>!!$f, 'cooked'=>!!$c], 200); }

    private function format_recipe_for_api( $post ) {
        $id = $post->ID;
        $image_url = get_the_post_thumbnail_url( $id, 'large' );
        if ( !$image_url ) $image_url = get_post_meta( $id, 'tariften_image_url', true );
        if( !$image_url ) $image_url = 'https://placehold.co/600x400?text=Tariften';

        // SEO Verilerini de Frontend'e Gönder (Next.js Head için)
        $seo = array(
            'title' => get_post_meta($id, 'rank_math_title', true),
            'description' => get_post_meta($id, 'rank_math_description', true),
        );
        return array(
            'id' => $id,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'content' => apply_filters('the_content', $post->post_content),
            'excerpt' => get_the_excerpt( $id ),
            'image' => $image_url,
            'prep_time' => get_post_meta( $id, 'prep_time', true ) ?: '15',
            'cook_time' => get_post_meta( $id, 'cook_time', true ) ?: '20',
            'calories' => get_post_meta( $id, 'calories', true ) ?: '0',
            'servings' => get_post_meta( $id, 'servings', true ) ?: '2',
            'ingredients' => get_post_meta( $id, 'tariften_ingredients', true ) ?: [],
            'steps' => get_post_meta( $id, 'tariften_steps', true ) ?: [],
            'cuisine' => $this->get_term_names( $id, 'cuisine' ),
            'diet' => $this->get_term_names( $id, 'diet' ),
            'meal_type' => $this->get_term_names( $id, 'meal_type' ),
            'difficulty' => $this->get_term_names( $id, 'difficulty' ),
            'collection' => $this->get_term_names( $id, 'collection' ), // YENİ
            'author_id' => $post->post_author,
            'seo' => $seo // YENİ: SEO verisi
        );
    }
    private function get_term_names( $post_id, $taxonomy ) {
        $terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
        return !is_wp_error($terms) && !empty($terms) ? $terms : array();
    }
    public function is_user_logged_in() { return is_user_logged_in(); }
    public function check_auth_and_credits() { return is_user_logged_in(); }
}