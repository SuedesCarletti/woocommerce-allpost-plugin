<?php
/**
 * Classe para gerenciar webhooks AllPost
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_AllPost_Webhook {

    /**
     * Tamanho máximo aceito para o corpo do webhook
     */
    const MAX_BODY_BYTES = 1048576;

    /**
     * Segredo do webhook. Gerado na primeira utilização e guardado em wp_options.
     */
    public static function get_secret() {
        $secret = get_option('wc_allpost_webhook_token', '');
        if (empty($secret)) {
            $secret = wp_generate_password(40, false, false);
            update_option('wc_allpost_webhook_token', $secret, false);
        }
        return $secret;
    }

    /**
     * Confere o segredo enviado em ?token= ou no cabeçalho X-Allpost-Token.
     */
    private function is_authorized_request() {
        $sent = '';
        if (isset($_GET['token'])) {
            $sent = sanitize_text_field(wp_unslash($_GET['token']));
        } elseif (isset($_SERVER['HTTP_X_ALLPOST_TOKEN'])) {
            $sent = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ALLPOST_TOKEN']));
        }
        return $sent !== '' && hash_equals(self::get_secret(), $sent);
    }

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
        // Registrar endpoint para webhook
        add_action('init', array($this, 'add_webhook_endpoint'));
        add_action('template_redirect', array($this, 'handle_webhook_request'));
        
        // Adicionar variável de query
        add_filter('query_vars', array($this, 'add_query_vars'));
    }
    
    /**
     * Adicionar endpoint para webhook
     */
    public function add_webhook_endpoint() {
        add_rewrite_rule(
            '^allpost-webhook/?$',
            'index.php?allpost_webhook=1',
            'top'
        );
    }
    
    /**
     * Adicionar variáveis de query
     */
    public function add_query_vars($vars) {
        $vars[] = 'allpost_webhook';
        return $vars;
    }
    
    /**
     * Processar requisição do webhook
     */
    public function handle_webhook_request() {
        if (!get_query_var('allpost_webhook')) {
            return;
        }
        
        // Verificar se é uma requisição POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->send_response(405, array('retorno' => 'Método não permitido'));
            return;
        }
        
        // Autenticar a origem antes de ler o corpo
        if (!$this->is_authorized_request()) {
            $this->send_response(403, array('retorno' => 'Não autorizado'));
            return;
        }

        // Obter dados do corpo da requisição (limite de 1 MB)
        $raw_body = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);

        if (strlen((string) $raw_body) > self::MAX_BODY_BYTES) {
            $this->send_response(413, array('retorno' => 'Body muito grande'));
            return;
        }

        if (empty($raw_body)) {
            $this->send_response(400, array('retorno' => 'Body vazio'));
            return;
        }
        
        // Decodificar JSON
        $data = json_decode($raw_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->send_response(400, array('retorno' => 'JSON inválido'));
            return;
        }
        
        // Log do webhook recebido
        $this->log_webhook($data, $raw_body);
        
        // Processar webhook baseado no tipo
        $result = $this->process_webhook($data);
        
        if ($result) {
            $this->send_response(200, array('retorno' => 'sucesso'));
        } else {
            $this->send_response(400, array('retorno' => 'Erro no processamento'));
        }
    }
    
    /**
     * Processar webhook baseado no tipo
     */
    private function process_webhook($data) {
        if (!isset($data['tipo']) || !isset($data['dados'])) {
            return false;
        }
        
        $tipo = $data['tipo'];
        $dados = $data['dados'];
        
        switch ($tipo) {
            case 'Rastreio':
                return $this->process_tracking_webhook($dados);
                
            case 'RastreioEventos':
                return $this->process_tracking_events_webhook($dados);
                
            case 'prazoAdicional':
                return $this->process_deadline_webhook($dados);
                
            case 'Aplicativo':
                return $this->process_app_webhook($dados);
                
            case 'metodoEnvio':
                return $this->process_shipping_method_webhook($dados);
                
            default:
                // Para tipos não implementados, retornar sucesso para evitar reenvios
                return true;
        }
    }
    
    /**
     * Processar webhook de rastreamento
     */
    private function process_tracking_webhook($dados) {
        if (!isset($dados['numeroPedido'])) {
            return false;
        }
        
        $order_id = intval($dados['numeroPedido']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return false;
        }
        
        // Atualizar status nos metadados do pedido
        $this->update_order_meta($order_id, $dados);
        
        // Processar baseado na situação
        $situacao = isset($dados['situacao']) ? $dados['situacao'] : '';
        $mensagem = isset($dados['mensagem']) ? $dados['mensagem'] : '';
        $info_adicional = isset($dados['infAdicional']) ? $dados['infAdicional'] : '';
        
        switch ($situacao) {
            case 'entregue':
                $this->handle_delivered_order($order, $dados);
                break;
                
            case 'postado':
                $this->handle_posted_order($order, $dados);
                break;
                
            case 'em transito':
                $this->handle_in_transit_order($order, $dados);
                break;
                
            case 'saiu para entrega':
                $this->handle_out_for_delivery_order($order, $dados);
                break;
                
            case 'ocorrencia':
                $this->handle_occurrence_order($order, $dados);
                break;
                
            default:
                // Adicionar nota genérica
                $note = sprintf(
                    __('AllPost - Status: %s. %s', 'wc-allpost'),
                    $situacao,
                    $mensagem
                );
                $order->add_order_note($note);
                break;
        }
        
        return true;
    }
    
    /**
     * Processar webhook de eventos de rastreamento
     */
    private function process_tracking_events_webhook($dados) {
        if (!isset($dados['numeroPedido'])) {
            return false;
        }
        
        $order_id = intval($dados['numeroPedido']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return false;
        }
        
        $mensagem = isset($dados['mensagem']) ? $dados['mensagem'] : '';
        $info_adicional = isset($dados['infAdicional']) ? $dados['infAdicional'] : '';
        
        $note = sprintf(
            __('AllPost - Evento: %s. %s', 'wc-allpost'),
            $mensagem,
            $info_adicional
        );
        
        $order->add_order_note($note);
        
        return true;
    }
    
    /**
     * Processar webhook de prazo adicional
     */
    private function process_deadline_webhook($dados) {
        // Log para análise futura de prazos
        $this->log_deadline_update($dados);
        return true;
    }
    
    /**
     * Processar webhook de aplicativo
     */
    private function process_app_webhook($dados) {
        // Log para controle de instalação/desinstalação
        if (isset($dados['situacao']) && $dados['situacao'] === 'delete') {
            $this->log_app_uninstall($dados);
        }
        return true;
    }
    
    /**
     * Processar webhook de método de envio
     */
    private function process_shipping_method_webhook($dados) {
        // Log para controle de alterações nos métodos
        $this->log_shipping_method_update($dados);
        return true;
    }
    
    /**
     * Lidar com pedido entregue
     */
    private function handle_delivered_order($order, $dados) {
        // Mudar status para entregue se disponível
        if ($order->get_status() !== 'completed') {
            $order->update_status('completed', __('Pedido entregue conforme AllPost', 'wc-allpost'));
        }
        
        $note = sprintf(
            __('AllPost - Pedido entregue. %s', 'wc-allpost'),
            isset($dados['mensagem']) ? $dados['mensagem'] : ''
        );
        $order->add_order_note($note);
        
        // Disparar ação customizada
        do_action('wc_allpost_order_delivered', $order, $dados);
    }
    
    /**
     * Lidar com pedido postado
     */
    private function handle_posted_order($order, $dados) {
        $note = sprintf(
            __('AllPost - Pedido postado. %s', 'wc-allpost'),
            isset($dados['mensagem']) ? $dados['mensagem'] : ''
        );
        $order->add_order_note($note);
        
        // Enviar email de confirmação de postagem se configurado
        if (get_option('wc_allpost_send_posted_email', 'no') === 'yes') {
            WC()->mailer()->get_emails()['WC_Email_Customer_Completed_Order']->trigger($order->get_id());
        }
        
        do_action('wc_allpost_order_posted', $order, $dados);
    }
    
    /**
     * Lidar com pedido em trânsito
     */
    private function handle_in_transit_order($order, $dados) {
        $note = sprintf(
            __('AllPost - Pedido em trânsito. %s', 'wc-allpost'),
            isset($dados['mensagem']) ? $dados['mensagem'] : ''
        );
        $order->add_order_note($note);
        
        do_action('wc_allpost_order_in_transit', $order, $dados);
    }
    
    /**
     * Lidar com pedido saiu para entrega
     */
    private function handle_out_for_delivery_order($order, $dados) {
        $note = sprintf(
            __('AllPost - Saiu para entrega. %s', 'wc-allpost'),
            isset($dados['mensagem']) ? $dados['mensagem'] : ''
        );
        $order->add_order_note($note);
        
        do_action('wc_allpost_order_out_for_delivery', $order, $dados);
    }
    
    /**
     * Lidar com ocorrência no pedido
     */
    private function handle_occurrence_order($order, $dados) {
        $note = sprintf(
            __('AllPost - Ocorrência: %s. %s', 'wc-allpost'),
            isset($dados['mensagem']) ? $dados['mensagem'] : '',
            isset($dados['infAdicional']) ? $dados['infAdicional'] : ''
        );
        $order->add_order_note($note);
        
        // Se for uma ocorrência crítica, pode mudar status
        $id_ocoren = isset($dados['idOcoren']) ? intval($dados['idOcoren']) : 0;
        if ($id_ocoren >= 3 && $id_ocoren <= 92) {
            // Marcar como problema na entrega
            $order->update_meta_data('_allpost_delivery_issue', true);
            $order->save();
        }
        
        do_action('wc_allpost_order_occurrence', $order, $dados);
    }
    
    /**
     * Atualizar metadados do pedido
     */
    private function update_order_meta($order_id, $dados) {
        if (isset($dados['situacao'])) {
            update_post_meta($order_id, '_allpost_status', sanitize_text_field($dados['situacao']));
        }
        
        if (isset($dados['codigoRastreio'])) {
            update_post_meta($order_id, '_allpost_tracking_code', sanitize_text_field($dados['codigoRastreio']));
        }
        
        // Log da atualização
        $this->log_status_update($order_id, $dados);
    }
    
    /**
     * Log de atualização de status
     */
    private function log_status_update($order_id, $dados) {
        if (get_option('wc_allpost_debug', 'no') === 'yes') {
            $logger = wc_get_logger();
            $logger->info(
                'Status atualizado via webhook',
                array(
                    'source' => 'allpost-webhook',
                    'order_id' => $order_id,
                    'situacao' => $dados['situacao'] ?? '',
                    'dados' => $dados
                )
            );
        }
    }
    
    /**
     * Enviar resposta HTTP
     */
    private function send_response($status_code, $data) {
        status_header($status_code);
        header('Content-Type: application/json; charset=UTF-8');
        echo wp_json_encode($data);
        exit;
    }
    
    /**
     * Log do webhook
     */
    private function log_webhook($data, $raw_body) {
        if (get_option('wc_allpost_debug', 'no') === 'yes') {
            $logger = wc_get_logger();
            $logger->info(
                'Webhook recebido',
                array(
                    'source' => 'allpost-webhook',
                    'data' => $data,
                    'raw_body' => $raw_body
                )
            );
        }
    }
    
    /**
     * Log de atualização de prazo
     */
    private function log_deadline_update($dados) {
        $logger = wc_get_logger();
        $logger->info(
            'Atualização de prazo recebida',
            array(
                'source' => 'allpost-webhook',
                'data' => $dados
            )
        );
    }
    
    /**
     * Log de desinstalação
     */
    private function log_app_uninstall($dados) {
        $logger = wc_get_logger();
        $logger->warning(
            'Aplicativo desinstalado',
            array(
                'source' => 'allpost-webhook',
                'data' => $dados
            )
        );
    }
    
    /**
     * Log de atualização de método de envio
     */
    private function log_shipping_method_update($dados) {
        $logger = wc_get_logger();
        $logger->info(
            'Método de envio atualizado',
            array(
                'source' => 'allpost-webhook',
                'data' => $dados
            )
        );
    }
    
    /**
     * Obter URL do webhook
     */
    public static function get_webhook_url() {
        return add_query_arg('token', self::get_secret(), home_url('/allpost-webhook/'));
    }
}