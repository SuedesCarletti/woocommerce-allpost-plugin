<?php
/**
 * Classe para gestão de pedidos AllPost
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_AllPost_Order {
    
    /**
     * Instância única
     */
    private static $instance = null;
    
    /**
     * Instância da API
     */
    private $api;
    
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
        $this->api = new WC_AllPost_API();
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Enviar pedido automaticamente quando status mudar
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        
        // Salvar dados da cotação no pedido
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_shipping_meta'));
        
        // Adicionar metabox no admin do pedido
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        
        // Processar ações do metabox
        add_action('wp_ajax_allpost_create_shipment', array($this, 'ajax_create_shipment'));
        add_action('wp_ajax_allpost_get_tracking', array($this, 'ajax_get_tracking'));
        
        // Adicionar coluna de rastreamento na lista de pedidos
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_tracking_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'display_order_tracking_column'), 10, 2);
        
        // Adicionar informações de rastreamento no email do cliente
        add_action('woocommerce_email_order_meta', array($this, 'add_tracking_to_email'), 10, 3);
    }
    
    /**
     * Lidar com mudança de status do pedido
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        // Status que devem disparar criação do envio
        $trigger_statuses = get_option('wc_allpost_trigger_statuses', array('processing', 'shipped'));
        
        if (!in_array($new_status, $trigger_statuses)) {
            return;
        }
        
        // Verificar se já foi enviado
        if ($this->order_already_sent($order_id)) {
            return;
        }
        
        // Verificar se usado AllPost no frete
        if (!$this->order_uses_allpost($order)) {
            return;
        }
        
        // Criar envio automaticamente
        $this->create_shipment($order);
    }
    
    /**
     * Salvar metadados do frete no pedido
     */
    public function save_shipping_meta($order_id) {
        if (!isset($_POST['shipping_method']) || !is_array($_POST['shipping_method'])) {
            return;
        }
        
        $chosen_shipping = reset($_POST['shipping_method']);
        
        if (strpos($chosen_shipping, 'allpost') === 0) {
            // Obter dados da sessão do WooCommerce
            $shipping_packages = WC()->session->get('shipping_for_package_0');
            
            if (isset($shipping_packages['rates'][$chosen_shipping])) {
                $rate = $shipping_packages['rates'][$chosen_shipping];
                $meta_data = $rate->get_meta_data();
                
                // Salvar dados importantes
                if (isset($meta_data['allpost_cotacao_id'])) {
                    update_post_meta($order_id, '_allpost_cotacao_id', $meta_data['allpost_cotacao_id']);
                }
                if (isset($meta_data['allpost_transportadora_id'])) {
                    update_post_meta($order_id, '_allpost_transportadora_id', $meta_data['allpost_transportadora_id']);
                }
                if (isset($meta_data['allpost_transportadora'])) {
                    update_post_meta($order_id, '_allpost_transportadora', $meta_data['allpost_transportadora']);
                }
                if (isset($meta_data['allpost_metodo_id'])) {
                    update_post_meta($order_id, '_allpost_metodo_id', $meta_data['allpost_metodo_id']);
                }
                if (isset($meta_data['allpost_metodo'])) {
                    update_post_meta($order_id, '_allpost_metodo', $meta_data['allpost_metodo']);
                }
                if (isset($meta_data['delivery_time'])) {
                    update_post_meta($order_id, '_allpost_delivery_time', $meta_data['delivery_time']);
                }
            }
        }
    }
    
    /**
     * Adicionar metabox no admin do pedido
     */
    public function add_order_metabox() {
        add_meta_box(
            'allpost-order-actions',
            __('AllPost - Gestão de Envio', 'wc-allpost'),
            array($this, 'render_order_metabox'),
            'shop_order',
            'side',
            'high'
        );
    }
    
    /**
     * Renderizar metabox do pedido
     */
    public function render_order_metabox($post) {
        $order = wc_get_order($post->ID);
        if (!$order) return;
        
        $shipment_data = $this->get_shipment_data($order->get_id());
        $uses_allpost = $this->order_uses_allpost($order);
        
        echo '<div id="allpost-metabox-content">';
        
        if (!$uses_allpost) {
            echo '<p>' . __('Este pedido não utiliza AllPost como método de envio.', 'wc-allpost') . '</p>';
            echo '</div>';
            return;
        }
        
        if ($shipment_data) {
            $this->render_shipment_info($shipment_data);
        } else {
            $this->render_create_shipment_button($order);
        }
        
        echo '</div>';
        
        // JavaScript
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#allpost-create-shipment').click(function(e) {
                e.preventDefault();
                var button = $(this);
                var orderId = <?php echo $order->get_id(); ?>;
                
                button.prop('disabled', true).text('<?php _e('Criando...', 'wc-allpost'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'allpost_create_shipment',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('allpost_order_action'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Erro: ' + response.data);
                            button.prop('disabled', false).text('<?php _e('Criar Envio', 'wc-allpost'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php _e('Erro na comunicação com o servidor.', 'wc-allpost'); ?>');
                        button.prop('disabled', false).text('<?php _e('Criar Envio', 'wc-allpost'); ?>');
                    }
                });
            });
            
            $('#allpost-get-tracking').click(function(e) {
                e.preventDefault();
                var orderId = <?php echo $order->get_id(); ?>;
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'allpost_get_tracking',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('allpost_order_action'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#allpost-tracking-info').html(response.data);
                        } else {
                            alert('Erro: ' + response.data);
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Renderizar informações do envio
     */
    private function render_shipment_info($shipment_data) {
        ?>
        <table class="widefat">
            <tr>
                <td><strong><?php _e('Status:', 'wc-allpost'); ?></strong></td>
                <td><?php echo esc_html($shipment_data->status); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Chave do Pedido:', 'wc-allpost'); ?></strong></td>
                <td><?php echo esc_html($shipment_data->chave_pedido); ?></td>
            </tr>
            <?php if ($shipment_data->tracking_code): ?>
            <tr>
                <td><strong><?php _e('Código de Rastreamento:', 'wc-allpost'); ?></strong></td>
                <td><?php echo esc_html($shipment_data->tracking_code); ?></td>
            </tr>
            <?php endif; ?>
        </table>
        
        <p>
            <a href="https://www.allpost.com.br/rastreio/<?php echo esc_attr($shipment_data->numero_pedido); ?>/<?php echo esc_attr($shipment_data->chave_pedido); ?>" 
               target="_blank" class="button button-primary">
                <?php _e('Ver Rastreamento', 'wc-allpost'); ?>
            </a>
            
            <button id="allpost-get-tracking" class="button">
                <?php _e('Atualizar Status', 'wc-allpost'); ?>
            </button>
        </p>
        
        <div id="allpost-tracking-info"></div>
        <?php
    }
    
    /**
     * Renderizar botão de criar envio
     */
    private function render_create_shipment_button($order) {
        ?>
        <p><?php _e('Envio ainda não foi criado na AllPost.', 'wc-allpost'); ?></p>
        
        <p>
            <button id="allpost-create-shipment" class="button button-primary">
                <?php _e('Criar Envio', 'wc-allpost'); ?>
            </button>
        </p>
        
        <?php
        // Exibir informações da cotação se disponível
        $cotacao_id = get_post_meta($order->get_id(), '_allpost_cotacao_id', true);
        if ($cotacao_id) {
            echo '<p><small>' . sprintf(__('ID da Cotação: %s', 'wc-allpost'), $cotacao_id) . '</small></p>';
        }
    }
    
    /**
     * Criar envio via AJAX
     */
    public function ajax_create_shipment() {
        check_ajax_referer('allpost_order_action', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Permissão negada.', 'wc-allpost'));
        }
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Pedido não encontrado.', 'wc-allpost'));
        }
        
        $result = $this->create_shipment($order);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(__('Envio criado com sucesso!', 'wc-allpost'));
    }
    
    /**
     * Obter rastreamento via AJAX
     */
    public function ajax_get_tracking() {
        check_ajax_referer('allpost_order_action', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Permissão negada.', 'wc-allpost'));
        }
        
        $order_id = intval($_POST['order_id']);
        // Aqui você implementaria a lógica para consultar o status do envio
        // Por agora, retornamos uma mensagem simples
        
        wp_send_json_success('<p>' . __('Funcionalidade de consulta de rastreamento será implementada em breve.', 'wc-allpost') . '</p>');
    }
    
    /**
     * Criar envio na AllPost
     */
    public function create_shipment($order) {
        // Evita criar o mesmo envio duas vezes (duplo clique, retry)
        if ($this->order_already_sent($order->get_id())) {
            return new WP_Error('allpost_already_sent', __('Este pedido já possui envio criado na AllPost.', 'wc-allpost'));
        }

        $cotacao_id = get_post_meta($order->get_id(), '_allpost_cotacao_id', true);
        
        // Converter pedido para formato AllPost
        $pedido_data = $this->api->convert_order_to_allpost($order, $cotacao_id);
        if (is_wp_error($pedido_data)) {
            return $pedido_data;
        }
        
        // Criar pedido via API
        $response = $this->api->criar_pedido($pedido_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Adicionar nota ao pedido e salvar dados
        $order->add_order_note(
            sprintf(__('Envio criado na AllPost. Chave: %s', 'wc-allpost'), $response['chavePedido'] ?? '-')
        );
        
        // Salvar dados do envio nos metadados
        $this->save_shipment_data($order->get_id(), $response);
        
        return $response;
    }
    
    /**
     * Verificar se pedido já foi enviado
     */
    private function order_already_sent($order_id) {
        $chave_pedido = get_post_meta($order_id, '_allpost_chave_pedido', true);
        return !empty($chave_pedido);
    }
    
    /**
     * Verificar se pedido usa AllPost
     */
    private function order_uses_allpost($order) {
        foreach ($order->get_shipping_methods() as $shipping_method) {
            if (strpos($shipping_method->get_method_id(), 'allpost') === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Obter dados do envio
     */
    private function get_shipment_data($order_id) {
        $chave_pedido = get_post_meta($order_id, '_allpost_chave_pedido', true);
        $status = get_post_meta($order_id, '_allpost_status', true);
        $tracking_code = get_post_meta($order_id, '_allpost_tracking_code', true);
        
        if (empty($chave_pedido)) {
            return null;
        }
        
        return (object) array(
            'chave_pedido' => $chave_pedido,
            'numero_pedido' => $order_id,
            'status' => $status ?: 'criado',
            'tracking_code' => $tracking_code
        );
    }
    
    /**
     * Adicionar coluna de rastreamento
     */
    public function add_order_tracking_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            if ($key === 'order_status') {
                $new_columns['allpost_tracking'] = __('Rastreamento', 'wc-allpost');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Exibir coluna de rastreamento
     */
    public function display_order_tracking_column($column, $post_id) {
        if ($column === 'allpost_tracking') {
            $shipment_data = $this->get_shipment_data($post_id);
            
            if ($shipment_data) {
                echo '<a href="https://www.allpost.com.br/rastreio/' . esc_attr($shipment_data->numero_pedido) . '/' . esc_attr($shipment_data->chave_pedido) . '" target="_blank">';
                echo esc_html($shipment_data->status);
                echo '</a>';
            } else {
                echo '-';
            }
        }
    }
    
    /**
     * Adicionar rastreamento ao email
     */
    public function add_tracking_to_email($order, $sent_to_admin, $plain_text) {
        if ($sent_to_admin) return;
        
        $shipment_data = $this->get_shipment_data($order->get_id());
        
        if ($shipment_data && $shipment_data->chave_pedido) {
            $tracking_url = 'https://www.allpost.com.br/rastreio/' . $shipment_data->numero_pedido . '/' . $shipment_data->chave_pedido;
            
            if ($plain_text) {
                echo "\n" . __('Rastreamento:', 'wc-allpost') . " " . $tracking_url . "\n";
            } else {
                echo '<h2>' . __('Rastreamento do Envio', 'wc-allpost') . '</h2>';
                echo '<p><a href="' . esc_url($tracking_url) . '" target="_blank">' . __('Clique aqui para rastrear seu pedido', 'wc-allpost') . '</a></p>';
            }
        }
    }
    
    /**
     * Salvar dados do envio criado
     */
    public function save_shipment_data($order_id, $response) {
        if (isset($response['chavePedido'])) {
            update_post_meta($order_id, '_allpost_chave_pedido', $response['chavePedido']);
            update_post_meta($order_id, '_allpost_status', 'criado');
        }
    }
}