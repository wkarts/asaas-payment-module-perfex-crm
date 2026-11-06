# Cartão de crédito e ambiente da página de pagamentos

Este guia descreve o **pagamento avulso**, oferecido junto de Pix e boleto no checkout Asaas. O novo meio **Cartão de crédito recorrente — Asaas** tem adesão própria na tela nativa da fatura e uma política específica de autorização: conserva somente o token criptografado necessário às futuras cobranças autorizadas. Consulte [Cartão de crédito recorrente](CARTAO-RECORRENTE.md) para esse fluxo. O pagamento avulso não ativa a recorrência nem passa a reutilizar tokens.

## Configuração

Em **Configurações → Meios de pagamento → Asaas**:

1. Configure a chave da conta e selecione o ambiente apropriado.
2. Ative **Habilitar cartão de crédito**. As configurações já salvas de boleto, Pix e cartão são preservadas pela atualização.
3. Em **Cobranças → Cartão de crédito**, configure o limite de **1 a 21 parcelas**, o mínimo por parcela, as faixas de taxas e quem absorve o custo. Acima de 12 parcelas, o servidor aceita apenas Visa e Mastercard. À vista não envia campos de parcelamento à API; parcelado envia `installmentCount` e `totalValue`, evitando perda de centavos por multiplicação de parcelas arredondadas.
4. Configure **Exibir o ambiente em produção na área de pagamentos**. **Não** é o padrão e oculta a faixa em produção. **Sim** mostra a faixa. Em sandbox, o aviso aparece nas páginas de seleção, Pix e cartão para identificar pagamentos de teste. A opção não troca o ambiente da API.
5. Confira a URL e o token do webhook. Mantenha pelo menos os eventos `PAYMENT_CONFIRMED` e `PAYMENT_RECEIVED` habilitados.

O checkout de cartão requer HTTPS. O servidor apura o IP do pagador pelo mecanismo do CodeIgniter; instalações com proxy devem manter corretamente a configuração de proxies confiáveis. O navegador não informa livremente esse IP no POST.

A validação de valor mínimo também ocorre antes de salvar uma nova fatura que permite Asaas quando boleto está desabilitado e o pagamento será por cartão/Pix. Rascunhos e faturas que não usam Asaas mantêm o comportamento anterior. A criação automática de boleto continua obedecendo à sua própria configuração.

O formulário solicita nome, número, validade e CVV do cartão, além de e-mail, CPF/CNPJ, CEP, número do endereço e telefone do titular. Os dados do titular atendem à autorização do cartão; não alteram as preferências nem o cadastro de e-mail/telefone do cliente no CRM ou no Asaas.

### Taxas, repasse e parcelamento

As novas opções são aditivas. **Absorver as taxas** é o padrão: o cliente continua pagando o valor da cobrança, sem acréscimo do módulo. As taxas efetivamente descontadas pelo Asaas dependem do contrato da conta; os campos abaixo configuram a precificação do módulo, não alteram o contrato com o Asaas.

| Parâmetro | Comportamento |
|---|---|
| Limite de parcelas | De 1 a 21; 1 oferece somente pagamento à vista. Visa/Mastercard permitem até 21; outras bandeiras ficam limitadas a 12. |
| Valor mínimo da parcela | Padrão R$ 5,00. O módulo verifica o mínimo líquido, considerando o desconto configurado; o limite administrativo aplica-se ao parcelamento. À vista continua sujeito ao mínimo da API. |
| Quem absorve as taxas | Empresa: sem acréscimo. Cliente: acrescenta o encargo calculado e mostra o total antes da confirmação. |
| Parcelas sem repasse | Até a quantidade informada, a empresa absorve o custo. Zero significa que não há faixa de isenção adicional. |
| Percentuais por faixa | À vista, 2–6, 7–12 e 13–21 parcelas. Aceitam ponto ou vírgula, até duas casas decimais. |
| Taxa fixa | Valor configurável, aplicado uma vez por cobrança ou por parcela, conforme a opção escolhida. |

Os valores iniciais de referência são **2,99%, 3,49%, 3,99%, 4,29% e R$ 0,49**, conforme o exemplo fornecido para esta implementação. Confirme e ajuste as condições da conta antes de habilitar o repasse. A escolha inicial da taxa fixa é uma vez por cobrança; se o contrato cobra o fixo em cada parcela, selecione essa modalidade. A configuração não consulta nem promete automaticamente a tarifa vigente na conta.

