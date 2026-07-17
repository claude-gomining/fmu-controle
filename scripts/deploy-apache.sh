#!/usr/bin/env bash
#
# Deploy do Portal FMU/Canvas com Apache + PHP-FPM em /var/www/html.
#
# - Instala apenas o que estiver faltando (Apache, PHP, extensões).
# - Publica o projeto em /var/www/html/fmu-controle e serve a pasta public/.
# - NÃO usa SSL. NÃO cria usuários.
# - Reaproveita /etc/fmu-portal.env; se não existir, gera a partir das
#   variáveis de ambiente que já estiverem definidas (se houver).
# - É idempotente: valida bibliotecas e arquivos já existentes antes de agir.
#
# Uso:
#   sudo bash scripts/deploy-apache.sh            # alvo padrão /var/www/html/fmu-controle
#   sudo bash scripts/deploy-apache.sh /var/www/html/outro-nome
#
set -uo pipefail

TARGET="${1:-/var/www/html/fmu-controle}"
WEB_USER="www-data"
ENV_FILE="${FMU_ENV_FILE:-/etc/fmu-portal.env}"
SERVER_NAME="${APP_SERVER_NAME:-$(hostname -f 2>/dev/null || echo localhost)}"

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

pkg_installed() { dpkg -s "$1" >/dev/null 2>&1; }
have_ext()     { php -m 2>/dev/null | grep -qi "^$1$"; }

# --------------------------------------------------------------------------
# 1. Validar / instalar bibliotecas
# --------------------------------------------------------------------------
info "[1/5] Verificando bibliotecas..."

APT=""
command -v apt-get >/dev/null 2>&1 && APT="yes"
[ -z "$APT" ] && warn "apt-get não encontrado — só validarei o que já existe (instalação manual em outras distros)."

TO_INSTALL=()

# PHP (detecta versão instalada; se não houver, mira 8.3)
if command -v php >/dev/null 2>&1; then
    PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    ok "PHP $PHPV já instalado"
else
    PHPV="8.3"
    warn "PHP não encontrado — será instado (php$PHPV)"
    TO_INSTALL+=("php${PHPV}-cli" "php${PHPV}-fpm")
fi

# Extensões obrigatórias
if have_ext mongodb; then ok "extensão mongodb presente"; else warn "extensão mongodb ausente"; TO_INSTALL+=("php${PHPV}-mongodb"); fi
if have_ext mbstring; then ok "extensão mbstring presente"; else warn "extensão mbstring ausente"; TO_INSTALL+=("php${PHPV}-mbstring"); fi
if have_ext curl;     then ok "extensão curl presente";     else warn "extensão curl ausente";     TO_INSTALL+=("php${PHPV}-curl"); fi

# php-fpm (pacote) e Apache
pkg_installed "php${PHPV}-fpm" || TO_INSTALL+=("php${PHPV}-fpm")
if command -v apache2ctl >/dev/null 2>&1 || pkg_installed apache2; then
    ok "Apache já instalado"
else
    warn "Apache não encontrado — será instalado"
    TO_INSTALL+=("apache2")
fi

if [ "${#TO_INSTALL[@]}" -gt 0 ]; then
    if [ -n "$APT" ]; then
        # de-duplica a lista
        mapfile -t TO_INSTALL < <(printf '%s\n' "${TO_INSTALL[@]}" | sort -u)
        info "Instalando: ${TO_INSTALL[*]}"
        export DEBIAN_FRONTEND=noninteractive
        $SUDO apt-get update -y || warn "apt-get update falhou"
        $SUDO apt-get install -y "${TO_INSTALL[@]}" || warn "Alguns pacotes podem não ter sido instalados."
    else
        err "Faltam pacotes (${TO_INSTALL[*]}) e não há apt-get. Instale-os manualmente e rode de novo."
    fi
else
    ok "Todas as bibliotecas necessárias já estão presentes."
fi

# Revalida o essencial
command -v php >/dev/null 2>&1 || { err "PHP indisponível. Abortando."; exit 1; }
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
have_ext mongodb || warn "A extensão mongodb ainda não aparece em 'php -m' — o portal não conectará ao banco sem ela."
say ""

# --------------------------------------------------------------------------
# 2. Publicar os arquivos em /var/www/html/fmu-controle
# --------------------------------------------------------------------------
info "[2/5] Publicando os arquivos em $TARGET..."

