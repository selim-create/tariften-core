<?php
/**
 * Tariften API Endpoints
 *
 * Headless mimari için özel REST API uçları.
 *
 * @package Tariften_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tariften_API {

    /**
     * Instance
     *
     * @var Tariften_API
     */
    private static $instance = null;

    /**
     * OpenAI API Key
     *
     * @var string
     */
    private $api_key;

    /**
     * Get Instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->api_key = get_option( 'tariften_openai_key', '' );
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
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
        
        // --- AI ROTALARI ---
        register_rest_route( $namespace, '/ai/generate', array( 'methods'  => 'POST', 'callback' => array( $this, 'generate_ai_recipe_endpoint' ), 'permission_callback' => array( $this, 'check_auth_and_credits' ) ) );
        register_rest_route( $namespace, '/ai/generate-menu', array( 'methods'  => 'POST', 'callback' => array( $this, 'generate_ai_menu' ), 'permission_callback' => array( $this, 'check_auth_and_credits' ) ) );
  
        // --- MENÜ ROTALARI ---
        register_rest_route( $namespace, '/menus/search', array( 'methods'  => 'GET', 'callback' => array( $this, 'search_menus' ), 'permission_callback' => '__return_true' ) );
        // YENİ: Menü Güncelleme Rotası
        register_rest_route( $namespace, '/menus/update', array( 'methods'  => 'POST', 'callback' => array( $this, 'update_menu' ), 'permission_callback' => array( $this, 'is_user_logged_in' ) ) );

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

        // Google Login Endpoint
        register_rest_route('tariften/v1', '/auth/google', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_google_login'),
            'permission_callback' => '__return_true',
        ));

        // Normal Register Endpoint
        register_rest_route('tariften/v1', '/auth/register', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_register'),
            'permission_callback' => '__return_true',
        ));

        // Profil Güncelleme (YENİ)
        register_rest_route('tariften/v1', '/auth/update', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_profile_update'),
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ));

        // Avatar Yükleme
        register_rest_route('tariften/v1', '/auth/avatar', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_avatar_upload'),
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ));

        // Mevcut Kullanıcı Bilgilerini Getir (YENİ)
        register_rest_route('tariften/v1', '/auth/me', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_current_user_data'),
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ));
    }

    /**
     * MENÜ GÜNCELLEME (YENİ)
     */
    public function update_menu( $request ) {
        $params = $request->get_json_params();
        $menu_id = isset($params['id']) ? intval($params['id']) : 0;
        $user_id = get_current_user_id();

        if ( !$menu_id ) return new WP_Error( 'missing_id', 'Menü ID eksik.', array( 'status' => 400 ) );

        $post = get_post( $menu_id );
        if ( !$post || $post->post_type !== 'menu' ) return new WP_Error( 'not_found', 'Menü bulunamadı.', array( 'status' => 404 ) );

        // Yetki Kontrolü: Sadece yazar veya admin güncelleyebilir
        if ( intval($post->post_author) !== $user_id && !current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'Bu menüyü düzenleme yetkiniz yok.', array( 'status' => 403 ) );
        }

        // Ana verileri güncelle
        $update_data = array( 'ID' => $menu_id );
        if ( isset( $params['title'] ) ) $update_data['post_title'] = sanitize_text_field( $params['title'] );
        if ( isset( $params['description'] ) ) $update_data['post_excerpt'] = sanitize_textarea_field( $params['description'] );
        
        wp_update_post( $update_data );

        // Meta verileri güncelle
        if ( isset( $params['concept'] ) ) update_post_meta( $menu_id, 'tariften_menu_concept', sanitize_text_field( $params['concept'] ) );
        if ( isset( $params['guest_count'] ) ) update_post_meta( $menu_id, 'tariften_guest_count', intval( $params['guest_count'] ) );
        if ( isset( $params['image'] ) ) update_post_meta( $menu_id, 'tariften_image_url', esc_url_raw( $params['image'] ) );
        if ( isset( $params['event_type'] ) ) update_post_meta( $menu_id, 'tariften_event_type', sanitize_text_field( $params['event_type'] ) );

        // Bölümleri ve Tarifleri Güncelle
        if ( isset( $params['sections'] ) && is_array( $params['sections'] ) ) {
            $clean_sections = [];
            foreach($params['sections'] as $sec) {
                $clean_recipes = [];
                if(!empty($sec['recipes'])) {
                    foreach($sec['recipes'] as $r) {
                        // Frontend'den obje gelirse ID'sini al, ID gelirse direkt kullan
                        $rid = is_array($r) ? ($r['id'] ?? 0) : $r;
                        if($rid) $clean_recipes[] = intval($rid);
                    }
                }
                $clean_sections[] = [
                    'type' => sanitize_text_field($sec['type']),
                    'title' => sanitize_text_field($sec['title']),
                    'recipes' => $clean_recipes
                ];
            }
            update_post_meta( $menu_id, 'tariften_menu_sections', $clean_sections );
        }

        return new WP_REST_Response( array( 'success' => true, 'slug' => $post->post_name ), 200 );
    }
    
/**
 * MERKEZİ AI TARİF MOTORU (GÜÇLENDİRİLMİŞ - INTENT + VALIDATION + REPAIR)
 */
