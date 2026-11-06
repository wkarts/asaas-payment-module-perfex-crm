# Changelog

## Não publicado

- Acrescenta o meio **Cartão de crédito recorrente – Asaas**, separado do checkout avulso, com adesão autenticada, consentimento explícito, token criptografado e revogação pelo cliente.
- Enfileira somente a fatura autorizada e as próximas faturas Asaas elegíveis, cobrando a mesma obrigação em 1x pelo cron; uma recusa suspende a recorrência e uma resposta incerta permite somente consulta, sem repetir captura.
- Preserva o recebimento nativo, as taxas à vista e a conciliação existente; inclui migração aditiva, diagnóstico por origem Recorrência e verificações de concorrência, autorização, transporte e privacidade.
- Restaura a cobrança original após recusa confirmada do cartão sem reenviar o vencimento, evitando rejeição de data passada e bloqueio indevido do pagamento avulso em boletos vencidos.
- Recupera vínculos e recebimentos pela API sem depender de webhook, incluindo faturas Asaas antigas e parciais; o cron passa a percorrer abertas sem corte de emissão/vencimento, preservando bloqueios e verificação de duplicidade.
- Acrescenta **Cobranças no Asaas** ao monitoramento, com paginação remota, filtros por situação e período, associação conservadora e ações individuais/em lote para faturas identificadas.
- Torna os erros do resumo acessíveis diretamente e detalha falhas de consulta/conciliação com códigos seguros, sem expor credenciais ou dados de cartão.
- Inclui antecipação avulsa/automática na precificação do cartão, com taxas mensais próprias para à vista/parcelado e prazo inicial ajustável. O cliente vê somente os encargos agregados; a opção inicia desabilitada e não solicita antecipação nem altera a conta Asaas.
- Preserva as condições da tentativa já capturada e exige nova conferência quando modalidade, taxa ou prazo de antecipação mudam antes do pagamento.
- Corrige o clique em **Pagar agora** para reapresentar uma cobrança existente, inclusive boleto vencido pagável, sem alterar a fatura ou reenviar vencimento/valor ao Asaas. Remove saídas públicas de debug do gateway.
- Acrescenta parâmetros de taxas e parcelamento em **Cobranças → Cartão de crédito**, com repasse opcional, mínimo por parcela, faixas de taxas e até 21 parcelas para Visa/Mastercard. A empresa absorve o custo por padrão.
- Confere o preço apresentado antes da captura e distribui somente o principal entre recibos com repasse, preservando centavos, duplicidade e recuperação por webhook/conciliação.
- Exibe o diagnóstico completo dentro da guia **Logs**, com leitura administrativa sob demanda e compatibilidade com a rota independente.
- Diferencia estado da cobrança, confirmação de pagamento e processamento do webhook no monitoramento. Coordena consultas de Logs/Monitoramento com a renovação do CSRF nativo.

## [1.5.0](https://github.com/wkarts/asaas-payment-module-perfex-crm/compare/v1.4.1...v1.5.0) (2026-02-26)


### Features

* adicionar log opcional em arquivo para debug do envio Asaas ([9860602](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/986060287941ad9d8e620903d7804986597853ad))
* atualizar SDK argws e implementar módulo Prefaces no adapter ([4f6c73a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/4f6c73ada564f3d535023ff15e37e698c9727be8))
* atualizar SDK e implementar módulo Prefaces no adapter ([5adfc16](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/5adfc1641432c15a0ccb9ff0468df4f7cb0ebf56))


### Bug Fixes

* alinhar release com versão do módulo e migration Perfex ([64b6d25](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/64b6d25f95dbc3fa8b4b12a2c33ffe9e32fb534a))
* estabilizar hook de fatura e blindar json_decode no Asaas ([92ad347](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/92ad347018cf73ad9daca350fb24e244c7f1b77c))
* impedir salto de versão no release-please usando release-as ([c4a6a10](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/c4a6a1057f096caefcdc8fd421d17dad695a10ba))
* normalizar tag de release e forçar sequência patch ([a775985](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/a775985976a3330b3cc0ed45c92be10f26ad6ed6))
* reforçar sdk-first, wrappers do gateway e logging resiliente ([ef2137a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/ef2137aac9ade265ce78ddd4498e78fdaa606b54))
* sincronizar release-please com versão do módulo Perfex ([d508938](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/d5089385ce0aa82f1184464b3baa8629df444454))
* travar próximo release em patch sequencial (1.4.1) ([ea605c2](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/ea605c2d77598ecab1fb0029033770d514d91bbd))
* usar SDK no Main e ajustar scan de padrões no CI/Release ([793f0c9](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/793f0c991afe46452077b1d47cb9a262aae13f5e))

## [1.5.0](https://github.com/wkarts/asaas-payment-module-perfex-crm/compare/v1.4.1...v1.5.0) (2026-02-26)


### Features

* adicionar log opcional em arquivo para debug do envio Asaas ([9860602](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/986060287941ad9d8e620903d7804986597853ad))
* atualizar SDK argws e implementar módulo Prefaces no adapter ([4f6c73a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/4f6c73ada564f3d535023ff15e37e698c9727be8))
* atualizar SDK e implementar módulo Prefaces no adapter ([5adfc16](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/5adfc1641432c15a0ccb9ff0468df4f7cb0ebf56))


### Bug Fixes

