# Cartão de crédito e ambiente da página de pagamentos

## Configuração

Em **Configurações → Meios de pagamento → Asaas**:

1. Configure a chave da conta e selecione o ambiente apropriado.
2. Ative **Habilitar cartão de crédito**. As configurações já salvas de boleto, Pix e cartão são preservadas pela atualização.
3. Informe **Limite de parcelas no cartão (1 a 12)**. A seleção apresentada ao cliente considera o saldo restante, o desconto configurado e o mínimo por parcela. À vista não envia campos de parcelamento à API; parcelado envia `installmentCount` e `totalValue`, evitando a perda de centavos por multiplicação de parcelas arredondadas.
4. Configure **Exibir o ambiente em produção na área de pagamentos**. **Não** é o padrão e oculta a faixa em produção. **Sim** mostra a faixa. Em sandbox, o aviso aparece nas páginas de seleção, Pix e cartão para identificar pagamentos de teste. A opção não troca o ambiente da API.
5. Confira a URL e o token do webhook. Mantenha pelo menos os eventos `PAYMENT_CONFIRMED` e `PAYMENT_RECEIVED` habilitados.

O checkout de cartão requer HTTPS. O servidor apura o IP do pagador pelo mecanismo do CodeIgniter; instalações com proxy devem manter corretamente a configuração de proxies confiáveis. O navegador não informa livremente esse IP no POST.

O formulário solicita nome, número, validade e CVV do cartão, além de e-mail, CPF/CNPJ, CEP, número do endereço e telefone do titular. Os dados do titular atendem à autorização do cartão; não alteram as preferências nem o cadastro de e-mail/telefone do cliente no CRM ou no Asaas.

## Atualização da SDK e instalação

A dependência `argws/asaas-sdk-php` está fixada no lock em **v0.2.64**, commit `170831639665082c3bf50422877942b04a4a5315`. O `composer.json` usa o repositório `wkarts/asaas-sdk-argws` somente para esse pacote. Isso permite resolver a versão publicada no GitHub mesmo quando o índice do Packagist ainda está atrasado.

As versões e referências dos outros pacotes do lock foram preservadas. O `vendor/` de produção versionado acompanha o lock; o ZIP candidato e o asset da release também incluem esse vendor. O ZIP candidato possui a pasta `asaas/` para substituir `modules/asaas/`, com identificação do commit em `BUILD-COMMIT.txt` e hash SHA256 separado.

Para uma instalação que usa Composer, execute na pasta do módulo:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

O pacote já pronto dispensa a instalação de dependências no servidor. Faça backup da pasta atual antes de substituí-la e recarregue o PHP/OPcache conforme o procedimento do servidor. Esta alteração não acrescenta tabelas nem modifica o esquema existente; usa `asaas_payments_map` para registrar a tentativa.

Se a raiz do Perfex tiver carregado outra SDK anteriormente, o módulo verifica se ela aceita o transporte dedicado de cartão. Uma implementação incompatível é recusada antes de enviar dados. Confira o vendor realmente carregado, atualizando também a dependência da raiz quando ela for a proprietária dessas classes.

## Comportamento do pagamento

| Situação | Comportamento |
|---|---|
| À vista, cobrança vinculada em aberto | Atualiza o tipo para cartão e paga o mesmo ID com `payWithCreditCard`. |
| À vista, sem cobrança existente | Cria uma cobrança de cartão pelo saldo da fatura. |
| Novo parcelamento, cobrança única anterior em aberto | Remove a cobrança anterior antes da tentativa; preserva seu ID para conciliação. Uma recusa definitiva restaura a cobrança anterior. |
| Parcelamento anterior ainda em aberto | Confere grupo, quantidade e soma; paga o mesmo grupo sem gerar outro parcelamento. |
| HTTP 400 de recusa | Exibe orientação controlada; não registra recebimento local. |
| Falha na preparação da SDK antes do envio | Libera a tentativa para correção da configuração; nenhuma captura foi enviada. |
| Timeout, resposta ilegível ou resultado incerto | Guarda a tentativa e consulta seu resultado; não repete captura automaticamente nem habilita outro meio de pagamento. |
| `AUTHORIZED`, `PENDING` ou análise de risco | Mantém acompanhamento sem dar baixa. |
| `CONFIRMED` ou `RECEIVED` | Registra somente o valor bruto confirmado, limitado ao saldo local. Parcelas têm IDs e recebimentos próprios. |
| Mesmo pagamento entregue por dois eventos | Deduplica por fatura e ID Asaas, preservando a proteção transacional existente. |

