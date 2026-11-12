<?php
/**
 * Funções auxiliares e configurações do plugin AllPost
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obter instância da API AllPost
 */
function wc_allpost_get_api() {
    return new WC_AllPost_API();
}

/**
 * Verificar se o plugin está configurado
 */
function wc_allpost_is_configured() {
    $quote_token = get_option('wc_allpost_quote_token', '');
    $api_token = get_option('wc_allpost_api_token', '');
    
    return !empty($quote_token) && !empty($api_token);
}

/**
 * Obter dados do envio AllPost para um pedido
 */
function wc_allpost_get_shipment_data($order_id) {
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
 * Obter URL de rastreamento para um pedido
 */
function wc_allpost_get_tracking_url($order_id) {
    $shipment = wc_allpost_get_shipment_data($order_id);
    
    if ($shipment && $shipment->chave_pedido) {
        return 'https://www.allpost.com.br/rastreio/' . $shipment->numero_pedido . '/' . $shipment->chave_pedido;
    }
    
    return false;
}

/**
 * Criar envio AllPost para um pedido
 */
function wc_allpost_create_shipment($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_Error('invalid_order', __('Pedido não encontrado', 'wc-allpost'));
    }
    
    $order_manager = WC_AllPost_Order::get_instance();
    return $order_manager->create_shipment($order);
}

/**
 * Adicionar teste de conexão via AJAX
 */
add_action('wp_ajax_wc_allpost_test_connection', 'wc_allpost_ajax_test_connection');

function wc_allpost_ajax_test_connection() {
    check_ajax_referer('wc_allpost_test', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('Permissão negada.', 'wc-allpost'));
    }
    
    $api = wc_allpost_get_api();
    
    // Testar cotação simples
    $test_volumes = array(
        array(
            'sku' => 'TESTE',
            'quantidade' => 1,
            'valorUnitario' => 100.00,
            'peso' => 1,
            'altura' => 10,
            'largura' => 15,
            'profundidade' => 20
        )
    );
    
    $result = $api->cotacao('01310100', $test_volumes); // CEP sem formatação
    
    if (is_wp_error($result)) {
        wp_send_json_error('Erro na conexão: ' . $result->get_error_message());
    } else {
        wp_send_json_success('Conexão estabelecida com sucesso!');
    }
}

/**
 * Shortcode para exibir rastreamento
 */
add_shortcode('allpost_tracking', 'wc_allpost_tracking_shortcode');

function wc_allpost_tracking_shortcode($atts) {
    $atts = shortcode_atts(array(
        'order_id' => 0,
        'show_details' => 'yes'
    ), $atts);
    
    $order_id = intval($atts['order_id']);
    
    if (!$order_id) {
        return __('ID do pedido não informado', 'wc-allpost');
    }
    
    $tracking_url = wc_allpost_get_tracking_url($order_id);
    
    if (!$tracking_url) {
        return __('Rastreamento não disponível para este pedido', 'wc-allpost');
    }
    
    $output = '<div class="wc-allpost-tracking">';
    $output .= '<a href="' . esc_url($tracking_url) . '" target="_blank" class="button">';
    $output .= __('Rastrear Pedido', 'wc-allpost');
    $output .= '</a>';
    
    if ($atts['show_details'] === 'yes') {
        $shipment = wc_allpost_get_shipment_data($order_id);
        if ($shipment) {
            $output .= '<p><small>' . sprintf(__('Status: %s', 'wc-allpost'), esc_html($shipment->status)) . '</small></p>';
        }
    }
    
    $output .= '</div>';
    
    return $output;
}

/**
 * Hook para adicionar rastreamento na página "Minha Conta"
 */
add_action('woocommerce_view_order', 'wc_allpost_add_tracking_to_order_view', 20);

