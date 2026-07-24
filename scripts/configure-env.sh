#!/usr/bin/env bash
#
# Configuração incremental do Portal FMU/Canvas.
#
# Diferente do deploy.sh, este script NÃO instala nada nem publica arquivos:
# ele apenas verifica quais configurações/credenciais já existem em
# /etc/fmu-portal.env (ou no ambiente) e pede APENAS as que ainda não existem,
# acrescentando-as ao arquivo sem alterar as que já estão definidas.
#
# Uso:  sudo bash scripts/configure-env.sh
#
set -uo pipefail

ENV_FILE="${FMU_ENV_FILE:-/etc/fmu-portal.env}"
WEB_USER="www-data"

say()  { printf '%s\n' "$*"; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[32m  ✓ %s\033[0m\n' "$*"; }
skip() { printf '\033[90m  · %s\033[0m\n' "$*"; }
warn() { printf '\033[33m  ! %s\033[0m\n' "$*" >&2; }
err()  { printf '\033[31m  ✗ %s\033[0m\n' "$*" >&2; }

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    command -v sudo >/dev/null 2>&1 && SUDO="sudo"
fi

ask() {
    local p="$1" d="${2:-}" a=""
    if [ -n "$d" ]; then read -r -p "$p [$d]: " a || true; printf '%s' "${a:-$d}"
    else read -r -p "$p: " a || true; printf '%s' "$a"; fi
}
ask_secret() { local p="$1" a=""; read -r -s -p "$p: " a || true; printf '\n' >&2; printf '%s' "$a"; }
ask_yesno() {
    local p="$1" d="${2:-n}" a="" h; [ "$d" = s ] && h="S/n" || h="s/N"
    read -r -p "$p [$h]: " a || true; a="${a:-$d}"; case "$a" in [sSyY]*) return 0;; *) return 1;; esac
}

# --------------------------------------------------------------------------
# 1. Descobre o que já existe (arquivo + ambiente)
# --------------------------------------------------------------------------
declare -A EXISTING

if [ -f "$ENV_FILE" ]; then
    while IFS= read -r line; do
        if [[ "$line" =~ ^([A-Z_][A-Z0-9_]*)=(.*)$ ]]; then
            key="${BASH_REMATCH[1]}"
            val="${BASH_REMATCH[2]}"
            val="${val%\"}"; val="${val#\"}"
            val="${val%\'}"; val="${val#\'}"
            [ -n "$val" ] && EXISTING["$key"]=1
        fi
    done < "$ENV_FILE"
fi

var_exists() {
    [ -n "${EXISTING[$1]:-}" ] && return 0
    [ -n "${!1:-}" ] && return 0   # variável presente no ambiente atual
    return 1
}

# Especificações: nome|secreto(y/n)|obrigatório(y/n)|padrão|descrição
ESSENTIAL=(
    "MONGODB_URI|y|y||String de conexão do MongoDB (com credenciais)"
    "MONGODB_DATABASE|n|n|activity|Banco de dados"
    "CANVAS_BASE_URL|n|n|https://afya.instructure.com|Host do Canvas"
    "CANVAS_API_TOKEN|y|n||Token de acesso do Canvas"
    "APP_TIMEZONE|n|n|America/Sao_Paulo|Fuso horário"
    "LOGIN_THROTTLE_DIR|n|n|/var/lib/fmu-portal/throttle|Pasta gravável do controle de login"
)
ADVANCED=(
    "MONGODB_ACTIVITY_COLLECTION|n|n|fmu_activity_control|Collection de disciplinas (FMU)"
    "MONGODB_USER_COLLECTION|n|n|fmu_user_control|Collection de usuários"
    "MONGODB_CANVAS_COLLECTION|n|n|canvas_blueprints|Collection de blueprints (Canvas)"
    "CANVAS_PAGE_SIZE|n|n|10|Blueprints por página"
    "CANVAS_TIMEOUT_SECONDS|n|n|20|Timeout das chamadas ao Canvas"
    "ADMIN_USERS|n|n|gomining|Logins com acesso ao upload"
    "LTI_CONTROL_BASE_URL|n|n||URL do serviço LTI (vazio = padrão do código)"
    "LTI_CONTROL_INSTITUTION|n|n|fmu|institution do painel FMU"
    "LTI_CONTROL_INSTITUTION_AFYA|n|n|afya|institution do painel AFYA"
    "APP_SESSION_NAME|n|n|fmu_auto_grading_portal|Nome do cookie de sessão"
)

ADD_KEYS=()
ADD_VALS=()

process_spec() {
    local spec="$1" name secret required default desc val
    IFS='|' read -r name secret required default desc <<< "$spec"

    if var_exists "$name"; then
        skip "$name — já configurada"
        return
    fi

    if [ "$secret" = "y" ]; then
        val="$(ask_secret "  $desc [$name]")"
        while [ "$required" = "y" ] && [ -z "$val" ]; do
            warn "Obrigatória."
            val="$(ask_secret "  $desc [$name]")"
        done
    else
        val="$(ask "  $desc [$name]" "$default")"
        while [ "$required" = "y" ] && [ -z "$val" ]; do
            warn "Obrigatória."
            val="$(ask "  $desc [$name]" "$default")"
        done
    fi

    if [ -n "$val" ]; then
        ADD_KEYS+=("$name")
        ADD_VALS+=("$val")
        ok "$name — será adicionada"
    else
        warn "$name — deixada em branco (usará o padrão do sistema)"
    fi
}

# --------------------------------------------------------------------------
# 2. Relatório do que já existe
# --------------------------------------------------------------------------
info "=== Configuração incremental (só o que falta) ==="
say "Arquivo: $ENV_FILE"
if [ "${#EXISTING[@]}" -gt 0 ]; then
    say "Já configuradas: ${!EXISTING[*]}"
else
    say "Nenhuma configuração encontrada ainda."
fi
say ""

# --------------------------------------------------------------------------
# 3. Pergunta as principais que faltam
# --------------------------------------------------------------------------
info "Configurações principais:"
for spec in "${ESSENTIAL[@]}"; do
    process_spec "$spec"
done
say ""

# --------------------------------------------------------------------------
# 4. Avançadas que faltam (opcional)
# --------------------------------------------------------------------------
missing_adv=0
for spec in "${ADVANCED[@]}"; do
    name="${spec%%|*}"
    var_exists "$name" || missing_adv=$((missing_adv + 1))
done

if [ "$missing_adv" -gt 0 ]; then
    if ask_yesno "Há $missing_adv configuração(ões) avançada(s) ainda não definida(s). Configurar agora?" "n"; then
        info "Configurações avançadas:"
        for spec in "${ADVANCED[@]}"; do
            process_spec "$spec"
        done
    else
        skip "Avançadas mantidas com os padrões do sistema."
    fi
else
    ok "Todas as configurações avançadas já existem."
fi
say ""

# --------------------------------------------------------------------------
# 5. Grava apenas o que falta
# --------------------------------------------------------------------------
if [ "${#ADD_KEYS[@]}" -eq 0 ]; then
    ok "Nada a fazer — tudo que era necessário já estava configurado."
    exit 0
fi

if [ ! -f "$ENV_FILE" ]; then
    printf '# Portal FMU/Canvas — criado por configure-env.sh em %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" | $SUDO tee "$ENV_FILE" >/dev/null
else
    $SUDO cp -a "$ENV_FILE" "${ENV_FILE}.bak.$(date '+%Y%m%d%H%M%S')" 2>/dev/null || true
fi

BLOCK="# Adicionadas por configure-env.sh em $(date '+%Y-%m-%d %H:%M:%S')"
for i in "${!ADD_KEYS[@]}"; do
    BLOCK="${BLOCK}
${ADD_KEYS[$i]}='${ADD_VALS[$i]}'"
done

printf '%s\n' "$BLOCK" | $SUDO tee -a "$ENV_FILE" >/dev/null
$SUDO chown "root:$WEB_USER" "$ENV_FILE" 2>/dev/null || true
$SUDO chmod 640 "$ENV_FILE" 2>/dev/null || true

ok "${#ADD_KEYS[@]} configuração(ões) adicionada(s) a $ENV_FILE (permissão 640)."

# Garante a pasta de throttle se ela foi definida agora
for i in "${!ADD_KEYS[@]}"; do
    if [ "${ADD_KEYS[$i]}" = "LOGIN_THROTTLE_DIR" ]; then
        $SUDO mkdir -p "${ADD_VALS[$i]}" 2>/dev/null && \
            $SUDO chown -R "$WEB_USER:$WEB_USER" "$(dirname "${ADD_VALS[$i]}")" 2>/dev/null || true
        ok "Pasta de throttle pronta: ${ADD_VALS[$i]}"
    fi
done

say ""
info "=== Concluído ==="
say "As demais configurações permaneceram como estavam. Se o portal já está no ar"
say "com PHP-FPM, reinicie-o para recarregar as variáveis (ex.: sudo systemctl restart php8.3-fpm)."
say "No modo mod_php/site padrão do deploy.sh, o app relê o arquivo sozinho a cada requisição."