Para repassar sem antecipação, o cálculo nominal é:

```text
total no cartão = arredondar para cima em centavos((saldo da fatura + fixo total) / (1 - percentual / 100))
encargo repassado = total no cartão - saldo da fatura
```

Exemplo: saldo **R$ 100,00**, taxa **2,99% + R$ 0,49**, uma cobrança: total **R$ 103,59**, encargo **R$ 3,59**. O cálculo usa aritmética inteira, inclusive para limites e arredondamento. As regras de desconto do Asaas são preservadas no payload: os valores apresentados são nominais, antes desses descontos adicionais quando elegíveis. Um desconto percentual incide sobre o valor enviado à cobrança, como no contrato anterior; o custo líquido final não é garantido por essa estimativa.

O checkout apresenta o saldo, o encargo, o total e a distribuição das parcelas antes do cartão. O backend recalcula a oferta sob o lock da fatura; se saldo, taxa, parcelas ou condições mudarem desde a apresentação, a tentativa é recusada antes de alterar ou capturar uma cobrança. O formulário também leva o comprovante da oferta selecionada sem depender de JavaScript. Esses hashes representam somente condições financeiras, não são tokens reutilizáveis de cartão.

O repasse **não altera `invoice.total`, itens, impostos, vencimento, boleto ou Pix**. Antes da captura, a tentativa guarda a composição financeira. Após validar todos os IDs, cliente, referência, quantidade e soma das parcelas, o módulo distribui o principal entre elas proporcionalmente, com ajuste determinístico dos centavos. Checkout, webhook e conciliação usam a mesma distribuição. Uma parcela confirma apenas sua parte do principal, e a nota do recibo informa principal, encargo e bruto nominal. O custo do cartão não vira principal adicional nem uma quitação antecipada da fatura. Isso não cria automaticamente um lançamento contábil de despesa; os valores da composição permanecem disponíveis na tentativa e na nota do recebimento.

Configurações posteriores não recalculam uma tentativa já capturada. Uma distribuição incompleta, um valor divergente ou um saldo alterado bloqueia a baixa para conferência. Na primeira entrega de um webhook após captura incerta, se faltar a distribuição, o módulo tenta recuperá-la por consultas ao Asaas antes de iniciar a transação do recebimento. Uma falha retorna erro para nova entrega; não confirma processamento sem validar a baixa.

O recebimento de cartão de débito direto não foi acrescentado ao formulário: a API documenta o débito pelo checkout hospedado do Asaas. Os parâmetros acima são de **crédito**.

### Antecipação incluída nos encargos do cartão

Em **Cobranças → Cartão de crédito → Antecipação de recebíveis**, escolha a modalidade usada na precificação. O padrão é **Não incluir**, que conserva os cálculos e as ofertas anteriores. **Avulsa** e **Automática** selecionam conjuntos independentes de taxas; a quantidade de parcelas determina se será usada a taxa à vista ou parcelada.

| Parâmetro | Valor inicial | Uso |
|---|---|---|
| `card_anticipation_mode` | `none` | `none`: não incluir; `manual`: avulsa; `automatic`: automática. Escolha do cálculo local. |
| `card_anticipation_manual_rate_1` | 1,25% ao mês | Avulsa em uma parcela. |
| `card_anticipation_manual_rate_installments` | 1,70% ao mês | Avulsa em duas ou mais parcelas. |
| `card_anticipation_automatic_rate_1` | 1,15% ao mês | Automática em uma parcela. |
| `card_anticipation_automatic_rate_installments` | 1,60% ao mês | Automática em duas ou mais parcelas. |
| `card_anticipation_first_days` | 30 dias | Prazo antecipado estimado da primeira parcela, de 0 a 365 dias; as seguintes acrescentam 30 dias cada. |

As quatro taxas são referências fornecidas para esta implementação, editáveis conforme o contrato da conta. Aceitam ponto ou vírgula e até duas casas decimais. O prazo representa **quantos dias o recebimento será adiantado**, não o prazo de entrega do dinheiro pelo Asaas. Ajuste-o à operação planejada. Por exemplo, com 30 dias iniciais, três parcelas usam prazos estimados de 30, 60 e 90 dias.