private function _internal_generate_recipe_logic( $input_text, $prompt_type = 'suggest', $is_menu_mode = false ) {
    if ( function_exists('set_time_limit') ) {
        @set_time_limit(120);
    }

    $api_key = get_option( 'tariften_openai_key' );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', 'OpenAI API anahtarı ayarlanmamış.' );
    }

    // -----------------------------
    // 1) Term listeleri (names) + slug listeleri
    // -----------------------------
    $available_cuisines      = get_terms(['taxonomy' => 'cuisine',     'fields' => 'names', 'hide_empty' => false]);
    $available_diets         = get_terms(['taxonomy' => 'diet',        'fields' => 'names', 'hide_empty' => false]);
    $available_meals         = get_terms(['taxonomy' => 'meal_type',   'fields' => 'names', 'hide_empty' => false]);
    $available_difficulties  = get_terms(['taxonomy' => 'difficulty',  'fields' => 'names', 'hide_empty' => false]);

    if ( empty($available_cuisines) )     $available_cuisines = ['Türk Mutfağı'];
    if ( empty($available_diets) )        $available_diets = ['Hepçil'];
    if ( empty($available_meals) )        $available_meals = ['Akşam Yemeği'];
    if ( empty($available_difficulties) ) $available_difficulties = ['Kolay'];

    $to_slug = function($s) {
        $s = trim((string)$s);
        if ($s === '') return '';
        $s = str_replace(
            ['İ', 'ı', 'ş', 'Ş', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'],
            ['i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'],
            $s
        );
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9\s-]/u', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return sanitize_title($s);
    };

    $cuisine_slugs     = array_values(array_filter(array_unique(array_map($to_slug, (array)$available_cuisines))));
    $diet_slugs        = array_values(array_filter(array_unique(array_map($to_slug, (array)$available_diets))));
    $meal_slugs        = array_values(array_filter(array_unique(array_map($to_slug, (array)$available_meals))));
    $difficulty_slugs  = array_values(array_filter(array_unique(array_map($to_slug, (array)$available_difficulties))));

    // -----------------------------
    // 2) INTENT DETECTION (self-contained)
    // -----------------------------
    $raw_input = trim((string)$input_text);
    $raw_lower = mb_strtolower($raw_input);

    $detect_intent = function(string $q) use ($raw_lower) {
        $q = trim($q);
        $q_l = mb_strtolower($q);

        // A) ingredient list (comma/measure/number)
        $is_list = (
            preg_match('/[,;]/u', $q) ||
            preg_match('/\b(\d+)\b/u', $q) ||
            preg_match('/\b(adet|gram|gr|kg|ml|lt|su bardağı|yemek kaşığı|tatlı kaşığı|çay kaşığı)\b/iu', $q)
        );
        if ($is_list) return ['intent' => 'ingredients', 'dish' => '', 'raw' => $q];

        // B) need / mood / constraint
        $need_markers = [
            'misafir','akşama','aksama','havalı','canım','canim',
            'diyette','diyetteyim','fit','yüksek protein','yuksek protein','protein',
            'kahve','tatlı','tatli','pratik','hızlı','hizli','kolay','az malzemeli'
        ];
        foreach ($need_markers as $m) {
            if (mb_strpos($q_l, $m) !== false) {
                return ['intent' => 'need', 'dish' => '', 'raw' => $q];
            }
        }

        // C) DB exact match as recipe -> dish
        $by_title = get_page_by_title($q, OBJECT, 'recipe');
        $by_slug  = get_page_by_path(sanitize_title($q), OBJECT, 'recipe');
        if ($by_title || $by_slug) {
            return ['intent' => 'dish', 'dish' => $q, 'raw' => $q];
        }

        // D) ingredient CPT exact -> ingredients
        $ing = get_page_by_title($q, OBJECT, 'ingredient');
        if ($ing) {
            return ['intent' => 'ingredients', 'dish' => '', 'raw' => $q];
        }

        // E) heuristic dish words
        $dish_words = [
            'dolma','sarma','kebap','kebab','çorba','corba','salata','pilav','makarna',
            'kek','kurabiye','börek','borek','köfte','kofte','baget','mantı','manti','sos','tost'
        ];
        foreach ($dish_words as $w) {
            if (mb_strpos($q_l, $w) !== false) {
                return ['intent' => 'dish', 'dish' => $q, 'raw' => $q];
            }
        }

        // Default: single / vague -> ingredients (fixes "şarap" bug)
        return ['intent' => 'ingredients', 'dish' => '', 'raw' => $q];
    };

    $intent = $detect_intent($raw_input);

    // Menü modunda: menüden gelen isimler genelde dish olur.
    // Ama "şarap" gibi tek kelime ingredient ise dish'e çevirmiyoruz.
    if ($is_menu_mode && $intent['intent'] !== 'ingredients' && $intent['intent'] !== 'need') {
        $intent['intent'] = 'dish';
        $intent['dish']   = $raw_input;
    }

    $requested_dish = ($intent['intent'] === 'dish') ? $intent['dish'] : '';
    $is_need_mode   = ($intent['intent'] === 'need');

    // -----------------------------
    // 3) JSON ŞEMA (güçlendirilmiş)
    // -----------------------------
    $json_structure = '{
      "title": "Cezbedici ve Özgün Tarif Adı(title için ASLA parantez içinde hook yazma)",
      "image_search_query": "Görsel arama terimi. Türk mutfağıysa TÜRKÇE (örn: \'İmambayıldı yemeği\'), dünya mutfağıysa İNGİLİZCE. Sonuna \'yemek\'/\'food\' ve \'tabak\'/\'plate\' ekle. Emin değilsen NULL.",
      "excerpt": "Tarif özeti (150-160 karakter)",
      "prep_time": "Sadece sayı (dk)",
      "cook_time": "Sadece sayı (dk)",
      "calories": "Sadece sayı (kcal)",
      "servings": "Sadece sayı (kişi)",

      "cuisine": ["SADECE cuisine slug (tek eleman)"],
      "meal_type": ["SADECE meal_type slug (tek eleman)"],
      "difficulty": ["SADECE difficulty slug (tek eleman)"],
      "diet": ["SADECE diet slug (tek eleman) veya []"],

      "ingredients": [{"name":"Malzeme adı","amount":"Miktar","unit":"Birim"}],
      "steps": ["Adım 1","Adım 2","Adım 3","Adım 4","Adım 5","Adım 6 (en az 6 adım)"],
      "chef_tip": "Bu tarife özel, pratik ve profesyonel bir şef ipucu (1-2 cümle)",

      "seo": {"title":"SEO Başlık (parantez içinde hook şart)","description":"SEO Açıklama","focus_keywords":"Anahtar kelimeler"}
    }';

    // -----------------------------
    // 4) SYSTEM PROMPT (sert kurallar)
    // -----------------------------
    $system_prompt = "Sen Tariften.com’un hem 'Tarif Üretim Şefi' hem de 'SEO Uzmanı'sın.
        Hedef kitle: Türkiye’de yaşayan ev kullanıcıları.

        0) NİYET KURALI:
        - Eğer kullanıcı bir YEMEK ADI verdiyse, ASLA başka yemeğe dönüştürme.
        - Eğer kullanıcı MALZEME / MALZEMELER verdiyse, bu malzemeleri gerçekten kullanan en uygun tarifi üret.
        - Eğer kullanıcı bir İHTİYAÇ yazdıysa (misafir, diyetteyim, yüksek protein, kahve vb.), ihtiyaca uygun bir tarif öner.

        1) BAŞLIK SANATI (SERT):
        - Sıradan başlıklar yasak. Türkçe, iştah açıcı, tıklanma odaklı olmalı.
        - Türk yemeklerinde İngilizce sıfat kullanma. (Crispy/Best/Delicious yasak)

        2) MALZEME->YEMEK KURALI (SERT):
        - Eğer intent=ingredients ise başlık ASLA sadece tek malzeme adı olamaz. (örn: \"Şarap\" yasak)
        - Malzemeyi içeren gerçek bir yemek üret: \"Şaraplı ...\", \"... Soslu ...\", \"... Marineli ...\"
        - Ayrıca kullanıcı malzemesi 'ingredients' listesinde ilk 5 içinde mutlaka yer alsın (özellikle tek kelime girdide).

        3) ADIM KURALI (SERT):
        - steps alanı EN AZ 6 adım olmak zorunda.

        4) SEO KURALI (SERT):
        - seo.title düz \"<Yemek> Tarifi\" olamaz.
        - Format: \"<Yemek> Tarifi (Kısa Hook)\" örn: \"Şaraplı Tavuk Tarifi (Misafir İçin Havalı, Yumuşacık)\"

        5) KATEGORİ ZORLAMASI:
        - Aşağıdaki slug listeleri dışında ASLA başka kategori uydurma.
        - cuisine slugları: " . implode(', ', (array)$cuisine_slugs) . "
        - diet slugları: " . implode(', ', (array)$diet_slugs) . "
        - meal_type slugları: " . implode(', ', (array)$meal_slugs) . "
        - difficulty slugları: " . implode(', ', (array)$difficulty_slugs) . "

        6) GÖRSEL ARAMA KURALI:
        - Türk mutfağı: sorgu TÜRKÇE + 'yemek'/'tabak'
        - Dünya mutfağı: sorgu İNGİLİZCE + 'food'/'plate'
        - Emin değilsen veya yanlış eşleşme riski varsa NULL döndür.

        ÇIKTI FORMATI: SADECE saf JSON (markdown yok).
        ŞEMA:
        {$json_structure}";

            // -----------------------------
            // 5) USER PROMPT (intent’e göre)
            // -----------------------------
            $temperature = 0.65;

            if (!empty($requested_dish)) {
                $user_prompt = "İstek (YEMEK ADI): {$requested_dish}
        Kural: Aynı yemeği üret; başka yemeğe çevirmek yasak.
        - steps en az 3
        - seo.title parantezli hook içersin.";
                $temperature = 0.30;
            } else if ($is_need_mode) {
                $user_prompt = "İstek (İHTİYAÇ): {$raw_input}
        Kural: Bu bir ihtiyaç cümlesi. Buna uygun bir tarif öner.
        - Uçuk/alakasız malzeme kullanma, Türkiye'de bulunabilir olsun.
        - steps en az 3
        - seo.title parantezli hook içersin.";
                $temperature = 0.60;
            } else {
                // ingredients
                $user_prompt = "İstek (MALZEME): {$raw_input}
        Kural: Bu bir malzeme girdisidir; malzemeyi yemek adı yapma.
        - Bu malzemeyi tarifte gerçekten kullan.
        - ingredients listesinde ilk 5 içinde bu malzemeyi geçir (özellikle tek kelime girdilerde).
        - steps en az 3
        - seo.title parantezli hook içersin.";

        if ($prompt_type === 'rescue') {
            $user_prompt .= " Malzemeler bozulmak üzere; pratik kurtarıcı tarif ver.";
            $temperature = 0.40;
        } else if ($prompt_type === 'plan') {
            $user_prompt .= " Dengeli bir ana öğün olacak şekilde kurgula.";
            $temperature = 0.50;
        } else {
            $user_prompt .= " En etkileyici ama uygulanabilir tarifi üret.";
            $temperature = 0.65;
        }
    }

    // -----------------------------
    // 6) OpenAI çağrısı (helper)
    // -----------------------------
    $call_openai = function(string $sys, string $usr, float $temp) use ($api_key) {
        $res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => get_option('tariften_ai_model', 'gpt-4o-mini'),
                'messages' => array(
                    array('role' => 'system', 'content' => $sys),
                    array('role' => 'user',   'content' => $usr),
                ),
                'response_format' => array('type' => 'json_object'),
                'temperature' => $temp,
                'max_tokens' => 2500,
            )),
        ) );

        if (is_wp_error($res)) return $res;

        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (empty($body)) return new WP_Error('ai_error', 'AI yanıtı okunamadı.');

        if (!empty($body['error'])) {
            $msg = $body['error']['message'] ?? 'AI hatası.';
            return new WP_Error('ai_error', $msg);
        }

        $content = $body['choices'][0]['message']['content'] ?? '';
        $json = json_decode($content, true);

        if (!is_array($json) || empty($json['title'])) {
            return new WP_Error('ai_error', 'AI geçersiz/eksik JSON üretti.');
        }
        return $json;
    };

    $recipe_json = $call_openai($system_prompt, $user_prompt, $temperature);
    if (is_wp_error($recipe_json)) return $recipe_json;

    // -----------------------------
    // 7) VALIDATION + REPAIR (1 kez)
    // -----------------------------
    $needs_repair = false;

    // steps >= 6
    if (empty($recipe_json['steps']) || !is_array($recipe_json['steps']) || count($recipe_json['steps']) < 6) $needs_repair = true;

    // ingredients >= 4
    if (empty($recipe_json['ingredients']) || !is_array($recipe_json['ingredients']) || count($recipe_json['ingredients']) < 4) $needs_repair = true;

    // seo title hook
    $seo_title = $recipe_json['seo']['title'] ?? '';
    if (empty($seo_title) || mb_strpos((string)$seo_title, '(') === false) $needs_repair = true;

    // ingredients mode strict: tek kelime input ilk 5 içinde geçsin
    if (empty($requested_dish) && !$is_need_mode) {
        $is_single_word = (mb_strpos($raw_input, ' ') === false);
        if ($is_single_word) {
            $needle = mb_strtolower($raw_input);
            $first5 = array_slice($recipe_json['ingredients'] ?? [], 0, 5);
            $hit = false;
            foreach ($first5 as $ing) {
                $nm = mb_strtolower((string)($ing['name'] ?? ''));
                if ($nm === '') continue;
                if (mb_strpos($nm, $needle) !== false || mb_strpos($needle, $nm) !== false) { $hit = true; break; }
            }
            if (!$hit) $needs_repair = true;
        }
    }

    if ($needs_repair) {
        $repair_prompt = "Aşağıdaki JSON eksik/kurala aykırı. ŞEMAYA sadık kalarak DÜZELT ve SADECE JSON döndür.
        Zorunlular:
        - steps en az 3
        - ingredients en az 4
        - title parantez içermez, Cezbedici ve Özgün Tarif Adı yazılır.
        - seo.title parantezli hook içersin
        - Eğer bu bir MALZEME isteğiyse, kullanıcı malzemesi ingredients listesinde ilk 5 içinde yer alsın (özellikle tek kelime).
        JSON:\n" . json_encode($recipe_json, JSON_UNESCAPED_UNICODE);

        $fixed = $call_openai($system_prompt, $repair_prompt, 0.20);
        if (!is_wp_error($fixed) && is_array($fixed) && !empty($fixed['title'])) {
            $recipe_json = $fixed;
        }
    }

    // -----------------------------
    // 8) Görsel (Senin mevcut smart image fonksiyonun)
    // -----------------------------
    $image_query_raw = $recipe_json['image_search_query'] ?? '';
    $img_query = (!empty($image_query_raw) && strtoupper((string)$image_query_raw) !== 'NULL')
        ? (string)$image_query_raw
        : (string)($recipe_json['title'] ?? $raw_input);

    $recipe_json['image'] = $this->_fetch_smart_image($img_query, (string)($recipe_json['title'] ?? ''));

    // -----------------------------
    // 9) Kategori doğrulama (senin mevcut validate_terms)
    // -----------------------------
    $recipe_json['cuisine']    = $this->validate_terms('cuisine',    $recipe_json['cuisine']    ?? [], $available_cuisines);
    $recipe_json['diet']       = $this->validate_terms('diet',       $recipe_json['diet']       ?? [], $available_diets);
    $recipe_json['meal_type']  = $this->validate_terms('meal_type',  $recipe_json['meal_type']  ?? [], $available_meals);
    $recipe_json['difficulty'] = $this->validate_terms('difficulty', $recipe_json['difficulty'] ?? [], $available_difficulties);

    // -----------------------------
    // 10) Son güvenlik: title boşsa hata
    // -----------------------------
    if ( empty($recipe_json['title']) ) {
        return new WP_Error('ai_error', 'AI Eksik veri üretti.');
    }

    return $recipe_json;
}


    /**
     * Endpoint: /ai/generate
     */
    public function generate_ai_recipe_endpoint( $request ) {
        $params = $request->get_json_params();
        $ingredients = $params['ingredients'] ?? '';
        $type = $params['type'] ?? 'suggest';

        $result = $this->_internal_generate_recipe_logic($ingredients, $type, false);

        if ( is_wp_error($result) ) return new WP_REST_Response(['success'=>false, 'message'=>$result->get_error_message()], 500);

        $slug = sanitize_title($result['title']);
        $existing = get_page_by_path($slug, OBJECT, 'recipe');
        if (!$existing) $existing = get_page_by_title($result['title'], OBJECT, 'recipe');
        
        if ($existing) {
             return new WP_REST_Response(['success'=>true, 'recipe'=>$this->format_recipe_for_api($existing), 'message'=>'Mevcut tarif getirildi.'], 200);
        }

        return new WP_REST_Response(['success'=>true, 'recipe'=>$result], 200);
    }

/**
 * Endpoint: /ai/generate-menu
 * FINAL: Section type/title backend kilitli, uzun açıklama, strict validate + repair
 */
