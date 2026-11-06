# Configurações, monitoramento e conciliação

## Acesso e organização

Como administrador, abra **Configurações → Meios de pagamento → Asaas**. As configurações estão divididas em oito subguias, dentro do formulário nativo do CRM:

| Subguia | Conteúdo |
| --- | --- |
| Geral | Ativação, título, moedas e descrição do meio de pagamento. |
| Ambiente | Homologação/Produção, respectivas chaves e exibição do ambiente ao pagador. |
| Pagamentos | Habilitação de boleto, Pix e cartão de crédito. |
| Cobranças | Encargos e descontos; cartão de crédito com parcelas, repasse, taxas e antecipação incluída nos encargos. |
| Webhook | Token, geração/cópia e endereço completo do callback. |
| Sincronização | Ciclo das faturas, quitação manual e conciliação pelo CRON. |
| Logs | Opções de diagnóstico e visualizador completo dos registros na própria subguia, incluindo estado e localização do arquivo. |
| Monitoramento | Histórico, comparação e conciliação de recebimentos. |

O botão **Salvar** continua sendo o botão do CRM. Os campos originais são movidos para as subguias; nomes, valores, eventos e regras de gravação são preservados. Campos futuros não reconhecidos ficam em Geral. Se o JavaScript não reconhecer o layout nativo, mantém o formulário plano disponível.