O cálculo é uma **estimativa comercial linear**, proporcional ao prazo médio das parcelas, sobre o líquido estimado após as tarifas de cartão. Não aplica uma taxa mensal única a todo parcelamento nem soma os dois percentuais como se incidissem sobre a mesma base. A simulação contratual depende das datas reais, da elegibilidade, de descontos, dos arredondamentos e das condições da conta; não é reproduzida nem solicitada por esta configuração.

```text
dias médios = dias da primeira parcela + 30 × (quantidade de parcelas - 1) / 2
antecipação proporcional = (taxa mensal / 100) × dias médios / 30
total no cartão = teto em centavos((saldo / (1 - antecipação proporcional) + fixo total) / (1 - taxa do cartão / 100))
encargos do cartão = total no cartão - saldo
```

O servidor usa inteiros/aritmética decimal exata e arredonda o total para cima ao centavo; as estimativas internas de custo são arredondadas separadamente. Taxa e prazo que consumiriam 100% ou mais do recebível de uma parcela são rejeitados. As opções de **absorver taxas** e **parcelas sem repasse** continuam aplicáveis ao custo completo: nesses casos, habilitar a estimativa de antecipação não aumenta o valor pago pelo cliente.

Exemplo nominal com saldo de R$ 100,00, fixo de R$ 0,49 uma vez por cobrança e 30 dias iniciais, antes de descontos adicionais do Asaas:

| Modalidade | Parcelas | Taxa de cartão | Taxa mensal de antecipação | Total do cartão | Encargos totais |
|---|---:|---:|---:|---:|---:|
| Avulsa | 1 | 2,99% | 1,25% | R$ 104,90 | R$ 4,90 |
| Automática | 1 | 2,99% | 1,15% | R$ 104,79 | R$ 4,79 |
| Avulsa | 3 | 3,49% | 1,70% | R$ 107,78 | R$ 7,78 |
| Automática | 3 | 3,49% | 1,60% | R$ 107,55 | R$ 7,55 |

**Apresentação ao pagador:** o checkout e a nota do recibo mantêm apenas os encargos agregados do cartão, sem discriminar a antecipação. Modalidade, percentuais e prazo não são enviados como campos extras da oferta pública. O total e o valor das parcelas continuam visíveis antes de confirmar. Internamente, a tentativa com repasse guarda a composição financeira necessária à conferência. A confirmação da oferta inclui a modalidade, a taxa aplicável e o prazo: mudanças antes da captura exigem atualizar a página; mudanças depois da captura não recalculam aquela tentativa nem suas baixas.

**Operação no Asaas:** salvar esses parâmetros não solicita antecipação, não ativa/desativa a antecipação automática e não altera taxas da conta. Nenhuma chamada adicional à API é feita para calcular a oferta. A operação real pode ser configurada/solicitada no Asaas ou por uma integração específica usando os endpoints já suportados pela SDK:

| Operação real | Endpoint Asaas |
|---|---|
| Consultar a configuração automática | `GET /v3/anticipations/configurations` |
| Ativar/desativar automática para a conta | `PUT /v3/anticipations/configurations` com `creditCardAutomaticEnabled` |
| Simular uma cobrança/parcelamento já existente | `POST /v3/anticipations/simulate` com `payment` ou `installment` |
| Solicitar uma antecipação avulsa | `POST /v3/anticipations` com `payment` ou `installment` e documentação quando exigida |

A configuração automática é da **conta**, não uma opção por venda. A execução está sujeita à análise, ao limite e à elegibilidade no Asaas. O simulador da API exige uma cobrança ou parcelamento existente e não está disponível em sandbox. A SDK distribuída já contém esses métodos; esta alteração não muda sua versão nem dispara essas operações. Nenhuma nova tabela, migration de dados, tarefa cron ou tratamento de webhook de antecipação é necessário para esta precificação.

Para conferir a configuração, mantenha inicialmente **Não incluir**, confirme um valor já conhecido, selecione a modalidade desejada e revise as quatro taxas e o prazo. Com repasse ativo, verifique o total à vista e parcelado; com absorção/isenção, confirme que o cliente continua pagando o saldo. Ajustar a modalidade no módulo deve acompanhar a forma de operação escolhida na conta Asaas. Para voltar ao cálculo anterior em novas ofertas, escolha **Não incluir**; tentativas já capturadas conservam sua composição original.

## Privacidade dos dados do cartão

