#!/usr/bin/env bash
# encerrar.sh — remove o que a atividade de deploy da CPVTESP criou no sandbox do AWS Academy.
#
# Salvaguardas:
#   1. Só toca recursos com a etiqueta Atividade=cpvtesp-deploy.
#   2. Só roda em conta do sandbox do AWS Academy (papel voclabs ou LabRole); em qualquer outra conta, recusa.
#   3. Sem --executar, apenas lista o que faria. Nada é removido.
#
# Uso:
#   bash encerrar.sh             # simulação: mostra o que seria removido
#   bash encerrar.sh --executar  # remove
#
# Pode rodar no terminal do Systems Manager da própria instância. Nesse caso a instância é encerrada por
# último, 60 segundos depois da linha "Fim do registro" (variável ESPERA), e o terminal cai; Ctrl+C não interrompe
# essa espera. O grupo de segurança da instância fica, porque ainda está em uso, e some com a limpeza automática do
# sandbox ao fim da sessão. A saída deste script, até "Fim do registro", faz parte da entrega.
set -uo pipefail

CHAVE="Atividade"
VALOR="cpvtesp-deploy"
export AWS_DEFAULT_REGION="us-east-1"
EXECUTAR=0
[ "${1:-}" = "--executar" ] && EXECUTAR=1

agora() { date '+%Y-%m-%d %H:%M:%S %Z'; }

identidade=$(aws sts get-caller-identity --query Arn --output text 2>/dev/null) || {
  echo "Não consegui ler a identidade da conta. A credencial do sandbox está ativa?"; exit 2; }
case "$identidade" in
  *":assumed-role/voclabs/"*|*":assumed-role/LabRole/"*) ;;
  *) echo "Recusado: esta conta não é o sandbox do AWS Academy."; echo "Identidade: $identidade"; exit 2 ;;
esac

# Se este script roda dentro de uma instância, descobre qual é, para encerrá-la por último.
token=$(curl -s -m 1 -X PUT http://169.254.169.254/latest/api/token \
  -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' 2>/dev/null || true)
eu=""
[ -n "$token" ] && eu=$(curl -s -m 1 -H "X-aws-ec2-metadata-token: $token" \
  http://169.254.169.254/latest/meta-data/instance-id 2>/dev/null || true)

bancos=$(aws rds describe-db-instances \
  --query "DBInstances[?TagList[?Key=='$CHAVE' && Value=='$VALOR']].DBInstanceIdentifier" --output text | tr '\t' ' ')
instancias=$(aws ec2 describe-instances \
  --filters "Name=tag:$CHAVE,Values=$VALOR" "Name=instance-state-name,Values=pending,running,stopping,stopped" \
  --query 'Reservations[].Instances[].InstanceId' --output text | tr '\t' ' ')
grupos=$(aws ec2 describe-security-groups --filters "Name=tag:$CHAVE,Values=$VALOR" \
  --query 'SecurityGroups[].GroupId' --output text | tr '\t' ' ')

echo "Início: $(agora)"
echo "Conta: $identidade"
[ -n "$eu" ] && echo "Rodando dentro da instância: $eu"
if [ "$EXECUTAR" = 1 ]; then echo "Modo: EXECUÇÃO"; else echo "Modo: SIMULAÇÃO (nada será removido; para remover, rode com --executar)"; fi
echo "Bancos com a etiqueta $CHAVE=$VALOR: ${bancos:-nenhum}"
echo "Instâncias com a etiqueta: ${instancias:-nenhuma}"
echo "Grupos de segurança com a etiqueta: ${grupos:-nenhum}"

# Recursos sem a etiqueta não são tocados, mas aparecem aqui para o estudante saber o que ficou de pé.
sem_etiqueta=$(aws ec2 describe-instances \
  --filters "Name=instance-state-name,Values=pending,running,stopping,stopped" \
  --query "Reservations[].Instances[?!(Tags[?Key=='$CHAVE' && Value=='$VALOR'])].InstanceId" --output text)
[ -n "$sem_etiqueta" ] && echo "Atenção: instâncias ativas ou paradas SEM a etiqueta, que este script não remove: $sem_etiqueta"

[ "$EXECUTAR" = 1 ] || { echo "Fim da simulação: $(agora)"; exit 0; }

aceitos=""
for b in $bancos; do
  if erro=$(aws rds delete-db-instance --db-instance-identifier "$b" --skip-final-snapshot --delete-automated-backups \
      --query 'DBInstance.DBInstanceStatus' --output text 2>&1); then
    echo "$(agora) banco $b: exclusão pedida"; aceitos="$aceitos $b"
  else
    # caso típico: proteção contra exclusão ligada; desligue-a no console e rode de novo
    echo "$(agora) banco $b: FALHOU ao pedir exclusão: $(echo "$erro" | tail -n 1)"
  fi
done

outras=""
for i in $instancias; do [ "$i" != "$eu" ] && outras="$outras $i"; done
if [ -n "${outras// /}" ]; then
  # shellcheck disable=SC2086
  aws ec2 terminate-instances --instance-ids $outras >/dev/null \
    && echo "$(agora) instâncias$outras: encerramento pedido" || echo "$(agora) instâncias$outras: FALHOU"
fi

for b in $aceitos; do
  echo "$(agora) aguardando o banco $b sair (leva alguns minutos)..."
  aws rds wait db-instance-deleted --db-instance-identifier "$b" && echo "$(agora) banco $b: removido"
done
if [ -n "${outras// /}" ]; then
  # shellcheck disable=SC2086
  aws ec2 wait instance-terminated --instance-ids $outras && echo "$(agora) instâncias$outras: encerradas"
fi

# Grupos: o que referencia outro sai antes; duas passadas resolvem a dependência entre os dois da atividade.
for passada in 1 2; do
  restantes=""
  for g in $grupos; do
    if aws ec2 delete-security-group --group-id "$g" >/dev/null 2>&1; then
      echo "$(agora) grupo $g: removido"
    else
      restantes="$restantes $g"
    fi
  done
  grupos=$restantes
  [ -z "${grupos// /}" ] && break
  [ "$passada" = 1 ] && sleep 15   # dá tempo para a AWS liberar a interface de rede do banco
done
[ -n "${grupos// /}" ] && echo "$(agora) grupos que ficaram, ainda em uso:$grupos"

echo "Fim do registro: $(agora)"
if [ -n "$eu" ] && echo " $instancias " | grep -q " $eu "; then
  echo "Esta instância ($eu) será encerrada em ${ESPERA:-60} segundos."
  echo "COPIE AGORA a saída acima, até a linha Fim do registro: ela faz parte da entrega, e o terminal vai cair."
  trap '' INT   # Ctrl+C, usado para copiar, não interrompe a espera nem o encerramento
  sleep "${ESPERA:-60}"
  aws ec2 terminate-instances --instance-ids "$eu" >/dev/null
fi
