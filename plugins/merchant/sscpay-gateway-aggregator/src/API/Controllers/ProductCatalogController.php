<?php
/**
 * WooCommerce Products with Variations API
 *
 * @package WooCommerce
 * @subpackage REST API
 * @since 1.0.0
 */

namespace PaymentGatewayAggregator\API\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductCatalogController
{
    private const CACHE_GENERATION = 'pga_product_catalog_cache_generation';
    private const LEGACY_API_VERSION = '2.0.0';

    public static function registerHooks(): void
    {
        if (!class_exists('WooCommerce') || class_exists('WCPVA_Product_Variations_API', false)) {
            return;
        }

        $controller = new self();
        add_action('rest_api_init', [$controller, 'registerRoutes']);
        add_action('save_post_product', [self::class, 'invalidateCache'], 10, 0);
        add_action('save_post_product_variation', [self::class, 'invalidateCache'], 10, 0);
        add_action('woocommerce_update_product', [self::class, 'invalidateCache'], 10, 0);
        add_action('woocommerce_update_product_variation', [self::class, 'invalidateCache'], 10, 0);
        add_action('deleted_post', [self::class, 'invalidateDeletedProduct'], 10, 2);
    }

    public function registerRoutes(): void
    {
        register_rest_route('wc/v3', '/products-with-variations', array(
            'methods'             => 'GET',
            'callback'            => [$this, 'getProductsWithVariations'],
            'permission_callback' => [$this, 'checkPermissions'],
        ));

        // Endpoint de estado: permite detectar que el plugin esta instalado,
        // su version, y datos generales del entorno (WooCommerce / WordPress / PHP / sitio).
        register_rest_route('wc/v3', '/ninox-status', array(
            'methods'             => 'GET',
            'callback'            => [$this, 'getStatus'],
            'permission_callback' => [$this, 'checkPermissions'],
        ));
    }

    /**
     * Devuelve el estado del plugin y del entorno.
     * La sola respuesta 200 de esta ruta confirma que el plugin esta activo y sus
     * endpoints responden; el 404 indica que no esta instalado.
     */
    public function getStatus(): \WP_REST_Response
    {
        $status = array(
            'plugin'         => 'sscpay-products-api',
            'plugin_name'    => 'SSCPay Products API',
            'plugin_version' => self::LEGACY_API_VERSION,
            'wc_version'     => defined('WC_VERSION') ? WC_VERSION : '',
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => phpversion(),
            'site_url'       => site_url(),
            'wc_active'      => class_exists('WooCommerce'),
        );

        $response = rest_ensure_response($status);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }

