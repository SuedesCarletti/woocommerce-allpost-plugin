<?php
/**
 * Classe para comunicação com a API AllPost
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_AllPost_API {
    
    /**
     * URL base da API
     */
    const API_BASE_URL = 'https://www.allpost.com.br/api/v1/';
    
    /**
     * Token de cotação
     */
    private $quote_token;
    
    /**
     * Token da API
     */
    private $api_token;
    
    /**
     * Canal padrão
     */
    private $channel;
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->quote_token = get_option('wc_allpost_quote_token', '');
        $this->api_token = get_option('wc_allpost_api_token', '');
        $this->channel = get_option('wc_allpost_channel', 'WooCommerce');
    }
    
    /**
     * Realizar cotação de frete
     *
     * @param string $cep_destino
     * @param array $volumes
     * @return array|WP_Error
     */
    public function cotacao($cep_destino, $volumes) {
        $url = self::API_BASE_URL . 'cotacao';
        
        $body = array(
            'cepDestino' => $cep_destino,
            'canal' => $this->channel,
            'volumes' => $volumes
        );
        
        $response = $this->make_request('POST', $url, $body, $this->quote_token);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Log da cotação para debug
        $this->log_quotation($response);
        
        return $response;
    }
    
    /**
     * Obter dados de uma cotação específica
     *
     * @param string $id_cotacao
     * @return array|WP_Error
     */
    public function get_cotacao($id_cotacao) {
        $url = self::API_BASE_URL . 'cotacao/' . $id_cotacao;
        
        return $this->make_request('GET', $url, null, $this->quote_token);
    }
    
    /**
     * Criar novo pedido
     *
     * @param array $pedido_data
     * @return array|WP_Error
     */
    public function criar_pedido($pedido_data) {
        $url = self::API_BASE_URL . 'pedido/novo';
        
        $response = $this->make_request('POST', $url, $pedido_data, $this->api_token);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Log do pedido criado
        $this->log_shipment($pedido_data, $response);
        
        return $response;
    }
    
    /**
     * Fazer requisição para a API
     *
     * @param string $method
     * @param string $url
     * @param array|null $body
     * @param string $token
     * @return array|WP_Error
     */
    private function make_request($method, $url, $body = null, $token = '') {
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        );
        
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        );
        
        if ($body && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = wp_json_encode($body);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $this->log_error('Request failed', array(
                'url' => $url,
                'method' => $method,
                'error' => $response->get_error_message()
            ));
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body_response = wp_remote_retrieve_body($response);
        
        // Log da requisição para debug
        $this->log_request($method, $url, $body, $status_code, $body_response);
        
        if ($status_code >= 400) {
            $error_data = json_decode($body_response, true);
            $error_message = isset($error_data['mensagem']) ? $error_data['mensagem'] : 'Erro na API';
            
            return new WP_Error(
                'allpost_api_error',
                $error_message,
                array('status' => $status_code, 'response' => $error_data)
            );
        }
        
        $decoded_response = json_decode($body_response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'allpost_json_error',
                'Erro ao decodificar resposta JSON',
                array('response' => $body_response)
            );
        }
        
        return $decoded_response;
    }
    
    /**
     * Log de cotação para debug
     *
     * @param array $response
     */
    private function log_quotation($response) {
        if (get_option('wc_allpost_debug', 'no') === 'yes') {
            $logger = wc_get_logger();
            $logger->info(
                'Cotação realizada com sucesso',
                array(
                    'source' => 'allpost-cotacao',
                    'cotacao_id' => $response['retorno']['idCotacao'] ?? '',
                    'opcoes_entrega' => count($response['opcoesEntrega'] ?? []),
                    'response' => $response
                )
            );
        }
    }
    
    /**
     * Log de envio criado
     *
     * @param array $pedido_data
     * @param array $response
     */
    private function log_shipment($pedido_data, $response) {
        $logger = wc_get_logger();
        $logger->info(
            'Envio criado na AllPost',
            array(
                'source' => 'allpost-envio',
                'order_id' => $pedido_data['numeroPedido'],
                'chave_pedido' => $response['chavePedido'] ?? '',
                'response' => $response
            )
        );
    }
    
    /**
     * Converter produto WooCommerce para formato AllPost
     *
     * @param WC_Product $product
     * @param int $quantity
     * @return array
     */
    public function convert_product_to_volume($product, $quantity = 1) {
        $volume = array(
            'sku' => $product->get_sku() ?: $product->get_id(),
            'quantidade' => $quantity,
            'valorUnitario' => floatval($product->get_price()),
            'peso' => floatval($product->get_weight()) ?: 0.1, // Peso mínimo se não informado
            'altura' => floatval($product->get_height()) ?: 5, // Dimensões mínimas
            'largura' => floatval($product->get_width()) ?: 15,
            'profundidade' => floatval($product->get_length()) ?: 10
        );
        
        return $volume;
    }
    
    /**
     * Converter pedido WooCommerce para formato AllPost
     *
     * @param WC_Order $order
     * @param string $id_cotacao
     * @return array
     */
    public function convert_order_to_allpost($order, $id_cotacao = '') {
        $shipping_address = $order->get_address('shipping');
        if (empty($shipping_address['first_name'])) {
            $shipping_address = $order->get_address('billing');
        }
        
        // Preparar produtos
        $produtos = array();
        $envio_items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            $produto = array(
                'peso' => floatval($product->get_weight()) ?: 0.1,
                'altura' => floatval($product->get_height()) ?: 5,
                'largura' => floatval($product->get_width()) ?: 15,
                'profundidade' => floatval($product->get_length()) ?: 10,
                'preco' => $item->get_quantity() > 0 ? floatval($item->get_total() / $item->get_quantity()) : 0,
                'titulo' => $product->get_name(),
                'sku' => $product->get_sku() ?: $product->get_id(),
                'categoria' => $this->get_product_category($product),
                'quantidade' => $item->get_quantity(),
                'quantidadeVolumes' => 1
            );
            
            $produtos[] = $produto;
        }
        
        if (empty($produtos)) {
            return new WP_Error('allpost_no_items', 'Pedido sem produtos válidos para envio.');
        }

        // Calcular totais
        $peso_total = array_sum(array_column($produtos, 'peso'));
        $altura_total = max(array_column($produtos, 'altura'));
        $largura_total = max(array_column($produtos, 'largura'));
        $profundidade_total = array_sum(array_column($produtos, 'profundidade'));
        
        $envio = array(
            'nome' => 'Envio Pedido #' . $order->get_order_number(),
            'peso' => $peso_total,
            'altura' => $altura_total,
            'largura' => $largura_total,
            'profundidade' => $profundidade_total,
            'quantidade' => $order->get_item_count(),
            'quantidadeVolumes' => 1,
            'prazoDeEntrega' => date('Y-m-d', strtotime('+7 days')), // Prazo padrão
            'produtos' => $produtos,
            'notaFiscal' => array(
                'filialERP' => get_option('wc_allpost_filial_erp', '1'),
                'pedidoERP' => $order->get_order_number(),
                'chave' => '', // Deve ser preenchido com a chave da NF-e quando disponível
                'data' => $order->get_date_created()->date('Y-m-d'),
                'valorTotal' => floatval($order->get_total()),
                'valorTotalProdutos' => floatval($order->get_subtotal())
            )
        );
        
        $pedido = array(
            'numeroPedido' => $order->get_id(),
            'tipoPedido' => 'envio',
            'pedidoAuxiliar' => $order->get_order_number(),
            'plataforma' => 'WooCommerce',
            'canal' => $this->channel,
            'idCotacao' => $id_cotacao,
            'valorFretePedido' => floatval($order->get_shipping_total()),
            'enderecoEntrega' => array(
                'nome' => $shipping_address['first_name'] . ' ' . $shipping_address['last_name'],
                'email' => $order->get_billing_email(),
                'cpfCnpj' => $this->extract_document($order),
                'cep' => preg_replace('/\D/', '', $shipping_address['postcode']),
                'uf' => $shipping_address['state'],
                'cidade' => $shipping_address['city'],
                'endereco' => $shipping_address['address_1'],
                'numero' => $this->extract_number($shipping_address['address_1']),
                'bairro' => $shipping_address['address_2'] ?: 'Centro',
                'referencia' => '',
                'telefones' => array($this->format_phone($order->get_billing_phone()))
            ),
            'envio' => array($envio)
        );
        
        return $pedido;
    }
    
    /**
     * Extrair categoria principal do produto
     */
    private function get_product_category($product) {
        $categories = get_the_terms($product->get_id(), 'product_cat');
        if ($categories && !is_wp_error($categories)) {
            return $categories[0]->name;
        }
        return 'Produto';
    }
    
    /**
     * Extrair documento (CPF/CNPJ) do pedido
     */
    private function extract_document($order) {
        // Tentar extrair de meta fields comuns
        $document = $order->get_meta('_billing_cpf');
        if (empty($document)) {
            $document = $order->get_meta('_billing_cnpj');
        }
        if (empty($document)) {
            $document = $order->get_meta('billing_document');
        }
        
        // Mantém letras: o CNPJ alfanumérico (a partir de jul/2026) não pode ser reduzido a dígitos.
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $document));
    }
    
    /**
     * Extrair número do endereço
     */
    private function extract_number($address) {
        preg_match('/\d+/', $address, $matches);
        return $matches[0] ?? 'S/N';
    }
    
    /**
     * Formatar telefone
     */
    private function format_phone($phone) {
        return preg_replace('/\D/', '', $phone);
    }
    
    /**
     * Log de requisições para debug
     */
    private function log_request($method, $url, $body, $status_code, $response) {
        if (get_option('wc_allpost_debug', 'no') === 'yes') {
            $logger = wc_get_logger();
            $logger->info(
                sprintf('[%s] %s - Status: %d', $method, $url, $status_code),
                array(
                    'source' => 'allpost',
                    'request_body' => $body,
                    'response_body' => $response
                )
            );
        }
    }
    
    /**
     * Log de erros
     */
    private function log_error($message, $data = array()) {
        $logger = wc_get_logger();
        $logger->error($message, array_merge(array('source' => 'allpost'), $data));
    }
}
