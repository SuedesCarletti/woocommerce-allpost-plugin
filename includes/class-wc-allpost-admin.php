<?php
/**
 * Classe para painel administrativo AllPost
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_AllPost_Admin {
    
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
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Adicionar menu do plugin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Registrar configurações
        add_action('admin_init', array($this, 'register_settings'));
        
        // Adicionar link de configurações na lista de plugins
        add_filter('plugin_action_links_' . plugin_basename(WC_ALLPOST_PLUGIN_FILE), array($this, 'add_plugin_action_links'));
        
        // Adicionar notice se não configurado
        add_action('admin_notices', array($this, 'configuration_notice'));
    }
    
    /**
     * Adicionar menu administrativo
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('AllPost Configurações', 'wc-allpost'),
            __('AllPost', 'wc-allpost'),
            'manage_woocommerce',
            'wc-allpost',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Registrar configurações
     */
    public function register_settings() {
        // Seção principal
        add_settings_section(
            'wc_allpost_main',
            __('Configurações Principais', 'wc-allpost'),
            array($this, 'main_section_callback'),
            'wc_allpost'
        );
        
        // Token de cotação
        add_settings_field(
            'wc_allpost_quote_token',
            __('Token de Cotação', 'wc-allpost'),
            array($this, 'token_field_callback'),
            'wc_allpost',
            'wc_allpost_main',
            array('field' => 'wc_allpost_quote_token', 'type' => 'password')
        );
        
        // Token da API
        add_settings_field(
            'wc_allpost_api_token',
            __('Token da API', 'wc-allpost'),
            array($this, 'token_field_callback'),
            'wc_allpost',
            'wc_allpost_main',
            array('field' => 'wc_allpost_api_token', 'type' => 'password')
        );
        
        // Canal
        add_settings_field(
            'wc_allpost_channel',
            __('Canal', 'wc-allpost'),
            array($this, 'text_field_callback'),
            'wc_allpost',
            'wc_allpost_main',
            array('field' => 'wc_allpost_channel', 'default' => 'WooCommerce')
        );
        
        // Filial ERP (AllPost)
        add_settings_field(
            'wc_allpost_filial_erp',
            __('Código da Filial ERP (AllPost)', 'wc-allpost'),
            array($this, 'text_field_callback'),
            'wc_allpost',
            'wc_allpost_main',
            array('field' => 'wc_allpost_filial_erp', 'default' => '1')
        );
        
        // Filial Sankhya (separada)
        add_settings_field(
            'wc_allpost_filial_sankhya',
            __('Código da Filial Sankhya', 'wc-allpost'),
            array($this, 'text_field_callback'),
            'wc_allpost',
            'wc_allpost_main',
            array('field' => 'wc_allpost_filial_sankhya', 'default' => '1')
        );
        
        // Seção de comportamento
        add_settings_section(
            'wc_allpost_behavior',
            __('Comportamento', 'wc-allpost'),
            array($this, 'behavior_section_callback'),
            'wc_allpost'
        );
        
        // Status que disparam envio
        add_settings_field(
            'wc_allpost_trigger_statuses',
            __('Status que disparam envio', 'wc-allpost'),
            array($this, 'multiselect_field_callback'),
            'wc_allpost',
            'wc_allpost_behavior',
            array(
                'field' => 'wc_allpost_trigger_statuses',
                'options' => wc_get_order_statuses(),
                'default' => array('wc-processing')
            )
        );
        
        // Enviar email de postagem
        add_settings_field(
            'wc_allpost_send_posted_email',
            __('Enviar email quando postado', 'wc-allpost'),
            array($this, 'checkbox_field_callback'),
            'wc_allpost',
            'wc_allpost_behavior',
            array('field' => 'wc_allpost_send_posted_email')
        );
        
        // Debug
        add_settings_field(
            'wc_allpost_debug',
            __('Modo Debug', 'wc-allpost'),
            array($this, 'checkbox_field_callback'),
            'wc_allpost',
            'wc_allpost_behavior',
            array('field' => 'wc_allpost_debug')
        );
        
        // Registrar todas as configurações
        $settings = array(
            'wc_allpost_quote_token',
            'wc_allpost_api_token',
            'wc_allpost_channel',
            'wc_allpost_filial_erp',
            'wc_allpost_trigger_statuses',
            'wc_allpost_send_posted_email',
            'wc_allpost_debug'
        );
        
        foreach ($settings as $setting) {
            register_setting('wc_allpost', $setting);
        }
    }
    
    /**
     * Página administrativa principal
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors(); ?>
            
            <div class="wc-allpost-admin-content">
                <div class="wc-allpost-main-settings">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('wc_allpost');
                        do_settings_sections('wc_allpost');
                        submit_button();
                        ?>
                    </form>
                </div>
                
                <div class="wc-allpost-sidebar">
                    <?php $this->render_sidebar(); ?>
                </div>
            </div>
        </div>
        
        <style>
        .wc-allpost-admin-content {
            display: flex;
            gap: 20px;
        }
        
        .wc-allpost-main-settings {
            flex: 2;
        }
        
        .wc-allpost-sidebar {
            flex: 1;
            max-width: 300px;
        }
        
        .wc-allpost-info-box {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .wc-allpost-info-box h3 {
            margin-top: 0;
        }
        
        .wc-allpost-webhook-url {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 3px;
            padding: 8px;
            font-family: monospace;
            word-break: break-all;
            margin: 10px 0;
        }
        </style>
        <?php
    }
    
    /**
     * Renderizar sidebar
     */
    private function render_sidebar() {
        ?>
        <div class="wc-allpost-info-box">
            <h3><?php _e('Informações do Plugin', 'wc-allpost'); ?></h3>
            <p><strong><?php _e('Versão:', 'wc-allpost'); ?></strong> <?php echo WC_ALLPOST_VERSION; ?></p>
            <p><strong><?php _e('Status:', 'wc-allpost'); ?></strong> 
                <?php echo $this->get_configuration_status(); ?>
            </p>
        </div>
        
        <div class="wc-allpost-info-box">
            <h3><?php _e('URL do Webhook', 'wc-allpost'); ?></h3>
            <p><?php _e('Configure esta URL no painel AllPost:', 'wc-allpost'); ?></p>
            <div class="wc-allpost-webhook-url">
                <?php echo esc_html(WC_AllPost_Webhook::get_webhook_url()); ?>
            </div>
            <button type="button" class="button" onclick="copyWebhookUrl()">
                <?php _e('Copiar URL', 'wc-allpost'); ?>
            </button>
        </div>
        
        <div class="wc-allpost-info-box">
            <h3><?php _e('Teste de Conectividade', 'wc-allpost'); ?></h3>
            <p><?php _e('Teste a conexão com a API AllPost:', 'wc-allpost'); ?></p>
            <button type="button" class="button" id="test-connection">
                <?php _e('Testar Conexão', 'wc-allpost'); ?>
            </button>
            <div id="test-result" style="margin-top: 10px;"></div>
        </div>
        
        <div class="wc-allpost-info-box">
            <h3><?php _e('Logs de Debug', 'wc-allpost'); ?></h3>
            <p><?php _e('Visualize os logs de debug:', 'wc-allpost'); ?></p>
            <a href="<?php echo admin_url('admin.php?page=wc-status&tab=logs'); ?>" class="button">
                <?php _e('Ver Logs', 'wc-allpost'); ?>
            </a>
        </div>
        
        <script>
        function copyWebhookUrl() {
            const url = '<?php echo esc_js(WC_AllPost_Webhook::get_webhook_url()); ?>';
            navigator.clipboard.writeText(url).then(function() {
                alert('<?php _e('URL copiada!', 'wc-allpost'); ?>');
            });
        }
        
        document.getElementById('test-connection').addEventListener('click', function() {
            const button = this;
            const result = document.getElementById('test-result');
            
            button.disabled = true;
            button.textContent = '<?php _e('Testando...', 'wc-allpost'); ?>';
            result.innerHTML = '';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=wc_allpost_test_connection&nonce=<?php echo wp_create_nonce('wc_allpost_test'); ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    result.innerHTML = '<span style="color: green;">✓ ' + data.data + '</span>';
                } else {
                    result.innerHTML = '<span style="color: red;">✗ ' + data.data + '</span>';
                }
            })
            .catch(error => {
                result.innerHTML = '<span style="color: red;">✗ Erro na requisição</span>';
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = '<?php _e('Testar Conexão', 'wc-allpost'); ?>';
            });
        });
        </script>
        <?php
    }
    
    /**
     * Callback da seção principal
     */
    public function main_section_callback() {
        echo '<p>' . __('Configure suas credenciais da AllPost abaixo:', 'wc-allpost') . '</p>';
    }
    
    /**
     * Callback da seção de comportamento
     */
    public function behavior_section_callback() {
        echo '<p>' . __('Configure o comportamento do plugin:', 'wc-allpost') . '</p>';
    }
    
    /**
     * Callback para campos de token
     */
    public function token_field_callback($args) {
        $field = $args['field'];
        $type = $args['type'] ?? 'text';
        $value = get_option($field, '');
        
        echo '<input type="' . esc_attr($type) . '" id="' . esc_attr($field) . '" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '" class="regular-text" />';
        
        if ($field === 'wc_allpost_quote_token') {
            echo '<p class="description">' . __('Token usado para cotações de frete (sem limite de requisições)', 'wc-allpost') . '</p>';
        } elseif ($field === 'wc_allpost_api_token') {
            echo '<p class="description">' . __('Token usado para criar pedidos e outras operações (com limite de requisições)', 'wc-allpost') . '</p>';
        }
    }
    
    /**
     * Callback para campos de texto
     */
    public function text_field_callback($args) {
        $field = $args['field'];
        $default = $args['default'] ?? '';
        $value = get_option($field, $default);
        
        echo '<input type="text" id="' . esc_attr($field) . '" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '" class="regular-text" />';
        
        if ($field === 'wc_allpost_channel') {
            echo '<p class="description">' . __('Nome do canal para identificar as cotações', 'wc-allpost') . '</p>';
        } elseif ($field === 'wc_allpost_filial_erp') {
            echo '<p class="description">' . __('Código da filial no seu ERP', 'wc-allpost') . '</p>';
        }
    }
    
    /**
     * Callback para campos checkbox
     */
    public function checkbox_field_callback($args) {
        $field = $args['field'];
        $value = get_option($field, 'no');
        
        echo '<label for="' . esc_attr($field) . '">';
        echo '<input type="checkbox" id="' . esc_attr($field) . '" name="' . esc_attr($field) . '" value="yes" ' . checked('yes', $value, false) . ' />';
        
        if ($field === 'wc_allpost_send_posted_email') {
            echo ' ' . __('Enviar email ao cliente quando o pedido for postado', 'wc-allpost');
        } elseif ($field === 'wc_allpost_debug') {
            echo ' ' . __('Ativar logs detalhados para debug', 'wc-allpost');
        }
        
        echo '</label>';
    }
    
    /**
     * Callback para campos multiselect
     */
    public function multiselect_field_callback($args) {
        $field = $args['field'];
        $options = $args['options'];
        $default = $args['default'] ?? array();
        $values = get_option($field, $default);
        
        if (!is_array($values)) {
            $values = array();
        }
        
        echo '<select multiple id="' . esc_attr($field) . '" name="' . esc_attr($field) . '[]" style="height: 120px; width: 300px;">';
        
        foreach ($options as $key => $label) {
            $selected = in_array($key, $values) ? 'selected' : '';
            echo '<option value="' . esc_attr($key) . '" ' . $selected . '>' . esc_html($label) . '</option>';
        }
        
        echo '</select>';
        echo '<p class="description">' . __('Segure Ctrl/Cmd para selecionar múltiplos status', 'wc-allpost') . '</p>';
    }
    
    /**
     * Adicionar links de ação no plugin
     */
    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-allpost') . '">' . __('Configurações', 'wc-allpost') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Notice de configuração
     */
    public function configuration_notice() {
        if (!$this->is_configured()) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>' . sprintf(
                __('AllPost precisa ser configurado. <a href="%s">Clique aqui para configurar</a>', 'wc-allpost'),
                admin_url('admin.php?page=wc-allpost')
            ) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Verificar se está configurado
     */
    private function is_configured() {
        $quote_token = get_option('wc_allpost_quote_token', '');
        $api_token = get_option('wc_allpost_api_token', '');
        
        return !empty($quote_token) && !empty($api_token);
    }
    
    /**
     * Obter status da configuração
     */
    private function get_configuration_status() {
        if ($this->is_configured()) {
            return '<span style="color: green;">✓ ' . __('Configurado', 'wc-allpost') . '</span>';
        } else {
            return '<span style="color: red;">✗ ' . __('Não configurado', 'wc-allpost') . '</span>';
        }
    }
}