same_path() { [ "$(readlink -f "$1" 2>/dev/null)" = "$(readlink -f "$2" 2>/dev/null)" ]; }

if [ -f "$TARGET/public/index.php" ]; then
    ok "Arquivos já existem em $TARGET — mantendo (não sobrescrevo)."
elif same_path "$SRC_ROOT" "$TARGET"; then
    ok "O projeto já está no alvo ($TARGET)."
else
    $SUDO mkdir -p "$TARGET"
    if command -v rsync >/dev/null 2>&1; then
        $SUDO rsync -a "$SRC_ROOT"/ "$TARGET"/
    else
        $SUDO cp -a "$SRC_ROOT"/. "$TARGET"/
    fi
    ok "Arquivos copiados para $TARGET."
fi

# Dono de leitura para o usuário do web/PHP
if id "$WEB_USER" >/dev/null 2>&1; then
    $SUDO chown -R "$WEB_USER:$WEB_USER" "$TARGET" 2>/dev/null || warn "Não foi possível ajustar o dono de $TARGET."
    ok "Dono ajustado para $WEB_USER."
else
    warn "Usuário $WEB_USER não existe — verifique as permissões de leitura de $TARGET."
fi
say ""

# --------------------------------------------------------------------------
# 3. Variáveis de ambiente (reaproveita as existentes)
# --------------------------------------------------------------------------
info "[3/5] Configurando variáveis de ambiente..."

KNOWN_VARS=(
    MONGODB_URI MONGODB_DATABASE MONGODB_ACTIVITY_COLLECTION MONGODB_USER_COLLECTION
    MONGODB_CANVAS_COLLECTION CANVAS_BASE_URL CANVAS_API_TOKEN CANVAS_TIMEOUT_SECONDS
    CANVAS_PER_PAGE CANVAS_MAX_PAGES CANVAS_PAGE_SIZE ADMIN_USERS UPLOAD_MAX_BYTES
    UPLOAD_MAX_ROWS LTI_CONTROL_BASE_URL LTI_CONTROL_INSTITUTION LTI_CONTROL_TIMEOUT_SECONDS
    LOGIN_MAX_ATTEMPTS LOGIN_IP_MAX_ATTEMPTS LOGIN_LOCKOUT_SECONDS LOGIN_THROTTLE_DIR
    APP_TIMEZONE APP_SESSION_NAME
)

if [ -f "$ENV_FILE" ]; then
    ok "Arquivo de variáveis já existe: $ENV_FILE — reaproveitando (não altero)."
else
    # Gera o arquivo apenas com as variáveis presentes no ambiente atual.
    CONTENT="# Gerado por deploy-apache.sh em $(date '+%Y-%m-%d %H:%M:%S')"
    found=0
    for v in "${KNOWN_VARS[@]}"; do
        if [ -n "${!v:-}" ]; then
            CONTENT="${CONTENT}
$v='${!v}'"
            found=$((found+1))
        fi
    done

    if [ "$found" -gt 0 ]; then
        printf '%s\n' "$CONTENT" | $SUDO tee "$ENV_FILE" >/dev/null
        $SUDO chown "root:$WEB_USER" "$ENV_FILE" 2>/dev/null || true
        $SUDO chmod 640 "$ENV_FILE" 2>/dev/null || true
        ok "Arquivo $ENV_FILE criado com $found variável(is) do ambiente."
    else
        warn "Nenhuma variável conhecida definida no ambiente e $ENV_FILE não existe."
        warn "O portal usará os padrões de config/config.php (MongoDB em 127.0.0.1, Canvas sem token)."
        warn "Defina as variáveis (ex.: rode scripts/setup-env.sh) e rode este deploy de novo."
    fi
fi