function wc_allpost_add_tracking_to_order_view($order_id) {
    $tracking_url = wc_allpost_get_tracking_url($order_id);
    
    if ($tracking_url) {
        echo '<div class="wc-allpost-customer-tracking" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 5px;">';
        echo '<h3>' . __('Rastreamento do Envio', 'wc-allpost') . '</h3>';
        echo '<p>' . __('Acompanhe o status do seu envio:', 'wc-allpost') . '</p>';
        echo '<a href="' . esc_url($tracking_url) . '" target="_blank" class="button" style="background: #0073aa; color: white; text-decoration: none; padding: 10px 15px; border-radius: 3px;">';
        echo __('Rastrear Pedido', 'wc-allpost');
        echo '</a>';
        echo '</div>';
    }
}

/**
 * Função para debug/log
 */
function wc_allpost_log($message, $data = array()) {
    if (get_option('wc_allpost_debug', 'no') === 'yes') {
        $logger = wc_get_logger();
        $logger->info($message, array_merge(array('source' => 'allpost'), $data));
    }
}

/**
 * Limpar dados antigos (executar via cron)
 */
add_action('wc_allpost_cleanup_old_data', 'wc_allpost_cleanup_old_data');

function wc_allpost_cleanup_old_data() {
    // Limpar apenas logs antigos - metadados dos pedidos permanecem
    wc_allpost_log('Limpeza de logs antigos executada');
}

/**
 * Agendar limpeza automática
 */
add_action('wp', 'wc_allpost_schedule_cleanup');

function wc_allpost_schedule_cleanup() {
    if (!wp_next_scheduled('wc_allpost_cleanup_old_data')) {
        wp_schedule_event(time(), 'weekly', 'wc_allpost_cleanup_old_data');
    }
}

/**
 * Limpar agendamento na desativação
 */
register_deactivation_hook(WC_ALLPOST_PLUGIN_FILE, 'wc_allpost_clear_scheduled_events');

function wc_allpost_clear_scheduled_events() {
    wp_clear_scheduled_hook('wc_allpost_cleanup_old_data');
}

/**
 * Adicionar meta fields personalizados no checkout para CPF/CNPJ
 */
add_action('woocommerce_after_checkout_billing_form', 'wc_allpost_add_document_field');

function wc_allpost_add_document_field($checkout) {
    // Verificar se já existe um campo de documento
    $existing_fields = array('billing_cpf', 'billing_cnpj', 'billing_document');
    $has_document_field = false;
    
    foreach ($existing_fields as $field) {
        if (isset(WC()->checkout->checkout_fields['billing'][$field])) {
            $has_document_field = true;
            break;
        }
    }
    
    // Se não existe campo de documento, adicionar
    if (!$has_document_field) {
        woocommerce_form_field('billing_document', array(
            'type' => 'text',
            'label' => __('CPF/CNPJ', 'wc-allpost'),
            'required' => true,
            'class' => array('form-row-wide'),
            'placeholder' => __('000.000.000-00 ou 00.000.000/0000-00', 'wc-allpost')
        ), $checkout->get_value('billing_document'));
    }
}

/**
 * Validar campo de documento
 */
add_action('woocommerce_checkout_process', 'wc_allpost_validate_document_field');

function wc_allpost_validate_document_field() {
    if (empty($_POST['billing_document']) && empty($_POST['billing_cpf']) && empty($_POST['billing_cnpj'])) {
        wc_add_notice(__('Por favor, informe seu CPF ou CNPJ.', 'wc-allpost'), 'error');
        return;
    }

    // Dígitos verificadores (Receita Federal) de qualquer campo de documento enviado.
    foreach (array('billing_document', 'billing_cpf', 'billing_cnpj') as $field) {
        if (!empty($_POST[$field]) && !wc_allpost_documento_valido(wp_unslash($_POST[$field]))) {
            wc_add_notice(__('<strong>CPF/CNPJ</strong> informado é inválido. Confira os números.', 'wc-allpost'), 'error');
            break;
        }
    }
}

