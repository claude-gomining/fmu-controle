#!/usr/bin/env bash
#
# Diagnóstico do Portal FMU/Canvas.
#
# Verifica, do ponto de vista do servidor web, por que uma página pode estar
# dando erro: leitura do arquivo de ambiente pelo usuário do Apache, valores
# que o PHP realmente enxerga, extensão e conexão do MongoDB, alcance da API
# do Canvas e do serviço LTI, e as últimas linhas do log de erro.
#
# NÃO imprime segredos: tokens e senhas aparecem mascarados.
#
# Uso:
#   sudo bash scripts/diagnose.sh                 # diagnóstico geral
#   sudo bash scripts/diagnose.sh 12345           # testa também a blueprint 12345
#
set -uo pipefail

BLUEPRINT_ID="${1:-}"
ENV_FILE="${FMU_ENV_FILE:-/etc/fmu-portal.env}"
APP_DIR="${FMU_APP_DIR:-/var/www}"
WEB_USER="${FMU_WEB_USER:-www-data}"

say()  { printf '%s\n' "$*"; }
info() { printf '\n\033[36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[32m  ✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[33m  ! %s\033[0m\n' "$*"; }
err()  { printf '\033[31m  ✗ %s\033[0m\n' "$*"; }

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    command -v sudo >/dev/null 2>&1 && SUDO="sudo"
fi

PROBLEMS=0
problem() { PROBLEMS=$((PROBLEMS + 1)); err "$1"; }

# Executa um comando COMO O USUÁRIO DO APACHE (é o que importa em produção).
as_web() {
    if [ "$(id -un)" = "$WEB_USER" ]; then
        "$@"
    elif command -v sudo >/dev/null 2>&1; then
        sudo -u "$WEB_USER" "$@"
    elif command -v runuser >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
        runuser -u "$WEB_USER" -- "$@"
    elif command -v su >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
        su -s /bin/sh "$WEB_USER" -c "$(printf '%q ' "$@")"
    else
        return 127
    fi
}

