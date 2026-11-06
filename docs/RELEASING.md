# Publicação do módulo Asaas

## Sequência de versões

O módulo usa um dígito para a segunda e a terceira posições. Ao avançar depois de `9`, a posição volta a `0` e a anterior aumenta:

```text
1.4.8 → 1.4.9 → 1.5.0 → 1.5.1
1.5.8 → 1.5.9 → 1.6.0 → 1.6.1
1.9.8 → 1.9.9 → 2.0.0 → 2.0.1
```

Essa regra é implementada em `scripts/module-version.php` e usada por `scripts/bump-patch.php`. O cabeçalho de `asaas.php`, o manifesto e a migration correspondente são atualizados juntos. O CI rejeita um novo cabeçalho com a segunda ou a terceira posição acima de `9`.

### Correção da versão histórica 1.4.10

`1.4.10` é normalizada para `1.5.0`. Ambas correspondem à migration `150` na fórmula do Perfex (`major × 100 + minor × 10 + patch`). A normalização preserva o arquivo `150_version_150.php`; não reexecuta nem substitui uma migration já aplicada.

Ao receber uma versão histórica fora da sequência, o script normaliza primeiro: `1.4.10 → 1.5.0`. Na execução seguinte, avança para `1.5.1`. Tags e artefatos já publicados não são apagados nem movidos por esse script.

## Alterações de banco

Quando uma migration nova é adicionada, a versão declarada do módulo também precisa avançar. Por exemplo, `151_version_151.php` pertence à versão `1.5.1`. Preserve a sequência dos arquivos e não altere migrations já publicadas para acrescentar uma atualização nova.

Na atualização que introduz o monitoramento, a migration `151` acrescenta índices às tabelas de eventos e logs. Ela pode ser executada novamente sem duplicar índices e não altera os registros. Instalações novas recebem os mesmos índices pelo `install.php`.

## Fluxo existente no GitHub

1. A PR executa o workflow `CI`, incluindo os testes do versionamento, as regressões do módulo, a interface em Chromium e as consultas do monitoramento em MariaDB.
2. Após o merge em `main`, o workflow `Release` testa o versionamento e executa `scripts/bump-patch.php` para avançar a versão.
3. O workflow confere as migrations, grava o commit de versão com `[skip release]`, cria a tag e publica o ZIP com as dependências de produção.

O script não usa o título da PR ou a mensagem do commit para decidir a posição que será incrementada. Continue usando mensagens claras com Conventional Commits para o histórico; a sequência publicada segue a regra descrita acima.

O workflow também pode ser disparado em **Actions → Release → Run workflow**. Cada disparo em `main` avança a versão. Abrir uma PR ou baixar seu pacote candidato não faz merge, não cria uma release e não altera a instalação do CRM.

## Verificação local

```bash
php tests/module-version-smoke.php
php scripts/ensure-version-migration.php
bash .github/scripts/check_perfex_module.sh
```

`php scripts/ensure-version-migration.php --write` cria somente a migration que estiver ausente para a versão declarada. `php scripts/bump-patch.php` altera a versão e deve ser executado apenas ao preparar uma publicação.

## Download

As releases ficam em **Releases** no repositório, com o asset `asaas-module-vX.Y.Z.zip`. O workflow de CI também disponibiliza um pacote candidato identificado pelo SHA do commit, separado das releases.