/**
 * CPF (11 dígitos) ou CNPJ (14, inclusive alfanumérico) com dígitos verificadores corretos.
 */
function wc_allpost_documento_valido($raw) {
    if (function_exists('ec_documento_valido')) {
        return ec_documento_valido($raw);
    }
    $c = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $raw));
    if (14 === strlen($c)) {
        if (!preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $c) || preg_match('/^(.)\1{13}$/', $c)) {
            return false;
        }
        $p1 = array(5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2);
        $p2 = array(6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2);
        $dv = function ($n, $p) use ($c) {
            $soma = 0;
            for ($i = 0; $i < $n; $i++) {
                $soma += (ord($c[$i]) - 48) * $p[$i];
            }
            $r = $soma % 11;
            return $r < 2 ? 0 : 11 - $r;
        };
        return $dv(12, $p1) === (int) $c[12] && $dv(13, $p2) === (int) $c[13];
    }
    if (11 === strlen($c) && ctype_digit($c) && !preg_match('/^(\d)\1{10}$/', $c)) {
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $c[$i] * ($t + 1 - $i);
            }
            if ((int) $c[$t] !== ((10 * $soma) % 11) % 10) {
                return false;
            }
        }
        return true;
    }
    return false;
}

/**
 * Salvar campo de documento
 */
add_action('woocommerce_checkout_update_order_meta', 'wc_allpost_save_document_field');

function wc_allpost_save_document_field($order_id) {
    if (!empty($_POST['billing_document'])) {
        update_post_meta($order_id, '_billing_document', sanitize_text_field($_POST['billing_document']));
    }
}

/**
 * Exibir campo de documento no admin
 */
add_filter('woocommerce_admin_billing_fields', 'wc_allpost_admin_billing_fields');

function wc_allpost_admin_billing_fields($fields) {
    $fields['document'] = array(
        'label' => __('CPF/CNPJ', 'wc-allpost'),
        'show' => true
    );
    return $fields;
}

/**
 * Widget para dashboard com estatísticas AllPost
 */
add_action('wp_dashboard_setup', 'wc_allpost_add_dashboard_widget');

function wc_allpost_add_dashboard_widget() {
    if (current_user_can('manage_woocommerce')) {
        wp_add_dashboard_widget(
            'wc_allpost_stats',
            __('AllPost - Estatísticas de Envio', 'wc-allpost'),
            'wc_allpost_dashboard_widget_content'
        );
    }
}

function wc_allpost_dashboard_widget_content() {
    // Contar pedidos com AllPost nos últimos 30 dias
    $args = array(
        'limit' => -1,
        'date_created' => '>=' . (time() - 30 * DAY_IN_SECONDS),
        'meta_query' => array(
            array(
                'key' => '_allpost_chave_pedido',
                'compare' => 'EXISTS'
            )
        )
    );
    
    $orders = wc_get_orders($args);
    
    $stats = array(
        'total' => count($orders),
        'entregue' => 0,
        'em_transito' => 0,
        'criado' => 0
    );
    
    foreach ($orders as $order) {
        $status = get_post_meta($order->get_id(), '_allpost_status', true);
        switch ($status) {
            case 'entregue':
                $stats['entregue']++;
                break;
            case 'em transito':
                $stats['em_transito']++;
                break;
            default:
                $stats['criado']++;
                break;
        }
    }
    
    echo '<div class="wc-allpost-dashboard-stats">';
    echo '<p><strong>' . __('Últimos 30 dias:', 'wc-allpost') . '</strong></p>';
    echo '<ul>';
    echo '<li>' . sprintf(__('Total de envios: %d', 'wc-allpost'), $stats['total']) . '</li>';
    echo '<li>' . sprintf(__('Entregues: %d', 'wc-allpost'), $stats['entregue']) . '</li>';
    echo '<li>' . sprintf(__('Em trânsito: %d', 'wc-allpost'), $stats['em_transito']) . '</li>';
    echo '<li>' . sprintf(__('Criados: %d', 'wc-allpost'), $stats['criado']) . '</li>';
    echo '</ul>';
    echo '<p><a href="' . admin_url('admin.php?page=wc-allpost') . '">' . __('Ver configurações', 'wc-allpost') . '</a></p>';
    echo '</div>';
}

