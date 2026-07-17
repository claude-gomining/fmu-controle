#!/usr/bin/env bash
#
# Setup interativo do Portal FMU/Canvas.
#
# Pergunta as informações uma a uma, monta a string de conexão do MongoDB
# (externo), gera o arquivo de variáveis de ambiente com permissão restrita,
# cria a pasta de throttle e, opcionalmente, configura o PHP-FPM para carregar
# essas variáveis e testa a conexão com o MongoDB.
#
# Uso:
#   bash scripts/setup-env.sh
#
set -uo pipefail

# --------------------------------------------------------------------------
# Utilitários
# --------------------------------------------------------------------------
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1; then
        SUDO="sudo"
    fi
fi

say()  { printf '%s\n' "$*"; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[32m%s\033[0m\n' "$*"; }
warn() { printf '\033[33m%s\033[0m\n' "$*" >&2; }
err()  { printf '\033[31m%s\033[0m\n' "$*" >&2; }

# ask <prompt> <default> -> resposta no stdout (prompt vai para stderr)
ask() {
    local prompt="$1" def="${2:-}" ans=""
    if [ -n "$def" ]; then
        read -r -p "$prompt [$def]: " ans || true
        printf '%s' "${ans:-$def}"
    else
        read -r -p "$prompt: " ans || true
        printf '%s' "$ans"
    fi
}

# ask_required <prompt> -> repete até receber valor não vazio
ask_required() {
    local prompt="$1" ans=""
    while [ -z "$ans" ]; do
        read -r -p "$prompt: " ans || true
        [ -z "$ans" ] && warn "Este campo é obrigatório."
    done
    printf '%s' "$ans"
}

# ask_secret <prompt> -> leitura oculta
ask_secret() {
    local prompt="$1" ans=""
    read -r -s -p "$prompt: " ans || true
    printf '\n' >&2
    printf '%s' "$ans"
}

# ask_yesno <prompt> <default s|n>
ask_yesno() {
    local prompt="$1" def="${2:-n}" ans="" hint
    [ "$def" = "s" ] && hint="S/n" || hint="s/N"
    read -r -p "$prompt [$hint]: " ans || true
    ans="${ans:-$def}"
    case "$ans" in [sSyY]*) return 0 ;; *) return 1 ;; esac
}

# devolve "sudo" apenas se o destino não for gravável sem privilégio
maybe_sudo() {
    local target="$1"
    if [ -w "$target" ] 2>/dev/null; then printf ''; return; fi
    if [ ! -e "$target" ] && [ -w "$(dirname "$target")" ] 2>/dev/null; then printf ''; return; fi
    printf '%s' "$SUDO"
}

urlencode() { php -r 'echo rawurlencode($argv[1]);' "$1"; }

# --------------------------------------------------------------------------
# Instalação de dependências (Ubuntu/Debian)
# --------------------------------------------------------------------------
install_dependencies() {
    if ! command -v apt-get >/dev/null 2>&1; then
        warn "apt-get não encontrado — a instalação automática só é suportada em Ubuntu/Debian."
        warn "Instale manualmente: PHP 8.1+ (cli e fpm), extensões mongodb e mbstring, e o Nginx."
        return
    fi
    if [ -z "$SUDO" ] && [ "$(id -u)" -ne 0 ]; then
        warn "Sem privilégios de root/sudo — não é possível instalar pacotes. Pulando a instalação."
        return
    fi

    local php_ver
    php_ver="$(ask 'Versão do PHP a instalar' '8.3')"

    info "Instalando dependências (PHP $php_ver + mongodb, mbstring, curl e Nginx)..."
    export DEBIAN_FRONTEND=noninteractive

    $SUDO apt-get update -y || warn "Falha no apt-get update."
    $SUDO apt-get install -y software-properties-common ca-certificates || \
        warn "Não foi possível instalar software-properties-common."

    # PPA ondrej: versões atuais do PHP e o pacote phpX.Y-mongodb prontos (Ubuntu).
    if ! grep -Rqs 'ondrej/php' /etc/apt/sources.list.d/ 2>/dev/null; then
        if $SUDO add-apt-repository -y ppa:ondrej/php 2>/dev/null; then
            $SUDO apt-get update -y || true
        else
            warn "Não foi possível adicionar o PPA ondrej/php (ok em Debian; tentarei os pacotes do repositório padrão)."
        fi
    fi

    $SUDO apt-get install -y \
        "php${php_ver}-cli" "php${php_ver}-fpm" \
        "php${php_ver}-mbstring" "php${php_ver}-curl" "php${php_ver}-mongodb" \
        nginx || warn "Alguns pacotes podem não ter sido instalados — verifique as mensagens acima."

    $SUDO phpenmod -v "$php_ver" mongodb mbstring 2>/dev/null || true

    if php -m 2>/dev/null | grep -qi '^mongodb$'; then
        ok "Dependências instaladas. Extensão mongodb ativa."
    else
        warn "Instalação concluída, mas a extensão mongodb não aparece em 'php -m'. Verifique manualmente."
    fi
}

# --------------------------------------------------------------------------
# Início
# --------------------------------------------------------------------------
info "=== Configuração do Portal FMU/Canvas ==="
say "Responda as perguntas a seguir. Pressione Enter para aceitar o valor padrão entre colchetes."
say ""

if ask_yesno "Instalar/atualizar as dependências do sistema (PHP, extensão mongodb, mbstring, Nginx)?" "s"; then
    install_dependencies
    say ""
fi

if ! command -v php >/dev/null 2>&1; then
    err "PHP não encontrado. A instalação de dependências não foi feita ou falhou."
    err "Instale o PHP (com as extensões mongodb e mbstring) e rode este script novamente."
    exit 1
fi

ENV_PATH="$(ask 'Caminho do arquivo de variáveis' '/etc/fmu-portal.env')"
WEB_USER="$(ask 'Usuário do servidor web/PHP-FPM (dono do arquivo)' 'www-data')"
say ""

# --------------------------------------------------------------------------
# MongoDB (servidor externo)
# --------------------------------------------------------------------------
info "-- Conexão com o MongoDB (servidor externo) --"
MONGODB_URI=""
if ask_yesno "Você já tem a string de conexão (URI) completa?" "n"; then
    MONGODB_URI="$(ask_secret 'Cole a MONGODB_URI (a entrada fica oculta)')"
    while [ -z "$MONGODB_URI" ]; do
        warn "A URI é obrigatória."
        MONGODB_URI="$(ask_secret 'Cole a MONGODB_URI')"
    done
else
    scheme="mongodb"
    if ask_yesno "É um cluster Atlas / usa DNS SRV (mongodb+srv)?" "n"; then
        scheme="mongodb+srv"
    fi
    host="$(ask_required 'Host do MongoDB (ex.: 10.0.0.5 ou cluster0.abcd.mongodb.net)')"
    portpart=""
    if [ "$scheme" = "mongodb" ]; then
        port="$(ask 'Porta' '27017')"
        portpart=":$port"
    fi
    creds=""
    muser="$(ask 'Usuário do MongoDB (deixe vazio se não houver autenticação)' '')"
    if [ -n "$muser" ]; then
        mpass="$(ask_secret 'Senha do MongoDB')"
        creds="$(urlencode "$muser"):$(urlencode "$mpass")@"
    fi
    opts="$(ask 'Opções extras (querystring, ex.: authSource=admin&tls=true)' '')"
    optpart=""
    [ -n "$opts" ] && optpart="?$opts"
    MONGODB_URI="${scheme}://${creds}${host}${portpart}/${optpart}"
    ok "URI montada (credenciais ocultas): ${scheme}://***@${host}${portpart}/${optpart}"
fi

MONGODB_DATABASE="$(ask 'Nome do banco de dados' 'activity')"

COLL_ACTIVITY="fmu_activity_control"
COLL_USER="fmu_user_control"
COLL_CANVAS="canvas_blueprints"
if ask_yesno "Personalizar os nomes das collections?" "n"; then
    COLL_ACTIVITY="$(ask 'Collection de disciplinas (FMU)' "$COLL_ACTIVITY")"
    COLL_USER="$(ask 'Collection de usuários/login' "$COLL_USER")"
    COLL_CANVAS="$(ask 'Collection de blueprints (Canvas)' "$COLL_CANVAS")"
fi
say ""

# --------------------------------------------------------------------------
# Canvas
# --------------------------------------------------------------------------
info "-- Integração com o Canvas (painel Afya) --"
CANVAS_BASE_URL="$(ask 'Host do Canvas' 'https://afya.instructure.com')"
CANVAS_API_TOKEN="$(ask_secret 'Token de acesso do Canvas (deixe vazio para configurar depois)')"
say ""

# --------------------------------------------------------------------------
# Opções gerais / avançadas
# --------------------------------------------------------------------------
APP_TIMEZONE="America/Sao_Paulo"
LOGIN_THROTTLE_DIR="/var/lib/fmu-portal/throttle"
ADMIN_USERS="gomining"
LTI_CONTROL_BASE_URL=""
LTI_CONTROL_INSTITUTION=""

info "-- Opções gerais --"
APP_TIMEZONE="$(ask 'Fuso horário (APP_TIMEZONE)' "$APP_TIMEZONE")"
LOGIN_THROTTLE_DIR="$(ask 'Pasta gravável para o controle de tentativas de login' "$LOGIN_THROTTLE_DIR")"

if ask_yesno "Personalizar admin do upload e serviço LTI?" "n"; then
    ADMIN_USERS="$(ask 'Logins com acesso ao upload de planilha (separados por vírgula)' "$ADMIN_USERS")"
    LTI_CONTROL_BASE_URL="$(ask 'URL do serviço LTI (vazio = padrão do config)' '')"
    LTI_CONTROL_INSTITUTION="$(ask 'institution do payload LTI (vazio = padrão do config)' '')"
fi
say ""

# --------------------------------------------------------------------------
# Monta o conteúdo do arquivo de ambiente
# --------------------------------------------------------------------------
ENV_CONTENT="# Arquivo de ambiente do Portal FMU/Canvas
# Gerado por scripts/setup-env.sh em $(date '+%Y-%m-%d %H:%M:%S')
# Variáveis omitidas usam os padrões de config/config.php (ver README.md).
"
add_var() { ENV_CONTENT="${ENV_CONTENT}
$1='$2'"; }

add_var MONGODB_URI "$MONGODB_URI"
add_var MONGODB_DATABASE "$MONGODB_DATABASE"
add_var MONGODB_ACTIVITY_COLLECTION "$COLL_ACTIVITY"
add_var MONGODB_USER_COLLECTION "$COLL_USER"
add_var MONGODB_CANVAS_COLLECTION "$COLL_CANVAS"
add_var CANVAS_BASE_URL "$CANVAS_BASE_URL"
[ -n "$CANVAS_API_TOKEN" ] && add_var CANVAS_API_TOKEN "$CANVAS_API_TOKEN"
add_var APP_TIMEZONE "$APP_TIMEZONE"
add_var LOGIN_THROTTLE_DIR "$LOGIN_THROTTLE_DIR"
[ "$ADMIN_USERS" != "gomining" ] && add_var ADMIN_USERS "$ADMIN_USERS"
[ -n "$LTI_CONTROL_BASE_URL" ] && add_var LTI_CONTROL_BASE_URL "$LTI_CONTROL_BASE_URL"
[ -n "$LTI_CONTROL_INSTITUTION" ] && add_var LTI_CONTROL_INSTITUTION "$LTI_CONTROL_INSTITUTION"

# --------------------------------------------------------------------------
# Grava o arquivo
# --------------------------------------------------------------------------
S="$(maybe_sudo "$ENV_PATH")"
if [ -e "$ENV_PATH" ]; then
    backup="${ENV_PATH}.bak.$(date '+%Y%m%d%H%M%S')"
    $S cp -a "$ENV_PATH" "$backup" && warn "Arquivo existente salvo em $backup"
fi
if ! printf '%s\n' "$ENV_CONTENT" | $S tee "$ENV_PATH" >/dev/null; then
    err "Falha ao gravar $ENV_PATH."
    exit 1
fi
$S chown "root:$WEB_USER" "$ENV_PATH" 2>/dev/null || $S chown "$WEB_USER" "$ENV_PATH" 2>/dev/null || true
$S chmod 640 "$ENV_PATH" 2>/dev/null || true
ok "Arquivo de variáveis criado em $ENV_PATH (permissão 640)."

# --------------------------------------------------------------------------
# Pasta de throttle
# --------------------------------------------------------------------------
St="$(maybe_sudo "$(dirname "$LOGIN_THROTTLE_DIR")")"
if $St mkdir -p "$LOGIN_THROTTLE_DIR" 2>/dev/null; then
    $St chown -R "$WEB_USER" "$LOGIN_THROTTLE_DIR" 2>/dev/null || true
    ok "Pasta de throttle pronta: $LOGIN_THROTTLE_DIR"
else
    warn "Não foi possível criar $LOGIN_THROTTLE_DIR — crie manualmente e dê acesso ao usuário $WEB_USER."
fi
say ""

# --------------------------------------------------------------------------
# Teste opcional de conexão com o MongoDB
# --------------------------------------------------------------------------
if php -m 2>/dev/null | grep -qi '^mongodb$'; then
    if ask_yesno "Testar a conexão com o MongoDB agora?" "s"; then
        if T_URI="$MONGODB_URI" T_DB="$MONGODB_DATABASE" php -r '
            $m = new MongoDB\Driver\Manager(getenv("T_URI"));
            $m->executeCommand(getenv("T_DB") ?: "admin", new MongoDB\Driver\Command(["ping" => 1]));
            echo "ok";
        ' 2>/tmp/fmu_conn_err.$$; then
            ok "Conexão com o MongoDB bem-sucedida ✓"
        else
            warn "Não foi possível conectar: $(cat /tmp/fmu_conn_err.$$ 2>/dev/null)"
        fi
        rm -f /tmp/fmu_conn_err.$$
    fi
else
    warn "Extensão PHP 'mongodb' não detectada — pulei o teste de conexão. Instale-a antes de usar o portal."
fi
say ""

# --------------------------------------------------------------------------
# Configuração opcional do PHP-FPM
# --------------------------------------------------------------------------
mapfile -t POOLS < <(ls /etc/php/*/fpm/pool.d/www.conf 2>/dev/null || true)
if [ "${#POOLS[@]}" -ge 1 ]; then
    if ask_yesno "Configurar o PHP-FPM para carregar essas variáveis automaticamente?" "s"; then
        pool="${POOLS[0]}"
        if [ "${#POOLS[@]}" -gt 1 ]; then
            say "Pools encontrados:"; i=1; for p in "${POOLS[@]}"; do say "  $i) $p"; i=$((i+1)); done
            choice="$(ask 'Qual pool usar (número)' '1')"
            pool="${POOLS[$((choice-1))]:-${POOLS[0]}}"
        fi
        php_ver="$(printf '%s' "$pool" | sed -E 's#/etc/php/([^/]+)/.*#\1#')"
        service="php${php_ver}-fpm"

        # clear_env = no (garante que os scripts recebam o ambiente)
        $SUDO cp -a "$pool" "${pool}.bak.$(date '+%Y%m%d%H%M%S')" 2>/dev/null || true
        if $SUDO grep -Eq '^\s*;?\s*clear_env' "$pool" 2>/dev/null; then
            $SUDO sed -i -E 's/^\s*;?\s*clear_env\s*=.*/clear_env = no/' "$pool"
        else
            printf 'clear_env = no\n' | $SUDO tee -a "$pool" >/dev/null
        fi

        # drop-in do systemd com o EnvironmentFile
        dropdir="/etc/systemd/system/${service}.service.d"
        $SUDO mkdir -p "$dropdir"
        printf '[Service]\nEnvironmentFile=%s\n' "$ENV_PATH" | $SUDO tee "$dropdir/fmu-portal.conf" >/dev/null
        $SUDO systemctl daemon-reload 2>/dev/null || true
        ok "PHP-FPM configurado ($service): EnvironmentFile=$ENV_PATH, clear_env = no."

        if ask_yesno "Reiniciar o $service agora?" "s"; then
            $SUDO systemctl restart "$service" && ok "$service reiniciado."
        else
            say "Lembre-se de reiniciar depois: sudo systemctl restart $service"
        fi
    fi
else
    warn "PHP-FPM não detectado em /etc/php/*/fpm — pulei essa etapa."
    say "Para o servidor embutido, carregue o arquivo antes de subir:"
    say "  set -a; source $ENV_PATH; set +a; php -S 0.0.0.0:8000 -t public"
fi
say ""

# --------------------------------------------------------------------------
# Próximos passos
# --------------------------------------------------------------------------
info "=== Configuração concluída ==="
say "Próximos passos:"
say "  1. Criar os usuários (fmu, gomining, afya):"
say "       cd $(pwd) && set -a; source $ENV_PATH; set +a; php scripts/add-users.php"
say "  2. Configurar o Nginx apontando a raiz para a pasta public/ (ver README.md)."
say "  3. Acessar o portal e validar o login de cada usuário."
