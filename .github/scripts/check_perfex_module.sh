#!/usr/bin/env bash
set -euo pipefail

MODULE_FILE="asaas.php"
MIGRATIONS_DIR="migrations"

if [[ ! -f "$MODULE_FILE" ]]; then
  echo "Arquivo principal do módulo não encontrado: $MODULE_FILE"
  exit 1
fi

if [[ ! -d "$MIGRATIONS_DIR" ]]; then
  echo "Diretório de migrations não encontrado: $MIGRATIONS_DIR"
  exit 1
fi

# 1) Garantir que existe versão no cabeçalho do módulo
if ! grep -qE '^Version:[[:space:]]*[0-9]+\.[0-9]\.[0-9][[:space:]]*$' "$MODULE_FILE"; then
  echo "Version do módulo inválida: minor e patch devem ter um dígito (ex.: 1.4.9 -> 1.5.0)."
  exit 1
fi

# 2) Validar migrations sequenciais (sem buracos) para padrão atual do módulo: NNN_version_NNN.php
mapfile -t files < <(find "$MIGRATIONS_DIR" -maxdepth 1 -type f -name '*_version_*.php' -printf '%f\n' | sort -V)

if [[ ${#files[@]} -eq 0 ]]; then
  echo "Nenhuma migration encontrada em $MIGRATIONS_DIR"
  exit 1
fi

prev=""
for f in "${files[@]}"; do
  if [[ ! "$f" =~ ^([0-9]+)_version_([0-9]+)\.php$ ]]; then
    echo "Nome de migration inválido: $f"
    exit 1
  fi

  num_file="${BASH_REMATCH[1]}"
  num_class="${BASH_REMATCH[2]}"

  if [[ "$num_file" != "$num_class" ]]; then
    echo "Migration inconsistente (arquivo != sufixo de versão): $f"
    exit 1
  fi

  if [[ -n "$prev" ]]; then
    if ((10#$num_file != 10#$prev + 1)); then
      echo "Sequência de migrations com buraco: esperado $((10#$prev + 1)), encontrado $num_file"
      exit 1
    fi
  fi

  prev="$num_file"
done

echo "Validações Perfex OK: versão do módulo e sequência de migrations." 