* alinhar release com versão do módulo e migration Perfex ([64b6d25](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/64b6d25f95dbc3fa8b4b12a2c33ffe9e32fb534a))
* estabilizar hook de fatura e blindar json_decode no Asaas ([92ad347](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/92ad347018cf73ad9daca350fb24e244c7f1b77c))
* normalizar tag de release e forçar sequência patch ([a775985](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/a775985976a3330b3cc0ed45c92be10f26ad6ed6))
* reforçar sdk-first, wrappers do gateway e logging resiliente ([ef2137a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/ef2137aac9ade265ce78ddd4498e78fdaa606b54))
* sincronizar release-please com versão do módulo Perfex ([d508938](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/d5089385ce0aa82f1184464b3baa8629df444454))
* travar próximo release em patch sequencial (1.4.1) ([ea605c2](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/ea605c2d77598ecab1fb0029033770d514d91bbd))
* usar SDK no Main e ajustar scan de padrões no CI/Release ([793f0c9](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/793f0c991afe46452077b1d47cb9a262aae13f5e))

## [1.5.0](https://github.com/wkarts/asaas-payment-module-perfex-crm/compare/v1.4.0...v1.5.0) (2026-02-26)


### Features

* adicionar log opcional em arquivo para debug do envio Asaas ([9860602](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/986060287941ad9d8e620903d7804986597853ad))

## [1.4.0](https://github.com/wkarts/asaas-payment-module-perfex-crm/compare/v1.3.5...v1.4.0) (2026-02-26)


### Features

* atualizar SDK argws e implementar módulo Prefaces no adapter ([4f6c73a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/4f6c73ada564f3d535023ff15e37e698c9727be8))
* atualizar SDK e implementar módulo Prefaces no adapter ([5adfc16](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/5adfc1641432c15a0ccb9ff0468df4f7cb0ebf56))


### Bug Fixes

* alinhar release com versão do módulo e migration Perfex ([64b6d25](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/64b6d25f95dbc3fa8b4b12a2c33ffe9e32fb534a))
* estabilizar hook de fatura e blindar json_decode no Asaas ([92ad347](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/92ad347018cf73ad9daca350fb24e244c7f1b77c))
* normalizar tag de release e forçar sequência patch ([a775985](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/a775985976a3330b3cc0ed45c92be10f26ad6ed6))
* reforçar sdk-first, wrappers do gateway e logging resiliente ([ef2137a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/ef2137aac9ade265ce78ddd4498e78fdaa606b54))
* sincronizar release-please com versão do módulo Perfex ([d508938](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/d5089385ce0aa82f1184464b3baa8629df444454))
* usar SDK no Main e ajustar scan de padrões no CI/Release ([793f0c9](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/793f0c991afe46452077b1d47cb9a262aae13f5e))

## [1.4.0](https://github.com/wkarts/asaas-payment-module-perfex-crm/compare/asaas-module-perfex-v1.3.5...asaas-module-perfex-v1.4.0) (2026-02-26)


### Features

* atualizar SDK argws e implementar módulo Prefaces no adapter ([4f6c73a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/4f6c73ada564f3d535023ff15e37e698c9727be8))
* atualizar SDK e implementar módulo Prefaces no adapter ([5adfc16](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/5adfc1641432c15a0ccb9ff0468df4f7cb0ebf56))


### Bug Fixes

* alinhar release com versão do módulo e migration Perfex ([64b6d25](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/64b6d25f95dbc3fa8b4b12a2c33ffe9e32fb534a))
* estabilizar hook de fatura e blindar json_decode no Asaas ([92ad347](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/92ad347018cf73ad9daca350fb24e244c7f1b77c))
* reforçar sdk-first, wrappers do gateway e logging resiliente ([ef2137a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/ef2137aac9ade265ce78ddd4498e78fdaa606b54))
* sincronizar release-please com versão do módulo Perfex ([d508938](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/d5089385ce0aa82f1184464b3baa8629df444454))
* usar SDK no Main e ajustar scan de padrões no CI/Release ([793f0c9](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/793f0c991afe46452077b1d47cb9a262aae13f5e))

## [1.0.2](https://github.com/wkarts/asaas-payment-module-perfex-crm/compare/v1.0.1...v1.0.2) (2026-02-26)


### Bug Fixes

* reforçar sdk-first, wrappers do gateway e logging resiliente ([ef2137a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/ef2137aac9ade265ce78ddd4498e78fdaa606b54))

## [1.0.1](https://github.com/wkarts/asaas-payment-module-perfex-crm/compare/v1.0.0...v1.0.1) (2026-02-26)


### Bug Fixes

* alinhar release com versão do módulo e migration Perfex ([64b6d25](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/64b6d25f95dbc3fa8b4b12a2c33ffe9e32fb534a))

## 1.0.0 (2026-02-26)


### Features

* atualizar SDK argws e implementar módulo Prefaces no adapter ([4f6c73a](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/4f6c73ada564f3d535023ff15e37e698c9727be8))
* atualizar SDK e implementar módulo Prefaces no adapter ([5adfc16](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/5adfc1641432c15a0ccb9ff0468df4f7cb0ebf56))


### Bug Fixes

* estabilizar hook de fatura e blindar json_decode no Asaas ([92ad347](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/92ad347018cf73ad9daca350fb24e244c7f1b77c))
* usar SDK no Main e ajustar scan de padrões no CI/Release ([793f0c9](https://github.com/wkarts/asaas-payment-module-perfex-crm/commit/793f0c991afe46452077b1d47cb9a262aae13f5e))
