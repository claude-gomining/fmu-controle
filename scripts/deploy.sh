#!/usr/bin/env bash
#
# Deploy definitivo do Portal FMU/Canvas em servidor novo (Ubuntu + Apache).
#
# - Apache com mod_php servindo o SITE PADRÃO (/var/www/html). Não cria
#   virtual host, não mexe em portas nem em serviços de HTTP, não usa SSL.
# - Publica em /var/www/html APENAS os arquivos que o usuário acessa
#   (o conteúdo de public/). O backend (src/ e config/) vai para /var/www,
#   FORA do diretório servido.
# - Pergunta os dados de configuração (servidor novo). NÃO cria usuários
#   nem pede senhas de login (os usuários já existem no MongoDB).
# - As variáveis ficam em /etc/fmu-portal.env e são lidas pelo próprio app.
#
# Uso:  sudo bash scripts/deploy.sh
#
set -uo pipefail

WEBROOT="${FMU_WEBROOT:-/var/www/html}"     # arquivos interativos (public/)
APP_DIR="${FMU_APP_DIR:-/var/www}"          # backend: /var/www/src e /var/www/config
ENV_FILE="${FMU_ENV_FILE:-/etc/fmu-portal.env}"
WEB_USER="www-data"

say()  { printf '%s\n' "$*"; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[32m  ✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[33m  ! %s\033[0m\n' "$*" >&2; }
err()  { printf '\033[31m  ✗ %s\033[0m\n' "$*" >&2; }

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    command -v sudo >/dev/null 2>&1 && SUDO="sudo"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

ask() {
    local prompt="$1" def="${2:-}" ans=""
    if [ -n "$def" ]; then read -r -p "$prompt [$def]: " ans || true; printf '%s' "${ans:-$def}"
    else read -r -p "$prompt: " ans || true; printf '%s' "$ans"; fi
}
ask_secret() { local p="$1" a=""; read -r -s -p "$p: " a || true; printf '\n' >&2; printf '%s' "$a"; }
ask_yesno() {
    local p="$1" d="${2:-n}" a="" h; [ "$d" = s ] && h="S/n" || h="s/N"
    read -r -p "$p [$h]: " a || true; a="${a:-$d}"; case "$a" in [sSyY]*) return 0;; *) return 1;; esac
}
pkg_installed() { dpkg -s "$1" >/dev/null 2>&1; }
have_ext() { php -m 2>/dev/null | grep -qi "^$1$"; }

# --------------------------------------------------------------------------
# 1. Coletar os dados de configuração (servidor novo)
# --------------------------------------------------------------------------
info "=== Deploy do Portal FMU/Canvas (Apache, sem SSL) ==="
say "Informe os dados de configuração. Enter aceita o padrão entre colchetes."
say ""

if command -v php >/dev/null 2>&1; then
    PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
else
    PHPV="$(ask 'Versão do PHP a instalar' '8.3')"
fi

info "-- MongoDB (servidor externo, onde os usuários já existem) --"
MONGODB_URI="$(ask_secret 'MONGODB_URI (string de conexão completa; entrada oculta)')"
while [ -z "$MONGODB_URI" ]; do
    warn "A MONGODB_URI é obrigatória."
    MONGODB_URI="$(ask_secret 'MONGODB_URI')"
done
MONGODB_DATABASE="$(ask 'Banco de dados' 'activity')"

info "-- Canvas (painel Afya) --"
CANVAS_BASE_URL="$(ask 'Host do Canvas' 'https://afya.instructure.com')"
CANVAS_API_TOKEN="$(ask_secret 'Token do Canvas (entrada oculta; vazio p/ configurar depois)')"

info "-- Geral --"
APP_TIMEZONE="$(ask 'Fuso horário' 'America/Sao_Paulo')"
LOGIN_THROTTLE_DIR="/var/lib/fmu-portal/throttle"

COLL_ACTIVITY="fmu_activity_control"; COLL_USER="fmu_user_control"; COLL_CANVAS="canvas_blueprints"
ADMIN_USERS=""; LTI_CONTROL_BASE_URL=""; LTI_CONTROL_INSTITUTION=""
if ask_yesno "Configurar opções avançadas (coleções, admin, LTI)?" "n"; then
    COLL_ACTIVITY="$(ask 'Collection de disciplinas' "$COLL_ACTIVITY")"
    COLL_USER="$(ask 'Collection de usuários' "$COLL_USER")"
    COLL_CANVAS="$(ask 'Collection de blueprints' "$COLL_CANVAS")"
    ADMIN_USERS="$(ask 'Logins com acesso ao upload (vazio = padrão gomining)' '')"
    LTI_CONTROL_BASE_URL="$(ask 'URL do serviço LTI (vazio = padrão)' '')"
    LTI_CONTROL_INSTITUTION="$(ask 'institution do LTI (vazio = padrão)' '')"
fi
say ""

# --------------------------------------------------------------------------
# 2. Instalar dependências (apenas o que faltar) — Apache + mod_php + extensões
# --------------------------------------------------------------------------
info "[1/4] Verificando/instalando dependências..."
TO_INSTALL=()
command -v php >/dev/null 2>&1 || TO_INSTALL+=("php${PHPV}-cli")
pkg_installed "libapache2-mod-php${PHPV}" || TO_INSTALL+=("libapache2-mod-php${PHPV}")
pkg_installed apache2 || command -v apache2ctl >/dev/null 2>&1 || TO_INSTALL+=("apache2")
have_ext mongodb || TO_INSTALL+=("php${PHPV}-mongodb")
have_ext mbstring || TO_INSTALL+=("php${PHPV}-mbstring")
have_ext curl     || TO_INSTALL+=("php${PHPV}-curl")

if [ "${#TO_INSTALL[@]}" -gt 0 ]; then
    if command -v apt-get >/dev/null 2>&1; then
        mapfile -t TO_INSTALL < <(printf '%s\n' "${TO_INSTALL[@]}" | sort -u)
        info "Instalando: ${TO_INSTALL[*]}"
        export DEBIAN_FRONTEND=noninteractive
        $SUDO apt-get update -y || warn "apt-get update falhou"
        $SUDO apt-get install -y software-properties-common ca-certificates >/dev/null 2>&1 || true
        if ! grep -Rqs 'ondrej/php' /etc/apt/sources.list.d/ 2>/dev/null; then
            $SUDO add-apt-repository -y ppa:ondrej/php 2>/dev/null && $SUDO apt-get update -y || \
                warn "PPA ondrej/php indisponível — usando repositório padrão."
        fi
        $SUDO apt-get install -y "${TO_INSTALL[@]}" || warn "Alguns pacotes podem não ter sido instalados."
    else
        err "apt-get ausente. Instale manualmente: ${TO_INSTALL[*]}"
    fi
else
    ok "Todas as dependências já presentes."
fi
command -v php >/dev/null 2>&1 && PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
have_ext mongodb && ok "extensão mongodb ativa" || warn "extensão mongodb ausente — o portal não conectará ao banco."
say ""

# --------------------------------------------------------------------------
# 3. Publicar os arquivos
#    public/*  -> /var/www/html   (apenas o que o usuário acessa)
#    src, config -> /var/www      (backend, fora do diretório servido)
# --------------------------------------------------------------------------
info "[2/4] Publicando arquivos..."

$SUDO mkdir -p "$WEBROOT" "$APP_DIR"
$SUDO rm -f "$WEBROOT/index.html"   # remove a página padrão do Apache

# Frontend (conteúdo de public/) -> WEBROOT
$SUDO cp -a "$SRC_ROOT/public/." "$WEBROOT/"
# Backend -> APP_DIR (substitui versões antigas do código, se houver)
$SUDO rm -rf "$APP_DIR/src" "$APP_DIR/config"
$SUDO cp -a "$SRC_ROOT/src" "$APP_DIR/src"
$SUDO cp -a "$SRC_ROOT/config" "$APP_DIR/config"
ok "Frontend em $WEBROOT (só os arquivos interativos); backend em $APP_DIR/{src,config}."

# Código pertence ao root e é somente-leitura para o servidor web
# (um eventual comprometimento do www-data não consegue reescrever o código).
$SUDO chown -R root:root "$WEBROOT" "$APP_DIR/src" "$APP_DIR/config" 2>/dev/null || true
$SUDO chmod -R u=rwX,go=rX "$WEBROOT" "$APP_DIR/src" "$APP_DIR/config" 2>/dev/null || true
ok "Permissões: código como root, somente-leitura para $WEB_USER."
say ""

# --------------------------------------------------------------------------
# 4. Variáveis de ambiente + pasta de throttle
# --------------------------------------------------------------------------
info "[3/4] Gravando variáveis e pastas graváveis..."

write_env=1
if [ -f "$ENV_FILE" ]; then
    if ask_yesno "$ENV_FILE já existe. Sobrescrever com os novos dados?" "n"; then
        $SUDO cp -a "$ENV_FILE" "${ENV_FILE}.bak.$(date '+%Y%m%d%H%M%S')" 2>/dev/null || true
    else
        write_env=0
        ok "Mantendo o $ENV_FILE existente."
    fi
fi

if [ "$write_env" -eq 1 ]; then
    C="# Portal FMU/Canvas — gerado por deploy.sh em $(date '+%Y-%m-%d %H:%M:%S')"
    add() { C="${C}
$1='$2'"; }
    add MONGODB_URI "$MONGODB_URI"
    add MONGODB_DATABASE "$MONGODB_DATABASE"
    add MONGODB_ACTIVITY_COLLECTION "$COLL_ACTIVITY"
    add MONGODB_USER_COLLECTION "$COLL_USER"
    add MONGODB_CANVAS_COLLECTION "$COLL_CANVAS"
    add CANVAS_BASE_URL "$CANVAS_BASE_URL"
    [ -n "$CANVAS_API_TOKEN" ] && add CANVAS_API_TOKEN "$CANVAS_API_TOKEN"
    add APP_TIMEZONE "$APP_TIMEZONE"
    add LOGIN_THROTTLE_DIR "$LOGIN_THROTTLE_DIR"
    [ -n "$ADMIN_USERS" ] && add ADMIN_USERS "$ADMIN_USERS"
    [ -n "$LTI_CONTROL_BASE_URL" ] && add LTI_CONTROL_BASE_URL "$LTI_CONTROL_BASE_URL"
    [ -n "$LTI_CONTROL_INSTITUTION" ] && add LTI_CONTROL_INSTITUTION "$LTI_CONTROL_INSTITUTION"

    printf '%s\n' "$C" | $SUDO tee "$ENV_FILE" >/dev/null
    # Segredos: legível só por root e pelo usuário do web
    $SUDO chown "root:$WEB_USER" "$ENV_FILE" 2>/dev/null || true
    $SUDO chmod 640 "$ENV_FILE" 2>/dev/null || true
    ok "Variáveis gravadas em $ENV_FILE (permissão 640, root:$WEB_USER)."
fi

# Pasta de throttle (gravável pelo web) — fora do diretório servido
$SUDO mkdir -p "$LOGIN_THROTTLE_DIR"
$SUDO chown -R "$WEB_USER:$WEB_USER" "$(dirname "$LOGIN_THROTTLE_DIR")" 2>/dev/null || true
ok "Pasta de throttle: $LOGIN_THROTTLE_DIR"
say ""

# --------------------------------------------------------------------------
# 5. Validação
# --------------------------------------------------------------------------
info "[4/4] Validação..."
[ -f "$WEBROOT/index.php" ] && ok "index.php publicado em $WEBROOT" || err "index.php ausente em $WEBROOT"
[ -f "$APP_DIR/src/bootstrap.php" ] && ok "backend em $APP_DIR/src" || err "backend ausente em $APP_DIR/src"
if [ -e "$WEBROOT/config" ] || [ -e "$WEBROOT/src" ]; then
    err "ATENÇÃO: src/ ou config/ ficaram dentro de $WEBROOT — não deveriam ser servidos!"
else
    ok "src/ e config/ estão fora de $WEBROOT (não são servidos)."
fi
have_ext mongodb && ok "extensão mongodb ativa" || warn "instale a extensão mongodb"

if command -v curl >/dev/null 2>&1; then
    code="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/index.php 2>/dev/null)"
    [ -z "$code" ] && code="000"
    case "$code" in
        200) ok "Apache respondeu HTTP 200 em http://127.0.0.1/index.php" ;;
        000) warn "Sem resposta local (o Apache pode não ter iniciado ainda)." ;;
        *)   warn "Resposta HTTP $code — confira /var/log/apache2/error.log" ;;
    esac
fi
say ""
info "=== Deploy concluído (sem SSL, conforme solicitado) ==="
say "Frontend:  $WEBROOT   (apenas os arquivos que o usuário acessa)"
say "Backend:   $APP_DIR/src e $APP_DIR/config   (fora da web)"
say "Variáveis: $ENV_FILE"
say "Usuários:  já existentes no MongoDB (este script não os altera)."
say "Acesse:    http://IP-OU-DNS-DO-SERVIDOR/"