O fluxo avulso não mantém um cadastro de cartões. Número completo ou mascarado, validade, CVV, nome/dados informados do titular e token reutilizável do cartão são descartados dos payloads antes de salvar o mapa da cobrança e os eventos de webhook. A sanitização também cobre campos isolados, variações de nomes e objetos JSON aninhados. Os erros de captura não incluem a resposta bruta da API nem a exceção original da SDK. A autorização recorrente é separada e exige adesão explícita; sua credencial criptografada não é incorporada aos mapas, eventos ou logs descritos nesta seção.

| Destino | Política do módulo |
|---|---|
| Banco, logs e arquivos de diagnóstico | Não gravar os dados de cartão/titular informados no checkout nem o token reutilizável. Manter IDs, valores, datas, status e parcelas necessários à conciliação. |
| Sessão e cookies | Não gravar dados de cartão. A sessão pode conter somente a mensagem controlada de resultado desse fluxo; os cookies normais de sessão/CSRF do Perfex continuam funcionando. |
| `localStorage`, `sessionStorage`, IndexedDB e outros armazenamentos do navegador | O JavaScript do checkout não utiliza esses mecanismos nem grava cookies. |
| Preenchimento automático | Formulário e campos editáveis usam `autocomplete="off"`. PAN, validade, CVV e nome do cartão não são repopulados pelo servidor. Defaults de contato vêm exclusivamente do cadastro já existente, sem recuperar o POST anterior. |
| Cache e histórico | A rota de cartão envia `Cache-Control: no-store`, inclusive nos redirecionamentos/erros, desativa cache de página e profiler do CodeIgniter e usa HTTP 303 após POST. O JavaScript limpa os campos ao sair e ao restaurar a página pelo histórico; a restauração consulta o estado do pagamento por GET. |
| Variáveis da requisição | Depois de ler o formulário, o controller retira os campos sensíveis de `$_POST` e `$_REQUEST`, antes da captura, e libera sua variável local ao terminar a tentativa. Isso não equivale a apagar de forma garantida toda a memória do processo PHP. |

Os dados precisam existir temporariamente no formulário, no corpo HTTPS da requisição e em memória para serem enviados ao Asaas. O processamento e a eventual tokenização realizados pelo próprio Asaas seguem o contrato do provedor; no pagamento avulso, o módulo não salva nem reutiliza o token retornado.

`autocomplete="off"` e `no-store` são instruções ao navegador, não controle absoluto sobre extensões, gerenciadores de cartões/senhas ou decisões do usuário. Esta implementação também não audita a configuração de proxy, WAF, APM, módulos de terceiros, gravação de sessões ou logs de corpo de requisição da instalação. Esses componentes não devem capturar os campos de cartão nem o corpo do POST dessa rota. Não há limpeza retroativa de dados que outra versão ou outro componente tenha gravado.

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
| `CONFIRMED` ou `RECEIVED` | Sem repasse, preserva o recebimento existente. Com repasse, registra exatamente o principal alocado à parcela, com conferência do saldo. Parcelas têm IDs e recebimentos próprios. |
| Mesmo pagamento entregue por dois eventos | Deduplica por fatura e ID Asaas, preservando a proteção transacional existente. |

Na listagem do parcelamento, o módulo exige a quantidade prevista, IDs distintos, o ID retornado na captura, o mesmo grupo/cliente/referência e a soma correspondente ao valor contratado. Uma inconsistência mantém a operação em conciliação; não presume quitação integral.

O transporte das mutações de cartão utiliza timeout de **90 segundos**, sem retries ou redirecionamentos automáticos. As consultas e as operações existentes de boleto/Pix conservam seu cliente HTTP anterior. O formulário bloqueia duplo envio, usa CSRF e aplica POST/redirect/GET. O servidor confere novamente configuração, estado da fatura, saldo, parcelas e dados recebidos.

## Conciliação e logs

Uma transação de banco não pode desfazer uma captura já realizada pelo Asaas. Por isso, antes do POST de captura, o módulo persiste o identificador da tentativa, a cobrança, o valor e o estado de acompanhamento. Essa marca permanece depois de timeout ou interrupção do PHP.