/**
 * Função para calcular cubagem de produtos
 */
function wc_allpost_calculate_cubagem($altura, $largura, $profundidade) {
    // Converter de cm para m³
    return ($altura * $largura * $profundidade) / 1000000;
}

/**
 * Adicionar colunas personalizadas na lista de produtos
 */
add_filter('manage_edit-product_columns', 'wc_allpost_add_product_columns');

function wc_allpost_add_product_columns($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'price') {
            $new_columns['allpost_dimensions'] = __('Dimensões/Peso', 'wc-allpost');
        }
    }
    
    return $new_columns;
}

add_action('manage_product_posts_custom_column', 'wc_allpost_display_product_columns', 10, 2);

function wc_allpost_display_product_columns($column, $post_id) {
    if ($column === 'allpost_dimensions') {
        $product = wc_get_product($post_id);
        if ($product) {
            echo sprintf(
                '%s × %s × %s cm<br>%s kg',
                $product->get_length() ?: '-',
                $product->get_width() ?: '-',
                $product->get_height() ?: '-',
                $product->get_weight() ?: '-'
            );
        }
    }
}

// MELHORIAS ADICIONADAS - FUNÇÕES AUXILIARES

/**
 * Limpar e validar CEP
 */
function wc_allpost_clean_postcode($postcode) {
    // Remove tudo que não é número
    $clean = preg_replace('/[^0-9]/', '', $postcode);
    
    // Validar se tem exatamente 8 dígitos
    if (strlen($clean) === 8) {
        return $clean;
    }
    
    return false;
}

/**
 * Formatar CEP para exibição
 */
function wc_allpost_format_postcode($postcode) {
    $clean = wc_allpost_clean_postcode($postcode);
    
    if ($clean) {
        return substr($clean, 0, 5) . '-' . substr($clean, 5, 3);
    }
    
    return $postcode;
}

/**
 * Validar CEP brasileiro
 */
function wc_allpost_validate_postcode($postcode) {
    $clean = wc_allpost_clean_postcode($postcode);
    
    if (!$clean) {
        return false;
    }
    
    // CEPs inválidos conhecidos
    $invalid_ceps = array('00000000', '11111111', '22222222', '33333333', '44444444', '55555555', '66666666', '77777777', '88888888', '99999999');
    
    return !in_array($clean, $invalid_ceps);
}

/**
 * Obter informações de localização por CEP
 */
function wc_allpost_get_address_by_cep($cep) {
    $clean_cep = wc_allpost_clean_postcode($cep);
    
    if (!$clean_cep) {
        return false;
    }
    
    // Cache por 24 horas
    $cache_key = 'allpost_cep_' . $clean_cep;
    $cached = get_transient($cache_key);
    
    if ($cached !== false) {
        return $cached;
    }
    
    // Consultar API dos Correios
    $response = wp_remote_get('https://viacep.com.br/ws/' . $clean_cep . '/json/');
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['erro'])) {
        return false;
    }
    
    $address_info = array(
        'cep' => $data['cep'],
        'logradouro' => $data['logradouro'],
        'bairro' => $data['bairro'],
        'cidade' => $data['localidade'],
        'uf' => $data['uf'],
        'ibge' => $data['ibge']
    );
    
    // Cache por 24 horas
    set_transient($cache_key, $address_info, DAY_IN_SECONDS);
    
    return $address_info;
}

/**
 * Adicionar máscara de CEP nos campos do checkout
 */
add_action('wp_footer', 'wc_allpost_add_cep_mask');

