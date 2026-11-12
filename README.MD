# WooCommerce AllPost

Plugin para integração completa entre WooCommerce e AllPost, oferecendo cotação de frete, criação automática de envios e rastreamento através de webhooks.

## Funcionalidades

### ✅ Cotação de Frete
- Cotação automática no checkout
- Múltiplas opções de transportadoras
- Exibição de prazos de entrega
- Frete grátis configurável
- Frete de contingência quando API não responde

### ✅ Gestão de Envios
- Criação automática de envios na AllPost
- Integração com status de pedidos WooCommerce
- Metabox no admin para gestão manual
- Rastreamento completo

### ✅ Webhooks
- Recebimento automático de atualizações de status
- Atualização automática de pedidos
- Suporte a todos os tipos de webhook AllPost
- Logs detalhados para debug

### ✅ Interface Administrativa
- Painel de configuração completo
- Teste de conectividade
- Visualização de logs
- Estatísticas no dashboard
- URL do webhook pré-configurada

## Requisitos

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- Conta ativa na AllPost

## Instalação

### Método 1: Upload Manual

1. Faça download dos arquivos do plugin
2. Crie a seguinte estrutura de pastas no seu WordPress:

```
wp-content/plugins/woocommerce-allpost/
├── woocommerce-allpost.php (arquivo principal)
├── includes/
│   ├── class-wc-allpost-api.php
│   ├── class-wc-allpost-shipping-method.php
│   ├── class-wc-allpost-admin.php
│   ├── class-wc-allpost-webhook.php
│   ├── class-wc-allpost-order.php
│   └── functions.php
└── README.md
```

3. Ative o plugin no painel do WordPress
4. Configure suas credenciais AllPost

### Método 2: Desenvolvimento

1. Clone ou baixe os arquivos
2. Coloque na pasta `wp-content/plugins/woocommerce-allpost/`
3. Ative no painel do WordPress

## Configuração

### 1. Obter Credenciais AllPost

1. Acesse o painel de desenvolvedor AllPost
2. Crie uma nova aplicação
3. Anote os tokens:
   - **Token de Cotação**: Para cotações (sem limite)
   - **Token da API**: Para operações (com limite)

### 2. Configurar Plugin

1. Vá em **WooCommerce > AllPost**
2. Preencha os campos obrigatórios:
   - Token de Cotação
   - Token da API
   - Canal (ex: "WooCommerce")
   - Código da Filial ERP

### 3. Configurar Webhook na AllPost

1. No painel AllPost, vá em configurações de webhook
2. Configure a URL: `https://seusite.com/allpost-webhook/?token=SEGREDO`
3. A URL completa, já com o segredo gerado pelo plugin, aparece no painel do plugin. Requisições sem o segredo (em `?token=` ou no cabeçalho `X-Allpost-Token`) recebem 403, e corpos acima de 1 MB recebem 413.

### 4. Configurar Método de Envio

1. Vá em **WooCommerce > Configurações > Envio**
2. Adicione uma zona de envio
3. Adicione o método "AllPost"
4. Configure as opções desejadas:
   - Frete grátis a partir de valor X
   - Dias adicionais no prazo
   - Frete de contingência

## Estrutura dos Arquivos

### Arquivo Principal
```php
woocommerce-allpost.php
```
- Inicialização do plugin
- Verificação de dependências
- Carregamento das classes
- Criação de tabelas

### Classes Principais

#### `WC_AllPost_API`
- Comunicação com API AllPost
- Métodos para cotação e criação de pedidos
- Conversão de dados WooCommerce ↔ AllPost
- Tratamento de erros e logs

#### `WC_AllPost_Shipping_Method`
- Método de envio para WooCommerce
- Cálculo de frete no checkout
- Configurações por zona de envio
- Fallback quando API falha

#### `WC_AllPost_Order`
- Gestão de pedidos e envios
- Criação automática baseada em status
- Metabox no admin
- Integração com rastreamento

#### `WC_AllPost_Webhook`
- Recebimento de webhooks AllPost
- Processamento de diferentes tipos
- Atualização automática de status
- Endpoint: `/allpost-webhook/`

