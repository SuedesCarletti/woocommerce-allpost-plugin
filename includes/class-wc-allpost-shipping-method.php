<?php
/**
 * Método de envio AllPost para WooCommerce - VERSÃO COMPLETA E CORRIGIDA
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_AllPost_Shipping_Method extends WC_Shipping_Method {
    
    /**
     * Instância da API
     */
    private $api;
    
    /**
     * Construtor
     */
    public function __construct($instance_id = 0) {
        $this->id = 'allpost';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('AllPost', 'wc-allpost');
        $this->method_description = __('Cotação de frete através da AllPost', 'wc-allpost');
        
        $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );
        
        $this->init();
        
        $this->api = new WC_AllPost_API();
    }
    
    /**
     * Inicializar configurações
     */
    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        
        $this->title = $this->get_option('title');
        $this->enabled = $this->get_option('enabled');
        
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }
    
    /**
     * Definir campos do formulário
     */
    public function init_form_fields() {
        $this->instance_form_fields = array(
            'enabled' => array(
                'title' => __('Ativar/Desativar', 'wc-allpost'),
                'type' => 'checkbox',
                'label' => __('Ativar este método de envio', 'wc-allpost'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Título', 'wc-allpost'),
                'type' => 'text',
                'description' => __('Título que aparecerá no checkout', 'wc-allpost'),
                'default' => __('AllPost', 'wc-allpost'),
                'desc_tip' => true
            ),
            'show_allpost_prefix' => array(
                'title' => __('Mostrar prefixo "AllPost"', 'wc-allpost'),
                'type' => 'checkbox',
                'label' => __('Exibir "AllPost - " antes do nome da transportadora', 'wc-allpost'),
                'default' => 'no',
                'description' => __('Se desmarcado, mostrará apenas "Transportadora - Método"', 'wc-allpost')
            ),
            'debug' => array(
                'title' => __('Debug', 'wc-allpost'),
                'type' => 'checkbox',
                'label' => __('Ativar logs de debug', 'wc-allpost'),
                'default' => 'no',
                'description' => __('Ativa logs detalhados para debug', 'wc-allpost')
            ),
            'fallback_shipping' => array(
                'title' => __('Frete de contingência', 'wc-allpost'),
                'type' => 'price',
                'description' => __('Valor de frete caso a cotação falhe', 'wc-allpost'),
                'default' => '15.00',
                'desc_tip' => true
            ),
            'free_shipping_min' => array(
                'title' => __('Frete grátis a partir de', 'wc-allpost'),
                'type' => 'price',
                'description' => __('Valor mínimo para frete grátis (deixe 0 para desativar)', 'wc-allpost'),
                'default' => '0',
                'desc_tip' => true
            ),
            'additional_days' => array(
                'title' => __('Dias adicionais', 'wc-allpost'),
                'type' => 'number',
                'description' => __('Dias adicionais no prazo de entrega', 'wc-allpost'),
                'default' => '0',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '0',
                    'step' => '1'
                )
            ),
            'show_delivery_time' => array(
                'title' => __('Mostrar prazo de entrega', 'wc-allpost'),
                'type' => 'checkbox',
                'label' => __('Exibir prazo de entrega no checkout', 'wc-allpost'),
                'default' => 'yes'
            ),
            'sort_by' => array(
                'title' => __('Ordenar por', 'wc-allpost'),
                'type' => 'select',
                'description' => __('Como ordenar as opções de frete', 'wc-allpost'),
                'default' => 'price',
                'options' => array(
                    'price' => __('Menor preço', 'wc-allpost'),
                    'time' => __('Menor prazo', 'wc-allpost'),
                    'carrier' => __('Nome da transportadora', 'wc-allpost')
                ),
                'desc_tip' => true
            )
        );
    }
    
    /**
     * Calcular frete
     */
    public function calculate_shipping($package = array()) {
        // Verificar se está habilitado
        if ($this->enabled !== 'yes') {
            return;
        }
        
        // Verificar se há produtos no carrinho
        if (empty($package['contents'])) {
            return;
        }
        
        // Obter CEP de destino e limpar formatação - ACEITA QUALQUER FORMATO
        $destination_postcode = isset($package['destination']['postcode']) ? 
            $this->clean_postcode($package['destination']['postcode']) : '';
        
        if (empty($destination_postcode) || strlen($destination_postcode) !== 8) {
            $this->add_debug_log('CEP inválido: ' . $destination_postcode);
            return;
        }
        
        // Verificar frete grátis
        $cart_total = WC()->cart->get_displayed_subtotal();
        $free_shipping_min = floatval($this->get_option('free_shipping_min', 0));
        
        if ($free_shipping_min > 0 && $cart_total >= $free_shipping_min) {
            $this->add_rate(array(
                'id' => $this->id . '_free',
                'label' => $this->format_shipping_label('', '', 'Frete Grátis'),
                'cost' => 0,
                'meta_data' => array(
                    'delivery_time' => __('Frete grátis', 'wc-allpost')
                )
            ));
            return;
        }
        
        // Converter produtos para volumes
        $volumes = $this->convert_package_to_volumes($package);
        
        if (empty($volumes)) {
            $this->add_debug_log('Nenhum volume válido encontrado');
            return;
        }
        
        // Realizar cotação
        $cotacao = $this->api->cotacao($destination_postcode, $volumes);
        
        if (is_wp_error($cotacao)) {
            $this->add_debug_log('Erro na cotação: ' . $cotacao->get_error_message());
            $this->add_fallback_shipping();
            return;
        }
        
        // Processar opções de entrega
        if (isset($cotacao['opcoesEntrega']) && is_array($cotacao['opcoesEntrega'])) {
            $this->process_shipping_options($cotacao['opcoesEntrega'], $cotacao['retorno']['idCotacao']);
        } else {
            $this->add_debug_log('Nenhuma opção de entrega encontrada');
            $this->add_fallback_shipping();
        }
    }
    
    /**
     * Limpar CEP removendo pontos, hífens e espaços
     */
    private function clean_postcode($postcode) {
        return preg_replace('/[^0-9]/', '', $postcode);
    }
    
    /**
     * Formatar label do frete
     */
    private function format_shipping_label($carrier_name, $method_name, $special_label = '') {
        // Se há um label especial (como "Frete Grátis"), usar ele
        if (!empty($special_label)) {
            return $special_label;
        }
        
        $show_prefix = $this->get_option('show_allpost_prefix', 'no') === 'yes';
        $label_parts = array();
        
        // Adicionar prefixo AllPost se configurado
        if ($show_prefix) {
            $label_parts[] = 'AllPost';
        }
        
        // Adicionar nome da transportadora
        if (!empty($carrier_name)) {
            $label_parts[] = $carrier_name;
        }
        
        // Adicionar método de envio
        if (!empty($method_name)) {
            $label_parts[] = '(' . $method_name . ')';
        }
        
        // Se não temos transportadora nem método, usar o título padrão
        if (empty($carrier_name) && empty($method_name)) {
            $label_parts[] = $this->title;
        }
        
        return implode(' - ', array_filter($label_parts));
    }
    
    /**
     * Converter produtos do carrinho para volumes AllPost
     */
    private function convert_package_to_volumes($package) {
        $volumes = array();
        
        foreach ($package['contents'] as $item_id => $item) {
            $product = $item['data'];
            
            if (!$product || !$product->needs_shipping()) {
                continue;
            }
            
            $volume = $this->api->convert_product_to_volume($product, $item['quantity']);
            $volumes[] = $volume;
        }
        
        return $volumes;
    }
    
    /**
     * Processar opções de entrega da API
     */
    private function process_shipping_options($opcoes, $id_cotacao) {
        $shipping_rates = array();
        
        foreach ($opcoes as $opcao) {
            if (!isset($opcao['freteCobrar']) || !isset($opcao['dadosFrete'])) {
                continue;
            }
            
            $shipping_cost = floatval($opcao['freteCobrar']);
            $delivery_days = isset($opcao['prazoEntregaTotal']) ? intval($opcao['prazoEntregaTotal']) : 0;
            $additional_days = intval($this->get_option('additional_days', 0));
            $total_days = $delivery_days + $additional_days;
            
            $carrier_name = $opcao['dadosFrete']['transportadoraNome'] ?? '';
            $method_name = $opcao['dadosFrete']['metodoEnvioNome'] ?? '';
            
            $label = $this->format_shipping_label($carrier_name, $method_name);
            
            // Adicionar prazo de entrega no label se habilitado
            if ($this->get_option('show_delivery_time', 'yes') === 'yes' && $total_days > 0) {
                $label .= ' - ' . sprintf(_n('%d dia útil', '%d dias úteis', $total_days, 'wc-allpost'), $total_days);
            }
            
            $rate_id = $this->id . '_' . sanitize_title($carrier_name . '_' . $method_name);
            
            $shipping_rates[] = array(
                'id' => $rate_id,
                'label' => $label,
                'cost' => $shipping_cost,
                'delivery_days' => $total_days,
                'carrier_name' => $carrier_name,
                'meta_data' => array(
                    'allpost_cotacao_id' => $id_cotacao,
                    'allpost_filial' => $opcao['dadosFrete']['filial'] ?? '',
                    'allpost_transportadora_id' => $opcao['dadosFrete']['idTransportadora'] ?? '',
                    'allpost_transportadora' => $carrier_name,
                    'allpost_metodo_id' => $opcao['dadosFrete']['idMetodoEnvio'] ?? '',
                    'allpost_metodo' => $method_name,
                    'delivery_time' => $total_days
                )
            );
        }
        
        // Ordenar as opções conforme configuração
        $this->sort_shipping_rates($shipping_rates);
        
        // Adicionar as opções ordenadas
        foreach ($shipping_rates as $rate) {
            $this->add_rate($rate);
        }
    }
    
    /**
     * Ordenar opções de frete
     */
    private function sort_shipping_rates(&$shipping_rates) {
        $sort_by = $this->get_option('sort_by', 'price');
        
        switch ($sort_by) {
            case 'price':
                usort($shipping_rates, function($a, $b) {
                    return $a['cost'] <=> $b['cost'];
                });
                break;
                
            case 'time':
                usort($shipping_rates, function($a, $b) {
                    return $a['delivery_days'] <=> $b['delivery_days'];
                });
                break;
                
            case 'carrier':
                usort($shipping_rates, function($a, $b) {
                    return strcmp($a['carrier_name'], $b['carrier_name']);
                });
                break;
        }
    }
    
    /**
     * Adicionar frete de contingência
     */
    private function add_fallback_shipping() {
        $fallback_cost = floatval($this->get_option('fallback_shipping', 15));
        
        if ($fallback_cost > 0) {
            $label = $this->format_shipping_label('', '', 'Frete Padrão');
            
            $this->add_rate(array(
                'id' => $this->id . '_fallback',
                'label' => $label,
                'cost' => $fallback_cost,
                'meta_data' => array(
                    'delivery_time' => __('A consultar', 'wc-allpost')
                )
            ));
        }
    }
    
    /**
     * Adicionar log de debug
     */
    private function add_debug_log($message) {
        if ($this->get_option('debug', 'no') === 'yes') {
            $logger = wc_get_logger();
            $logger->debug($message, array('source' => 'allpost-shipping'));
        }
    }
    
    /**
     * Validar configurações administrativas
     */
    public function validate_additional_days_field($key, $value) {
        if (is_numeric($value) && $value >= 0) {
            return intval($value);
        }
        return 0;
    }
}

/**
 * Script para melhorar validação de CEP no checkout
 */
add_action('wp_footer', 'wc_allpost_checkout_scripts');

function wc_allpost_checkout_scripts() {
    if (!is_checkout()) {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Limpar CEP automaticamente ao digitar
        $(document).on('input', '#shipping_postcode, #billing_postcode', function() {
            let cep = $(this).val();
            
            // Remover caracteres não numéricos temporariamente para validação
            let cleanCep = cep.replace(/[^0-9]/g, '');
            
            // Se tem 8 dígitos, é válido - deixar o usuário formatar como quiser
            if (cleanCep.length === 8) {
                $(this).removeClass('woocommerce-invalid');
            }
            
            // Disparar atualização do checkout após pequeno delay
            clearTimeout(window.cepTimeout);
            window.cepTimeout = setTimeout(function() {
                $('body').trigger('update_checkout');
            }, 800);
        });
        
        // Interceptar submissão para garantir que CEP está limpo internamente
        $(document).on('checkout_place_order', function() {
            // Não alterar visualmente, apenas garantir que o processamento seja correto
            return true;
        });
    });
    </script>
    <?php
}