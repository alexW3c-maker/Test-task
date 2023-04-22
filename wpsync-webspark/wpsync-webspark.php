<?php
/*
Plugin Name: WPsync-WebSpark
Description: Синхронизация товаров с API
Version: 1.0
Author: WebSpark
Author URI: https://www.webspark.com/
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

add_filter('upload_mimes', 'sync_products_api_custom_upload_mimes');
function sync_products_api_custom_upload_mimes($mimes)
{
    $mimes['jpg'] = 'image/jpeg';
    $mimes['jpeg'] = 'image/jpeg';
    $mimes['jpe'] = 'image/jpeg';
    $mimes['gif'] = 'image/gif';
    $mimes['png'] = 'image/png';
    $mimes['bmp'] = 'image/bmp';
    $mimes['tiff'] = 'image/tiff';
    $mimes['tif'] = 'image/tiff';
    $mimes['ico'] = 'image/x-icon';
    $mimes['webp'] = 'image/webp';
    return $mimes;
}

if (!class_exists('Sync_Products_API')) {
    class Sync_Products_API
    {
        public function __construct()
        {
            register_activation_hook(__FILE__, array($this, 'on_activation'));
            add_action('init', array($this, 'init'));
            add_action('sync_products_api_cron', array($this, 'sync_products_cron'));
        }
        
        public function on_activation()
        {
            if (class_exists('WooCommerce')) {
                $this->sync_products();
            } else {
                deactivate_plugins(plugin_basename(__FILE__));
                wp_die(__('Для работы плагина требуется WooCommerce.', 'sync-products-api'));
            }
        }

        public function import_products($offset = 0)
        {
            $api_url = 'https://wp.webspark.dev/wp-api/products?offset=' . $offset;
            $response = wp_remote_get($api_url);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200) {
                $response_data = json_decode(wp_remote_retrieve_body($response), true);
                $products = isset($response_data['data']) ? $response_data['data'] : array();

                if (!empty($products) && count($products) <= 2000) {
                    $csv_file = $this->generate_csv_file($products);
                    $this->run_csv_importer($csv_file);
                }
            }
        }
        

        private function generate_csv_file($products)
        {
            $csv_file = fopen('php://temp', 'r+');
            fputcsv($csv_file, array('sku', 'name', 'description', 'price', 'picture', 'in_stock'));

            foreach ($products as $product) {
                fputcsv($csv_file, array(
                    $product['sku'],
                    $product['name'],
                    $product['description'],
                    $product['price'],
                    $product['picture'],
                    $product['in_stock'],
                ));
            }

            rewind($csv_file);
            return $csv_file;
        }

        private function run_csv_importer($csv_file)
        {
            if (!class_exists('WC_Product_CSV_Importer_Controller')) {
                include_once WC_ABSPATH . 'includes/admin/importers/class-wc-product-csv-importer-controller.php';
            }

            $file_path = tempnam(sys_get_temp_dir(), 'csv_import_');
            file_put_contents($file_path, stream_get_contents($csv_file));

            $args = array(
                'delimiter' => ',',
                'start_pos' => 0,
                'mapping' => array(
                    'sku' => 'sku',
                    'name' => 'name',
                    'description' => 'description',
                    'price' => 'price',
                    'picture' => 'picture',
                    'in_stock' => 'in_stock',
                ),
                'parse' => true,
            );

            $importer_controller = new WC_Product_CSV_Importer_Controller($file_path, $args);
            $importer_controller->import();
            fclose($csv_file);
            unlink($file_path);
        }

        public function init()
        {
            if (!wp_next_scheduled('sync_products_api_cron')) {
                wp_schedule_event(time(), 'hourly', 'sync_products_api_cron');
            }
        }

        public function sync_products()
        {
            $api_url = 'https://wp.webspark.dev/wp-api/products';
            $args = array(
                'timeout' => 30,
            );
            $response = wp_remote_get($api_url, $args);

            if (is_wp_error($response)) {
                error_log('WPsync-WebSpark: API request error: ' . $response->get_error_message());
            } elseif (wp_remote_retrieve_response_code($response) != 200) {
                error_log('WPsync-WebSpark: API request failed with status code ' . wp_remote_retrieve_response_code($response));
            } else {
                error_log('WPsync-WebSpark: API response: ' . wp_remote_retrieve_body($response));
                $response_data = json_decode(wp_remote_retrieve_body($response), true);
                $products = isset($response_data['data']) ? $response_data['data'] : array();

                if (!empty($products) && count($products) >= 2000) {
                    error_log('WPsync-WebSpark: Received ' . count($products) . ' products.');
                    $this->update_products($products);
                }
            }
        }

        public function sync_products_cron()
        {
            error_log('WPsync-WebSpark: Starting sync_products()...');
            $this->sync_products();
            error_log('WPsync-WebSpark: Finished sync_products().');
        }

        private function update_products($products)
        {
            $received_skus = array_column($products, 'sku');
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'fields' => 'ids',
            );
            $all_product_ids = get_posts($args);

            error_log('WPsync-WebSpark: Updating products...');
            error_log('WPsync-WebSpark: Received SKUs: ' . print_r($received_skus, true));

            foreach ($all_product_ids as $product_id) {
                $product = wc_get_product($product_id);
                $sku = $product->get_sku();
                error_log('WPsync-WebSpark: Processing product with SKU ' . $sku . ' and ID ' . $product_id);

                if (!in_array($sku, $received_skus)) {
                    $product->set_status('draft');
                    $product->save();
                }
            }

            foreach ($products as $product_data) {
                if (is_array($product_data) && isset($product_data['sku'])) {
                    $product_id = wc_get_product_id_by_sku($product_data['sku']);

                    if (!$product_id) {
                        $this->create_product($product_data);
                    } else {
                        $this->update_product($product_id, $product_data);
                    }
                }
            }
        }

        private function create_product($product_data)
        {
            $product = new WC_Product();
            if (isset($product_data['sku'])) {
                $product->set_sku($product_data['sku']);
            }
            if (isset($product_data['name'])) {
                $product->set_name($product_data['name']);
            }
            if (isset($product_data['description'])) {
                $product->set_description($product_data['description']);
            }
            if (isset($product_data['price'])) {
                $price = floatval(str_replace('$', '', $product_data['price']));
                $product->set_regular_price($price);
            }
            if (isset($product_data['picture'])) {
                $product->set_image_id($this->get_image_id($product_data['picture']));
            }
            if (isset($product_data['in_stock'])) {
                $product->set_stock_quantity($product_data['in_stock']);
            }
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_manage_stock(true);
            error_log('WPsync-WebSpark: Creating product with data: ' . print_r($product_data, true));
            $product->save();
        }

        private function update_product($product_id, $product_data)
        {
            $product = wc_get_product($product_id);
            if (isset($product_data['name'])) {
                $product->set_name($product_data['name']);
            }
            if (isset($product_data['description'])) {
                $product->set_description($product_data['description']);
            }
            if (isset($product_data['price'])) {
                $price = floatval(str_replace('$', '', $product_data['price']));
                $product->set_regular_price($price);
            }
            if (isset($product_data['picture'])) {
                $product->set_image_id($this->get_image_id($product_data['picture']));
            }
            if (isset($product_data['in_stock'])) {
                $product->set_stock_quantity($product_data['in_stock']);
            }
            $product->set_status('publish');
            error_log('WPsync-WebSpark: Updating product with ID ' . $product_id . ' and data: ' . print_r($product_data, true));
            $product->save();
        }

        private function find_existing_image_id($image_url)
        {
            $args = array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => '_wp_attached_file',
                        'value' => basename($image_url),
                        'compare' => 'LIKE',
                    ),
                ),
            );

            $query = new WP_Query($args);

            if ($query->have_posts()) {
                return $query->posts[0]->ID;
            }

            return false;
        }

        private function get_image_id($image_url)
        {
            if (!empty($image_url)) {
                
                $existing_image_id = $this->find_existing_image_id($image_url);
                if ($existing_image_id) {
                    return $existing_image_id;
                }
                $attachment_id = attachment_url_to_postid($image_url);

                if (!$attachment_id) {
                    $args = array(
                        'timeout' => 60,
                    );
                    $image_response = wp_remote_get($image_url, $args);
                    if (!is_wp_error($image_response) && wp_remote_retrieve_response_code($image_response) == 200) {
                        $image_content = wp_remote_retrieve_body($image_response);
                    } else {
                        error_log('WPsync-WebSpark: Image download failed for URL ' . $image_url . '. Error: ' . (is_wp_error($image_response) ? $image_response->get_error_message() : 'Unknown error'));
                        return;
                    }

                    $filename = basename($image_url);
                    if (!pathinfo($filename, PATHINFO_EXTENSION)) {
                        $filename .= '.jpg';
                    }
                    $upload = wp_upload_bits($filename, null, $image_content);

                    if (!is_wp_error($upload) && isset($upload['file']) && $upload['file']) {
                        $filetype = wp_check_filetype($upload['file'], null);
                        $attachment = array(
                            'post_mime_type' => $filetype['type'],
                            'post_title' => sanitize_file_name($upload['file']),
                            'post_content' => '',
                            'post_status' => 'inherit',
                        );

                        $attachment_id = wp_insert_attachment($attachment, $upload['file']);
                        $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
                        wp_update_attachment_metadata($attachment_id, $attachment_data);
                    } else {
                        error_log('WPsync-WebSpark: Image upload failed for URL ' . $image_url . '. Error: ' . (is_wp_error($upload) ? $upload->get_error_message() : 'Unknown error'));
                    }
                }

                return $attachment_id;
            }
        }
    }

}
    
function sync_products_api()
{
    return new Sync_Products_API();
}
    
$GLOBALS['sync_products_api'] = sync_products_api();