#### `WC_AllPost_Admin`
- Painel administrativo
- Configurações do plugin
- Teste de conectividade
- Interface de gerenciamento

## Uso

### Cotação Automática

A cotação acontece automaticamente no checkout quando:
- Cliente informa CEP válido
- Produtos têm dimensões/peso configurados
- Tokens estão configurados corretamente

### Criação de Envios

Os envios são criados automaticamente quando:
- Pedido muda para status configurado (ex: "Processando")
- Método de envio é AllPost
- Dados obrigatórios estão preenchidos

### Rastreamento

O rastreamento é disponibilizado:
- Na página "Minha Conta" do cliente
- Via shortcode `[allpost_tracking order_id="123"]`
- No email de confirmação
- No admin do pedido

## Shortcodes

### `[allpost_tracking]`
Exibe link de rastreamento para um pedido:

```php
[allpost_tracking order_id="123"]
[allpost_tracking order_id="123" show_details="no"]
```

## Hooks Disponíveis

### Actions
```php
// Quando pedido é entregue
do_action('wc_allpost_order_delivered', $order, $webhook_data);

// Quando pedido é postado
do_action('wc_allpost_order_posted', $order, $webhook_data);

// Quando pedido está em trânsito
do_action('wc_allpost_order_in_transit', $order, $webhook_data);

// Quando pedido sai para entrega
do_action('wc_allpost_order_out_for_delivery', $order, $webhook_data);

// Quando há ocorrência no pedido
do_action('wc_allpost_order_occurrence', $order, $webhook_data);
```

### Filters
```php
// Modificar dados antes de enviar para AllPost
apply_filters('wc_allpost_order_data', $order_data, $order);

// Modificar volumes para cotação
apply_filters('wc_allpost_cotacao_volumes', $volumes, $package);
```

## Funções Auxiliares

```php
// Verificar se está configurado
wc_allpost_is_configured();

// Obter dados de envio
wc_allpost_get_shipment_data($order_id);

// Obter URL de rastreamento
wc_allpost_get_tracking_url($order_id);

// Criar envio manualmente
wc_allpost_create_shipment($order_id);

// Log de debug
wc_allpost_log('Mensagem', $data);
```

## Banco de Dados

O plugin cria duas tabelas:

### `wp_allpost_quotations`
Armazena cotações realizadas:
```sql
id, order_id, quote_id, cep_destino, canal, response_data, created_at
```

### `wp_allpost_shipments`
Armazena envios criados:
```sql
id, order_id, chave_pedido, numero_pedido, tipo_pedido, status, tracking_code, created_at, updated_at
```

## Debug e Logs

### Ativar Debug
1. Vá em **WooCommerce > AllPost**
2. Marque "Modo Debug"
3. Logs aparecem em **WooCommerce > Status > Logs**

### Tipos de Log
- `allpost`: Requisições da API
- `allpost-shipping`: Cálculo de frete
- `allpost-webhook`: Webhooks recebidos

## Troubleshooting

### Cotação não aparece
1. Verificar tokens configurados
2. Verificar CEP válido (8 dígitos)
3. Verificar peso/dimensões dos produtos
4. Ativar debug e verificar logs

### Envio não é criado
1. Verificar se método é AllPost
2. Verificar status que disparam envio
3. Verificar dados obrigatórios (CPF/CNPJ)
4. Verificar logs de erro

### Webhook não funciona
1. Verificar URL configurada na AllPost
2. Verificar se site é acessível publicamente
3. Verificar logs do webhook
4. Testar URL manualmente

## Suporte

### Logs de Debug
Sempre ative o modo debug ao reportar problemas.

### Informações Necessárias
- Versão do WordPress
- Versão do WooCommerce
- Versão do plugin
- Logs relevantes
- Passos para reproduzir

## Changelog

### 1.0.0
- Versão inicial
- Cotação de frete
- Criação de envios
- Webhooks
- Painel administrativo
- Rastreamento

## Licença

Este plugin é distribuído sob a licença GPL v2 ou posterior.

## Créditos

Desenvolvido para integração com AllPost TMS.