function wc_allpost_add_cep_mask() {
    if (!is_checkout() && !is_account_page()) {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Função para aplicar máscara de CEP
        function applyCepMask(element) {
            let value = element.val().replace(/\D/g, '');
            
            if (value.length <= 8) {
                value = value.replace(/(\d{5})(\d)/, '$1-$2');
                element.val(value);
            }
        }
        
        // Aplicar máscara em tempo real
        $(document).on('input keyup', '#billing_postcode, #shipping_postcode', function() {
            applyCepMask($(this));
        });
        
        // Auto-completar endereço quando CEP for válido
        $(document).on('blur', '#billing_postcode, #shipping_postcode', function() {
            const $field = $(this);
            const cep = $field.val().replace(/\D/g, '');
            
            if (cep.length === 8) {
                const isShipping = $field.attr('id') === 'shipping_postcode';
                const prefix = isShipping ? 'shipping' : 'billing';
                
                // Buscar informações do CEP
                $.get('https://viacep.com.br/ws/' + cep + '/json/')
                    .done(function(data) {
                        if (!data.erro) {
                            $('#' + prefix + '_city').val(data.localidade);
                            $('#' + prefix + '_state').val(data.uf);
                            
                            if (data.logradouro) {
                                $('#' + prefix + '_address_1').val(data.logradouro);
                            }
                            if (data.bairro) {
                                $('#' + prefix + '_address_2').val(data.bairro);
                            }
                            
                            // Disparar evento de atualização
                            $('body').trigger('update_checkout');
                        }
                    })
                    .fail(function() {
                        console.log('Erro ao buscar CEP');
                    });
            }
        });
        
        // Limpar formatação antes de enviar
        $('form.checkout').on('submit', function() {
            $('#billing_postcode, #shipping_postcode').each(function() {
                const clean = $(this).val().replace(/\D/g, '');
                $(this).val(clean);
            });
        });
    });
    </script>
    
    <style>
    .woocommerce-input-wrapper {
        position: relative;
    }
    
    .allpost-cep-loading {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        display: none;
    }
    
    .allpost-cep-loading:after {
        content: "⟳";
        animation: spin 1s linear infinite;
        color: #666;
    }
    
    @keyframes spin {
        0% { transform: translateY(-50%) rotate(0deg); }
        100% { transform: translateY(-50%) rotate(360deg); }
    }
    </style>
    <?php
}

/**
 * Personalizar mensagens de frete - VERSÃO CORRIGIDA
 */
add_filter('woocommerce_cart_shipping_method_full_label', 'wc_allpost_customize_shipping_label', 10, 2);

function wc_allpost_customize_shipping_label($label, $method) {
    if (strpos($method->get_id(), 'allpost') === 0) {
        $cost = $method->get_cost();
        
        if ($cost > 0) {
            // Adicionar formatação especial ao preço
            $formatted_cost = wc_price($cost);
            $label = str_replace($formatted_cost, '<strong>' . $formatted_cost . '</strong>', $label);
            
            // Tentar obter informação de prazo de forma compatível
            $delivery_time = null;
            
            // Método compatível para obter metadados
            if (method_exists($method, 'get_meta_data')) {
                $meta_data = $method->get_meta_data();
                if (isset($meta_data['delivery_time'])) {
                    $delivery_time = $meta_data['delivery_time'];
                }
            }
            
            // Se não conseguiu pelos metadados, tentar pela propriedade direta (fallback)
            if (!$delivery_time && isset($method->meta_data['delivery_time'])) {
                $delivery_time = $method->meta_data['delivery_time'];
            }
            
            // Adicionar informação de prazo se disponível
            if ($delivery_time && is_numeric($delivery_time) && $delivery_time > 0) {
                $label .= '<br><small style="color: #666;">Receba em até ' . $delivery_time . ' dias úteis</small>';
            }
        }
    }
    
    return $label;
}

/**
 * Adicionar validação de CEP no checkout
 */
add_action('woocommerce_checkout_process', 'wc_allpost_validate_checkout_postcode');