Em **Meios de pagamento → Asaas → Logs**, procure pelo ID da fatura, pelo ID da cobrança, pelo identificador da tentativa e pelo código HTTP. A consulta está na própria guia; a rota independente continua disponível como alternativa. Os logs de transporte incluem um ID de correlação. Não persistimos PAN, CVV, objeto do titular ou token reutilizável de cartão no mapa, nos eventos de webhook ou no diagnóstico desse fluxo.

Quando o pagador abre **Acompanhar pagamento por cartão**, são feitas consultas ao Asaas e conciliação dos recebimentos confirmados, sem reenviar o cartão. O webhook continua sendo o mecanismo assíncrono de confirmação.

Se a consulta permanecer inconclusiva ou em aberto depois de um timeout, a marca não é removida por tempo decorrido: uma ausência momentânea na consulta não prova que a captura falhou. O financeiro precisa conferir a tentativa no Asaas antes de liberar outra cobrança. Não há estorno automático nem liberação pública de tentativas ambíguas nesta alteração.

Enquanto a captura estiver incerta ou em análise, alterações financeiras, cancelamento, exclusão e recebimento por outro meio ficam bloqueados. Um parcelamento existente também não recebe o total integral da fatura em cada parcela durante uma edição. Campos internos podem ser editados sem reenviar valores ao parcelamento. A reativação de uma fatura cancelada cria uma nova dívida e não herda a trava da tentativa anterior.

## Validação e publicação

O CI executa os testes anteriores e os novos cenários em PHP 8.1 e 8.2. O job de pacote só executa após ambos passarem, instala dependências de produção a partir do lock e produz o ZIP candidato com SHA256. Nenhum teste faz cobranças reais.

Foram incluídos testes de transporte com SDK/Guzzle reais e respostas controladas, persistência da tentativa em SQLite conferida por uma segunda conexão, renderização das views, controller real com interfaces Perfex simuladas, baixa por parcela, duplicatas e regressões do ciclo de faturas. O núcleo proprietário do Perfex e a autorização na conta Asaas devem ser homologados no ambiente sandbox da instalação antes de produção.

Os testes de privacidade verificam sanitização de dados aninhados/aliases, preservação dos metadados financeiros, headers e profiler desativado no controller, remoção dos campos das variáveis globais, formulário sem autocomplete, limpeza ao navegar/restaurar a página e integridade do POST antes da limpeza. A verificação de cookies/cache e de ferramentas de observabilidade no navegador e servidor efetivamente instalados continua sendo parte da homologação.

Para rollback do código, restaure a pasta anterior completa, inclusive o vendor. Antes disso, concilie tentativas de cartão pendentes; versões anteriores não conhecem a marca que impede repetir uma captura incerta. Os dados e IDs das tentativas não devem ser apagados para forçar uma nova cobrança.

**Rollback com repasse ativo:** desative novas ofertas com repasse e conclua a conciliação de todas as parcelas já criadas antes de voltar a uma versão sem essa distribuição de principal. Versões anteriores podem interpretar o bruto como valor da fatura. Restaurar apenas arquivos não desfaz uma captura no Asaas, e apagar o mapa não é um procedimento de rollback.

## Referências

- [Criar cobrança com cartão](https://docs.asaas.com/reference/criar-cobranca-com-cartao-de-credito)
- [Pagar cobrança existente com cartão de crédito](https://docs.asaas.com/reference/pagar-uma-cobranca-com-cartao-de-credito)
- [Guia de cobranças por cartão](https://docs.asaas.com/docs/cobrancas-via-cartao-de-credito)
- [Parcelamento e limites por bandeira](https://docs.asaas.com/docs/criar-uma-cobranca-parcelada)
- [Consultar as taxas da conta](https://docs.asaas.com/reference/recuperar-taxas-da-conta)
- [Antecipação: base líquida e prazo por parcela](https://www.asaas.com/antecipacao-de-recebiveis)
- [Configurar antecipação automática na conta](https://docs.asaas.com/reference/atualizar-status-da-antecipacao-automatica)
- [Simular antecipação pela API](https://docs.asaas.com/reference/simular-antecipa%C3%A7%C3%A3o)
- [Solicitar antecipação pela API](https://docs.asaas.com/reference/solicitar-antecipacao)
- [SDK 0.2.64](https://github.com/wkarts/asaas-sdk-argws/releases/tag/v0.2.64)
- [Atributo autocomplete — MDN](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Attributes/autocomplete)
- [Cache-Control — MDN](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Cache-Control)
