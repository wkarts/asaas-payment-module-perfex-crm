# Releasing do módulo Asaas

Este módulo usa **Conventional Commits** + **Release Please** para versionamento e releases automáticas.

## Conventional Commits (resumo)
- **patch**: `fix: corrigir erro no gateway`
- **minor**: `feat: adicionar nova tela de configuração`
- **major**: `feat!: mudança incompatível` ou adicionar `BREAKING CHANGE:` no corpo

### Exemplos
```
fix: corrigir provider do Asaas
feat: adicionar healthcheck
feat!: alterar contrato de webhook
```

## Regra de versionamento do módulo (Perfex)
- Quando **uma migration é adicionada**, a **versão do módulo também deve subir**.
- Sem migration, siga apenas a regra do Conventional Commits.

## Como gerar release (automático)
1. Faça merge na branch **main**.
2. O workflow `Release` roda, executa os testes e então:
   - cria/atualiza a **tag** `vX.Y.Z`
   - cria o **GitHub Release**
   - anexa o **ZIP instalável** do módulo

## Como rodar manualmente
- Vá em **Actions → Release → Run workflow**.
- O fluxo executa testes; se tudo passar, gera a release.

## Onde baixar o ZIP
- Na página de **Releases** do repositório.
- O asset é anexado com nome `asaas-module-vX.Y.Z.zip`.