function wc_allpost_validate_checkout_postcode() {
    // Validar CEP de cobrança
    if (!empty($_POST['billing_postcode'])) {
        $billing_postcode = sanitize_text_field($_POST['billing_postcode']);
        if (!wc_allpost_validate_postcode($billing_postcode)) {
            wc_add_notice(__('CEP de cobrança inválido. Por favor, verifique.', 'wc-allpost'), 'error');
        }
    }
    
    // Validar CEP de entrega se diferente
    if (!empty($_POST['shipping_postcode']) && $_POST['shipping_postcode'] !== $_POST['billing_postcode']) {
        $shipping_postcode = sanitize_text_field($_POST['shipping_postcode']);
        if (!wc_allpost_validate_postcode($shipping_postcode)) {
            wc_add_notice(__('CEP de entrega inválido. Por favor, verifique.', 'wc-allpost'), 'error');
        }
    }
}

/**
 * Limpar CEPs antes de salvar no pedido
 */
add_action('woocommerce_checkout_update_order_meta', 'wc_allpost_clean_order_postcodes');

function wc_allpost_clean_order_postcodes($order_id) {
    $order = wc_get_order($order_id);
    
    if ($order) {
        // Limpar CEP de cobrança
        $billing_postcode = $order->get_billing_postcode();
        if ($billing_postcode) {
            $clean_postcode = wc_allpost_clean_postcode($billing_postcode);
            if ($clean_postcode) {
                $order->set_billing_postcode($clean_postcode);
            }
        }
        
        // Limpar CEP de entrega
        $shipping_postcode = $order->get_shipping_postcode();
        if ($shipping_postcode) {
            $clean_postcode = wc_allpost_clean_postcode($shipping_postcode);
            if ($clean_postcode) {
                $order->set_shipping_postcode($clean_postcode);
            }
        }
        
        $order->save();
    }
}

/**
 * Adicionar CSS personalizado para melhorar a exibição
 */
add_action('wp_head', 'wc_allpost_custom_styles');

function wc_allpost_custom_styles() {
    if (!is_checkout() && !is_cart()) {
        return;
    }
    ?>
    <style>
    .shipping-calculator-form .allpost-cep-help {
        font-size: 0.85em;
        color: #666;
        margin-top: 5px;
    }
    
    .woocommerce-shipping-methods .allpost-shipping-option {
        border-left: 3px solid #0073aa;
        padding-left: 10px;
        margin: 5px 0;
    }
    
    .allpost-delivery-estimate {
        font-size: 0.9em;
        color: #666;
        font-style: italic;
    }
    
    .allpost-free-shipping {
        color: #46b450 !important;
        font-weight: bold;
    }
    
    /* Melhorar exibição mobile */
    @media (max-width: 768px) {
        .woocommerce-shipping-methods label {
            display: block;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin: 5px 0;
        }
        
        .woocommerce-shipping-methods label:hover {
            background-color: #f8f9fa;
        }
    }
    </style>
    <?php
}

/**
 * Otimizar performance das cotações
 */
function wc_allpost_cache_quote($cep, $volumes, $response) {
    $cache_key = 'allpost_quote_' . md5($cep . serialize($volumes));
    set_transient($cache_key, $response, 30 * MINUTE_IN_SECONDS);
}

function wc_allpost_get_cached_quote($cep, $volumes) {
    $cache_key = 'allpost_quote_' . md5($cep . serialize($volumes));
    return get_transient($cache_key);
}

/**
 * Função para debug avançado
 */
function wc_allpost_structured_log($level, $message, $context = array()) {
    if (get_option('wc_allpost_debug', 'no') === 'yes') {
        $logger = wc_get_logger();
        
        $context['timestamp'] = current_time('Y-m-d H:i:s');
        $context['user_id'] = get_current_user_id();
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $logger->{$level}($message, array_merge(array('source' => 'allpost'), $context));
    }
}