mask() {
    local v="$1" n=${#1}
    if [ "$n" -le 8 ]; then printf '***'; else printf '%s…%s (%d chars)' "${v:0:4}" "${v: -3}" "$n"; fi
}

info "=== 1. Arquivo de ambiente ==="
say "Arquivo: $ENV_FILE"

if [ ! -f "$ENV_FILE" ]; then
    problem "O arquivo NÃO existe. O portal usará todos os valores padrão (host de TESTE do Canvas, sem token)."
    say "     Crie-o com: sudo bash scripts/configure-env.sh"
else
    ok "Existe."
    say "     Permissões: $($SUDO stat -c '%A %U:%G' "$ENV_FILE" 2>/dev/null)"

    # O TESTE QUE MAIS IMPORTA: o usuário do Apache consegue ler?
    if as_web test -r "$ENV_FILE" 2>/dev/null; then
        ok "O usuário $WEB_USER CONSEGUE ler o arquivo."
    else
        problem "O usuário $WEB_USER NÃO consegue ler o arquivo!"
        say "     É a causa mais comum: o app ignora o arquivo em silêncio e usa os PADRÕES"
        say "     (CANVAS_BASE_URL vira https://afya.test.instructure.com e o token fica vazio)."
        say "     Corrija com:"
        say "       sudo chown root:$WEB_USER $ENV_FILE && sudo chmod 640 $ENV_FILE"
    fi

    # Diretórios do caminho precisam ser atravessáveis
    d="$(dirname "$ENV_FILE")"
    as_web test -x "$d" 2>/dev/null || problem "O usuário $WEB_USER não consegue atravessar $d (falta permissão x)."
fi

info "=== 2. Valores que o PHP realmente enxerga ==="
if ! command -v php >/dev/null 2>&1; then
    problem "PHP não encontrado no PATH."
elif [ ! -f "$APP_DIR/config/config.php" ]; then
    warn "config.php não encontrado em $APP_DIR/config — o backend foi publicado? (rode o deploy)"
else
    # Lê a configuração COMO O USUÁRIO DO APACHE, que é o que vale em produção.
    as_web env FMU_ENV_FILE="$ENV_FILE" FMU_APP_DIR_CFG="$APP_DIR/config/config.php" php -r '
        $c = require getenv("FMU_APP_DIR_CFG");
        $mask = function ($v) {
            $v = (string) $v;
            $n = strlen($v);
            if ($n === 0) return "(VAZIO)";
            if ($n <= 8) return "***";
            return substr($v, 0, 4) . "…" . substr($v, -3) . " ({$n} chars)";
        };
        printf("     CANVAS_BASE_URL   = %s\n", $c["canvas"]["base_url"]);
        printf("     CANVAS_API_TOKEN  = %s\n", $mask($c["canvas"]["token"]));
        printf("     MONGODB_DATABASE  = %s\n", $c["mongo"]["database"]);
        printf("     MONGODB_URI       = %s\n", $mask($c["mongo"]["uri"]));
        printf("     Collection canvas = %s\n", $c["canvas"]["collection"]);
        printf("     LTI base_url      = %s\n", $c["lti_control"]["base_url"]);
        if (strpos($c["canvas"]["base_url"], ".test.") !== false) {
            fwrite(STDERR, "TEST_HOST\n");
        }
        if ($c["canvas"]["token"] === "") {
            fwrite(STDERR, "NO_TOKEN\n");
        }
    ' 2>"/tmp/fmu-diag-flags.$$" || problem "Falha ao ler a configuração pelo PHP."

    if grep -q TEST_HOST "/tmp/fmu-diag-flags.$$" 2>/dev/null; then
        problem "O CANVAS_BASE_URL está apontando para o host de TESTE — sinal de que o arquivo de ambiente NÃO está sendo lido."
    fi
    if grep -q NO_TOKEN "/tmp/fmu-diag-flags.$$" 2>/dev/null; then
        problem "O CANVAS_API_TOKEN está VAZIO para o usuário $WEB_USER."
    fi
    rm -f "/tmp/fmu-diag-flags.$$"
fi

info "=== 3. MongoDB ==="
if php -m 2>/dev/null | grep -qi '^mongodb$'; then
    ok "Extensão mongodb carregada (CLI)."
else
    problem "Extensão mongodb ausente no PHP CLI."
fi

if [ -f "$APP_DIR/config/config.php" ]; then
    as_web env FMU_ENV_FILE="$ENV_FILE" FMU_APP_DIR_CFG="$APP_DIR/config/config.php" php -r '
        $c = require getenv("FMU_APP_DIR_CFG");
        try {
            $m = new MongoDB\Driver\Manager($c["mongo"]["uri"]);
            $cmd = new MongoDB\Driver\Command(["ping" => 1]);
            $m->executeCommand($c["mongo"]["database"], $cmd);
            echo "  \033[32m✓ Conexão com o MongoDB OK (ping respondeu).\033[0m\n";
        } catch (Throwable $e) {
            echo "  \033[31m✗ Falha ao conectar no MongoDB: " . $e->getMessage() . "\033[0m\n";
        }
    ' 2>/dev/null || warn "Não foi possível testar a conexão com o MongoDB."
fi

info "=== 4. API do Canvas ==="
if [ -f "$APP_DIR/config/config.php" ]; then
    as_web env FMU_ENV_FILE="$ENV_FILE" FMU_APP_DIR_CFG="$APP_DIR/config/config.php" FMU_BP="$BLUEPRINT_ID" php -r '
        $c = require getenv("FMU_APP_DIR_CFG");
        $base = rtrim($c["canvas"]["base_url"], "/");
        $token = $c["canvas"]["token"];
        if ($token === "") { echo "  \033[31m✗ Sem token: a chamada nem seria feita.\033[0m\n"; exit; }
        $bp = getenv("FMU_BP");
        $url = $bp !== "" && ctype_digit($bp)
            ? "{$base}/api/v1/courses/{$bp}/blueprint_templates/default/associated_courses?per_page=1"
            : "{$base}/api/v1/users/self";
        $ctx = stream_context_create(["http" => [
            "method" => "GET",
            "header" => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            "timeout" => (int) $c["canvas"]["timeout_seconds"],
            "ignore_errors" => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match("{^HTTP/\S+\s+(\d{3})}", $h, $m)) { $status = (int) $m[1]; }
        }
        $shown = preg_replace("/\?.*$/", "", $url);
        if ($body === false) {
            echo "  \033[31m✗ Não foi possível contatar {$shown} (rede/DNS/firewall ou timeout).\033[0m\n";
        } elseif ($status >= 200 && $status < 300) {
            echo "  \033[32m✓ HTTP {$status} em {$shown} — token e host OK.\033[0m\n";
        } elseif ($status === 401 || $status === 403) {
            echo "  \033[31m✗ HTTP {$status} — token inválido/sem permissão PARA ESTE HOST ({$base}).\033[0m\n";
        } elseif ($status === 404) {
            echo "  \033[31m✗ HTTP 404 — recurso não encontrado (blueprint inexistente nesse host?).\033[0m\n";
        } else {
            echo "  \033[31m✗ HTTP {$status} em {$shown}.\033[0m\n";
            echo "     Resposta: " . substr(trim((string) $body), 0, 200) . "\n";
        }
    ' 2>/dev/null || warn "Não foi possível testar a API do Canvas."
    [ -z "$BLUEPRINT_ID" ] && say "     (rode com o número da blueprint para testar o endpoint real: sudo bash scripts/diagnose.sh 12345)"
fi

info "=== 5. Serviço LTI de controle ==="
if [ -f "$APP_DIR/config/config.php" ]; then
    as_web env FMU_ENV_FILE="$ENV_FILE" FMU_APP_DIR_CFG="$APP_DIR/config/config.php" php -r '
        $c = require getenv("FMU_APP_DIR_CFG");
        $url = rtrim($c["lti_control"]["base_url"], "/") . "/v1/control/list";
        $ctx = stream_context_create(["http" => ["method" => "GET", "timeout" => 5, "ignore_errors" => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            echo "  \033[33m! Sem resposta de {$url} (pode ser normal p/ GET, mas verifique a rede).\033[0m\n";
        } else {
            echo "  \033[32m✓ O serviço LTI respondeu em {$url}.\033[0m\n";
        }
    ' 2>/dev/null || true
fi

info "=== 6. Últimos erros do servidor ==="
LOG=""
for candidate in /var/log/apache2/error.log /var/log/httpd/error_log /var/log/nginx/error.log; do
    [ -f "$candidate" ] && LOG="$candidate" && break
done

if [ -n "$LOG" ]; then
    say "Arquivo: $LOG (últimas linhas relevantes)"
    $SUDO grep -iE 'PHP|Falha|Canvas|Mongo|LTI' "$LOG" 2>/dev/null | tail -n 15 | sed 's/^/     /' || true
    say ""
    say "Para acompanhar em tempo real enquanto reproduz o erro na tela:"
    say "  sudo tail -f $LOG"
else
    warn "Log de erro do servidor não encontrado nos caminhos usuais."
fi

info "=== Resumo ==="
if [ "$PROBLEMS" -eq 0 ]; then
    ok "Nenhum problema de configuração detectado."
    say "Se a tela continua com erro, reproduza-a com 'sudo tail -f $LOG' aberto"
    say "e veja a linha registrada no momento exato do erro."
else
    err "$PROBLEMS problema(s) encontrado(s) — veja os itens marcados com ✗ acima."
fi