Na listagem do parcelamento, o módulo exige a quantidade prevista, IDs distintos, o ID retornado na captura, o mesmo grupo/cliente/referência e a soma correspondente ao valor contratado. Uma inconsistência mantém a operação em conciliação; não presume quitação integral.

O transporte das mutações de cartão utiliza timeout de **90 segundos**, sem retries ou redirecionamentos automáticos. As consultas e as operações existentes de boleto/Pix conservam seu cliente HTTP anterior. O formulário bloqueia duplo envio, usa CSRF e aplica POST/redirect/GET. O servidor confere novamente configuração, estado da fatura, saldo, parcelas e dados recebidos.

## Conciliação e logs

Uma transação de banco não pode desfazer uma captura já realizada pelo Asaas. Por isso, antes do POST de captura, o módulo persiste o identificador da tentativa, a cobrança, o valor e o estado de acompanhamento. Essa marca permanece depois de timeout ou interrupção do PHP.

Em **Ver logs da integração**, procure pelo ID da fatura, pelo ID da cobrança, pelo identificador da tentativa e pelo código HTTP. Os logs de transporte incluem um ID de correlação. Não persistimos PAN, CVV, objeto do titular ou token reutilizável de cartão no mapa, nos eventos de webhook ou no diagnóstico desse fluxo.

Quando o pagador abre **Acompanhar pagamento por cartão**, são feitas consultas ao Asaas e conciliação dos recebimentos confirmados, sem reenviar o cartão. O webhook continua sendo o mecanismo assíncrono de confirmação.

Se a consulta permanecer inconclusiva ou em aberto depois de um timeout, a marca não é removida por tempo decorrido: uma ausência momentânea na consulta não prova que a captura falhou. O financeiro precisa conferir a tentativa no Asaas antes de liberar outra cobrança. Não há estorno automático nem liberação pública de tentativas ambíguas nesta alteração.

Enquanto a captura estiver incerta ou em análise, alterações financeiras, cancelamento, exclusão e recebimento por outro meio ficam bloqueados. Um parcelamento existente também não recebe o total integral da fatura em cada parcela durante uma edição. Campos internos podem ser editados sem reenviar valores ao parcelamento. A reativação de uma fatura cancelada cria uma nova dívida e não herda a trava da tentativa anterior.

## Validação e publicação

O CI executa os testes anteriores e os novos cenários em PHP 8.1 e 8.2. O job de pacote só executa após ambos passarem, instala dependências de produção a partir do lock e produz o ZIP candidato com SHA256. Nenhum teste faz cobranças reais.

Foram incluídos testes de transporte com SDK/Guzzle reais e respostas controladas, persistência da tentativa em SQLite conferida por uma segunda conexão, renderização das views, controller real com interfaces Perfex simuladas, baixa por parcela, duplicatas e regressões do ciclo de faturas. O núcleo proprietário do Perfex e a autorização na conta Asaas devem ser homologados no ambiente sandbox da instalação antes de produção.

Para rollback do código, restaure a pasta anterior completa, inclusive o vendor. Antes disso, concilie tentativas de cartão pendentes; versões anteriores não conhecem a marca que impede repetir uma captura incerta. Os dados e IDs das tentativas não devem ser apagados para forçar uma nova cobrança.

## Referências

- [Criar cobrança com cartão](https://docs.asaas.com/reference/criar-cobranca-com-cartao-de-credito)
- [Pagar cobrança existente com cartão de crédito](https://docs.asaas.com/reference/pagar-uma-cobranca-com-cartao-de-credito)
- [Guia de cobranças por cartão](https://docs.asaas.com/docs/cobrancas-via-cartao-de-credito)
- [SDK 0.2.64](https://github.com/wkarts/asaas-sdk-argws/releases/tag/v0.2.64)