Em **Cobranças → Cartão de crédito → Antecipação de recebíveis**, a modalidade escolhe quais taxas mensais entram na estimativa dos encargos. Há taxas próprias para avulsa/automática, à vista/parcelado e prazo inicial ajustável. **Não incluir** é o padrão. O cliente vê somente os encargos totais; salvar a escolha não solicita antecipações nem altera a configuração da conta Asaas. Consulte [o cálculo, os parâmetros e a operação pela API](CARTAO-CREDITO.md#antecipação-incluída-nos-encargos-do-cartão).

## Ambiente e credenciais

O seletor **Ambiente de operação** tem duas opções exclusivas: **Homologação** e **Produção**. Internamente, permanece a opção `paymentmethod_asaas_sandbox`: `1` para Homologação e `0` para Produção. Nenhuma segunda flag de produção é necessária. A validação do servidor rejeita valores inválidos ou combinações contraditórias enviadas por formulários externos, preservando a configuração anterior.

A opção antiga `debug` continua responsável pelos diagnósticos e aparece como **Registrar diagnóstico detalhado**, na subguia Logs. Ela pode estar habilitada em qualquer ambiente.

As chaves de API e o token do webhook iniciam mascarados. Cada botão **Exibir** revela apenas seu respectivo campo. Trocar de subguia, sair da página ou ocultar o documento mascara novamente os campos. Gerar um token novo mantém o campo mascarado. O mascaramento não altera o segredo salvo: as chaves de API continuam usando a criptografia do gateway nativo e o token de webhook mantém o contrato de armazenamento já existente.

Ao gerar ou alterar o token, salve no CRM e configure o mesmo `authToken` no Asaas. A geração não modifica o webhook remotamente. Consulte [WEBHOOK.md](WEBHOOK.md).

## Logs dentro das configurações

A subguia **Logs** carrega o relatório de diagnóstico abaixo das opções nativas, sem navegar para outra página. **Atualizar registros de log** consulta novamente os últimos 100 registros e informa a origem dos dados, o estado do banco, o caminho do arquivo do dia e a permissão de escrita. A contingência pelo arquivo do dia continua disponível quando a consulta ao banco falha. As configurações exibidas no relatório refletem o que já foi salvo; alterar um rádio ainda não enviado não muda o estado efetivo do logger.

A leitura é administrativa, sob demanda, sem inicializar a SDK nem fazer chamadas ao Asaas. A rota antiga permanece disponível para links existentes e como fallback sem JavaScript. Não há formulário adicional nem alteração de credenciais para consultar o diagnóstico. Consulte [DIAGNOSTICO.md](DIAGNOSTICO.md).

## Histórico disponível

Monitoramento carrega dados somente quando a subguia é aberta. **Faturas**, **Webhooks** e **API e conciliações** consultam o banco local, com paginação e filtros. **Cobranças no Asaas** consulta a API da conta configurada ao abrir a guia, filtrar ou navegar entre páginas. Atualizar uma lista local não inicia uma varredura remota.

- **Faturas:** inclui faturas com vínculo, com Asaas nos meios de pagamento permitidos ou com histórico inequívoco de recebimento Asaas. Inclui antigas e parcialmente recebidas, mesmo quando não há mapa local ou webhook. Mostra saldo, último estado conhecido, última consulta e último webhook.
- **Cobranças no Asaas:** consulta remota paginada, com situação e período por pagamento, vencimento ou criação; mostra cada cobrança/parcela e a possibilidade de associá-la a uma fatura do CRM.
- **Webhooks:** identificador e tipo do evento, cobrança, fatura, recebimento, processamento e diagnóstico disponível.
- **API e conciliações:** mensagens sanitizadas e metadados permitidos, filtrados por nível e origem.

O estado exibido na coluna **Pagamento no Asaas** é o último estado local registrado. Use **Comparar** para consultar o estado atual. **Possíveis divergências** filtra faturas ainda abertas cujo histórico local indica recebimento confirmado; esse filtro não substitui uma consulta atual à API.

**Vínculo a recuperar** indica que a fatura ainda não possui um identificador local de cobrança. **Ainda não consultada** não significa que o cliente deixou de pagar. Comparar ou Conciliar procura a cobrança pela referência exata da fatura no Asaas. O resumo distingue todas as faturas elegíveis em aberto, as vinculadas e as que precisam recuperar o vínculo.

O botão **Ver erros** no resumo abre os diagnósticos filtrados. Os resultados individuais mostram o motivo, o código de diagnóstico e o status HTTP quando disponíveis. Os registros continuam sanitizados; chaves, tokens e corpos brutos de respostas não são exibidos.

### Como saber se houve pagamento

**Situação no CRM** filtra a situação local da fatura. Estar em aberto não prova, por si só, se o cliente pagou no Asaas. A coluna de pagamento distingue:

| Indicação | Significado no último estado conhecido |
| --- | --- |
| Sem confirmação de pagamento | Cobrança pendente, vencida ou com negativação solicitada; esse estado não confirma recebimento. |
| Recebimento confirmado | Estado `CONFIRMED`, `RECEIVED` ou `RECEIVED_IN_CASH`; pode se referir a uma parcela e não comprova quitação integral da fatura. |
| Somente autorizado | Cartão autorizado; autorização não é recebimento confirmado. |
| Pagamento em análise | A análise de risco ainda está pendente. |
| Pagamento estornado / cobrança excluída | O último estado exige observar o estorno ou encerramento; não é destacado como recebimento confirmado. |
| Situação a conferir | Ausência de informação ou estado diferente dos reconhecidos; consulte a cobrança. |

**Evento: Processado** significa que uma notificação foi tratada. Eventos de criação, alteração e vencimento também podem ser processados sem pagamento. Para conferir uma fatura, clique **Comparar** e leia o resultado e o valor confirmado retornados na consulta. Se continuar pendente, nenhum recebimento é acrescentado. Os rótulos não modificam os critérios financeiros existentes, os filtros ou as ações.

Entregas recusadas antes de processar o evento, como token inválido, aparecem nos diagnósticos de origem **Webhook**. O callback também registra o resultado quando a transação de processamento falha e desfaz a linha do evento. O resumo separa falhas de entrega e erros de processamento persistidos para evitar somá-los como se fossem eventos diferentes.

O histórico depende dos registros efetivamente existentes na instalação. A atualização não cria eventos retroativos para webhooks que nunca chegaram nem insere eventos artificiais quando uma conciliação é feita.

## Comparar e conciliar

**Comparar** consulta a cobrança no Asaas, valida o vínculo e registra a observação. Não acrescenta recebimentos no CRM.

**Conciliar** repete a consulta e pode registrar os recebimentos confirmados que faltam no CRM. A operação confere a fatura, a referência externa, o cliente, o identificador da cobrança, os identificadores já recebidos e o saldo atual. Faturas canceladas, em rascunho, cobranças encerradas ou substituídas e vínculos inconsistentes são bloqueados.

As operações remotas desse recurso são consultas `GET`. A conciliação não cria cobranças, não captura cartões, não exclui cobranças e não solicita estornos. O recebimento local utiliza o gateway existente, seus bloqueios e sua verificação de duplicidade. Uma nova consulta ao saldo ocorre antes da gravação.

São elegíveis estados de recebimento confirmado (`CONFIRMED`, `RECEIVED` e `RECEIVED_IN_CASH`). Uma mera autorização de cartão não é tratada como quitação. Parcelamentos são conferidos como grupo e cada parcela recebida tem seu próprio identificador. Quando existe composição de encargos do cartão, o recibo usa exclusivamente o principal daquela parcela, preservando o snapshot da captura. Cobranças legadas sem composição usam o valor da cobrança segundo o contrato existente. Valores acima do saldo atual exigem conferência e não são cortados silenciosamente pelo monitoramento.

### Recuperação sem webhook

Uma queda da VPS ou falha na entrega do webhook não precisa ser reparada por um evento artificial. O módulo consulta o estado atual no Asaas e usa o mesmo escritor de recebimentos do gateway, com bloqueio e conferência de duplicidade.

Quando falta o mapa, a consulta usa `GET /payments` com `externalReference` igual ao hash da fatura. A recuperação exige referência exata e única, cobrança válida, cliente coerente e grupo completo quando houver parcelamento. Mais de uma cobrança independente para a mesma referência exige conferência; o módulo não escolhe a primeira arbitrariamente.

Se o cliente também não tem vínculo local, a recuperação consulta `GET /customers/{id}` e exige o identificador esperado, a referência externa do cliente igual ao seu ID no CRM e CPF/CNPJ não vazio e igual ao cadastro. Não cria nem altera clientes. Clientes antigos com referência ausente ou divergente podem exigir conferência de identidade. Um vínculo local conflitante não é sobrescrito.

O vínculo recuperado registra somente informações operacionais permitidas e preserva as proteções do ciclo de vida. A leitura remota é seguida por nova verificação local sob bloqueio. Alteração de ambiente/credencial durante a conferência ou contexto de empresa que não pode ser comprovado impede a associação. Quando a fatura identifica uma empresa e o provider resolve comprovadamente a mesma conta e ambiente globais, a recuperação permanece permitida; isso não introduz roteamento automático entre contas distintas. Uma cobrança previamente vinculada que retorna erro não é substituída automaticamente por outra encontrada com a mesma referência.

### Conferência de um período no Asaas

1. Abrir **Monitoramento → Cobranças no Asaas**.
2. Para recuperar recebimentos de uma indisponibilidade, selecionar **Data de pagamento** e informar o início/fim do período. O filtro não depende da emissão da fatura.
3. Consultar as páginas e conferir situação, parcela, valor e vínculo. Para procurar cobranças ainda pendentes, deixar o período de pagamento vazio ou usar vencimento/criação.
4. Usar **Comparar** nas faturas identificadas. O servidor consulta novamente e pode recuperar um vínculo comprovado; a listagem remota, sozinha, não grava mapa nem recibo.
5. Usar **Conciliar** somente para os recebimentos confirmados e ainda ausentes. A mesma ação está disponível em lote para faturas elegíveis da página.

Cobranças de outros sistemas na mesma conta, referências ausentes, identidades conflitantes e vínculos ambíguos permanecem visíveis com o motivo e sem ação financeira habilitada. Não são criadas faturas no CRM a partir de registros desconhecidos. Uma fatura pode possuir várias parcelas remotas; a seleção em lote evita executar repetidamente a mesma fatura na página.

### Operações em lote

Selecione as faturas da página e use **Comparar selecionadas** ou **Conciliar selecionadas**. A interface envia uma fatura por vez e mostra progresso, resultado e mensagem de cada uma.

**Parar lote** encerra a sequência depois que a requisição em andamento terminar. Não cancela uma gravação já enviada. Em falha de rede ou resposta incerta, o lote para e não repete automaticamente a operação: atualize o histórico e faça uma nova comparação para identificar o resultado real. Uma conciliação parcialmente concluída mantém os recibos já confirmados e informa que o restante precisa de conferência.

Os endpoints exigem sessão administrativa e mantêm a proteção CSRF do CRM. A interface atualiza o token CSRF do formulário de configurações após as respostas, permitindo salvar normalmente depois de usar o monitoramento.

## Conciliação automática pelo CRON

Em **Sincronização**, configure:

| Parâmetro | Comportamento |
| --- | --- |
| Conciliar recebimentos automaticamente pelo CRON | Habilita o processamento; padrão desabilitado. |
| Janela de revisão das faturas quitadas | Revisa faturas com recebimentos locais recentes; de 1 a 365 dias, padrão 7. Não limita as faturas em aberto. |
| Limite de faturas por execução | De 1 a 100, padrão 50. |

O módulo usa o hook `after_cron_run` do CRON nativo do Perfex. Não é necessário criar outro endpoint público ou um agendamento externo para o módulo. O CRON do próprio CRM precisa estar configurado e funcionando.

Habilitar essa opção executa **conciliação**, e não apenas uma listagem de possíveis divergências: a rotina consulta as cobranças elegíveis, valida os dados e registra automaticamente os recebimentos confirmados que faltam no CRM. Uma cobrança ainda pendente permanece aberta. Os recebimentos já registrados são reconhecidos pelo identificador para evitar duplicidade. O webhook continua independente dessa opção.

Não há garantia de ausência de erros externos. Falhas de API, credenciais, vínculo ou valores são registradas para conferência; não são tratadas como confirmação de pagamento. Uma falha depois de iniciar a gravação pode exigir conferir o recibo efetivamente persistido, conforme o resultado indicado no histórico.

A rotina percorre todas as faturas Asaas elegíveis em aberto, incluindo antigas sem mapa, e as quitadas com recebimentos Asaas locais dentro da janela de revisão. Usa um cursor para continuar de onde parou e impede duas execuções automáticas simultâneas com um bloqueio no banco. Há intervalo mínimo de cinco minutos entre execuções automáticas e trinta minutos entre tentativas da mesma fatura. Um registro interno limitado e limpo a cada rodada aplica esse intervalo também às tentativas sem mapa ou com falha. O processamento respeita o limite configurado e verifica um orçamento de 45 segundos antes de iniciar a próxima fatura. Uma consulta já iniciada pode ultrapassar esse orçamento até o timeout do cliente HTTP existente; ela não é interrompida no meio.

O painel mostra a última execução, suas contagens e se restaram itens para as próximas rodadas. A janela do CRON não limita a comparação manual, que pode ser usada para conferir faturas antigas. Se houver erro na rotina opcional, o diagnóstico é registrado sem interromper as demais atividades do CRON do CRM.

Uma fatura emitida e vencida há 90 dias que continua aberta no CRM entra na fila, mesmo com janela de 7 dias. Se foi paga no Asaas durante uma queda, a consulta pode recuperar o recebimento depois que o CRM e seu cron voltarem a funcionar. O limite de 50, por exemplo, é o tamanho máximo da rodada; não restringe a recuperação às 50 faturas mais recentes.

O cron não executa uma importação indiscriminada de todas as cobranças da conta. Ele parte das faturas elegíveis do CRM; a guia remota serve também para investigar registros sem referência local. API indisponível, credencial revogada, identidade ambígua ou retorno incompleto permanecem pendentes com diagnóstico. Pagamentos estornados ou cobranças canceladas não geram recebimento positivo; a rotina não reverte recibos automaticamente.

## Desempenho e armazenamento

As páginas têm 25 registros por padrão, com limite de 50 no servidor. O histórico de webhook mais recente é consultado para o conjunto da página, sem carregar o histórico inteiro em PHP. O Perfex padrão calcula os saldos da listagem por agregados de recebimentos e créditos. Quando o núcleo possui regras próprias de escopo de emissor, a leitura financeira nativa é preservada. A decisão de baixar uma fatura sempre usa a apuração nativa atual.

A consulta remota usa a paginação oficial `limit`, `offset` e `hasMore`. Um limite por página não significa fim do histórico. A recuperação por referência rejeita respostas incompletas e grupos ambíguos em vez de interpretar uma página parcial como todas as cobranças existentes. Erros de autenticação, permissão, limite da API e indisponibilidade recebem códigos próprios; a interface não repete uma baixa automaticamente após resposta incerta.

As consultas diretas do monitoramento usam transporte próprio de leitura, sem repetição imediata em `429` ou falhas do servidor e sem seguir redirecionamentos. O cliente padrão da SDK e o fluxo existente de recuperação de tentativas ambíguas de cartão mantêm seus contratos. A SDK, o webhook e os demais recursos não foram refatorados para aplicar essa opção globalmente.

A migration `151` cria os índices `asaas_monitor_invoice` e `asaas_monitor_status` nos eventos e `asaas_monitor_level` nos logs. Ela não modifica colunas ou registros. A criação ocorre na instalação/atualização do módulo; abrir Monitoramento não executa alterações de estrutura.

Os filtros de origem dos diagnósticos suportam as colunas históricas `context_json` e `context`. A interface retorna uma lista permitida de metadados, sem disponibilizar o corpo bruto de requisições, cabeçalhos de autenticação ou dados de cartão.

## Atualização e retorno à versão anterior

1. Preserve uma cópia dos arquivos do módulo e do banco antes de substituir a instalação.
2. Instale o pacote completo na pasta `modules/asaas`, com seu `vendor`, mantendo a estrutura habitual do módulo.
3. Execute a atualização do módulo pelo Perfex para aplicar a migration `151`. A versão `1.5.1` avança o contador de migration mesmo para instalações que já receberam `1.4.10`, equivalente à migration `150`.
4. Confira Ambiente, Webhook e Sincronização; as opções existentes são reutilizadas.
5. Abra Monitoramento, compare uma fatura conhecida e confira o resultado. Habilite o CRON conforme a operação desejada.

Para interromper a automação, desabilite a opção de conciliação pelo CRON. Os índices adicionados são compatíveis com o código anterior e não exigem remoção em um retorno de arquivos. Recibos já conciliados são registros financeiros do CRM e não são desfeitos ao retornar o código; confira o histórico antes de qualquer ajuste financeiro manual.

Consulte também [DIAGNOSTICO.md](DIAGNOSTICO.md), [CICLO-FATURAS.md](CICLO-FATURAS.md), [CARTAO-CREDITO.md](CARTAO-CREDITO.md) e [RELEASING.md](RELEASING.md).

Referências oficiais: [Listar cobranças](https://docs.asaas.com/reference/listar-cobrancas), [Listagem e paginação](https://docs.asaas.com/reference/listagem-e-paginacao) e [Polling vs. Webhooks](https://docs.asaas.com/docs/polling-vs-webhooks). A SDK distribuída já suporta essas consultas; essa recuperação não exige atualização da SDK.