# Garante que o PHP-FPM carregue o arquivo de variáveis
if [ -f "$ENV_FILE" ] && [ -d "/etc/php/${PHPV}/fpm" ] && command -v systemctl >/dev/null 2>&1; then
    FPM="php${PHPV}-fpm"
    dropdir="/etc/systemd/system/${FPM}.service.d"
    if [ ! -f "$dropdir/fmu-portal.conf" ]; then
        $SUDO mkdir -p "$dropdir"
        printf '[Service]\nEnvironmentFile=%s\n' "$ENV_FILE" | $SUDO tee "$dropdir/fmu-portal.conf" >/dev/null
        $SUDO systemctl daemon-reload 2>/dev/null || true
        ok "PHP-FPM ($FPM) configurado para carregar $ENV_FILE."
    else
        ok "PHP-FPM já aponta para o arquivo de variáveis."
    fi
    # clear_env = no no pool
    pool="/etc/php/${PHPV}/fpm/pool.d/www.conf"
    if [ -f "$pool" ]; then
        if $SUDO grep -Eq '^\s*clear_env\s*=\s*no' "$pool"; then
            :
        elif $SUDO grep -Eq '^\s*;?\s*clear_env' "$pool"; then
            $SUDO sed -i -E 's/^\s*;?\s*clear_env\s*=.*/clear_env = no/' "$pool"
        else
            printf 'clear_env = no\n' | $SUDO tee -a "$pool" >/dev/null
        fi
    fi
    $SUDO systemctl enable --now "$FPM" >/dev/null 2>&1 || true
    $SUDO systemctl restart "$FPM" 2>/dev/null || warn "Não foi possível reiniciar o $FPM."
fi
say ""

# --------------------------------------------------------------------------
# 4. Configurar o Apache (sem SSL)
# --------------------------------------------------------------------------
info "[4/5] Configurando o Apache..."

if command -v a2ensite >/dev/null 2>&1 && [ -d /etc/apache2/sites-available ]; then
    # Libera a porta 80 se o Nginx estiver ocupando
    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet nginx 2>/dev/null; then
        warn "Nginx está ativo na porta 80 — desativando para o Apache assumir."
        $SUDO systemctl disable --now nginx || true
    fi

    $SUDO a2enmod proxy_fcgi setenvif >/dev/null 2>&1 || true

    vhost="/etc/apache2/sites-available/fmu-portal.conf"
    if [ -f "$vhost" ]; then
        $SUDO cp -a "$vhost" "${vhost}.bak.$(date '+%Y%m%d%H%M%S')" 2>/dev/null || true
        warn "Virtual host já existia — backup criado e reescrito."
    fi

    $SUDO tee "$vhost" >/dev/null <<EOF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    DocumentRoot ${TARGET}/public

    <Directory ${TARGET}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>

    # PHP via PHP-FPM
    <FilesMatch \.php\$>
        SetHandler "proxy:unix:/run/php/php${PHPV}-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Não expõe arquivos ocultos (.git, .env, etc.)
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/fmu-portal-error.log
    CustomLog \${APACHE_LOG_DIR}/fmu-portal-access.log combined
</VirtualHost>
EOF

    $SUDO a2ensite fmu-portal >/dev/null 2>&1 || true
    $SUDO a2dissite 000-default >/dev/null 2>&1 || true

    if $SUDO apache2ctl configtest 2>/dev/null; then
        $SUDO systemctl reload apache2 2>/dev/null || $SUDO systemctl restart apache2 2>/dev/null || true
        ok "Apache configurado: DocumentRoot em ${TARGET}/public (site 000-default desativado)."
    else
        err "apache2ctl configtest falhou — revise $vhost."
    fi
else
    warn "Apache não disponível para configuração automática. Configure o virtual host manualmente"
    warn "apontando o DocumentRoot para ${TARGET}/public (ver README.md)."
fi
say ""

# --------------------------------------------------------------------------
# 5. Validação final
# --------------------------------------------------------------------------
info "[5/5] Validação..."
have_ext mongodb && ok "extensão mongodb ativa" || warn "extensão mongodb ausente"
if command -v apache2ctl >/dev/null 2>&1; then
    $SUDO apache2ctl -M 2>/dev/null | grep -q proxy_fcgi && ok "módulo proxy_fcgi ativo" || warn "módulo proxy_fcgi não confirmado"
fi

if command -v curl >/dev/null 2>&1; then
    code="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/index.php 2>/dev/null)"
    [ -z "$code" ] && code="000"
    case "$code" in
        200) ok "Portal respondeu HTTP 200 em http://127.0.0.1/index.php" ;;
        000) warn "Sem resposta HTTP local (Apache pode não estar no ar ainda)." ;;
        502) warn "HTTP 502 — o socket do PHP-FPM não bate. Confira /run/php/php${PHPV}-fpm.sock." ;;
        *)   warn "Portal respondeu HTTP $code — verifique os logs em /var/log/apache2/fmu-portal-error.log" ;;
    esac
fi
say ""

info "=== Deploy concluído ==="
say "Acesse:  http://${SERVER_NAME}/   (ou http://IP-DA-EC2/)"
say "Usuários: já existentes (este script não recria)."
say "Sem SSL, conforme solicitado."
