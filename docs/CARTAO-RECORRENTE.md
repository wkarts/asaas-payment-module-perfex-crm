# Cartão de crédito recorrente controlado pelo CRM

Disponível a partir da versão **1.5.6** do módulo.

## Objetivo e modelo de operação

Este recurso permite ao cliente autorizar o pagamento automático de faturas elegíveis com um cartão tokenizado pelo Asaas. O CRM continua responsável pela emissão das faturas e pelo vínculo de cada documento com sua cobrança. A recorrência usa um meio de pagamento próprio na tela nativa da fatura, separado do checkout avulso que apresenta cartão, Pix e boleto.

A integração utiliza **tokenização e pagamento de uma cobrança existente**. Não cria uma assinatura nativa no Asaas para gerar outro calendário de cobranças. A distinção evita uma segunda origem de emissão e permite conservar o valor, o saldo, o vencimento e o histórico de cada fatura do CRM.

Na [assinatura nativa](https://docs.asaas.com/docs/assinaturas-recorr%C3%AAncia), o Asaas gera as cobranças conforme o ciclo configurado e tenta cobrar o cartão no vencimento. Na recorrência deste módulo, a emissão controlada pelo CRM determina quais faturas podem ser processadas. [Tokenizar um cartão](https://docs.asaas.com/reference/tokenizacao-de-cartao-de-credito) não cobra uma fatura e não comprova que uma captura futura será aprovada.

## Pré-requisitos externos

- A conta Asaas precisa permitir cartão e **tokenização**. A documentação informa que a produção depende de habilitação e análise da operação pelo Asaas. A disponibilidade no Sandbox não representa liberação automática em produção.
- O cliente precisa estar vinculado ao `customer` correto na mesma conta e no mesmo ambiente. O token não pode ser utilizado para outro cliente Asaas.
- O cadastro do cartão requer HTTPS. O IP enviado na tokenização é o IP do pagador obtido pelo backend, respeitando os proxies confiáveis da instalação; não é o IP da VPS nem um campo livre enviado pelo navegador.
- O cron nativo do Perfex precisa estar operacional para o processamento em segundo plano. Os webhooks de cobranças e a conciliação permanecem responsáveis pelo acompanhamento financeiro.
- A aplicação necessita de uma chave de criptografia válida e protegida para conservar a credencial tokenizada. Um erro de configuração deve impedir a ativação e o uso da autorização.

Nenhuma configuração local concede ao módulo permissão que a conta Asaas ainda não possua. Um teste local com respostas simuladas não confirma a habilitação nem a aprovação de cartões reais.

## Configuração no CRM

1. Em **Configurações → Meios de pagamento → Asaas**, mantenha o gateway principal ativo e confira ambiente, credencial e webhook. O recurso compartilha essas configurações, sem cadastrar uma segunda chave.
2. Em **Asaas → Cobranças → Cartão de crédito**, confira os encargos à vista e a estimativa de antecipação, quando utilizada. As mesmas condições de uma parcela serão aplicadas à recorrência.
3. Na guia nativa **Cartão de crédito recorrente – Asaas**, ative o novo gateway e confira seu título. O identificador interno é `asaas_recurring`; ele começa desativado e trabalha com faturas em BRL.
4. Mantenha o cron nativo do Perfex operacional. O processamento recorrente é independente da opção **Conciliar recebimentos automaticamente pelo CRON** do Asaas principal.
5. O cliente abre uma fatura Asaas, seleciona o novo meio, entra em sua conta e confirma a adesão. A opção de cartão avulso não constitui adesão e pode ser configurada separadamente.

Para faturas antigas que já permitem Asaas, o módulo apresenta um acesso explícito ao cadastro na página nativa da fatura, preservando os meios já gravados. Para novas faturas Asaas de um cliente com autorização válida, o hook acrescenta o modo recorrente e mantém o Asaas habitual para o pagamento pontual. O acesso adicional não é inserido no menu de boleto, Pix e cartão do checkout avulso.

## Adesão, escopo e cancelamento

O gateway nativo usa o identificador `asaas_recurring` e o nome **Cartão de crédito recorrente — Asaas**. Ele começa desativado. A tela própria é acessada em `asaas/recurring/index/{hash}`, a partir da seleção nativa de meios de pagamento da fatura. O checkout avulso atual continua com suas opções próprias.

O cliente precisa entrar na área de clientes para criar, substituir ou cancelar a autorização permanente, e o contato precisa ter permissão para faturas. O acesso ao link público de uma fatura não equivale, isoladamente, à autorização para cobrar suas próximas faturas. A sessão e a fatura devem pertencer ao mesmo cliente. O convite ao login utiliza um retorno interno; a URL pública não se transforma em uma credencial para consultar ou alterar autorizações.

A adesão deve apresentar a fatura escolhida, o valor da cobrança atual e as condições aplicáveis às próximas faturas. O aceite é explícito. Um pagamento avulso, um webhook contendo token ou a existência de dados de cartão no Asaas não ativa automaticamente a recorrência.

Na tela de adesão, o aceite inclui **pagar a fatura atual em uma parcela e autorizar as futuras faturas elegíveis desse cliente**. O backend exige `pay_now=true`, a versão de consentimento `2026-11-v1` e o comprovante das condições exibidas. Não existe nessa tela um botão para cadastrar silenciosamente um cartão sem aceitar a cobrança atual.

As futuras faturas precisam ser emitidas após o marco de autorização e estar permitidas para a integração. Cadastrar um cartão não autoriza varrer todo o passivo antigo. Faturas canceladas, em rascunho, quitadas ou incompatíveis com o vínculo não se tornam cobradas por causa da adesão. Uma fatura já quitada permite consultar e revogar a autorização; para trocar o cartão, o cliente utiliza uma próxima fatura aberta.

A substituição de cartão é uma nova operação de tokenização e de autorização. Uma recusa confirmada suspende o uso automático e exige que o cliente volte ao fluxo de cadastro ou realize o pagamento pelo meio avulso disponível. Cancelar a autorização interrompe novas tentativas; isso não desfaz uma captura que já tenha sido enviada ou um recebimento confirmado.

Após uma recusa confirmada, a restauração da cobrança original preserva o vencimento que já estava no Asaas. Como a preparação do cartão não altera essa data, a restauração também não a reenvia. Isso evita que um boleto vencido seja bloqueado por rejeição de uma data passada. Se a restauração falhar por outro motivo, a operação permanece em conferência até que o vínculo remoto seja verificado.

O cancelamento local remove a credencial utilizável do módulo. Não deve ser apresentado como exclusão do cartão no ambiente do Asaas: não foi identificado, na referência pública consultada, um endpoint de revogação individual do token. Uma tentativa incerta do mesmo perfil impede contornar a conferência cadastrando outro cartão em outra fatura; a revogação das cobranças futuras continua disponível.

## Fluxo financeiro

1. A aplicação valida o cliente autenticado, a fatura, a configuração e o aceite.
2. O cartão é enviado ao endpoint de tokenização, sem captura.
3. A autorização operacional e o token criptografado são persistidos separadamente dos mapas e logs de cobrança.
4. Para uma fatura elegível, o módulo confere a cobrança existente, o cliente, o saldo, o contexto da conta e as condições do cartão. Se ainda não existir cobrança, prepara uma única cobrança sem dados de cartão e vincula seu ID antes da captura.
5. Antes de qualquer captura, registra uma tentativa durável e protege a fatura contra execução concorrente.
6. A captura utiliza o ID da cobrança existente e o `creditCardToken`. Não é criada uma segunda cobrança apenas para executar a recorrência.
7. A resposta e as consultas posteriores determinam o estado da tentativa. A baixa utiliza os controles nativos de recebimento, inclusive deduplicação pelo identificador Asaas.
8. Webhooks e conciliação acompanham mudanças posteriores, inclusive análise de risco, confirmação, recebimento, recusa e estorno.

A tentativa automática não pertence à validação anterior ao salvamento da fatura. Uma recusa de cartão deve manter a obrigação em aberto para pagamento posterior. A emissão local e a captura financeira não são uma única transação reversível: desfazer uma transação SQL não estorna uma captura concluída no Asaas.

**Momento da cobrança:** a fatura atual é processada após a adesão. As próximas faturas entram na fila depois de emitidas e são avaliadas pelo cron; o módulo não espera o vencimento para capturá-las. A [operação `payWithCreditCard`](https://docs.asaas.com/reference/pagar-uma-cobranca-com-cartao-de-credito) tenta o pagamento no momento da chamada, inclusive se o vencimento estiver no futuro.

### Encargos e antecipação

Cada fatura recorrente é cobrada **à vista, em uma parcela**. Isso não cria um parcelamento de várias faturas: cada emissão mantém uma cobrança e um saldo próprios. O parcelamento avulso permanece disponível no checkout atual.

O pagamento recorrente apresenta a composição aplicável ao cartão antes da adesão. A tentativa conserva o principal, os encargos e o bruto necessários à conciliação, usando as mesmas regras financeiras do módulo. O aceite inclui as condições de encargos das futuras faturas, e a autorização registra a política financeira consentida; não presume autorização para um conjunto de condições incompatível.

A estimativa de antecipação integra os **encargos do cartão**, sem se transformar em uma linha pública separada. Escolher essa estimativa não solicita antecipação real nem altera a política da conta Asaas. As condições de uma tentativa já enviada não são recalculadas por uma alteração posterior das configurações.

O principal recebido no CRM não deve ser aumentado pelo repasse dos custos do cartão. Qualquer diferença de saldo, valor, vínculo ou composição que impeça comprovar o recebimento exige conferência, em vez de uma baixa presumida.

As configurações de credenciais e encargos seguem o provider e o mecanismo nativo do Perfex, inclusive o contexto de empresa quando presente. Parte dessas opções pode permanecer em cache durante a requisição. Aguarde as operações em andamento antes de alterar ambiente, conta ou política financeira: a revalidação da autorização não constitui uma transação distribuída entre configurações, banco local e Asaas. Na execução seguinte, uma política incompatível pausa a autorização; uma captura já enviada conserva sua composição original. As flags de ativação recebem leitura atualizada no Perfex padrão, preservando o resolvedor próprio quando existe `CompanySettingsRuntime`.

## Resultado da tentativa e tratamento das falhas

| Situação | Tratamento esperado |
|---|---|
| Tokenização recusada ou indisponível | Não ativar uma autorização sem token válido; apresentar mensagem controlada para correção. |
| Pagamento confirmado/recebido | Registrar o recebimento confirmado ausente, respeitando principal, saldo e deduplicação. |
| Recusa comprovada de captura | Manter a fatura aberta, suspender o uso do cartão e exigir nova adesão para retomar a recorrência. |
| Timeout, conexão interrompida ou retorno ilegível | Marcar resultado incerto e consultar a cobrança; não repetir automaticamente a captura. |
| Análise de risco, autorização sem captura ou estado ainda pendente | Acompanhar; não considerar a fatura paga somente porque a requisição retornou sucesso. |
| Pagamento por outro fluxo ocorrido antes da captura | Revalidar saldo e registros; não cobrar novamente o valor já recebido. |
| Ambiente, conta, cliente ou condições alterados | Impedir o uso de uma autorização incompatível e exigir conferência. |

Um resultado incerto não equivale a cartão recusado. Enquanto não for possível concluir se houve captura, outra tentativa ou alternativa de pagamento pode duplicar o débito. A incerteza precisa ser preservada até uma confirmação suficiente pela API/webhook; não deve ser removida apenas porque passou algum tempo ou porque o cliente atualizou o cartão.

O Asaas documenta [`payWithCreditCard`](https://docs.asaas.com/reference/pagar-uma-cobranca-com-cartao-de-credito) como uma operação que não deve ser tratada como idempotente. O módulo controla tentativa e concorrência localmente. `externalReference` ajuda a identificar a cobrança, mas não constitui, por si só, uma garantia de idempotência da captura.

### Cron, webhooks e diagnóstico

O hook de emissão apenas enfileira; ele não captura, não confirma a transação SQL do núcleo e não muda o resultado da gravação da fatura. O processamento ocorre depois do commit. A fila tem uma entrada por fatura e cada execução examina até 40 candidatas sem entrada, processando até 10 trabalhos. O limite organiza o trabalho entre execuções; não limita o histórico de faturas autorizadas.

O worker verifica um orçamento de 45 segundos **para iniciar a próxima fatura**, sem impor um teto absoluto à execução do cron. Uma fatura já iniciada pode envolver consultas GET, preparação de até 90 segundos e captura de até 90 segundos, conforme as operações necessárias; a sequência financeira não é interrompida no meio por esse orçamento. A duração total pode, portanto, superar 45 segundos e também o tempo de uma única chamada. Uma tentativa inconclusiva volta a ser elegível para consulta após cinco minutos, sem repetir o POST de captura.

| Estado da fila | Significado |
|---|---|
| `queued` | Fatura autorizada aguardando avaliação. Nenhuma captura foi iniciada por esse trabalho. |
| `processing` | Trabalho em andamento, com fase durável de preparação ou captura. |
| `uncertain` | Resultado financeiro ou registro local ainda precisa de confirmação. A recuperação consulta a API, sem nova captura. |
| `confirmed` | Recebimento confirmado foi aceito pelo gravador nativo do CRM, inclusive quando já existia. |
| `declined` | A tentativa foi recusada de forma conclusiva ou a preparação falhou de forma definitiva. O motivo diferencia as situações. |
| `skipped` | O trabalho não deve capturar: por exemplo, revisão antiga, fatura fora do escopo, autorização revogada ou recuperação de preparação não enviada. |

O webhook habitual continua autenticando e processando o recebimento. Depois que esse fluxo termina, um observador com campos permitidos apenas adianta `next_check_at` de um trabalho já em acompanhamento. Ele não chama a API, não espera o bloqueio da captura e não decide a baixa ou a recusa; assim, não prolonga a confirmação do webhook com uma consulta financeira. O cron faz o GET posteriormente. Uma falha nesse observador não desfaz o webhook nem sua baixa. A fila pode ser acompanhada pelo cron mesmo sem receber um webhook.

Um evento de recusa isolado não altera a autorização nem libera uma nova tentativa: ele pode estar atrasado em relação a outra operação na mesma cobrança. Se a consulta ainda não comprovar o resultado, o trabalho permanece incerto, exigindo acompanhamento, em vez de declarar uma recusa que permitiria duplicar um pagamento.

Desativar o gateway interrompe novas capturas. Trabalhos em acompanhamento continuam sendo consultados; um trabalho ainda em fila que for examinado com o recurso desativado é ignorado, sem ser reaberto automaticamente só por reativar o gateway. O processamento registra diagnóstico com origem `recurring` e `reason_code`, visível pelos recursos de Logs e Monitoramento já existentes.

## Dados conservados e privacidade

O fluxo avulso continua descartando o token retornado pelo Asaas. A exceção deste recurso é **um token reutilizável criptografado no servidor, associado a uma autorização explícita**. Ele é uma credencial financeira e não deve ser exibido, enviado ao navegador ou incluído em diagnóstico.

O módulo não precisa guardar número completo ou mascarado do cartão, validade, CVV, bandeira ou os dados do titular digitados no formulário para executar as próximas cobranças. Esses campos devem existir somente durante a coleta e o envio necessário à tokenização. A autorização guarda os identificadores operacionais, contexto, estado e evidências de consentimento necessários à execução e à auditoria.

| Destino | Política |
|---|---|
| Banco da autorização | Somente token criptografado e metadados operacionais de autorização; nenhum PAN, validade ou CVV. |
| Mapa da cobrança e eventos de webhook | Somente dados financeiros permitidos; excluir o objeto de cartão e qualquer token reutilizável. |
| Logs e erros | Mensagens controladas e identificadores operacionais; excluir corpo bruto do cartão/tokenização e token. |
| Sessão e cookies | Não incluir campos do cartão nem token reutilizável do Asaas. A sessão autentica o contato e conserva somente o hash e os vínculos do nonce de proteção do formulário, além dos mecanismos nativos de sessão/CSRF e das mensagens controladas. |
| Armazenamento do navegador | Não salvar cartão/token em `localStorage`, `sessionStorage`, IndexedDB ou cookies. |
| Resposta ao navegador | Não retornar o token; informar somente estado da autorização e resultado controlado. |
| Cache e restauração de formulário | Usar `no-store`, não repopular dados de cartão e limpar campos ao sair/restaurar a página. |

Os dados transitam pelo formulário, HTTPS e memória do backend para chegar à tokenização server-side. A ausência de persistência não significa que esses sistemas deixam de participar do tratamento de dados. A [documentação PCI-DSS do Asaas](https://docs.asaas.com/docs/pci-dss-1) esclarece essa responsabilidade e proíbe conservar CVV após autorização, mesmo criptografado.

As instruções `autocomplete="off"` e `Cache-Control: no-store` não controlam extensões ou decisões do navegador. Proxy, WAF, APM, logs de corpo de requisição, ferramentas de gravação de sessão e outros módulos da instalação também precisam respeitar a política. Não há garantia de apagamento físico de toda a memória PHP nem limpeza retroativa de dados gravados por componentes externos.

A página utiliza o layout nativo do CRM e mantém os scripts e plugins carregados por essa instalação. Esses componentes podem ter acesso ao formulário enquanto os dados são preenchidos. A política de não persistência descrita aqui cobre o fluxo implementado pelo módulo; não garante o comportamento de scripts, plugins ou infraestrutura de terceiros, nem representa certificação da instalação completa.

## Contratos Asaas utilizados

| Operação | Campos/regras relevantes |
|---|---|
| `POST /v3/creditCard/tokenizeCreditCard` | `customer`, `creditCard`, `creditCardHolderInfo` e `remoteIp` do pagador. Não cobra. O token pertence ao mesmo cliente. |
| `POST /v3/payments`, somente quando não houver cobrança | Prepara o débito sem cartão/token e com a referência da fatura. Uma falha inconclusiva exige localização por consulta; não cria outra cobrança para tentar descobrir o resultado. |
| `POST /v3/payments/{id}/payWithCreditCard` | `creditCardToken` substitui os objetos de cartão/titular. Processa no momento da chamada e não cria outra cobrança. |
| `GET /v3/payments/{id}` | Consulta o estado conhecido para acompanhar a tentativa e conciliar. |
| Webhooks de cobrança | Os eventos autenticados continuam sendo processados pelo fluxo existente. Um evento recebido duas vezes não deve duplicar o recebimento. |

A referência de `payWithCreditCard` consultada não lista `remoteIp` como campo dessa operação. Não se deve inventar um IP de pagador no cron. Já a tokenização exige o IP real obtido durante a adesão.

O recurso não cria assinaturas, não cadastra um novo cliente indiscriminadamente, não agenda antecipação, não estorna automaticamente pagamentos e não transforma uma URL de retorno em prova de recebimento.

Este fluxo não implementa uma jornada de desafio 3DS. Se a conta ou a transação exigir uma etapa interativa adicional, não deve ser apresentada como pagamento concluído. A integração conserva o estado pendente e exige acompanhamento; as exigências da conta precisam ser verificadas na homologação.

### SDK distribuída

O lock do módulo permanece em `argws/asaas-sdk-php` **v0.2.64**, que já suporta tokenização e pagamento de cobrança existente com token. Esta implementação não exige inventar um novo contrato nem publicar outra versão da SDK. O módulo utiliza seu transporte de cartão sem retentativas automáticas e com mensagens controladas. A ausência de mudança na SDK não dispensa instalar o pacote completo e as dependências do lock.

### Contrato da tela do módulo

| Requisição | Finalidade |
|---|---|
| `GET asaas/recurring/index/{hash}` | Exibir estado e oferta da fatura; exigir a identidade autorizada para acesso à autorização permanente. |
| `POST asaas/recurring/index/{hash}` com `action=enroll` | Receber o aceite, tokenizar e registrar/substituir a autorização; CSRF nativo, nonce próprio e validação de cliente no servidor. |
| `POST asaas/recurring/index/{hash}` com `action=revoke` | Interromper novas cobranças mediante revogação da autorização do próprio cliente, com as mesmas proteções de sessão e formulário. |

As ações usam POST/redirect/GET com HTTP 303. Nenhum token de cartão é parâmetro de URL. A seleção de meios e o título configurável do gateway seguem o mecanismo nativo do Perfex.

### Proteção adicional do formulário

Algumas instalações do CRM permitem desativar a proteção CSRF global. A recorrência preserva o campo CSRF nativo e exige também um nonce próprio, independentemente dessa configuração, sem modificar o núcleo.

O GET autenticado gera 32 bytes aleatórios, representados em 64 caracteres hexadecimais no campo oculto `recurringNonce`. A sessão guarda somente seu SHA-256 em `asaas_recurring_form_nonce`, associado ao cliente, ao contato autenticado, ao ID e hash da fatura e à expiração de **30 minutos**. Esse nonce protege a operação do formulário; ele não é uma credencial Asaas, um token de cartão nem um dado do cartão.

O POST consome o nonce antes de chamar o serviço de adesão ou revogação. Valor ausente, inválido, expirado, reutilizado ou vinculado a outra identidade/fatura resulta em **HTTP 403**, sem tokenização, captura ou revogação. Os campos do cartão não são conservados para uma nova tentativa; o cliente atualiza a página e preenche o formulário novamente.

Há um formulário ativo por sessão. Abrir novamente a página autenticada gera outro nonce e invalida o anterior, inclusive em outra aba. Para continuar em uma aba antiga, basta atualizar o formulário que será utilizado. A mesma proteção de uso único se aplica à revogação, mesmo que o formulário de cartão não esteja disponível.

## Persistência e concorrência

A instalação acrescenta duas tabelas próprias com o prefixo nativo da instalação e `ENGINE=InnoDB`. Não acrescenta colunas às tabelas nativas de faturas ou recebimentos.

| Tabela | Conteúdo e restrição principal |
|---|---|
| `{prefixo}asaas_recurring_authorizations` | Uma autorização por `client_id` e `scope_key`, garantida por índice único. Conserva `customer_id`, ambiente, empresa, revisão, estado, token criptografado, contato responsável, versão do consentimento, data da autorização, limite inicial de faturas e identificação da política de encargos. |
| `{prefixo}asaas_recurring_jobs` | Uma tentativa controlada por fatura, com `invoice_id` como chave primária. Conserva a revisão da autorização e seu `customer_id`, indicação de pagamento atual explícito, estado/fase, identificador da cobrança, código de motivo e datas de processamento. Não recebe dados de cartão ou token. |

O limite `cutoff_invoice_id` registra quais faturas já existiam no momento da adesão. A fatura atual precisa de autorização explícita para constituir a exceção ao limite. A revisão da autorização impede que uma fila antiga herde silenciosamente o cartão de uma nova adesão.

O cofre usa a biblioteca de criptografia autenticada do CodeIgniter. O conteúdo criptografado vincula a credencial ao dono e ao contexto autorizado. A chave de criptografia precisa permanecer protegida, estável e disponível: restaurar somente o banco em um ambiente sem a mesma chave não torna os tokens utilizáveis.

Há um bloqueio por cliente e contexto para ordenar adesão, substituição, cancelamento e processamento. A captura também utiliza o bloqueio de recebimento da fatura já existente no módulo. O backend valida a posse do bloqueio e a autorização novamente antes do envio financeiro. Os índices únicos, a revisão e as transições de estado complementam os bloqueios; nenhum desses mecanismos converte a API externa em uma transação SQL.

Os bloqueios nomeados dependem de uma conexão MySQL/MariaDB compatível. A instalação deve manter essa conexão durante a operação; se o banco não oferece o mecanismo necessário ou a conexão muda, o recurso deve parar com erro controlado. Os testes com banco simulado ou SQLite não substituem a validação de concorrência na instalação real.

## Homologação antes de produção

As verificações de código usam respostas controladas, fixtures e bancos descartáveis. Elas não executam tokenização/captura real e não comprovam a habilitação de uma conta Asaas.

Na instalação Sandbox, validar pelo menos:

1. Gateway recorrente na tela nativa, separado das opções do checkout avulso.
2. Exigência de login e bloqueio de acesso de um cliente à fatura/autorização de outro.
3. Adesão explícita; nenhum token de pagamento avulso/webhook ativa recorrência.
4. Tokenização aprovada, recusada e não habilitada, sem retorno de token ao navegador.
5. Uma fatura atual autorizada e uma nova fatura elegível; dívidas anteriores fora do escopo permanecem intocadas.
6. Reexecução de hook/cron e duplo envio sem uma segunda captura.
7. Pagamento manual concorrente e webhook atrasado sem duplicar recebimento.
8. Recusa comprovada, suspensão da autorização e recadastro posterior do cartão.
9. Timeout após envio com confirmação remota posterior; nenhuma alternativa é liberada antes de conferir o resultado.
10. Encargos e antecipação coerentes com a oferta, com recebimento somente do principal no CRM.
11. Revogação da autorização e mudança de ambiente/conta, sem reutilizar token incompatível.
12. Ausência de cartão/token em banco fora da autorização criptografada, logs, cookies, armazenamento do navegador, respostas HTTP e ferramentas da infraestrutura.
13. Com a proteção CSRF global desativada no ambiente de homologação, confirmar que POST sem nonce, expirado, repetido ou de outra fatura/contato recebe HTTP 403 sem operação financeira; confirmar também a renovação do formulário entre abas e a manutenção do CSRF nativo quando habilitado.

## Publicação, suspensão e retorno de versão

Instalar o pacote completo do módulo com as dependências correspondentes ao lock e executar a atualização nativa solicitada pelo Perfex. A migração `156_version_156.php` e a instalação chamam o mesmo instalador aditivo das tabelas recorrentes. Preservar backup dos arquivos e banco e conferir a disponibilidade da chave de criptografia no ambiente de destino.

Para suspender novas capturas, desabilitar o meio recorrente. Isso não cancela uma requisição financeira já enviada. Tentativas em andamento ou incertas devem continuar sendo acompanhadas antes de retornar a uma versão que não conhece seus estados.

Restaurar o código não estorna pagamentos nem apaga recebimentos confirmados. Não remover mapas ou tentativas para forçar uma nova cobrança. Não habilitar o recurso em uma cópia de banco conectada à conta de produção: a autorização é uma credencial financeira, mesmo que os dados completos do cartão não estejam armazenados.

## Referências oficiais

Documentação consultada em **6 de novembro de 2026**:

- [Tokenização de cartão de crédito](https://docs.asaas.com/reference/tokenizacao-de-cartao-de-credito)
- [Pagar uma cobrança existente com cartão de crédito](https://docs.asaas.com/reference/pagar-uma-cobranca-com-cartao-de-credito)
- [Criar cobrança com cartão de crédito](https://docs.asaas.com/reference/criar-cobranca-com-cartao-de-credito)
- [Cobranças via cartão de crédito](https://docs.asaas.com/docs/cobrancas-via-cartao-de-credito)
- [Cobranças e mensalidades controladas pela aplicação](https://docs.asaas.com/docs/guia-de-cobrancas)
- [Assinaturas: geração e calendário controlados pelo Asaas](https://docs.asaas.com/docs/assinaturas-recorr%C3%AAncia)
- [Retentativas de assinaturas nativas](https://central.ajuda.asaas.com/hc/pt-br/articles/54672193044251-O-que-acontece-se-houver-falha-no-pagamento-da-assinatura-com-cart%C3%A3o-de-cr%C3%A9dito)
- [Retries e idempotência](https://docs.asaas.com/docs/retries-e-idempot%C3%AAncia)
- [Eventos para cobranças](https://docs.asaas.com/docs/webhook-para-cobrancas)
- [PCI-DSS e responsabilidade da integração](https://docs.asaas.com/docs/pci-dss-1)
- [Testes de cartão no Sandbox](https://docs.asaas.com/docs/testando-pagamento-com-cart%C3%A3o-de-cr%C3%A9dito)

As tarifas, a habilitação da tokenização, a elegibilidade e a aprovação das capturas pertencem à conta e à operação do provedor. A implementação local não substitui essa validação externa.
