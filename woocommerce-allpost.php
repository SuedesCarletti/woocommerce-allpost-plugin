<?php
/**
 * Plugin Name: WooCommerce AllPost
 * Plugin URI: https://github.com/SuedesCarletti/woocommerce-allpost-plugin
 * Description: Integração com AllPost para cotação de frete e gestão de envios
 * Version: 1.0.0
 * Author: Suedes Carletti
 * License: Todos os direitos reservados / All rights reserved
 * Text Domain: wc-allpost
 * Domain Path: /languages
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes
define('WC_ALLPOST_VERSION', '1.0.0');
define('WC_ALLPOST_PLUGIN_FILE', __FILE__);
define('WC_ALLPOST_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_ALLPOST_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Declara compatibilidade com HPOS (High-Performance Order Storage) do WooCommerce
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Verificar se WooCommerce está ativo
 */
function wc_allpost_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wc_allpost_missing_wc_notice');
        return false;
    }
    return true;
}

/**
 * Exibir notice se WooCommerce não estiver ativo
 */
function wc_allpost_missing_wc_notice() {
    echo '<div class="error"><p><strong>WooCommerce AllPost</strong> requer que o WooCommerce esteja instalado e ativo.</p></div>';
}

/**
 * Classe principal do plugin
 */
class WC_AllPost {
    
    /**
     * Instância única
     */
    private static $instance = null;
    
    /**
     * Obter instância única
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Construtor
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
    }
    
    /**
     * Inicializar plugin
     */
    public function init() {
        // Verificar se WooCommerce está ativo
        if (!wc_allpost_check_woocommerce()) {
            return;
        }
        
        // Carregar arquivos
        $this->includes();
        
        // Inicializar componentes
        $this->init_hooks();
        
        // Carregar textdomain
        load_plugin_textdomain('wc-allpost', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Incluir arquivos necessários
     */
    private function includes() {
        include_once WC_ALLPOST_PLUGIN_PATH . 'includes/class-wc-allpost-api.php';
        include_once WC_ALLPOST_PLUGIN_PATH . 'includes/class-wc-allpost-shipping-method.php';
        include_once WC_ALLPOST_PLUGIN_PATH . 'includes/class-wc-allpost-admin.php';
        include_once WC_ALLPOST_PLUGIN_PATH . 'includes/class-wc-allpost-webhook.php';
        include_once WC_ALLPOST_PLUGIN_PATH . 'includes/class-wc-allpost-order.php';
        include_once WC_ALLPOST_PLUGIN_PATH . 'includes/functions.php';
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Registrar método de envio
        add_action('woocommerce_shipping_init', array($this, 'init_shipping_method'));
        add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));
        
        // Hooks de ativação e desativação
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Inicializar componentes
        WC_AllPost_Admin::get_instance();
        WC_AllPost_Webhook::get_instance();
        WC_AllPost_Order::get_instance();
    }
    
    /**
     * Inicializar método de envio
     */
    public function init_shipping_method() {
        new WC_AllPost_Shipping_Method();
    }
    
    /**
     * Adicionar método de envio
     */
    public function add_shipping_method($methods) {
        $methods['allpost'] = 'WC_AllPost_Shipping_Method';
        return $methods;
    }
    
    /**
     * Ativar plugin
     */
    public function activate() {
        // Limpar cache de rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Desativar plugin
     */
    public function deactivate() {
        // Limpar cache de rewrite rules
        flush_rewrite_rules();
    }
}

/**
 * Inicializar plugin
 */
function wc_allpost() {
    return WC_AllPost::get_instance();
}

// Inicializar
wc_allpost();