public function generate_ai_menu( $request ) {

    if ( function_exists('set_time_limit') ) {
        @set_time_limit(240);
    }

    $params      = $request->get_json_params();
    $user_id     = get_current_user_id();

    $concept     = sanitize_text_field( $params['concept'] ?? '' );
    $guest_count = intval( $params['guest_count'] ?? 0 );
    $event_type_raw = sanitize_text_field( $params['event_type'] ?? '' ); // kullanıcı seçiyor

    // opsiyonel diet
    $diet_from_user = sanitize_text_field( $params['diet_preference'] ?? '' );

    if ( empty($concept) || $guest_count < 1 || empty($event_type_raw) ) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Konsept, kişi sayısı ve öğün tipi zorunludur.'
        ], 400);
    }

    $api_key = get_option( 'tariften_openai_key' );
    if ( empty( $api_key ) ) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'OpenAI Key eksik.'
        ], 500);
    }

    $model_menu = get_option('tariften_ai_model', 'gpt-4o-mini');

    // --- event_type normalize ---
    $normalize_event_type = function(string $raw) {
        $r = mb_strtolower(trim($raw));
        $r = str_replace(['ı','İ','ş','Ş','ğ','Ğ','ü','Ü','ö','Ö','ç','Ç'], ['i','i','s','s','g','g','u','u','o','o','c','c'], $r);

        if (strpos($r, 'kahval') !== false) return 'breakfast';
        if (strpos($r, 'ogle') !== false) return 'lunch';
        if (strpos($r, 'bes') !== false || strpos($r, 'cay') !== false) return 'tea_time';
        if (strpos($r, 'aksam') !== false) return 'dinner';
        if (strpos($r, 'kokteyl') !== false) return 'cocktail';

        return '';
    };

    $event_type = $normalize_event_type($event_type_raw);
    if (empty($event_type)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Geçersiz öğün tipi.'
        ], 400);
    }

    $event_type_label_map = [
        'breakfast' => 'Kahvaltı',
        'lunch'     => 'Öğle Yemeği',
        'tea_time'  => 'Beş Çayı',
        'dinner'    => 'Akşam Yemeği',
        'cocktail'  => 'Kokteyl',
    ];
    $event_type_label = $event_type_label_map[$event_type] ?? $event_type_raw;

    // diet kullanıcıdan gelmediyse user meta
    if (empty($diet_from_user)) {
        $diet_meta = get_user_meta($user_id, 'tariften_diet', true);
        if (!empty($diet_meta)) $diet_from_user = sanitize_text_field($diet_meta);
    }

    /**
     * FRONTEND UYUMLU CANONICAL SECTION TYPE SETİ
     * Not: Frontend’in tanımadığı type -> "Diğer Lezzetler"e düşer.
     * O yüzden type’ları sabit ve tanımlı tutuyoruz.
     * 
     * Standart Section Yapısı:
     * - starter: Başlangıç
     * - hot_appetizer: Ara Sıcak
     * - soup: Çorba
     * - meze: Meze ve Aperatifler
     * - main: Ana Yemek
     * - dessert: Tatlı
     * - drink: İçecek
     */
    $plans = [
        'breakfast' => [
            ['type'=>'starter','title'=>'Başlangıç','count'=>2,'rules'=>'Kahvaltıya uygun hafif başlangıçlar.'],
            ['type'=>'main','title'=>'Ana Kahvaltılıklar','count'=>2,'rules'=>'Menemen, omlet, yumurtalı tarifler.'],
            ['type'=>'drink','title'=>'İçecek','count'=>1,'rules'=>'Kahvaltıya uygun içecek.'],
        ],
        'lunch' => [
            ['type'=>'soup','title'=>'Çorba','count'=>1,'rules'=>'Öğle yemeğine uygun çorba.'],
            ['type'=>'starter','title'=>'Başlangıç','count'=>1,'rules'=>'Hafif başlangıç.'],
            ['type'=>'main','title'=>'Ana Yemek','count'=>2,'rules'=>'Ana yemek + eşlikçi.'],
            ['type'=>'drink','title'=>'İçecek','count'=>1,'rules'=>'İçecek.'],
        ],
        'tea_time' => [
            ['type'=>'starter','title'=>'Tuzlu Atıştırmalıklar','count'=>2,'rules'=>'Çay saatine uygun tuzlular.'],
            ['type'=>'dessert','title'=>'Tatlılar','count'=>2,'rules'=>'Çay saatine uygun tatlı.'],
            ['type'=>'drink','title'=>'İçecek','count'=>1,'rules'=>'Çay/kahve.'],
        ],
        'dinner' => [
            ['type'=>'soup','title'=>'Çorba','count'=>1,'rules'=>'Akşam yemeğine uygun çorba.'],
            ['type'=>'meze','title'=>'Meze ve Aperatifler','count'=>2,'rules'=>'Soğuk/sıcak mezeler.'],
            ['type'=>'hot_appetizer','title'=>'Ara Sıcak','count'=>1,'rules'=>'Ara sıcak.'],
            ['type'=>'main','title'=>'Ana Yemek','count'=>2,'rules'=>'Ana yemek.'],
            ['type'=>'dessert','title'=>'Tatlı','count'=>1,'rules'=>'Tatlı.'],
            ['type'=>'drink','title'=>'İçecek','count'=>1,'rules'=>'İçecek.'],
        ],
        'cocktail' => [
            ['type'=>'meze','title'=>'Soğuk Mezeler','count'=>3,'rules'=>'Finger food/kanape.'],
            ['type'=>'hot_appetizer','title'=>'Sıcak İkramlar','count'=>2,'rules'=>'Sıcak atıştırmalık.'],
            ['type'=>'drink','title'=>'İçecekler','count'=>2,'rules'=>'İçecekler.'],
        ],
    ];

    $plan = $plans[$event_type];

    // --- OpenAI JSON caller ---
    $call_openai_json = function(string $model, array $messages, int $timeout = 90, float $temperature = 0.35, int $max_tokens = 2400) use ($api_key) {
        $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'messages' => $messages,
                'response_format' => ['type' => 'json_object'],
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
            ])
        ]);

        if (is_wp_error($res)) return $res;

        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!empty($body['error'])) {
            $msg = $body['error']['message'] ?? 'OpenAI hatası';
            return new WP_Error('openai_error', $msg);
        }

        $content = $body['choices'][0]['message']['content'] ?? '';
        $json = json_decode($content, true);

        if (!is_array($json)) {
            return new WP_Error('json_parse_error', 'AI JSON üretemedi veya bozuk JSON döndü.');
        }
        return $json;
    };

    // --- strict validator (type set + count) ---
    $validate_sections = function(array $sections, array $plan) {
        if (!is_array($sections)) return false;

        $plan_map = [];
        foreach ($plan as $p) {
            $t = (string)($p['type'] ?? '');
            $c = intval($p['count'] ?? 0);
            if ($t && $c > 0) $plan_map[$t] = $c;
        }

        // aynı type set olmalı
        $seen = [];
        foreach ($sections as $sec) {
            $type = (string)($sec['type'] ?? '');
            if (!$type || !isset($plan_map[$type])) return false;
            if (isset($seen[$type])) return false;
            $seen[$type] = true;

            $recipes = $sec['recipes'] ?? null;
            if (!is_array($recipes) || count($recipes) !== $plan_map[$type]) return false;

            foreach ($recipes as $r) {
                if (!is_string($r) || trim($r) === '') return false;
                // “tarif cümlesi” olmasın: aşırı uzun isimleri kırp
                if (mb_strlen(trim($r)) > 80) return false;
            }
        }

        // plan’daki tüm type’lar gelmiş mi
        foreach ($plan_map as $t => $_c) {
            if (empty($seen[$t])) return false;
        }

        return true;
    };

    /**
     * 1) Menü meta üret: başlık + uzun description + seo + image_search_query
     * 2) Section listesi backend tarafından yazılacak (AI sadece recipes üretecek)
     */
    $system_menu_meta = "Sen Tariften.com’un Executive Şefisin.

KURALLAR:
- Öğün tipi: {$event_type_label}. Buna %100 uy.
- Menü başlığı PARANTEZSİZ olacak. Parantez kullanma.
- Description KISA OLMAYACAK: 450–650 karakter arası, hikaye/akış/servis önerisi içersin.
- Diyet tercihi varsa %100 uy (vegan/vejetaryen/glutensiz/keto/yüksek protein/düşük kalorili).
- SEO: seo.title max 60 karakter, parantez yok. seo.description max 160 karakter.

ÇIKTI: SADECE JSON:
{
  \"menu_title\":\"\",
  \"description\":\"\",
  \"image_search_query\":\"... veya NULL\",
  \"seo\":{\"title\":\"\",\"description\":\"\",\"focus_keywords\":\"\"}
}";

    $user_menu_meta = "Konsept: {$concept}
Kişi sayısı: {$guest_count}
Öğün tipi: {$event_type_label}
Diyet tercihi: " . ($diet_from_user ?: 'yok');

    $menu_meta = $call_openai_json($model_menu, [
        ['role'=>'system','content'=>$system_menu_meta],
        ['role'=>'user','content'=>$user_menu_meta],
    ], 90, 0.35, 1400);

    if (is_wp_error($menu_meta)) {
        return new WP_REST_Response(['success'=>false,'message'=>$menu_meta->get_error_message()], 500);
    }

    $menu_title = sanitize_text_field($menu_meta['menu_title'] ?? '');
    $menu_desc  = sanitize_textarea_field($menu_meta['description'] ?? '');

    if (empty($menu_title)) $menu_title = $concept . ' Menüsü';

    // Description minimum güvence
    if (mb_strlen($menu_desc) < 200) {
        $menu_desc = $menu_desc . " Bu menü, konseptin ruhuna uygun şekilde dengeli bir akışla kurgulandı. Hazırlık sırası ve servis önerileriyle birlikte, evde uygulanabilir malzemelerle misafirlerine şık bir deneyim sunar.";
    }

    // --- SECTION RECIPE LIST üret (AI sadece recipe isimleri) ---
    $system_sections = "Sen Tariften.com’da Menü Bölüm Asistanısın.

KURALLAR:
- Sana verilecek PLAN'daki her bölüm için TAM olarak count kadar NET YEMEK ADI üret.
- ASLA bölüm type/title uydurma. Sana verilen type'lara %100 uy.
- Bir içecek bölümüne meze/çorba koyma. Bir çorba bölümüne tatlı koyma. Yanlış eşleştirme YASAK.
- Tarif adı PARANTEZSİZ olacak. Parantez kullanma.
- Sadece yemek adı yaz: açıklama, emoji, ölçü, tarif cümlesi yazma.
- MENÜ AÇIKLAMASINDA bahsedilen tarifler MUTLAKA ilgili section'a dahil edilmeli.

