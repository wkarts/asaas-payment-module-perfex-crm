# Pix Automático — operação e homologação

O módulo adiciona **Pix Automático – Asaas** como meio de pagamento próprio do CRM. Ele aparece na seleção de meios da fatura; não é um quarto botão dentro da tela de boleto, Pix e cartão avulso. Para faturas anteriores que permitem Asaas, um card compacto permite iniciar a adesão. O recurso é opcional e permanece desativado até a configuração do administrador.

O CRM continua emitindo as faturas. A autorização usa a Jornada 3 e `paymentCreationMode=MANUAL`: o primeiro QR paga a fatura escolhida e permite ao cliente autorizar os débitos seguintes no aplicativo do banco. O módulo não cria uma assinatura Asaas paralela à recorrência de faturas do Perfex. A documentação oficial distingue os modos de criação e a antecedência de agendamento em [Implementação do Pix Automático](https://docs.asaas.com/docs/pix-automatico-implementacao).

## 1. Configuração

1. Atualize o módulo com seu `vendor` e execute a atualização normal de módulos do Perfex. A migração adiciona `asaas_pix_authorizations` e `asaas_pix_jobs`, respeitando o prefixo do banco. Ela não reconstrói tabelas de faturas ou pagamentos.
2. Configure e mantenha ativo o gateway **Asaas** principal, usando o ambiente e a chave da conta pretendida. A dependência distribuída é `argws/asaas-sdk-php` **0.3.0**. Se o Composer principal do CRM carregar outra SDK antes, confira a compatibilidade desse autoloader; o recurso recusa um transporte sem as capacidades necessárias.
3. Em **Configurações → Meios de pagamento → Asaas → Webhook**, configure o token de autenticação. No Asaas, use a URL exibida pelo módulo e o mesmo `authToken`. Não use a chave de API como token de webhook. O token não pode estar vazio para novas adesões e cobranças automáticas.
4. Configure os eventos de pagamento já usados pelo módulo, incluindo `PAYMENT_CONFIRMED` e `PAYMENT_RECEIVED`, e os eventos de autorização/instrução listados abaixo. Mantenha o webhook habilitado no mesmo ambiente da integração.
5. Mantenha o **cron nativo do Perfex** funcionando. O Pix utiliza `after_cron_run`; não exige uma segunda tarefa de sistema operacional, mas a execução periódica do cron do CRM é necessária.
6. Em **Meios de pagamento → Pix Automático – Asaas**, habilite o recurso, mantenha a moeda BRL e informe feriados adicionais quando aplicáveis. Disponibilize esse meio nas faturas desejadas. A opção de disponibilização padrão para novas faturas é independente da ativação.
7. Confirme a elegibilidade da conta e do ambiente no Asaas antes da adesão real. Habilitar o gateway no CRM não habilita um produto bancário na conta. Consulte a [FAQ oficial](https://docs.asaas.com/docs/faq-2).

Eventos adicionais de autorização:

```text
PIX_AUTOMATIC_RECURRING_AUTHORIZATION_CREATED
PIX_AUTOMATIC_RECURRING_AUTHORIZATION_ACTIVATED
PIX_AUTOMATIC_RECURRING_AUTHORIZATION_CANCELLED
PIX_AUTOMATIC_RECURRING_AUTHORIZATION_EXPIRED
PIX_AUTOMATIC_RECURRING_AUTHORIZATION_REFUSED
```

Eventos adicionais de instrução:

```text
PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_CREATED
PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_SCHEDULED
PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_REFUSED
PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_CANCELLED
```

Os nomes e objetos desses eventos constam em [Eventos para Pix Automático](https://docs.asaas.com/docs/eventos-para-pix-autom%C3%A1tico). O módulo mantém a validação do cabeçalho `asaas-access-token` e o processamento nativo de recebimentos. O observador de autorização apenas antecipa uma conferência por API; um evento isolado não é usado para reativar um mandato encerrado.

## 2. Adesão e primeiro pagamento

O cliente abre a fatura, seleciona Pix Automático e entra na própria conta do CRM com um contato ativo e permissão para faturas. Ter apenas o link público da fatura não permite autorizar débitos futuros.

A tela apresenta o saldo inicial, a frequência e um consentimento explícito. O cliente precisa marcar a aceitação. Uma alteração de saldo, situação, identidade ou escopo após a exibição invalida a confirmação e exige atualizar a página. O formulário usa POST, proteção CSRF do CRM e um nonce adicional de uso único vinculado à fatura e ao contato.

Quando existe uma cobrança aberta para a mesma fatura, o módulo confere no Asaas o cliente, a referência, o valor e a situação. Havendo exatamente uma cobrança compatível, solicita sua exclusão e **confirma a exclusão por GET antes de emitir o QR de adesão**. Cobranças pagas, agrupadas, divergentes ou sem identificação inequívoca impedem a substituição. O consentimento informa essa substituição ao cliente.

O QR inicial expira em dez minutos. A resposta de criação fornece um identificador de conciliação do QR; não fornece um `paymentId` de primeira cobrança nem permite anexar o QR a um boleto existente. Esses campos são descritos em [Criar uma autorização](https://docs.asaas.com/reference/criar-uma-autorizacao-pix-automatico).

Para associar esse primeiro Pix à fatura original, o módulo consulta o pagamento e sua transação Pix. Exige a correspondência exata entre `immediateQrCode.conciliationIdentifier`, `transaction.conciliationIdentifier`, `transaction.payment`, o cliente e o valor. Também verifica ambiente, empresa, fatura e ausência de agrupamento. Essa associação é uma decisão de integração baseada nos campos oficiais de [Recuperar uma transação Pix](https://docs.asaas.com/reference/recuperar-uma-unica-transacao), não uma dedução por nome, descrição ou valor semelhante.

**Pagamento inicial e autorização são confirmações separadas.** Gerar o QR (`CREATED`) não ativa o débito nem baixa a fatura. A autorização deve chegar a `ACTIVE` mediante confirmação bancária. Se o primeiro Pix for recebido e a autorização posteriormente for recusada, o pagamento continua válido; somente os débitos futuros ficam indisponíveis. Veja os [fluxos oficiais de webhook](https://docs.asaas.com/docs/fluxos-de-webhook).

### Valor inicial, juros e descontos

O QR de adesão usa o **saldo da fatura exibido e aceito pelo cliente**. O contrato `immediateQrCode` não oferece os mesmos campos de multa, juros e desconto da cobrança normal. Portanto, o primeiro QR não reproduz automaticamente encargos externos calculados no boleto nem descontos adicionais do Asaas. Se o valor atual da cobrança consultada divergir do saldo do CRM, a adesão é bloqueada para conferência.

Para uma fatura vencida que deva conservar exatamente as condições do boleto existente, utilize o pagamento pontual já disponível. As próximas cobranças automáticas usam os parâmetros de multa, juros e desconto existentes no gateway, sujeitos à validação do Asaas. O módulo não altera esses parâmetros nem as regras atuais de envio de telefone/e-mail ao cadastro de clientes.

## 3. Próximas faturas e frequência

Após a autorização estar ativa, novas faturas Asaas elegíveis podem receber o meio Pix Automático e entrar na fila local. A emissão da fatura não faz um POST financeiro dentro da transação de gravação do CRM. O cron prepara e envia a cobrança em uma execução posterior.

Regras locais:

- Apenas faturas novas após a adesão, em BRL e com esse meio permitido, são candidatas. A adesão não inclui retroativamente dívidas antigas.
- A frequência é escolhida explicitamente pelo cliente: semanal, mensal, trimestral, semestral ou anual. Alterá-la exige cancelar e criar uma nova autorização com novo consentimento.
- Há uma cobrança por ciclo autorizado. O primeiro pagamento ocupa o ciclo inicial. O controle semanal usa períodos de sete dias a partir da adesão; os demais usam períodos de meses a partir do mês de adesão. Outra fatura do mesmo ciclo permanece para pagamento pontual.
- O módulo não muda a configuração de recorrência que gera faturas no Perfex. A frequência das faturas deve ser compatível com a autorização escolhida.
- Antes de cada novo envio, a autorização é consultada no Asaas. Mandatos cancelados, recusados, expirados, pendentes de cancelamento ou de escopo divergente não geram novos débitos.
- O primeiro recebimento precisa estar efetivamente registrado no CRM antes do envio de ciclos seguintes.

O envio ocorre entre **2 e 10 dias úteis antes do vencimento**, conforme o contrato de agendamento da [implementação oficial](https://docs.asaas.com/docs/pix-automatico-implementacao). Mais distante que essa janela, o job aguarda. Com menos de dois dias úteis, ele é marcado como fora do prazo, mantendo o pagamento pontual. A API continua sendo a autoridade final sobre aceitação, datas e limites definidos no banco do pagador.

O calendário local usa `America/Sao_Paulo`, fins de semana, feriados nacionais cadastrados no código, Carnaval, Sexta-feira Santa e Corpus Christi. Datas adicionais são configuradas como `AAAA-MM-DD`, separadas por vírgula. Datas inválidas não substituem a configuração anterior. Essa configuração não altera o calendário do Asaas; serve para o módulo evitar agendamentos fora da janela.

## 4. Cron, webhook e recuperação de indisponibilidade

O webhook permanece o caminho habitual de confirmação do pagamento. O cron complementa esse fluxo: revisa autorizações, procura faturas novas que ainda não entraram na fila e consulta cobranças ou operações cujo resultado ficou pendente.

Por execução, o worker seleciona até cinco autorizações para consulta, quarenta candidatos para inclusão local e dez jobs para processamento. A paginação e os cursores persistidos permitem continuar nas próximas execuções. Falhas por item recebem nova data de conferência para não monopolizar a fila. Esses limites controlam trabalho e consumo da API; não apagam faturas ou impedem percorrer o histórico elegível.

Há um orçamento cooperativo de quarenta segundos, conferido entre operações. Ele não interrompe uma requisição HTTP em andamento. O transporte Pix usa os limites atuais do Provider — trinta segundos por requisição e dez de conexão — e não herda o mínimo de noventa segundos do fluxo de cartão.

Se a VPS ficar fora do ar, a recuperação não depende exclusivamente dos eventos que chegaram. Para o primeiro QR ainda sem `paymentId`, o worker percorre cobranças Pix do cliente e consulta suas transações, preservando o cursor. Para ciclos seguintes com resposta perdida, procura a referência da fatura e confere a autorização nas instruções bancárias. Identificadores ou páginas contraditórios mantêm a operação pendente para revisão.

Uma instrução `SCHEDULED` ou `DONE` **não comprova recebimento**. A baixa exige pagamento `RECEIVED` ou `CONFIRMED`, vínculo inequívoco e recibo realmente persistido no CRM com fatura, transação e principal correspondentes. Um retorno positivo do writer, sozinho, não encerra a conciliação. O mesmo `transactionid` não pode quitar outra fatura ou ser reutilizado com outro valor.

O botão **Atualizar situação** faz consultas, sem criar nova cobrança. Uma leitura normal da página também não dispara a API financeira. O cron do Pix funciona independentemente da opção de conciliação automática do monitoramento geral, porque é responsável pelo agendamento desta modalidade.

## 5. Erros, cancelamento e pagamento alternativo

| Situação | Comportamento |
| --- | --- |
| Autorização criada, ainda sem confirmação | Exibe QR válido e aguarda confirmação do banco. |
| Cobrança agendada ou resultado incerto | Mantém a proteção contra outro pagamento da mesma fatura. |
| Timeout, resposta inválida, HTTP 408/409/429 ou falha do servidor após envio | Não repete POST/DELETE; registra resultado incerto e consulta a operação já iniciada. |
| Rejeição definitiva da própria criação | Registra a falha; outra tentativa exige ação explícita, respeitando o estado local e bancário. |
| Todas as instruções confirmadas como recusadas/canceladas e cobrança ainda pendente | Libera a alternativa pontual. Uma instrução recusada não revoga necessariamente o mandato. |
| Recebimento confirmado com saldo ou recibo divergente | Mantém conferência financeira; não força baixa nem oferece novo débito automático. |
| Solicitação de cancelamento da autorização | Registra a intenção antes do DELETE, impede novos ciclos e confere o resultado por GET. |

Uma resposta perdida nunca é interpretada como prova de que o Asaas não criou a cobrança. A recuperação procura o contrato ou pagamento existente; não apaga o diário para tentar novamente. Uma página vazia com metadados inconsistentes também não comprova ausência de operação.

No primeiro QR, a alternativa pontual só é liberada quando há estado terminal confirmado, expiração do QR com margem de cinco minutos e uma varredura completa iniciada depois dessa margem, sem recebimento correspondente. Para cobranças futuras, o estado das instruções e do pagamento continua sendo conferido mesmo após cancelar a autorização. Cancelar a autorização não é confirmação imediata de cancelamento de toda operação bancária já enviada.

A política desta integração é `retryPolicy=NOT_ALLOWED`; ela não solicita retentativas extradia. Eventuais procedimentos do banco durante o próprio dia continuam sujeitos ao produto Asaas. As diferenças estão descritas em [Retentativas do Pix Automático](https://docs.asaas.com/docs/pix-autom%C3%A1tico-processo-de-retentativas-jornada-3-api).

Cartão recorrente e Pix Automático compartilham o lock de cliente/conta e não podem ter autorizações concorrentes. Uma operação Pix em preparação, enviada ou incerta também protege a fatura contra baixa manual, edição financeira ou nova cobrança pontual até a conferência necessária. Os pagamentos avulsos continuam disponíveis quando não existe operação em voo para a fatura.

## 6. Diagnóstico, escopo e dados persistidos

Em **Asaas → Monitoramento** ou na subguia correspondente dos meios de pagamento, consulte a fatura e seus eventos. Nos registros de API/diagnóstico, filtre a origem **Pix Automático** (`automatic_pix`). As mensagens incluem o motivo de conferência e a identificação local da fatura, sem copiar corpos da API, QR, credenciais ou dados bancários do pagador para o log.

O diário registra consentimento, contato, frequência, fatura, identificadores bancários, valores em centavos e fases da operação. O QR e a imagem são criptografados em repouso usando a criptografia já configurada no CRM; o conteúdo é retirado do registro quando a autorização deixa o estado de criação. Preserve a chave de criptografia do CRM nos backups e restaurações.

O escopo inclui ambiente, empresa e hash da credencial Asaas. Trocar a chave, alternar Homologação/Produção ou mudar a empresa não transfere automaticamente um mandato. Operações do escopo anterior ficam para conferência; não são debitadas com outra conta. Não edite hashes, identificadores ou estados diretamente para contornar esse bloqueio. Uma rotação de credencial demanda conferir os mandatos pendentes sob a conta original.

Este recurso não coleta dados de cartão. O browser não persiste QR, autorização ou credenciais em `localStorage`, `sessionStorage` ou cookies próprios. A sessão nativa do CRM continua necessária para autenticação e nonce; as respostas da página recebem `Cache-Control: no-store`.

## 7. Desativação e reversão

Desabilitar o gateway impede novas adesões e novos envios financeiros. **Não cancela autorizações no Asaas nem comprova que instruções já enviadas foram encerradas.** Mantenha cron, webhook e o código de recuperação funcionando até conferir o resultado das operações pendentes. A consulta de operações já iniciadas continua disponível mesmo com o gateway desativado.

Antes de reverter para uma versão sem Pix Automático, interrompa novas adesões, trate autorizações e pagamentos pendentes na conta correta, confirme recebimentos e cancelamentos e faça backup das duas tabelas novas. Uma versão anterior não conhece os locks e estados Pix; não deve processar a mesma fatura enquanto houver cobrança bancária em voo. As tabelas aditivas podem ser preservadas no rollback: apagar os registros elimina a correlação necessária para recuperar respostas perdidas e prevenir duplicação.

## 8. Testes e liberação

Testes incluídos, sem chamada financeira real:

- `tests/automatic-pix-service-smoke.php`: fluxo, consentimento, vínculo inicial, concorrência, calendário, cursor, falhas de transporte, recusa, cancelamento e recibos persistidos.
- `tests/automatic-pix-controller-smoke.php`: dono da conta, permissão de faturas, HTTPS, POST, nonce, replay, PRG e dados permitidos na view.
- `tests/automatic-pix-api-smoke.php`: SDK/Provider/Guzzle reais com handler offline; caminhos, filtros, cabeçalhos, timeouts, ausência de repetição/redirecionamento e descarte de corpos sensíveis.
- `tests/automatic-pix-interlocks-smoke.php`: webhook nativo autenticado e repetido, recebimento manual com sincronização desabilitada, concorrência com cartão e proteção durante transações do núcleo.
- `tests/automatic-pix-mysql-smoke.php`: DDL, CAS, índices únicos e locks compartilhados em MariaDB descartável. Usa `ASAAS_MONITOR_TEST_DSN`, `ASAAS_MONITOR_TEST_USER` e `ASAAS_MONITOR_TEST_PASSWORD`.
- `tests/automatic-pix-browser-smoke.cjs`: views PHP e Bootstrap 3 reais em 360/768/1280 px, fluxo nativo POST/303/GET e ausência de armazenamento adicional no browser. `--contract-only` executa somente o contrato HTML.

Esses testes não substituem uma homologação bancária. Antes de liberar a modalidade para clientes, valide na conta elegível: adesão e QR, primeiro Pix recebido antes/depois do evento de ativação, recusa da autorização após pagamento inicial, ciclo dentro da janela, cancelamento com instrução pendente e recuperação sem webhook. Confira especialmente os campos de correlação do primeiro QR e o recibo final da fatura. Não é necessário usar dados ou credenciais reais nos testes automatizados do repositório.

### Transações de núcleos customizados

O núcleo e suas customizações devem concluir suas próprias transações de recebimento. Se uma transação externa continuar aberta, o módulo conserva o bloqueio da fatura até o encerramento da conexão, sem executar commit ou rollback de uma transação alheia. Em uma conexão persistente mal gerenciada, novas tentativas podem permanecer bloqueadas até o núcleo concluir a transação ou descartar a conexão. O diagnóstico identifica essa condição.