    public function checkPermissions(\WP_REST_Request $request): bool
    {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        // Igual que WooKeyAuthenticator (sscpay-gateway-aggregator): las
        // credenciales viajan en texto plano, asi que solo se aceptan por
        // HTTPS (salvo WC_API_DEV_MODE, para depuracion local).
        if (!is_ssl() && !(defined('WC_API_DEV_MODE') && WC_API_DEV_MODE)) {
            return false;
        }

        [$consumer_key, $consumer_secret] = $this->extractCredentials($request);
        if (!is_string($consumer_key) || !is_string($consumer_secret)
            || $consumer_key === '' || $consumer_secret === '') {
            return false;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia de WooCommerce sin API publica de lectura; una credencial no se cachea a proposito.
        $key = $wpdb->get_row($wpdb->prepare(
            "SELECT key_id, consumer_secret, user_id, permissions
            FROM {$wpdb->prefix}woocommerce_api_keys
            WHERE consumer_key = %s",
            wc_api_hash(sanitize_text_field($consumer_key))
        ));

        if (!$key || !in_array($key->permissions, ['read', 'read_write'], true)
            || !hash_equals($key->consumer_secret, $consumer_secret)) {
            return false;
        }

        $user = get_user_by('id', $key->user_id);
        if (!$user || !$user->has_cap('manage_woocommerce')) {
            return false;
        }

        // Igual que WooKeyAuthenticator: deja el contexto de usuario listo
        // para current_user_can()/get_current_user_id() y registra el ultimo
        // uso de la key (visible en WooCommerce > Ajustes > API REST).
        wp_set_current_user((int) $key->user_id);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Actualizacion puntual de una fila por su key_id.
        $wpdb->update(
            $wpdb->prefix.'woocommerce_api_keys',
            array('last_access' => current_time('mysql', true)),
            array('key_id' => $key->key_id)
        );

        return true;
    }

    /**
     * Extrae consumer_key/consumer_secret del request, igual que
     * WooKeyAuthenticator::extractCredentials() en sscpay-gateway-aggregator:
     * 1. Query params: ?consumer_key=...&consumer_secret=...
     * 2. Header Authorization en texto plano "consumer_key:consumer_secret"
     *    (sin prefijo Basic)
     * Se mantiene ademas el formato estandar de WooCommerce
     * "Authorization: Basic base64(key:secret)" como tercera opcion, por
     * compatibilidad con integraciones que ya lo usaban.
     *
     * @return array [consumer_key, consumer_secret] (pueden venir null)
     */
    private function extractCredentials(\WP_REST_Request $request): array
    {
        $query = $request->get_query_params();
        if (array_key_exists('consumer_key', $query) || array_key_exists('consumer_secret', $query)) {
            return [$query['consumer_key'] ?? null, $query['consumer_secret'] ?? null];
        }

        $authorization = trim((string) $request->get_header('authorization'));
        if ($authorization === '') {
            return [$_SERVER['PHP_AUTH_USER'] ?? null, $_SERVER['PHP_AUTH_PW'] ?? null];
        }

        if (stripos($authorization, 'Basic ') === 0) {
            $authorization = base64_decode(substr($authorization, 6), true);
        }

        if (!is_string($authorization) || strpos($authorization, ':') === false) {
            return [null, null];
        }

        return array_map('trim', explode(':', $authorization, 2));
    }

    public static function invalidateCache(): void
    {
        update_option(self::CACHE_GENERATION, wp_generate_uuid4(), false);
    }

    public static function invalidateDeletedProduct(int $postId, \WP_Post $post): void
    {
        if (in_array($post->post_type, ['product', 'product_variation'], true)) {
            self::invalidateCache();
        }
    }

    private function getProductIds(): array
    {
        global $wpdb;
        // Solo productos simples y variables. Se usa LEFT JOIN porque muchos productos
        // importados no tienen la fila _product_type en postmeta; WooCommerce los trata
        // como "simple" por defecto, asi que meta ausente = incluido. Con INNER JOIN se
        // descartarian todos y el endpoint devolveria siempre vacio.
        // El resultado viaja al transient de la respuesta, por lo que no se cachea aparte.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Solo IDs, sin hidratar posts; el resultado viaja al transient de la respuesta.
        return array_map('absint', $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = %s
            WHERE p.post_type = %s
            AND p.post_status = %s
            AND (pm.meta_value IS NULL OR pm.meta_value IN (%s, %s))
            ORDER BY p.ID",
            '_product_type',
            'product',
            'publish',
            'simple',
            'variable'
        )));
    }

    public function getProductsWithVariations(\WP_REST_Request $request): \WP_REST_Response
    {
        $page = max(1, absint($request->get_param('page') ?? 1));
        $per_page = max(1, min(100, absint($request->get_param('per_page') ?? 20)));
        $force_refresh = in_array($request->get_param('force_refresh'), [true, 1, '1', 'true'], true);
        $generation = get_option(self::CACHE_GENERATION, '');
        $cache_key = 'pga_product_catalog_' . md5(implode(':', [PGA_VERSION, $generation, $page, $per_page]));

        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $this->productsResponse($cached['products'], $cached['total'], $per_page, 'cache');
            }
        }

        $all_product_ids = $this->getProductIds();
        $total_products  = count($all_product_ids);
        if ($page > ceil($total_products / $per_page)) {
            return $this->productsResponse([], $total_products, $per_page, 'empty-page');
        }

        $current_batch_ids = array_slice($all_product_ids, ($page - 1) * $per_page, $per_page);
        $products = array();

        global $wpdb;

        // Los *_placeholders de este bloque son cadenas de %d/%s construidas con array_fill() a
        // partir del conteo de elementos; los valores siempre viajan como argumentos de prepare().
        // PHPCS no puede seguir esa construccion, de ahi el disable acotado a este bloque.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        // 1. Obtener datos básicos de producto
        $placeholders = implode(',', array_fill(0, count($current_batch_ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lectura por lote; el resultado completo se cachea en el transient de la respuesta.
        $product_data = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_content, p.post_excerpt
            FROM {$wpdb->posts} p
            WHERE p.ID IN ({$placeholders}) AND p.post_type = %s
            ORDER BY p.ID",
            array_merge($current_batch_ids, array('product'))
        ));

        // 2. Obtener metadatos de productos
        // _thumbnail_id: id del attachment de la imagen destacada (se resuelve a URL mas abajo).
        $meta_fields       = array(
            '_sku', '_price', '_regular_price', '_sale_price', '_stock', '_stock_status', '_weight', '_length',
            '_width', '_height', '_thumbnail_id'
        );
        $meta_placeholders = implode(',', array_fill(0, count($meta_fields), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lectura por lote; el resultado completo se cachea en el transient de la respuesta.
        $meta_data = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id IN ({$placeholders}) AND meta_key IN ({$meta_placeholders})",
            array_merge($current_batch_ids, $meta_fields)
        ));

        // Organizar metadatos
        $product_meta = array();
        foreach ($meta_data as $meta) {
            $product_meta[$meta->post_id][$meta->meta_key] = $meta->meta_value;
        }

        // 3. Obtener IDs de variaciones (solo publicadas)
        // Los dos %s son los dos ultimos elementos del array_merge; el resto son los IDs del lote.
        // PHPCS no puede contar un array armado en runtime, asi que ve 1 argumento y espera 2.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Lectura por lote cacheada en el transient de la respuesta; el conteo de reemplazos es dinamico.
        $variation_ids_data = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_parent
            FROM {$wpdb->posts}
            WHERE post_parent IN ({$placeholders})
            AND post_type = %s
            AND post_status = %s",
            array_merge($current_batch_ids, array('product_variation', 'publish'))
        ));

        // Organizar IDs de variaciones por producto
        $variation_ids_by_product = array();
        $all_variation_ids        = array();
        foreach ($variation_ids_data as $var) {
            $variation_ids_by_product[$var->post_parent][] = $var->ID;
            $all_variation_ids[]                           = $var->ID;
        }

        // 4. Obtener metadatos de variaciones solo si hay variaciones
        $variation_meta = array();
        if (!empty($all_variation_ids)) {
            $var_placeholders = implode(',', array_fill(0, count($all_variation_ids), '%d'));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lectura por lote; el resultado completo se cachea en el transient de la respuesta.
            $var_meta_data = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_key, meta_value
                FROM {$wpdb->postmeta}
                WHERE post_id IN ({$var_placeholders}) AND
                (meta_key IN ({$meta_placeholders}) OR meta_key LIKE %s)",
                array_merge($all_variation_ids, $meta_fields, array('attribute_%'))
            ));

            foreach ($var_meta_data as $meta) {
                $variation_meta[$meta->post_id][$meta->meta_key] = $meta->meta_value;
            }
        }

        // 5. Obtener atributos de producto
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lectura por lote; el resultado completo se cachea en el transient de la respuesta.
        $attribute_data = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id, t.term_id, t.name, t.slug, tt.taxonomy
            FROM {$wpdb->term_relationships} tr
            JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE tr.object_id IN ({$placeholders}) AND tt.taxonomy LIKE %s",
            array_merge($current_batch_ids, array('pa_%'))
        ));

        // Organizar atributos por producto
        $product_attributes = array();
        $attr_taxonomies    = array();

        foreach ($attribute_data as $attr) {
            $taxonomy                                          = str_replace('pa_', '', $attr->taxonomy);
            $product_attributes[$attr->object_id][$taxonomy][] = $attr->slug;
            $attr_taxonomies[$taxonomy]                        = true;
        }

        // 6. Obtener información de taxonomías de atributos si hay atributos
        $attribute_taxonomies = array();
        if (!empty($attr_taxonomies)) {
            $taxonomy_names = array_keys($attr_taxonomies);

            if (!empty($taxonomy_names)) {
                $taxonomy_placeholders = implode(',', array_fill(0, count($taxonomy_names), '%s'));

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lectura por lote; el resultado completo se cachea en el transient de la respuesta.
                $taxonomy_data = $wpdb->get_results($wpdb->prepare(
                    "SELECT attribute_id, attribute_name, attribute_label
                    FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
                    WHERE attribute_name IN ({$taxonomy_placeholders})",
                    $taxonomy_names
                ));

                foreach ($taxonomy_data as $tax) {
                    $attribute_taxonomies[$tax->attribute_name] = array(
                        'id'   => $tax->attribute_id,
                        'name' => $tax->attribute_label
                    );
                }
            }
        }

        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        // Imagen destacada: resolver _thumbnail_id -> URL en una unica consulta por
        // lote en lugar de llamar wp_get_attachment_image_url() por cada producto.
        $thumbnail_ids = array();
        foreach ($product_data as $product) {
            $tid = isset($product_meta[$product->ID]['_thumbnail_id']) ? absint($product_meta[$product->ID]['_thumbnail_id']) : 0;
            if ($tid) {
                $thumbnail_ids[$product->ID] = $tid;
            }
        }

        $image_urls = array();
        if (!empty($thumbnail_ids)) {
            $unique_attachment_ids = array_values(array_unique($thumbnail_ids));
            $img_placeholders      = implode(',', array_fill(0, count($unique_attachment_ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Lectura por lote cacheada en el transient de la respuesta.
            $attachment_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, guid
                FROM {$wpdb->posts}
                WHERE ID IN ({$img_placeholders}) AND post_type = %s",
                array_merge($unique_attachment_ids, array('attachment'))
            ));

            foreach ($attachment_rows as $attachment) {
                $image_urls[intval($attachment->ID)] = $attachment->guid;
            }
        }

        // Helper para convertir stock (una sola instancia, fuera del bucle)
        $parse_int = function ($value) {
            if ($value === '' || $value === null) {
                return null;
            }
            return intval($value);
        };

        // Construir resultado final
        foreach ($product_data as $product) {
            $product_id = $product->ID;

            // Imagen destacada del producto: lookup directo contra el mapa ya resuelto.
            $thumbnail_id = isset($thumbnail_ids[$product_id]) ? $thumbnail_ids[$product_id] : 0;
            $image_url    = ($thumbnail_id && isset($image_urls[$thumbnail_id])) ? $image_urls[$thumbnail_id] : '';

            $processed_product = array(
                'id'                => intval($product_id),
                'permalink'         => get_permalink($product_id),
                'name'              => $product->post_title,
                'description'       => $product->post_content,
                'short_description' => $product->post_excerpt,
                'image'             => $image_url ? $image_url : '',
                'sku'               => isset($product_meta[$product_id]['_sku']) ? $product_meta[$product_id]['_sku'] : '',
                'price'             => isset($product_meta[$product_id]['_price']) ? $product_meta[$product_id]['_price'] : '',
                'regular_price'     => isset($product_meta[$product_id]['_regular_price']) ? $product_meta[$product_id]['_regular_price'] : '',
                'sale_price'        => isset($product_meta[$product_id]['_sale_price']) ? $product_meta[$product_id]['_sale_price'] : '',
                'stock'             => $parse_int(isset($product_meta[$product_id]['_stock']) ? $product_meta[$product_id]['_stock'] : null),
                'stock_status'      => isset($product_meta[$product_id]['_stock_status']) ? $product_meta[$product_id]['_stock_status'] : '',
                'weight'            => isset($product_meta[$product_id]['_weight']) ? $product_meta[$product_id]['_weight'] : '',
                'length'            => isset($product_meta[$product_id]['_length']) ? $product_meta[$product_id]['_length'] : '',
                'width'             => isset($product_meta[$product_id]['_width']) ? $product_meta[$product_id]['_width'] : '',
                'height'            => isset($product_meta[$product_id]['_height']) ? $product_meta[$product_id]['_height'] : '',
                'attributes'        => array(),
                'variations'        => array(),
            );

            // Agregar atributos
            if (isset($product_attributes[$product_id])) {
                foreach ($product_attributes[$product_id] as $attr_name => $options) {
                    $attr_id    = isset($attribute_taxonomies[$attr_name]['id']) ? intval($attribute_taxonomies[$attr_name]['id']) : 0;
                    $attr_label = isset($attribute_taxonomies[$attr_name]['name']) ? $attribute_taxonomies[$attr_name]['name'] : $attr_name;

                    $processed_product['attributes'][] = array(
                        'id'      => $attr_id,
                        'name'    => $attr_label,
                        'options' => $options
                    );
                }
            }

            // Agregar variaciones
            if (isset($variation_ids_by_product[$product_id])) {
                foreach ($variation_ids_by_product[$product_id] as $variation_id) {
                    $variation = array(
                        'id'            => intval($variation_id),
                        'sku'           => isset($variation_meta[$variation_id]['_sku']) ? $variation_meta[$variation_id]['_sku'] : '',
                        'price'         => isset($variation_meta[$variation_id]['_price']) ? $variation_meta[$variation_id]['_price'] : '',
                        'regular_price' => isset($variation_meta[$variation_id]['_regular_price']) ? $variation_meta[$variation_id]['_regular_price'] : '',
                        'sale_price'    => isset($variation_meta[$variation_id]['_sale_price']) ? $variation_meta[$variation_id]['_sale_price'] : '',
                        'stock'         => $parse_int(isset($variation_meta[$variation_id]['_stock']) ? $variation_meta[$variation_id]['_stock'] : null),
                        'stock_status'  => isset($variation_meta[$variation_id]['_stock_status']) ? $variation_meta[$variation_id]['_stock_status'] : '',
                        'attributes'    => array()
                    );

                    // Agregar atributos de variación
                    if (isset($variation_meta[$variation_id])) {
                        foreach ($variation_meta[$variation_id] as $meta_key => $meta_value) {
                            if (strpos($meta_key, 'attribute_pa_') === 0) {
                                $attr_name = str_replace('attribute_pa_', '', $meta_key);
                                $attr_id   = isset($attribute_taxonomies[$attr_name]['id']) ? intval($attribute_taxonomies[$attr_name]['id']) : 0;

                                $variation['attributes'][] = array(
                                    'id'     => $attr_id,
                                    'name'   => $attr_name,
                                    'option' => $meta_value
                                );
                            }
                        }
                    }

                    $processed_product['variations'][] = $variation;
                }
            }

            $products[] = $processed_product;
        }

        // Guardar en caché (duración: 1 hora)
        set_transient($cache_key, ['products' => $products, 'total' => $total_products], HOUR_IN_SECONDS);

        return $this->productsResponse($products, $total_products, $per_page, $force_refresh ? 'fresh-no-cache' : 'fresh-cached');
    }

    private function productsResponse(array $products, int $total, int $perPage, string $source): \WP_REST_Response
    {
        $response = rest_ensure_response($products);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) ceil($total / $perPage));
        $response->header('X-Data-Source', $source);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
