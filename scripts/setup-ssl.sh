#!/usr/bin/env bash
#
# Habilita HTTPS (Let's Encrypt) no Apache para o Portal FMU/Canvas.
#
# Usa o certbot com o plugin do Apache: cria/ajusta o virtual host do domínio
# apontando para o mesmo DocumentRoot do deploy (/var/www/html), obtém (ou
# reaproveita) o certificado e configura o site HTTPS + redirecionamento
# HTTP->HTTPS. Rode DEPOIS do scripts/deploy.sh.
#
# Uso:
#   sudo bash scripts/setup-ssl.sh                       # domínio padrão
#   sudo bash scripts/setup-ssl.sh meu.dominio.com       # outro domínio
#
# Pré-requisitos:
#   - O domínio já deve apontar (DNS/Route53) para o IP deste servidor.
#   - As portas 80 e 443 abertas no firewall (Security Group / Lightsail).
#
set -uo pipefail

DOMAIN="${1:-controle.gomining-lti.com}"
WEBROOT="${FMU_WEBROOT:-/var/www/html}"
VHOST_NAME="gomining-lti"

say()  { printf '%s\n' "$*"; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[32m  ✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[33m  ! %s\033[0m\n' "$*" >&2; }
err()  { printf '\033[31m  ✗ %s\033[0m\n' "$*" >&2; }

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    command -v sudo >/dev/null 2>&1 && SUDO="sudo" || { err "Rode como root ou com sudo."; exit 1; }
fi

ask() { local p="$1" d="${2:-}" a=""; if [ -n "$d" ]; then read -r -p "$p [$d]: " a || true; printf '%s' "${a:-$d}"; else read -r -p "$p: " a || true; printf '%s' "$a"; fi; }

info "=== Habilitar HTTPS para $DOMAIN ==="

if ! command -v apache2ctl >/dev/null 2>&1; then
    err "Apache não encontrado. Rode antes o scripts/deploy.sh."
    exit 1
fi
if [ ! -d /etc/apache2/sites-available ]; then
    err "/etc/apache2/sites-available não existe — Apache não está instalado corretamente."
    exit 1
fi

# --------------------------------------------------------------------------
# 1. Dependências: certbot + plugin do Apache, e mod_ssl
# --------------------------------------------------------------------------
info "[1/4] Instalando certbot e habilitando mod_ssl..."
if ! command -v certbot >/dev/null 2>&1 || ! dpkg -s python3-certbot-apache >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    $SUDO apt-get update -y || warn "apt-get update falhou"
    $SUDO apt-get install -y certbot python3-certbot-apache || { err "Falha ao instalar o certbot."; exit 1; }
fi
$SUDO a2enmod ssl >/dev/null 2>&1 || true
ok "certbot pronto, mod_ssl habilitado."

# --------------------------------------------------------------------------
# 2. Virtual host HTTP do domínio (para o certbot anexar o SSL)
#    Aponta para o mesmo DocumentRoot do deploy; o PHP é servido pelo mod_php.
# --------------------------------------------------------------------------
info "[2/4] Configurando o virtual host de $DOMAIN..."
vhost="/etc/apache2/sites-available/${VHOST_NAME}.conf"
if [ -f "$vhost" ]; then
    $SUDO cp -a "$vhost" "${vhost}.bak.$(date '+%Y%m%d%H%M%S')" 2>/dev/null || true
fi
$SUDO tee "$vhost" >/dev/null <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${WEBROOT}

    <Directory ${WEBROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>

    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/${VHOST_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${VHOST_NAME}-access.log combined
</VirtualHost>
EOF
$SUDO a2ensite "$VHOST_NAME" >/dev/null 2>&1 || true

if $SUDO apache2ctl configtest 2>/dev/null; then
    $SUDO systemctl reload apache2 2>/dev/null || $SUDO systemctl restart apache2 2>/dev/null || true
    ok "Virtual host de $DOMAIN ativo (DocumentRoot $WEBROOT)."
else
    err "apache2ctl configtest falhou — revise $vhost."
    exit 1
fi

# --------------------------------------------------------------------------
# 3. Certificado + configuração HTTPS (certbot --apache)
# --------------------------------------------------------------------------
info "[3/4] Obtendo/instalando o certificado Let's Encrypt..."
email="$(ask 'E-mail para avisos do Let'\''s Encrypt (Enter para pular)' '')"

CERTBOT_ARGS=(--apache -d "$DOMAIN" --redirect --agree-tos --non-interactive --keep-until-expiring)
if [ -n "$email" ]; then
    CERTBOT_ARGS+=(-m "$email")
else
    CERTBOT_ARGS+=(--register-unsafely-without-email)
fi

if $SUDO certbot "${CERTBOT_ARGS[@]}"; then
    ok "Certificado instalado e HTTPS configurado (com redirecionamento HTTP->HTTPS)."
else
    err "O certbot falhou. Causas comuns:"
    err "  - o domínio $DOMAIN ainda não aponta para o IP deste servidor (DNS/Route53);"
    err "  - a porta 80 (e 443) não está aberta no firewall (Security Group / Lightsail);"
    err "  - propagação de DNS ainda em andamento."
    err "Corrija e rode novamente. O virtual host HTTP já ficou pronto."
    exit 1
fi

# --------------------------------------------------------------------------
# 4. Verificação
# --------------------------------------------------------------------------
info "[4/4] Verificação..."
$SUDO apache2ctl -M 2>/dev/null | grep -q ssl_module && ok "mod_ssl ativo" || warn "mod_ssl não confirmado"
if [ -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
    ok "Certificado em /etc/letsencrypt/live/${DOMAIN}/"
fi
$SUDO systemctl is-enabled certbot.timer >/dev/null 2>&1 && ok "Renovação automática ativa (certbot.timer)." || \
    warn "Confirme a renovação automática: systemctl status certbot.timer"

if command -v curl >/dev/null 2>&1; then
    code="$(curl -s -o /dev/null -w '%{http_code}' "https://${DOMAIN}/index.php" 2>/dev/null)"
    [ -z "$code" ] && code="000"
    case "$code" in
        200) ok "https://${DOMAIN}/ respondeu HTTP 200" ;;
        000) warn "Não consegui validar https://${DOMAIN}/ a partir daqui (normal se o DNS/porta 443 ainda não estiverem prontos)." ;;
        *)   warn "https://${DOMAIN}/ respondeu HTTP $code — confira os logs do Apache." ;;
    esac
fi

say ""
info "=== HTTPS configurado ==="
say "Acesse: https://${DOMAIN}/"
say "Lembre-se de liberar a porta 443 (e 80) no firewall/Security Group da AWS."
say "O cookie de sessão passa a receber a flag Secure e o HSTS é enviado automaticamente."