ÇIKTI SADECE JSON:
{ \"sections\": [ {\"type\":\"...\",\"recipes\":[\"...\"]} ] }";

    $user_sections = "Konsept: {$concept}
Kişi sayısı: {$guest_count}
Öğün tipi: {$event_type_label}
Diyet: " . ($diet_from_user ?: 'yok') . "
Menü Açıklaması: {$menu_desc}

PLAN (type ve count sabit):
" . json_encode($plan, JSON_UNESCAPED_UNICODE);

    $sections_raw = $call_openai_json($model_menu, [
        ['role'=>'system','content'=>$system_sections],
        ['role'=>'user','content'=>$user_sections],
    ], 90, 0.25, 2200);

    if (is_wp_error($sections_raw) || empty($sections_raw['sections'])) {
        return new WP_REST_Response(['success'=>false,'message'=>'Menü bölümleri üretilemedi.'], 500);
    }

    // Backend: title’ları plan’dan bas, AI title üretmesin.
    $sections = [];
    foreach ($plan as $p) {
        $t = $p['type'];
        $sections[] = [
            'type'    => $t,
            'title'   => $p['title'],
            'recipes' => [],
        ];
    }

    // sections_raw -> map
    $by_type = [];
    foreach ((array)$sections_raw['sections'] as $sec) {
        $type = sanitize_text_field($sec['type'] ?? '');
        $recipes = $sec['recipes'] ?? [];
        if (!$type || !is_array($recipes)) continue;
        $by_type[$type] = array_map('sanitize_text_field', $recipes);
    }

    foreach ($sections as &$s) {
        $type = $s['type'];
        $need = 0;
        foreach ($plan as $p) if ($p['type'] === $type) { $need = intval($p['count']); break; }
        $list = $by_type[$type] ?? [];

        // count’i zorla
        $list = array_values(array_filter($list, function($x){
            return is_string($x) && trim($x) !== '';
        }));
        if (count($list) > $need) $list = array_slice($list, 0, $need);
        while (count($list) < $need) $list[] = $concept; // fallback (repair sonrası düzelir)

        $s['recipes'] = $list;
    }
    unset($s);

    // Validate değilse REPAIR çağrısı (aynı type set ile yeniden dağıt)
    if (!$validate_sections($sections, $plan)) {

        $system_repair = "Sen Tariften.com’da Menü Bölüm Düzeltme Asistanısın.

Görev: Aşağıdaki bölümlerde yanlış yerde duran tarifleri doğru bölümlere TAŞI.
KURALLAR:
- TYPE set'i ASLA değişmeyecek.
- Her type için recipe sayısı PLAN'daki count ile birebir olacak.
- Tarif adları PARANTEZSİZ.
- Sadece yemek adı.

ÇIKTI SADECE JSON:
{ \"sections\": [ {\"type\":\"...\",\"recipes\":[\"...\"]} ] }";

        $user_repair = "Öğün tipi: {$event_type_label}
Konsept: {$concept}
Diyet: " . ($diet_from_user ?: 'yok') . "

PLAN:
" . json_encode($plan, JSON_UNESCAPED_UNICODE) . "

MEVCUT (HATALI OLABİLİR):
" . json_encode(['sections'=>$sections], JSON_UNESCAPED_UNICODE);

        $repaired = $call_openai_json($model_menu, [
            ['role'=>'system','content'=>$system_repair],
            ['role'=>'user','content'=>$user_repair],
        ], 90, 0.10, 2200);

        if (!is_wp_error($repaired) && !empty($repaired['sections'])) {
            // apply repaired (titles yine backend’den)
            $by_type2 = [];
            foreach ((array)$repaired['sections'] as $sec) {
                $type = sanitize_text_field($sec['type'] ?? '');
                $recipes = $sec['recipes'] ?? [];
                if (!$type || !is_array($recipes)) continue;
                $by_type2[$type] = array_map('sanitize_text_field', $recipes);
            }

            foreach ($sections as &$s) {
                $type = $s['type'];
                $need = 0;
                foreach ($plan as $p) if ($p['type'] === $type) { $need = intval($p['count']); break; }

                $list = $by_type2[$type] ?? $s['recipes'];
                $list = array_values(array_filter($list, function($x){
                    return is_string($x) && trim($x) !== '';
                }));
                if (count($list) > $need) $list = array_slice($list, 0, $need);
                while (count($list) < $need) $list[] = $concept;

                $s['recipes'] = $list;
            }
            unset($s);
        }
    }

    if (!$validate_sections($sections, $plan)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Menü bölümleri planına uygun üretilemedi (validate/repair başarısız).'
        ], 500);
    }

    // --- Menü kaydı ---
    $menu_id = wp_insert_post([
        'post_title'   => $menu_title,
        'post_excerpt' => $menu_desc,
        'post_status'  => 'publish',
        'post_type'    => 'menu',
        'post_author'  => $user_id,
    ]);

    if (is_wp_error($menu_id) || !$menu_id) {
        return new WP_REST_Response(['success'=>false,'message'=>'Menü kaydedilemedi.'], 500);
    }

    update_post_meta($menu_id, 'tariften_menu_concept', $concept);
    update_post_meta($menu_id, 'tariften_guest_count', $guest_count);
    update_post_meta($menu_id, 'tariften_event_type', $event_type);
    if (!empty($diet_from_user)) update_post_meta($menu_id, 'tariften_diet_preference', $diet_from_user);

    // SEO kayıt
    if (!empty($menu_meta['seo']) && is_array($menu_meta['seo'])) {
        $seo_title = sanitize_text_field($menu_meta['seo']['title'] ?? '');
        $seo_desc  = sanitize_text_field($menu_meta['seo']['description'] ?? '');
        $seo_kw    = sanitize_text_field($menu_meta['seo']['focus_keywords'] ?? '');

        if ($seo_title) {
            update_post_meta($menu_id, 'rank_math_title', $seo_title);
            update_post_meta($menu_id, '_yoast_wpseo_title', $seo_title);
        } else {
            update_post_meta($menu_id, 'rank_math_title', $menu_title);
            update_post_meta($menu_id, '_yoast_wpseo_title', $menu_title);
        }

        if ($seo_desc) {
            update_post_meta($menu_id, 'rank_math_description', $seo_desc);
            update_post_meta($menu_id, '_yoast_wpseo_metadesc', $seo_desc);
        } else {
            update_post_meta($menu_id, 'rank_math_description', $menu_desc);
            update_post_meta($menu_id, '_yoast_wpseo_metadesc', $menu_desc);
        }

        if ($seo_kw) {
            update_post_meta($menu_id, 'rank_math_focus_keyword', $seo_kw);
            update_post_meta($menu_id, '_yoast_wpseo_focuskw', $seo_kw);
        }
    } else {
        update_post_meta($menu_id, 'rank_math_title', $menu_title);
        update_post_meta($menu_id, '_yoast_wpseo_title', $menu_title);
        update_post_meta($menu_id, 'rank_math_description', $menu_desc);
        update_post_meta($menu_id, '_yoast_wpseo_metadesc', $menu_desc);
    }

    // Menü görseli
    $menu_img_query = (string)($menu_meta['image_search_query'] ?? '');
    if (!$menu_img_query || strtoupper($menu_img_query) === 'NULL') {
        $menu_img_query = $concept . ' ' . $event_type_label . ' sofra';
    }
    $menu_image_url = $this->_fetch_smart_image($menu_img_query, '');
    update_post_meta($menu_id, 'tariften_image_url', $menu_image_url);

    // --- Tarifleri üret ve menu_sections'a ID olarak yaz ---
    $processed_sections = [];
    
    // Batch all recipe names first for potential optimization
    $all_recipe_names = [];
    foreach ($sections as $section) {
        $recipe_names = (array)$section['recipes'];
        foreach ($recipe_names as $recipe_name_raw) {
            $recipe_name = sanitize_text_field((string)$recipe_name_raw);
            if ($recipe_name && $recipe_name !== $concept) {
                $all_recipe_names[] = $recipe_name;
            }
        }
    }

    foreach ($sections as $section) {

        $sec_type  = sanitize_text_field($section['type']);
        $sec_title = sanitize_text_field($section['title']);
        $recipe_names = (array)$section['recipes'];

        $recipe_ids = [];

        foreach ($recipe_names as $recipe_name_raw) {
            $recipe_name = sanitize_text_field((string)$recipe_name_raw);
            if (!$recipe_name || $recipe_name === $concept) continue;

            // Mevcut tarif araması - önce tam eşleşme
            $existing = get_page_by_title($recipe_name, OBJECT, 'recipe');
            if (!$existing) $existing = get_page_by_path(sanitize_title($recipe_name), OBJECT, 'recipe');

            // Benzer tarif araması (tam eşleşme yoksa)
            // Basit benzerlik kontrolü ile yalnızca yakın eşleşmeleri kabul et
            if (!$existing) {
                $similar_search = new WP_Query([
                    'post_type' => 'recipe',
                    'post_status' => 'publish',
                    's' => $recipe_name,
                    'posts_per_page' => 3  // İlk 3 sonucu al ve karşılaştır
                ]);
                
                if ($similar_search->have_posts()) {
                    // Benzerlik skoru hesapla - basit kelime eşleşme oranı
                    $best_match = null;
                    $best_score = 0;
                    $recipe_words = array_map('mb_strtolower', explode(' ', $recipe_name));
                    
                    foreach ($similar_search->posts as $candidate) {
                        $candidate_words = array_map('mb_strtolower', explode(' ', $candidate->post_title));
                        $matching_words = count(array_intersect($recipe_words, $candidate_words));
                        $total_words = max(count($recipe_words), count($candidate_words));
                        $score = $total_words > 0 ? ($matching_words / $total_words) : 0;
                        
                        // %60+ benzerlik varsa kabul et (problem statement'da %80 deniyordu ama çok katı)
                        if ($score > $best_score && $score >= 0.6) {
                            $best_score = $score;
                            $best_match = $candidate;
                        }
                    }
                    
                    if ($best_match) {
                        $existing = $best_match;
                    }
                }
                wp_reset_postdata();
            }

            if ($existing && $existing->post_status === 'publish') {
                $recipe_ids[] = $existing->ID;
                continue;
            }

            // menü modu true: yemek adını tarif olarak üret
            $generated_data = $this->_internal_generate_recipe_logic($recipe_name, 'suggest', true);

            if (is_wp_error($generated_data) || empty($generated_data['title'])) continue;

            $steps = is_array($generated_data['steps'] ?? null) ? $generated_data['steps'] : [];
            if (count($steps) < 3) continue; // eksik tarif kaydetme

            $content = '<ul><li>' . implode('</li><li>', array_map('sanitize_text_field', $steps)) . '</li></ul>';

            $new_recipe_id = wp_insert_post([
                'post_title'   => sanitize_text_field($generated_data['title']),
                'post_content' => $content,
                'post_excerpt' => sanitize_textarea_field($generated_data['excerpt'] ?? ''),
                'post_status'  => 'publish',
                'post_type'    => 'recipe',
                'post_author'  => $user_id
            ]);

            if (is_wp_error($new_recipe_id) || !$new_recipe_id) continue;

            // meta
            if (!empty($generated_data['image'])) update_post_meta($new_recipe_id, 'tariften_image_url', esc_url_raw($generated_data['image']));
            foreach (['prep_time','cook_time','calories','servings'] as $m) {
                if (isset($generated_data[$m])) update_post_meta($new_recipe_id, $m, sanitize_text_field($generated_data[$m]));
            }
            update_post_meta($new_recipe_id, 'tariften_steps', array_map('sanitize_text_field', $steps));
            
            // Chef tip - AI'dan gelen ipucu
            if (!empty($generated_data['chef_tip'])) {
                update_post_meta($new_recipe_id, 'tariften_chef_tip', sanitize_textarea_field($generated_data['chef_tip']));
            }

            // ingredients
            $ingredients_formatted = [];
            $ings = is_array($generated_data['ingredients'] ?? null) ? $generated_data['ingredients'] : [];
            foreach ($ings as $ing) {
                $ing_name = sanitize_text_field($ing['name'] ?? '');
                if (!$ing_name) continue;

                $ing_exist = get_page_by_title($ing_name, OBJECT, 'ingredient');
                $ing_id = $ing_exist ? $ing_exist->ID : wp_insert_post([
                    'post_title'  => $ing_name,
                    'post_type'   => 'ingredient',
                    'post_status' => 'publish'
                ]);

                $ingredients_formatted[] = [
                    'ingredient_id' => intval($ing_id),
                    'name'   => $ing_name,
                    'amount' => sanitize_text_field($ing['amount'] ?? ''),
                    'unit'   => sanitize_text_field($ing['unit'] ?? ''),
                ];
            }
            if (!empty($ingredients_formatted)) {
                update_post_meta($new_recipe_id, 'tariften_ingredients', $ingredients_formatted);
            }

            // recipe SEO
            if (!empty($generated_data['seo']) && is_array($generated_data['seo'])) {
                $rt = sanitize_text_field($generated_data['seo']['title'] ?? '');
                $rd = sanitize_text_field($generated_data['seo']['description'] ?? '');
                $rk = sanitize_text_field($generated_data['seo']['focus_keywords'] ?? '');
                if ($rt) update_post_meta($new_recipe_id, 'rank_math_title', $rt);
                if ($rd) update_post_meta($new_recipe_id, 'rank_math_description', $rd);
                if ($rk) update_post_meta($new_recipe_id, 'rank_math_focus_keyword', $rk);

                if ($rt) update_post_meta($new_recipe_id, '_yoast_wpseo_title', $rt);
                if ($rd) update_post_meta($new_recipe_id, '_yoast_wpseo_metadesc', $rd);
                if ($rk) update_post_meta($new_recipe_id, '_yoast_wpseo_focuskw', $rk);
            }

            // taxonomy (slug)
            if (!empty($generated_data['cuisine']))     wp_set_object_terms($new_recipe_id, $generated_data['cuisine'], 'cuisine');
            if (!empty($generated_data['meal_type']))   wp_set_object_terms($new_recipe_id, $generated_data['meal_type'], 'meal_type');
            if (!empty($generated_data['difficulty']))  wp_set_object_terms($new_recipe_id, $generated_data['difficulty'], 'difficulty');
            if (!empty($generated_data['diet']))        wp_set_object_terms($new_recipe_id, $generated_data['diet'], 'diet');

            $recipe_ids[] = $new_recipe_id;
        }

        $processed_sections[] = [
            'type'    => $sec_type,
            'title'   => $sec_title,
            'recipes' => $recipe_ids
        ];
    }

    update_post_meta($menu_id, 'tariften_menu_sections', $processed_sections);

    $menu_post = get_post($menu_id);

    return new WP_REST_Response([
        'success' => true,
        'slug'    => $menu_post ? $menu_post->post_name : '',
        'id'      => $menu_id,
        'message' => 'Menü oluşturuldu.'
    ], 200);
}


    public function search_menus($request) {
        $slug = $request->get_param('slug');
        $collection = $request->get_param('collection'); // YENİ: Koleksiyon parametresi
        
        $sanitized_slug = sanitize_title($slug);

        $args = array(
            'post_type' => 'menu',
            'post_status' => 'publish',
        );

        // A) Tekil Menü Getirme
        if ( !empty($slug) ) {
            $args['posts_per_page'] = 1;
            $args['name'] = $sanitized_slug;
            $query = new WP_Query($args);

            if (!$query->have_posts()) {
                $args['name'] = $slug;
                $query = new WP_Query($args);
                if (!$query->have_posts()) {
                    $approx_title = str_replace('-', ' ', $slug);
                    unset($args['name']);
                    $args['s'] = $approx_title;
                    $query = new WP_Query($args);
                }
            }
        } 
        // B) Menü Listesi / Arşiv / Vitrin
        else {
            $args['posts_per_page'] = 12;
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';

            // YENİ: Koleksiyon Filtresi
            if ( !empty($collection) ) {
                $args['tax_query'] = array(
                    array(
                        'taxonomy' => 'collection',
                        'field'    => 'slug',
                        'terms'    => sanitize_text_field($collection),
                    ),
                );
            }

            $query = new WP_Query($args);
        }

        if (!$query->have_posts()) {
             return new WP_REST_Response(['message' => 'Menü bulunamadı', 'slug' => $slug, 'data' => []], 404);
        }

        $results = [];
        $is_single = !empty($slug);

        while ($query->have_posts()) {
            $query->the_post();
            $post = get_post();
            $id = $post->ID;

            $image = get_post_meta($id, 'tariften_image_url', true);
            if (!$image) $image = 'https://placehold.co/800x600?text=Menu';

            $seo = [
                'title' => get_post_meta($id, 'rank_math_title', true),
                'description' => get_post_meta($id, 'rank_math_description', true),
                'keywords' => get_post_meta($id, 'rank_math_focus_keyword', true)
            ];

            $raw_sections = get_post_meta($id, 'tariften_menu_sections', true) ?: [];
            $sections = [];

            // Listelemede detaylara gerek yok, tekil sayfada detayları doldur
            if ($is_single) {
                foreach ($raw_sections as $sec) {
                    $recipes_data = [];
                    if (!empty($sec['recipes'])) {
                        foreach ($sec['recipes'] as $rid) {
                            $r_post = get_post($rid);
                            if ($r_post) {
                                $recipes_data[] = $this->format_recipe_for_api($r_post);
                            }
                        }
                    }
                    $sections[] = array(
                        'type' => $sec['type'],
                        'title' => $sec['title'],
                        'recipes' => $recipes_data
                    );
                }
            }

            $results[] = array(
                'id' => $id,
                'title' => $post->post_title,
                'slug' => $post->post_name,
                'description' => $post->post_excerpt,
                'image' => $image,
                'concept' => get_post_meta($id, 'tariften_menu_concept', true),
                'guest_count' => get_post_meta($id, 'tariften_guest_count', true),
                'event_type' => get_post_meta($id, 'tariften_event_type', true),
                'sections' => $sections,
                'author_id' => $post->post_author,
                'date' => get_the_date('Y-m-d H:i:s', $id),
                'seo' => $seo
            );
        }

        if (!empty($slug) && count($results) > 0) {
            return new WP_REST_Response($results[0], 200);
        }

        return new WP_REST_Response(['data' => $results], 200);
    }

    /**
     * GÜÇLENDİRİLMİŞ GÖRSEL ARAMA
     * Pexels + Unsplash Fallback + Smart Pick Logic
     */
    private function _fetch_smart_image($query, $strict_match_term = '') {
        $pexels_key = get_option('tariften_pexels_key');
        $unsplash_key = get_option('tariften_unsplash_key');
        
        // 1. Pick Best Logic (Orijinal Koddan)
        $pick_best = function(array $candidates, string $needle) {
            // Eğer strict_match_term boşsa (örn: menü oluştururken), direkt ilkini dön
            if (empty($needle)) return !empty($candidates) ? $candidates[0] : null;

            $stopwords = ['yemek', 'yemeği', 'food', 'plate', 'dish', 'recipe', 'tarif', 'cooked', 'meal', 'mutfağı'];
            
            $needle_clean = str_replace(['İ', 'ı', 'ş', 'Ş', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'], ['i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'], $needle);
            $needle_clean = mb_strtolower($needle_clean);
            foreach ($stopwords as $sw) { $needle_clean = str_replace($sw, '', $needle_clean); }
            $needle_clean = trim($needle_clean);
            if (mb_strlen($needle_clean) < 3) $needle_clean = mb_strtolower($needle);

            foreach ($candidates as $c) {
                $hay = str_replace(['İ', 'ı', 'ş', 'Ş', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'], ['i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'], (string)($c['haystack']??''));
                $hay = mb_strtolower($hay);
                // Strict check: Kelime geçiyor mu?
                if ($hay && mb_strpos($hay, $needle_clean) !== false) return $c;
            }
            return null; // Strict match yoksa NULL dön
        };

        // 2. Pexels Arama (15 Aday)
        if (!empty($pexels_key)) {
            $res = wp_remote_get("https://api.pexels.com/v1/search?query=" . urlencode($query) . "&per_page=15&orientation=landscape", ['headers'=>['Authorization'=>$pexels_key], 'timeout'=>8]);
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                $data = json_decode(wp_remote_retrieve_body($res), true);
                $candidates = [];
                if (!empty($data['photos'])) {
                    foreach ($data['photos'] as $p) {
                        if (!empty($p['src']['landscape'])) {
                            $candidates[] = ['url' => $p['src']['landscape'], 'haystack' => $p['alt']];
                        }
                    }
                    $best = $pick_best($candidates, $strict_match_term);
                    if ($best) return $best['url'];
                }
            }
        }
        
        // 3. Unsplash Arama (Fallback - 15 Aday)
        if (!empty($unsplash_key)) {
            $res = wp_remote_get("https://api.unsplash.com/search/photos?query=" . urlencode($query) . "&per_page=15&orientation=landscape&client_id=" . $unsplash_key, ['timeout'=>8]);
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
                $data = json_decode(wp_remote_retrieve_body($res), true);
                $candidates = [];
                if (!empty($data['results'])) {
                    foreach ($data['results'] as $r) {
                        if (!empty($r['urls']['regular'])) {
                            $candidates[] = ['url' => $r['urls']['regular'], 'haystack' => $r['alt_description'] . ' ' . $r['description']];
                        }
                    }
                    $best = $pick_best($candidates, $strict_match_term);
                    if ($best) return $best['url'];
                }
            }
        }

        // 4. Placeholder (Eğer strict match bulunamadıysa)
        return 'https://placehold.co/800x600/db4c3f/ffffff?font=lora&text=' . urlencode($strict_match_term ?: $query);
    }
    
    /**
     * Kullanıcı Verilerini Formatla (YENİ HELPER)
     * * Format user data with all required fields for frontend consumption.
     * Note: Some fields have duplicate keys (username/user_login, email/user_email, etc.)
     * for backward compatibility with existing frontend code.
     */
    private function format_user_data($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return null;

        // Avatar önceliği: Özel yüklenen > Google avatar > Gravatar
        $avatar_id = get_user_meta($user_id, 'tariften_avatar_id', true);
        if ($avatar_id) {
            $avatar_url = wp_get_attachment_url($avatar_id);
        } else {
            $google_avatar = get_user_meta($user_id, 'tariften_google_avatar', true);
            $avatar_url = $google_avatar ?: get_avatar_url($user_id, array('size' => 200));
        }

        return array(
            'id' => $user->ID,
            'username' => $user->user_login,
            'user_login' => $user->user_login,
            'user_nicename' => $user->user_nicename,
            'email' => $user->user_email,
            'user_email' => $user->user_email,
            'fullname' => $user->display_name,
            'user_display_name' => $user->display_name,
            'avatar_url' => $avatar_url ?: '',
            'diet' => get_user_meta($user_id, 'tariften_diet', true) ?: '',
            'experience' => get_user_meta($user_id, 'tariften_experience', true) ?: '',
            'bio' => get_user_meta($user_id, 'description', true) ?: '',
        );
    }

    /**
     * Mevcut Kullanıcı Verilerini Getir (YENİ ENDPOINT)
     */
    public function get_current_user_data($request) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'Oturum açılmamış.', array('status' => 401));
        }
        $user_data = $this->format_user_data($user_id);
        if (!$user_data) {
            return new WP_Error('user_not_found', 'Kullanıcı bulunamadı.', array('status' => 404));
        }
        return new WP_REST_Response(array('success' => true, 'user' => $user_data), 200);
    }

    // Google Login İşleyicisi (GÜÇLENDİRİLMİŞ & HATA GİDERİLMİŞ)
    public function handle_google_login($request) {
        $params = $request->get_json_params();
        $access_token = isset($params['token']) ? $params['token'] : '';

        if (empty($access_token)) {
            return new WP_Error('no_token', 'Token bulunamadı', array('status' => 400));
        }

        // 1. Google'dan Kullanıcı Bilgilerini Doğrula
        $google_api_url = 'https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . $access_token;
        $response = wp_remote_get($google_api_url);

        if (is_wp_error($response)) {
            return new WP_Error('google_error', 'Google bağlantı hatası: ' . $response->get_error_message(), array('status' => 500));
        }

        $body = wp_remote_retrieve_body($response);
        $user_info = json_decode($body, true);

        if (empty($user_info) || !isset($user_info['email'])) {
            return new WP_Error('invalid_token', 'Geçersiz Token veya E-posta alınamadı.', array('status' => 401));
        }

        $email = $user_info['email'];
        $first_name = isset($user_info['given_name']) ? $user_info['given_name'] : '';
        $last_name = isset($user_info['family_name']) ? $user_info['family_name'] : '';
        $full_name = isset($user_info['name']) ? $user_info['name'] : $first_name . ' ' . $last_name;
        $google_avatar = isset($user_info['picture']) ? $user_info['picture'] : '';

        // 2. Kullanıcı Var mı Kontrol Et
        $user = get_user_by('email', $email);
        $is_new_user = false;

        if (!$user) {
            // Kullanıcı yoksa oluştur
            $username = strtolower(sanitize_user(explode('@', $email)[0]));
            
            if (username_exists($username)) {
                $username .= rand(100, 999);
            }

            $password = wp_generate_password();
            $user_id = wp_create_user($username, $password, $email);

            if (is_wp_error($user_id)) {
                return $user_id;
            }

            wp_update_user(array(
                'ID' => $user_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'display_name' => $full_name
            ));

            // Google avatar'ı kaydet
            if (!empty($google_avatar)) {
                update_user_meta($user_id, 'tariften_google_avatar', $google_avatar);
            }

            $user = get_user_by('id', $user_id);
            $is_new_user = true;
        } else {
            // Mevcut kullanıcı için Google avatar'ı güncelle
            if (!empty($google_avatar)) {
                update_user_meta($user->ID, 'tariften_google_avatar', $google_avatar);
            }
        }

        // 3. JWT Token Oluştur (DÜZELTİLMİŞ YÖNTEM)
        $token = '';
        
        // Önce wp-config.php içinde anahtar tanımlı mı kontrol et
        if (!defined('JWT_AUTH_SECRET_KEY')) {
            return new WP_Error('jwt_config_error', 'Sunucu Hatası: JWT Secret Key tanımlanmamış.', array('status' => 500));
        }

        /**
         * YÖNTEM 1: Eklentinin sınıfını doğrudan çağırmak yerine,
         * Eklentinin kullandığı kütüphaneyi kullanarak manuel token oluşturuyoruz.
         * Çünkü eklentinin generate_token fonksiyonu WP_User değil WP_REST_Request bekliyor.
         */
        try {
            // JWT kütüphanesini kullanabilmek için eklentinin yüklü olması gerekir.
            // Eklenti genellikle Firebase\JWT\JWT sınıfını yükler.
            // Eğer class yoksa, eklenti yüklü değil demektir.
            
            $secret_key = JWT_AUTH_SECRET_KEY;
            $issuedAt   = time();
            $notBefore  = $issuedAt;
            $expire     = $issuedAt + (WEEK_IN_SECONDS); // 1 Hafta geçerli

            $payload = array(
                'iss' => get_bloginfo('url'),
                'iat' => $issuedAt,
                'nbf' => $notBefore,
                'exp' => $expire,
                'data' => array(
                    'user' => array(
                        'id' => $user->ID,
                    ),
                ),
            );

            // JWT sınıfının varlığını kontrol et
            // Not: Farklı JWT eklentileri farklı sınıflar/namespaces kullanabilir.
            // En yaygın olanı "JWT Authentication for WP-API" eklentisidir.
            
            if (class_exists('Firebase\JWT\JWT')) {
                $token = \Firebase\JWT\JWT::encode($payload, $secret_key, 'HS256');
            } 
            // Alternatif: Belki eklenti eski versiyondur ve global JWT sınıfı vardır
            else if (class_exists('JWT')) {
                $token = JWT::encode($payload, $secret_key);
            }
            // YÖNTEM 2: Eklentinin generate_token fonksiyonunu zorla çalıştırmak (Riskli ama deneyelim)
            else if (class_exists('JWT_Auth_Public')) {
                 // Bu noktaya gelindiyse Firebase sınıfı bulunamadı demektir, 
                 // ama JWT_Auth_Public var. Bu durumda eklentinin kendi metodunu
                 // doğru parametrelerle (WP_REST_Request) çağırmayı deneyebiliriz.
                 // Ancak Google login'de parola yok, bu yüzden bu yöntem çalışmaz (eklenti parola doğrular).
                 // Bu yüzden manuel encode şart.
                 
                 return new WP_Error('jwt_lib_error', 'JWT Kütüphanesi bulunamadı. Lütfen "JWT Authentication for WP-API" eklentisini güncelleyin.', array('status' => 500));
            } else {
                 return new WP_Error('jwt_missing', 'JWT Eklentisi yüklü değil.', array('status' => 500));
            }

        } catch (Throwable $e) {
            return new WP_Error('jwt_exception', 'Token üretilirken hata: ' . $e->getMessage(), array('status' => 500));
        }

        if (empty($token)) {
             return new WP_Error('jwt_empty', 'Token oluşturulamadı.', array('status' => 500));
        }

        return array(
            'success' => true,
            'token' => $token,
            'is_new_user' => $is_new_user,
            'user' => $this->format_user_data($user->ID)
        );
    }

    // Register İşleyicisi (GÜNCELLENDİ)
    public function handle_register($request) {
        $params = $request->get_json_params();
        
        $username = sanitize_user($params['username']);
        $email = sanitize_email($params['email']);
        $password = $params['password'];
        $fullname = sanitize_text_field($params['fullname']);
        
        // Yeni Parametreler
        $diet = isset($params['diet']) ? sanitize_text_field($params['diet']) : '';
        $level = isset($params['level']) ? sanitize_text_field($params['level']) : ''; // Mutfak Deneyimi

        if (username_exists($username) || email_exists($email)) {
            return new WP_Error('exists', 'Kullanıcı adı veya e-posta zaten kayıtlı.', array('status' => 400));
        }

        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Kullanıcı Meta Verilerini Kaydet
        wp_update_user(array(
            'ID' => $user_id,
            'display_name' => $fullname
        ));

        if (!empty($diet)) {
            update_user_meta($user_id, 'tariften_diet', $diet);
        }
        
        if (!empty($level)) {
            update_user_meta($user_id, 'tariften_experience', $level);
        }

        return array('message' => 'Kayıt başarılı', 'user_id' => $user_id);
    }

   // Profil Güncelleme (GÜÇLENDİRİLDİ)
    public function handle_profile_update($request) {
        $user_id = get_current_user_id();
        $params = $request->get_json_params();

        // 1. Temel Bilgileri Güncelle
        $update_data = array('ID' => $user_id);

        if (isset($params['fullname'])) {
            $update_data['display_name'] = sanitize_text_field($params['fullname']);
        }

        if (isset($params['email']) && is_email($params['email'])) {
            $email = sanitize_email($params['email']);
            // Başkası kullanmıyorsa güncelle
            if (!email_exists($email) || email_exists($email) == $user_id) {
                 $update_data['user_email'] = $email;
            }
        }

        if (!empty($params['password'])) {
            $update_data['user_pass'] = $params['password'];
        }

        $user_update_result = wp_update_user($update_data);

        if (is_wp_error($user_update_result)) {
            return new WP_Error('update_failed', 'Kullanıcı güncellenemedi: ' . $user_update_result->get_error_message(), array('status' => 500));
        }

        // 2. Meta Verileri Güncelle
        if (isset($params['diet'])) {
            update_user_meta($user_id, 'tariften_diet', sanitize_text_field($params['diet']));
        }
        
        if (isset($params['experience'])) {
            update_user_meta($user_id, 'tariften_experience', sanitize_text_field($params['experience']));
        }

        if (isset($params['bio'])) {
            update_user_meta($user_id, 'description', sanitize_textarea_field($params['bio']));
        }

        // 3. Güncel Veriyi Döndür
        return array(
            'success' => true,
            'message' => 'Profil güncellendi',
            'user' => $this->format_user_data($user_id)
        );
    }

    // Avatar Yükleme (GÜÇLENDİRİLDİ)
    public function handle_avatar_upload($request) {
        $user_id = get_current_user_id();
        
        $files = $request->get_file_params();
        
        if (empty($files) || empty($files['file'])) {
             return new WP_Error('no_file', 'Dosya yüklenmedi.', array('status' => 400));
        }

        // WordPress Media Library için gerekli dosyalar
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Eski avatar'ı sil
        // Not: tariften_avatar_id bu kullanıcıya özel olarak kaydedildiği için güvenle silinebilir
        $old_avatar_id = get_user_meta($user_id, 'tariften_avatar_id', true);
        if ($old_avatar_id) {
            wp_delete_attachment($old_avatar_id, true);
        }

        // Dosyayı media_handle_upload için hazırla
        // Not: $_FILES superglobal'i media_handle_upload için standart WordPress yöntemidir
        $_FILES['upload_file'] = $files['file'];
        $attachment_id = media_handle_upload('upload_file', 0);

        if (is_wp_error($attachment_id)) {
             return new WP_Error('upload_error', 'Görsel yüklenemedi: ' . $attachment_id->get_error_message(), array('status' => 500));
        }

        // Meta alanına ID'yi kaydet
        update_user_meta($user_id, 'tariften_avatar_id', $attachment_id);
        
        // URL'i al ve döndür
        $url = wp_get_attachment_url($attachment_id);

        return array(
            'success' => true,
            'message' => 'Avatar güncellendi',
            'avatar_url' => $url,
            'attachment_id' => $attachment_id
        );
    }
    
    /**
     * AI Tarif Üretimi (SLUG-BASED + DISH MODE + SMART IMAGE + STRICT MATCH)
     */
    public function generate_ai_recipe( $request ) {

        // 502 Hatasını engellemek için zaman limitini artır
        if ( function_exists('set_time_limit') ) {
            @set_time_limit(120);
        }

        $params      = $request->get_json_params();
        $ingredients = isset($params['ingredients']) ? sanitize_text_field($params['ingredients']) : '';
        $prompt_type = isset($params['type']) ? sanitize_text_field($params['type']) : 'suggest';

        $api_key = get_option( 'tariften_openai_key' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'OpenAI API anahtarı ayarlanmamış.', array( 'status' => 500 ) );
        }

        // --- ADIM 1: KATEGORİLER (TERM NAMES) ---
        $available_cuisines      = get_terms(['taxonomy' => 'cuisine',     'fields' => 'names', 'hide_empty' => false]);
        $available_diets         = get_terms(['taxonomy' => 'diet',        'fields' => 'names', 'hide_empty' => false]);
        $available_meals         = get_terms(['taxonomy' => 'meal_type',   'fields' => 'names', 'hide_empty' => false]);
        $available_difficulties  = get_terms(['taxonomy' => 'difficulty',  'fields' => 'names', 'hide_empty' => false]);

        // fallback
        if ( empty($available_cuisines) )     $available_cuisines = ['Türk Mutfağı'];
        if ( empty($available_diets) )        $available_diets = ['Hepçil'];
        if ( empty($available_meals) )        $available_meals = ['Akşam Yemeği'];
        if ( empty($available_difficulties) ) $available_difficulties = ['Kolay'];

        // Helper: Manuel Türkçe Karakter Düzeltme + Slug
        $to_slug = function($s) {
            $s = (string) $s;
            $s = trim($s);
            if ($s === '') return '';
            
            // Türkçe karakterleri manuel olarak değiştir (WP sanitize_title bazen İ'yi atlayabilir)
            $s = str_replace(
                ['İ', 'ı', 'ş', 'Ş', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'],
                ['i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'],
                $s
            );
            
            $s = mb_strtolower($s);
            if (function_exists('remove_accents')) {
                $s = remove_accents($s);
            }
            $s = str_replace(['/', '|'], ' ', $s);
            $s = preg_replace('/\s+/', ' ', $s);
            return sanitize_title($s);
        };

        $cuisine_slugs     = array_values(array_filter(array_unique(array_map($to_slug, (array)$available_cuisines))));
        $diet_slugs        = array_values(array_filter(array_unique(array_map($to_slug, (array)$available_diets))));
        $meal_slugs        = array_values(array_filter(array_unique(array_map($to_slug, (array)$available_meals))));
        $difficulty_slugs  = array_values(array_filter(array_unique(array_map($to_slug, (array)$available_difficulties))));

        // --- ADIM 2: DISH MODE ---
        $raw_input = trim((string)$ingredients);
        $is_ingredient_list = false;

        if (
            preg_match('/[,;]/', $raw_input) ||
            preg_match('/\b(\d+)\b/', $raw_input) ||
            preg_match('/\b(adet|gram|gr|kg|ml|lt|su bardağı|yemek kaşığı|tatlı kaşığı|çay kaşığı)\b/i', $raw_input)
        ) {
            $is_ingredient_list = true;
        }

        $requested_dish = $is_ingredient_list ? '' : $raw_input;

        // --- ADIM 3: JSON ŞEMASI ---
        $json_structure = '{
          "title": "Cezbedici ve Özgün Tarif Adı",
          "image_search_query": "Görsel arama terimi. KURAL: Türk mutfağıysa TÜRKÇE (örn: \'İmambayıldı yemeği\'), dünya mutfağıysa İNGİLİZCE (örn: \'Sushi dish\'). Yanına \'yemek\', \'tabak\' eklemeyi unutma. Emin değilsen NULL.",
          "excerpt": "Tarif özeti (150-160 karakter)",
          "prep_time": "Sadece sayı (dk)",
          "cook_time": "Sadece sayı (dk)",
          "calories": "Sadece sayı (kcal)",
          "servings": "Sadece sayı (kişi)",

          "cuisine": ["SADECE cuisine slug (tek eleman)"],
          "meal_type": ["SADECE meal_type slug (tek eleman)"],
          "difficulty": ["SADECE difficulty slug (tek eleman)"],
          "diet": ["SADECE diet slug (tek eleman) veya []"],

          "ingredients": [{"name":"Malzeme adı","amount":"Miktar","unit":"Birim"}],
          "steps": ["Adım 1","Adım 2","Adım 3 (en az 3 adım)"],

          "seo": {"title":"SEO Başlık","description":"SEO Açıklama","focus_keywords":"Anahtar kelimeler"}
        }';

        // --- ADIM 4: SİSTEM PROMPTU ---
        $system_prompt = "Sen Tariften.com’un hem 'Tarif Üretim Şefi' hem de 'SEO Uzmanı'sın.
    Hedef kitle: Türkiye’de yaşayan ev kullanıcıları.

    0) NİYET KURALI:
    - Eğer kullanıcı bir YEMEK ADI verdiyse, ASLA başka yemeğe dönüştürme.
    - Eğer kullanıcı MALZEME LİSTESİ verdiyse, en uygun tarifi üret.

    1) BAŞLIK SANATI: Sıradan başlıklar YASAK. İştahtan, lezzetten bahseden SEO dostu başlık kullan.
    2) MALZEME MANTIĞI: Yemeğin adını ASLA malzeme listesine ekleme.
    3) KATEGORİ ZORLAMASI: Aşağıdaki slug listeleri dışında ASLA başka kategori uydurma.
       - cuisine slugları: " . implode(', ', (array)$cuisine_slugs) . "
       - diet slugları: " . implode(', ', (array)$diet_slugs) . "
       - meal_type slugları: " . implode(', ', (array)$meal_slugs) . "
       - difficulty slugları: " . implode(', ', (array)$difficulty_slugs) . "
       
    4) GÖRSEL ARAMA KURALI (ÇOK ÖNEMLİ):
       - Türk Mutfağı yemekleri için (örn: 'Mantı', 'Karnıyarık', 'Mıhlama') arama terimi mutlaka TÜRKÇE olmalı (örn: 'Karnıyarık yemeği').
       - Dünya Mutfağı yemekleri için (örn: 'Pizza', 'Sushi', 'Taco') arama terimi İNGİLİZCE olmalı (örn: 'Sushi rolls on plate').
       - Terimin sonuna mutlaka 'yemek' veya 'food', 'plate' ekle ki insan/fabrika/tezgah değil, YEMEK TABAĞI görseli gelsin.
       - Eğer yemeğin %100 karşılığı stok sitelerinde bulunamayacak kadar özelse 'NULL' döndür. Yanlış görsel seçmek yasak.

    ÇIKTI FORMATI: SADECE saf JSON.
    ŞEMA:
    {$json_structure}";

        // --- ADIM 5: KULLANICI PROMPTU ---
        $temperature = 0.7;

        if ( !empty($requested_dish) ) {
            $user_prompt  = "İstek (YEMEK ADI): {$requested_dish}. ";
            $user_prompt .= "Aynı yemeği üret; başka bir yemeğe çevirmek yasak.";
            $temperature  = 0.35;
        } else {
            $user_prompt = "İstek (MALZEME): {$ingredients}. ";

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
        }

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
                    array( 'role' => 'user',   'content' => $user_prompt ),
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
            $error_msg = (isset($data['error']['code']) && $data['error']['code'] === 'insufficient_quota')
                ? 'Şefimiz şu an çok yoğun.'
                : ($data['error']['message'] ?? 'Bilinmeyen hata');
            return new WP_REST_Response( array( 'success' => false, 'message' => $error_msg ), 500 );
        }

        $ai_content  = $data['choices'][0]['message']['content'] ?? '';
        $recipe_json = json_decode( $ai_content, true );

        if ( json_last_error() !== JSON_ERROR_NONE || !is_array($recipe_json) ) {
            return new WP_REST_Response(['success'=>false, 'message'=>'AI geçersiz veri üretti.'], 500);
        }

        if ( empty($recipe_json['title']) ) {
            return new WP_REST_Response(['success'=>false, 'message'=>'AI eksik tarif üretti.'], 500);
        }

        // --- ADIM 6: MÜKERRER KONTROLÜ (SLUG DAHİL) ---
        $potential_slug   = sanitize_title($recipe_json['title']);
        $existing_by_slug = get_page_by_path($potential_slug, OBJECT, 'recipe');
        $existing_by_title= get_page_by_title($recipe_json['title'], OBJECT, 'recipe');

        $existing_recipe  = $existing_by_slug ?: $existing_by_title;

        if ( $existing_recipe && $existing_recipe->post_status === 'publish' ) {
            return new WP_REST_Response( array(
                'success' => true,
                'recipe'  => $this->format_recipe_for_api( $existing_recipe ),
                'message' => 'Bu tarif zaten sistemde mevcuttu, mevcut tarif gösteriliyor.'
            ), 200 );
        }

        // --- ADIM 7: KATEGORİ DOĞRULAMASI ---
        $recipe_json['cuisine']    = $this->validate_terms('cuisine',    $recipe_json['cuisine']    ?? [], $available_cuisines);
        $recipe_json['meal_type']  = $this->validate_terms('meal_type',  $recipe_json['meal_type']  ?? [], $available_meals);
        $recipe_json['difficulty'] = $this->validate_terms('difficulty', $recipe_json['difficulty'] ?? [], $available_difficulties);
        $recipe_json['diet']       = $this->validate_terms('diet',       $recipe_json['diet']       ?? [], $available_diets);

        // --- ADIM 8: GÖRSEL MANTIĞI (PEXELS-FIRST + SMART PICK + PLACEHOLDER) ---
        $image_query_raw = $recipe_json['image_search_query'] ?? '';
        $use_placeholder = false;

        // AI "NULL" dediyse placeholder kullan
        if ( empty($image_query_raw) || strtoupper((string)$image_query_raw) === 'NULL' ) {
            $use_placeholder = true;
        }

        // Sorguyu hazırla
        $query_term = trim( (string)($image_query_raw ?: ($recipe_json['title'] ?? '')) );
        
        $image_url   = null;
        $pexels_key  = get_option('tariften_pexels_key');
        $unsplash_key= get_option('tariften_unsplash_key');

        // YENİ: pick_best artık çok katı. Yemeğin adı (çekirdek isim) görselde geçmek zorunda.
        $pick_best = function(array $candidates, string $needle) {
            // Aranan terimden "yemek", "plate" gibi dolgu kelimeleri çıkar
            $stopwords = ['yemek', 'yemeği', 'food', 'plate', 'dish', 'recipe', 'tarif', 'cooked', 'meal', 'mutfağı'];
            
            // Türkçe karakterleri temizle ve küçük harfe çevir
            $needle_clean = str_replace(
                ['İ', 'ı', 'ş', 'Ş', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'],
                ['i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'],
                $needle
            );
            $needle_clean = mb_strtolower($needle_clean);
            
            // Stopwords temizle
            foreach ($stopwords as $sw) {
                $needle_clean = str_replace($sw, '', $needle_clean);
            }
            $needle_clean = trim($needle_clean);
            
            // Eğer kelime çok kısaysa orijinaline dön (örn: 'et')
            if (mb_strlen($needle_clean) < 2) {
                $needle_clean = mb_strtolower($needle);
            }

            foreach ($candidates as $c) {
                $hay = (string)($c['haystack'] ?? '');
                
                // Samanlığı da temizle
                $hay_clean = str_replace(
                    ['İ', 'ı', 'ş', 'Ş', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'],
                    ['i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'],
                    $hay
                );
                $hay_clean = mb_strtolower($hay_clean);

                // Eşleşme var mı?
                if ($hay_clean && mb_strpos($hay_clean, $needle_clean) !== false) {
                    return $c;
                }
            }
            // Hiçbiri uymadıysa görsel seçme (placeholder dönecek)
            return null;
        };

        if ( !$use_placeholder ) {

            // 1) PEXELS
            if ( !empty($pexels_key) ) {
                $pexels_response = wp_remote_get(
                    "https://api.pexels.com/v1/search?query=" . rawurlencode($query_term) . "&per_page=15&orientation=landscape",
                    array(
                        'headers' => array('Authorization' => $pexels_key),
                        'timeout' => 10
                    )
                );

                if ( !is_wp_error($pexels_response) && wp_remote_retrieve_response_code($pexels_response) === 200 ) {
                    $pexels_body = json_decode( wp_remote_retrieve_body($pexels_response), true );

                    if ( !empty($pexels_body['photos']) && is_array($pexels_body['photos']) ) {
                        $candidates = [];
                        foreach ($pexels_body['photos'] as $p) {
                            $url = $p['src']['landscape'] ?? ($p['src']['large'] ?? '');
                            if (!$url) continue;
                            $candidates[] = [
                                'url'      => $url,
                                'haystack' => (string)($p['alt'] ?? '')
                            ];
                        }
                        
                        $best = $pick_best($candidates, $query_term);
                        if (!empty($best['url'])) {
                            $image_url = $best['url'];
                        }
                    }
                }
            }

            // 2) UNSPLASH fallback
            if ( empty($image_url) && !empty($unsplash_key) ) {
                $unsplash_response = wp_remote_get(
                    "https://api.unsplash.com/search/photos?page=1&per_page=15&query=" . rawurlencode($query_term) . "&orientation=landscape&client_id=" . $unsplash_key,
                    array('timeout' => 10)
                );

                if ( !is_wp_error($unsplash_response) && wp_remote_retrieve_response_code($unsplash_response) === 200 ) {
                    $unsplash_body = json_decode( wp_remote_retrieve_body($unsplash_response), true );

                    if ( !empty($unsplash_body['results']) && is_array($unsplash_body['results']) ) {
                        $candidates = [];
                        foreach ($unsplash_body['results'] as $r) {
                            $url = $r['urls']['regular'] ?? '';
                            if (!$url) continue;
                            $hay = (string)($r['alt_description'] ?? ($r['description'] ?? ''));
                            $candidates[] = [
                                'url'      => $url,
                                'haystack' => $hay
                            ];
                        }

                        $best = $pick_best($candidates, $query_term);
                        if (!empty($best['url'])) {
                            $image_url = $best['url'];
                        }
                    }
                }
            }
        }

        // Placeholder
        if ( empty($image_url) ) {
            $placeholder_text = (string)($recipe_json['title'] ?? 'Tarif');
            // Türkçe karakter desteği için placehold.co'da font belirtmek gerekebilir veya urlencode yeterli olur
            $image_url = 'https://placehold.co/800x600/db4c3f/ffffff?font=lora&text=' . urlencode($placeholder_text . "\n(Görsel Hazırlanıyor)");
        }

        $recipe_json['image'] = $image_url;

        return new WP_REST_Response( array(
            'success' => true,
            'recipe'  => $recipe_json
        ), 200 );
    }


    /**
     * Kategori/terim doğrulama (SLUG-BASED + synonym + tolerant)
     */
    private function validate_terms($taxonomy, $input_terms, $allowed_terms) {

        if (empty($input_terms) || empty($allowed_terms)) return [];

        $to_slug = function($s) {
            $s = (string) $s;
            $s = trim($s);
            if ($s === '') return '';
            
            // Türkçe karakter düzeltmesi (slug için)
            $s = str_replace(
                ['İ', 'ı', 'ş', 'Ş', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'],
                ['i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'],
                $s
            );
            
            $s = mb_strtolower($s);
            if (function_exists('remove_accents')) {
                $s = remove_accents($s);
            }
            $s = str_replace(['/', '|'], ' ', $s);
            $s = preg_replace('/\s+/', ' ', $s);
            return sanitize_title($s);
        };

        // Synonym map (GENİŞLETİLMİŞ)
        $synonyms = [
            'cuisine' => [
                'indian' => 'hint-mutfagi',
                'hindistan' => 'hint-mutfagi',
                'hint' => 'hint-mutfagi',

                'lebanese' => 'lubnan-mutfagi',
                'lebanon'  => 'lubnan-mutfagi',
                'lubnan'   => 'lubnan-mutfagi',

                'italian' => 'italyan-mutfagi',
                'italyan' => 'italyan-mutfagi',
                'italya'  => 'italyan-mutfagi',
                
                'spanish' => 'ispanyol-mutfagi',
                'ispanyol' => 'ispanyol-mutfagi',
                'ispanya' => 'ispanyol-mutfagi',
                
                'british' => 'ingiliz-mutfagi',
                'english' => 'ingiliz-mutfagi',
                'ingiliz' => 'ingiliz-mutfagi',
                'ingiltere' => 'ingiliz-mutfagi',

                'mexican' => 'meksika-mutfagi',
                'meksika' => 'meksika-mutfagi',

                'chinese' => 'cin-mutfagi',
                'cin'     => 'cin-mutfagi',
                'asian'   => 'uzak-dogu-mutfagi',

                'french'  => 'fransiz-mutfagi',
                'fransiz' => 'fransiz-mutfagi',
                'fransa'  => 'fransiz-mutfagi',

                'turkish' => 'turk-mutfagi',
                'turk'    => 'turk-mutfagi',
                'turkiye' => 'turk-mutfagi',
            ],
            'meal_type' => [
                'snack' => 'ara-ogun-atistirmalik',
                'atistirmalik' => 'ara-ogun-atistirmalik',
                'ara-ogun' => 'ara-ogun-atistirmalik',

                'dinner' => 'aksam-yemegi',
                'aksam' => 'aksam-yemegi',

                'lunch' => 'ogle-yemegi',
                'ogle'  => 'ogle-yemegi',

                'breakfast' => 'kahvalti',
                'kahvaltilik' => 'kahvalti',
                
                'drink' => 'icecek',
                'beverage' => 'icecek',
                'smoothie' => 'icecek',
            ],
            'diet' => [
                'vegetarian' => 'vejeteryan',
                'vejetaryen' => 'vejeteryan',

                'keto' => 'ketojenik-keto',
                'ketogenic' => 'ketojenik-keto',
                'ketojenik' => 'ketojenik-keto',

                'dairy-free' => 'sut-urunu-icermeyen',
                'sut-urunsuz' => 'sut-urunu-icermeyen',
                'sutsuz' => 'sut-urunu-icermeyen',

                'gluten-free' => 'glutensiz',
                'glutensiz'   => 'glutensiz',

                'low-calorie' => 'dusuk-kalorili',
                'high-protein' => 'yuksek-proteinli',
                'protein' => 'yuksek-proteinli',
            ],
            'difficulty' => [
                'very-easy' => 'cok-kolay',
                'easy' => 'kolay',
                'medium' => 'orta',
                'hard' => 'zor',
                'chef' => 'sef',
            ],
        ];

        $syn = $synonyms[$taxonomy] ?? [];

        $input_array   = is_array($input_terms) ? $input_terms : [$input_terms];
        $allowed_array = is_array($allowed_terms) ? $allowed_terms : [$allowed_terms];

        // Allowed slug set
        $allowed_set = [];
        foreach ($allowed_array as $a) {
            $s = $to_slug($a);
            if ($s !== '') $allowed_set[$s] = true;
        }

        $validated = [];

        foreach ($input_array as $t) {
            $key = $to_slug($t);
            if ($key === '') continue;

            // 1) synonym
            if (isset($syn[$key])) {
                $cand = $syn[$key];
                if (isset($allowed_set[$cand])) {
                    $validated[] = $cand;
                    continue;
                }
            }

            // 2) birebir
            if (isset($allowed_set[$key])) {
                $validated[] = $key;
                continue;
            }

            // 3) contains fallback (iki yönlü)
            foreach ($allowed_set as $allowed_slug => $_true) {
                if ($allowed_slug === '') continue;
                if (strpos($allowed_slug, $key) !== false || strpos($key, $allowed_slug) !== false) {
                    $validated[] = $allowed_slug;
                    break;
                }
            }
        }

        $validated = array_values(array_unique(array_filter($validated)));

        // Tek seçim hedefi
        if (count($validated) > 1) {
            $validated = [$validated[0]];
        }

        return $validated;
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
        
        // Yeni meta alanları
        if (isset($params['chef_tip'])) {
            update_post_meta($post_id, 'tariften_chef_tip', sanitize_textarea_field($params['chef_tip']));
        }
        if (isset($params['serving_weight'])) {
            update_post_meta($post_id, 'tariften_serving_weight', intval($params['serving_weight']));
        }
        if (isset($params['keywords'])) {
            update_post_meta($post_id, 'tariften_keywords', sanitize_text_field($params['keywords']));
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

    /**
     * Pişirme sayısını getir
     */
    private function get_cooked_count($recipe_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'tariften_interactions';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE recipe_id = %d AND type = 'cooked'",
            $recipe_id
        ));
    }

    /**
     * Varsayılan şef ipucu kütüphanesi
     */
    private function get_random_chef_tip($recipe_id) {
        $tips = array(
            "Malzemeleri oda sıcaklığında kullanmak lezzeti artırır.",
            "Yemekleri pişirmeden önce tüm malzemeleri hazırlayın.",
            "Baharatları yağda kavurmak aromasını daha iyi açığa çıkarır.",
            "Et pişirirken sık sık çevirmekten kaçının.",
            "Makarna suyuna tuz eklemeyi unutmayın.",
            "Sebzeleri buharda pişirmek besin değerlerini korur.",
            "Tavayı önceden ısıtmak yapışmayı önler.",
            "Limon suyu veya sirke yemeklere ferahlık katar.",
            "Taze otları yemeğin sonunda eklemek aromasını korur.",
            "Pişirme sırasında kapağı çok açmayın.",
            "Tereyağını köpürene kadar ısıtın, sonra malzemeleri ekleyin.",
            "Yemekleri dinlendirmek lezzetin oturmasını sağlar.",
            "Keskin bıçak kullanmak hem güvenli hem de pratiktir.",
            "Soğan doğrarken ağlamanızı önlemek için buzdolabında bekletin.",
            "Et marine ederken buzdolabında bekletin."
        );
        
        // Deterministik seçim (recipe_id bazlı)
        $index = $recipe_id % count($tips);
        return $tips[$index];
    }

    private function format_recipe_for_api( $post ) {
        $id = $post->ID;
        $image_url = get_the_post_thumbnail_url( $id, 'large' );
        if ( !$image_url ) $image_url = get_post_meta( $id, 'tariften_image_url', true );
        
        // Eğer hala görsel yoksa ŞIK PLACEHOLDER kullan
        if( empty($image_url) ) {
            $title = $post->post_title;
            // Metin: Tarif Adı + (Görsel Hazırlanıyor)
            $placeholder_text = $title . "\n(Görsel Hazırlanıyor)";
            $image_url = 'https://placehold.co/800x600/db4c3f/ffffff?font=lora&text=' . urlencode($placeholder_text);
        }

        // Chef tip - eğer boşsa varsayılan ipucu kütüphanesinden seç
        $chef_tip = get_post_meta($id, 'tariften_chef_tip', true);
        if (empty($chef_tip)) {
            $chef_tip = $this->get_random_chef_tip($id);
        }

        // Keywords - eğer boşsa otomatik oluştur
        $keywords = get_post_meta($id, 'tariften_keywords', true);
        if (empty($keywords)) {
            $auto_keywords = array_merge(
                $this->get_term_names($id, 'meal_type'),
                $this->get_term_names($id, 'cuisine'),
                $this->get_term_names($id, 'diet'),
                $this->get_term_names($id, 'difficulty')
            );
            $keywords = implode(', ', array_filter($auto_keywords));
        }

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
            'collection' => $this->get_term_names( $id, 'collection' ), 
            'author_id' => $post->post_author,
            'chef_tip' => $chef_tip,
            'serving_weight' => get_post_meta($id, 'tariften_serving_weight', true) ?: '',
            'keywords' => $keywords,
            'cooked_count' => $this->get_cooked_count($id),
            'seo' => $seo 
        );
    }
    private function get_term_names( $post_id, $taxonomy ) {
        $terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
        return !is_wp_error($terms) && !empty($terms) ? $terms : array();
    }
    public function is_user_logged_in() { return is_user_logged_in(); }
    public function check_auth_and_credits() { return is_user_logged_in(); }
}