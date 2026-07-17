# Portal FMU - Correção Automática

Portal em PHP para ativar ou desativar disciplinas de correção automática armazenadas em MongoDB.

## Requisitos

- PHP 8.1+ (CLI e, em produção, PHP-FPM)
- Extensões PHP: `mongodb` (obrigatória), `mbstring` (obrigatória), `openssl` (para HTTPS ao Canvas; já vem por padrão), `curl` (opcional)
- `allow_url_fopen` habilitado (padrão do PHP) — usado para chamar o Canvas e o serviço LTI
- MongoDB acessível pela aplicação (Atlas ou self-hosted)

## O que você precisa fornecer

Para colocar o portal no ar, tenha em mãos os seguintes valores (os demais têm padrão e são opcionais):

| Valor | Variável | Para quê |
|-------|----------|----------|
| String de conexão do MongoDB | `MONGODB_URI` | Onde os dados são gravados/lidos |
| Host do Canvas do cliente | `CANVAS_BASE_URL` | Ex.: `https://afya.instructure.com` |
| Token de acesso do Canvas (segredo) | `CANVAS_API_TOKEN` | Autoriza a busca dos cursos da blueprint |
| Senhas dos 3 usuários | `FMU_USER_PASSWORD`, `GOMINING_USER_PASSWORD`, `AFYA_USER_PASSWORD` | Usadas **uma vez** pelo `scripts/add-users.php` para criar os logins |

> **Segredos** (`MONGODB_URI` com senha, `CANVAS_API_TOKEN`, senhas dos usuários) nunca devem ser commitados. Coloque-os em um arquivo de ambiente com permissão restrita (ver seção Ubuntu).

## Variáveis de ambiente (referência completa)

Todas as configurações são lidas de variáveis de ambiente. As que têm padrão podem ser omitidas.

| Variável | Padrão | Descrição |
|----------|--------|-----------|
| `MONGODB_URI` | `mongodb://127.0.0.1:27017` | Conexão do MongoDB. **Defina em produção.** |
| `MONGODB_DATABASE` | `activity` | Banco de dados usado. |
| `MONGODB_ACTIVITY_COLLECTION` | `fmu_activity_control` | Collection das disciplinas (painel FMU). |
| `MONGODB_USER_COLLECTION` | `fmu_user_control` | Collection dos usuários/login. |
| `MONGODB_CANVAS_COLLECTION` | `canvas_blueprints` | Collection das blueprints/cursos (painel Canvas). |
| `CANVAS_BASE_URL` | `https://afya.test.instructure.com` | Host do Canvas. **Ajuste para o host real do cliente.** |
| `CANVAS_API_TOKEN` | *(vazio)* | Token de acesso do Canvas (segredo). **Obrigatório para o painel Canvas.** |
| `CANVAS_TIMEOUT_SECONDS` | `20` | Timeout de cada chamada ao Canvas. |
| `CANVAS_PER_PAGE` | `100` | Itens por página na API do Canvas (máx. 100). |
| `CANVAS_MAX_PAGES` | `200` | Limite de páginas seguidas por blueprint (proteção). |
| `CANVAS_PAGE_SIZE` | `10` | Blueprints por página na tela. |
| `ADMIN_USERS` | `gomining` | Logins com acesso ao upload de planilha (lista separada por vírgula). |
| `UPLOAD_MAX_BYTES` | `5242880` (5 MB) | Tamanho máximo do CSV de importação. |
| `UPLOAD_MAX_ROWS` | `10000` | Máximo de linhas do CSV de importação. |
| `LTI_CONTROL_BASE_URL` | `http://prd-lti-activity-control.eba-ikyyadp3.us-east-2.elasticbeanstalk.com` | Serviço LTI notificado ao ativar/desativar disciplinas (painel FMU). |
| `LTI_CONTROL_INSTITUTION` | `fmu` | Valor do campo `institution` no payload LTI. |
| `LTI_CONTROL_TIMEOUT_SECONDS` | `5` | Timeout da chamada ao serviço LTI. |
| `LOGIN_MAX_ATTEMPTS` | `5` | Tentativas de login por usuário+IP antes do bloqueio. |
| `LOGIN_IP_MAX_ATTEMPTS` | `30` | Tentativas de login por IP antes do bloqueio. |
| `LOGIN_LOCKOUT_SECONDS` | `900` (15 min) | Duração do bloqueio de login. |
| `LOGIN_THROTTLE_DIR` | diretório temporário do sistema | Pasta gravável onde o estado do bloqueio é salvo. |
| `APP_TIMEZONE` | `America/Sao_Paulo` | Fuso horário da aplicação. |
| `APP_SESSION_NAME` | `fmu_auto_grading_portal` | Nome do cookie de sessão. |

Variáveis usadas **apenas** pelo `scripts/add-users.php` (criação de usuários): `FMU_USER_PASSWORD`, `GOMINING_USER_PASSWORD`, `AFYA_USER_PASSWORD`.

## Instalação e configuração em servidor Ubuntu

### Setup automático (recomendado)

O script `scripts/setup-env.sh` faz o processo de ponta a ponta de forma interativa — pergunta cada informação, uma a uma:

```bash
cd /var/www/fmu-controle   # ou a pasta onde clonou o projeto
bash scripts/setup-env.sh
```

Ele:

1. **Instala as dependências** (PHP + extensões `mongodb`/`mbstring`/`curl` e o Nginx) via PPA `ondrej/php` — pergunta a versão do PHP (padrão 8.3);
2. **Monta a `MONGODB_URI`** do seu servidor externo (você cola a URI pronta ou informa host, porta, usuário e senha — a senha é lida de forma oculta e URL-encoded automaticamente), ou aceita um Atlas `mongodb+srv`;
3. Pergunta host/token do **Canvas** e as demais opções (com padrões sensatos);
4. **Grava `/etc/fmu-portal.env`** com permissão `640` (dono `root:www-data`) e cria a pasta de throttle;
5. **Testa a conexão** com o MongoDB (se a extensão estiver ativa);
6. Opcionalmente **configura o PHP-FPM** (EnvironmentFile + `clear_env = no`) e reinicia o serviço.

Ao final, ele mostra os próximos passos (criar os usuários e configurar o Nginx). Rode como `root` ou com `sudo` disponível para que ele possa instalar pacotes e gravar em `/etc`.

> Segredos (senha do Mongo, token do Canvas) são lidos ocultos e nunca aparecem na tela nem no histórico do shell.

### Deploy com Apache em /var/www/html (script)

Se você já rodou o `setup-env.sh` (variáveis e usuários prontos) e quer publicar com **Apache** servindo a partir de `/var/www/html`, use:

```bash
sudo bash scripts/deploy-apache.sh
```

O `deploy-apache.sh`:

- **Valida** o que já existe e instala só o que faltar (Apache, PHP, extensões `mongodb`/`mbstring`/`curl`);
- Publica o projeto em **`/var/www/html/fmu-controle`** (não sobrescreve se já existir) e serve a subpasta `public/` — mantendo `src/`, `config/` e `.git` fora da web;
- **Reaproveita** o `/etc/fmu-portal.env`; se ele não existir, gera a partir das variáveis já presentes no ambiente;
- Configura o Apache (PHP-FPM via `mod_proxy_fcgi`), desativa o site `000-default` e recarrega;
- **Não** cria usuários e **não** configura SSL.

Ajuste o `ServerName` exportando `APP_SERVER_NAME` antes de rodar (padrão: hostname da máquina). Para outro caminho, passe como argumento: `sudo bash scripts/deploy-apache.sh /var/www/html/outro-nome`.

### Passo a passo manual

Caso prefira configurar manualmente, siga os passos abaixo (Ubuntu 22.04/24.04 com Nginx + PHP-FPM). Ajuste a versão do PHP (`8.3` nos exemplos) conforme a instalada.

### 1. Instalar PHP, a extensão mongodb e o Nginx

```bash
# PPA ondrej: versões atuais do PHP e o pacote php-mongodb prontos
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.3-cli php8.3-fpm php8.3-mbstring php8.3-curl php8.3-mongodb nginx

# Conferir que a extensão mongodb está ativa
php -m | grep -i mongodb
```

### 2. Publicar o código

```bash
sudo mkdir -p /var/www/fmu-controle
sudo chown -R "$USER":www-data /var/www/fmu-controle
git clone <URL_DO_REPOSITORIO> /var/www/fmu-controle
# a raiz pública é a pasta public/
```

### 3. Criar o arquivo de variáveis de ambiente (com segredos)

Crie `/etc/fmu-portal.env` com os valores reais. Preencha ao menos os obrigatórios:

```bash
sudo tee /etc/fmu-portal.env >/dev/null <<'EOF'
MONGODB_URI=mongodb+srv://USUARIO:SENHA@HOST.mongodb.net/?appName=Atividades
MONGODB_DATABASE=activity
CANVAS_BASE_URL=https://afya.instructure.com
CANVAS_API_TOKEN=COLE_AQUI_O_TOKEN_DO_CANVAS
APP_TIMEZONE=America/Sao_Paulo
LOGIN_THROTTLE_DIR=/var/lib/fmu-portal/throttle
EOF

# Permissão restrita: só o usuário do PHP lê o arquivo de segredos
sudo chown root:www-data /etc/fmu-portal.env
sudo chmod 640 /etc/fmu-portal.env

# Pasta gravável para o controle de tentativas de login
sudo mkdir -p /var/lib/fmu-portal/throttle
sudo chown -R www-data:www-data /var/lib/fmu-portal
```

### 4. Fazer o PHP-FPM carregar essas variáveis

```bash
# Carrega o arquivo de ambiente no serviço do PHP-FPM
sudo systemctl edit php8.3-fpm
```

No editor que abrir, insira:

```ini
[Service]
EnvironmentFile=/etc/fmu-portal.env
```

Garanta que o pool repassa o ambiente aos scripts — em `/etc/php/8.3/fpm/pool.d/www.conf` a linha deve ser:

```ini
clear_env = no
```

Depois recarregue:

```bash
sudo systemctl restart php8.3-fpm
```

### 5. Configurar o Nginx

```bash
sudo tee /etc/nginx/sites-available/fmu-portal >/dev/null <<'EOF'
server {
    listen 80;
    server_name portal.exemplo.com;
    root /var/www/fmu-controle/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # Não expõe arquivos ocultos
    location ~ /\.(?!well-known) { deny all; }
}
EOF

sudo ln -s /etc/nginx/sites-available/fmu-portal /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Para HTTPS (recomendado — o cookie de sessão só recebe a flag `Secure` sob HTTPS):

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d portal.exemplo.com
```

### 6. Criar os três usuários (fmu, gomining, afya)

As senhas nunca ficam no código; passe-as por variável de ambiente só nesta execução:

```bash
cd /var/www/fmu-controle
sudo -u www-data \
  FMU_USER_PASSWORD='senha-fmu' \
  GOMINING_USER_PASSWORD='senha-gomining' \
  AFYA_USER_PASSWORD='senha-afya' \
  MONGODB_URI='mongodb+srv://USUARIO:SENHA@HOST.mongodb.net/?appName=Atividades' \
  php scripts/add-users.php
```

> Prefira senhas fortes (mínimo 8 caracteres). O script grava apenas o hash (`password_hash`) e marca `ativo: true`. Rode novamente a qualquer momento para redefinir senhas.

Pronto — acesse `https://portal.exemplo.com`. Cada login cai no seu painel (ver "Acesso por painel").

### Testar sem servidor web (validação rápida)

Para um teste pontual com o servidor embutido do PHP, exporte as variáveis e rode:

```bash
cd /var/www/fmu-controle
set -a; source /etc/fmu-portal.env; set +a
php -S 0.0.0.0:8000 -t public
```

Depois acesse `http://SERVIDOR:8000`.

## Página de administração (upload de planilha)

A página `admin.php` permite cadastrar disciplinas em massa a partir de uma planilha. **O acesso é restrito ao login da Gomining** — os demais usuários recebem "Acesso restrito" (HTTP 403). Os logins com acesso são configuráveis pela variável `ADMIN_USERS` (lista separada por vírgula; padrão `gomining`).

O arquivo deve ser um **CSV separado por ponto-e-vírgula** (no Excel em português: *Arquivo → Salvar como → CSV*). A primeira linha é obrigatoriamente o cabeçalho, nesta ordem exata:

```
CRT;DISCIPLINA;BLOCO;ANO
```

- `CRT` — nome/código da oferta, usado como identificador único (gravado em `codigo_disciplina`)
- `DISCIPLINA` — nome da disciplina (`nome_disciplina`)
- `BLOCO` — bloco (`bloco`)
- `ANO` — ano (`ano`)

Cada linha corresponde a uma disciplina. Regras de importação:

- Cada célula sofre `trim` (espaços no início/fim são removidos).
- Disciplinas cujo `CRT` (codigo_disciplina) **ainda não existe** são adicionadas com status **`Ativa`**.
- Disciplinas já cadastradas (mesmo `CRT`) **não são alteradas**.
- Linhas em branco são ignoradas; linhas sem `CRT` são reportadas como ignoradas; `CRT` repetido no próprio arquivo é reportado como duplicado (só a primeira ocorrência é considerada).
- Arquivos salvos em Windows-1252 (Latin-1) e com BOM UTF-8 são tratados automaticamente.

Limites configuráveis: `UPLOAD_MAX_BYTES` (padrão 5 MB) e `UPLOAD_MAX_ROWS` (padrão 10000).

## Página Canvas (controle de cursos por blueprint)

A página `canvas.php` é uma segunda interface (layout próprio) para clientes que usam o Canvas. A partir do código de uma **blueprint** (ID do curso da blueprint no Canvas), o portal busca todos os cursos associados e os cadastra para controle de correção.

Configuração por variáveis de ambiente:

```powershell
$env:CANVAS_BASE_URL="https://afya.test.instructure.com"
$env:CANVAS_API_TOKEN="<token de acesso do Canvas>"
$env:MONGODB_CANVAS_COLLECTION="canvas_blueprints"   # collection própria
$env:CANVAS_PAGE_SIZE="10"                            # blueprints por página
```

> **Nunca** grave o token do Canvas no código ou no repositório — use apenas `CANVAS_API_TOKEN`. O token concede acesso à API do Canvas; trate-o como segredo e rotacione-o se for exposto.

Fluxo:

- **Cadastrar blueprint:** informe o código (apenas números). O portal chama a API do Canvas (`/api/v1/courses/{id}/blueprint_templates/default/associated_courses`), seguindo automaticamente a paginação (header `Link`), grava tudo no banco e volta para a lista. Durante a busca, uma tela de **"Aguarde"** é exibida.
- **Listagem:** as blueprints aparecem paginadas, cada uma com seus cursos encadeados (nome, ID do curso, SIS, termo, data de coleta e status). Há um campo de **filtro por ID ou nome** de curso.
- **Ativar/desativar:** por curso (seleção múltipla) ou a blueprint inteira (selecionar todos). O status é gravado apenas no banco (sem chamadas externas).
- **Atualizar:** cada blueprint tem um botão que rebusca no Canvas os cursos — os novos entram como `Ativa`, os já existentes mantêm o status atual, e os que saíram da blueprint são preservados.

Os dados ficam em uma collection própria (`canvas_blueprints`), com cada documento representando uma blueprint e seus cursos embutidos. A página exige login (qualquer usuário autenticado do portal).

## Integração com o serviço LTI de controle de atividades

Sempre que disciplinas são ativadas ou desativadas no portal, é enviado um `PUT` para o serviço LTI de controle:

- Ativação: `{base_url}/v1/control/enable/list`
- Desativação: `{base_url}/v1/control/disable/list`

Payload enviado (os códigos vêm do campo `codigo_disciplina` das disciplinas selecionadas):

```json
{
    "activityId": "codigo1,codigo2,codigo3",
    "institution": "fmu"
}
```

Configuração por variáveis de ambiente (valores padrão já apontam para produção):

```powershell
$env:LTI_CONTROL_BASE_URL="http://prd-lti-activity-control.eba-ikyyadp3.us-east-2.elasticbeanstalk.com"
$env:LTI_CONTROL_INSTITUTION="fmu"
$env:LTI_CONTROL_TIMEOUT_SECONDS="5"
```

Se o serviço LTI estiver indisponível, a alteração de status no banco **é mantida** e o portal exibe um aviso pedindo para tentar novamente; o detalhe do erro fica registrado no `error_log` do servidor. O envio usa `allow_url_fopen` (habilitado por padrão no PHP).

## Collections

### `fmu_activity_control`

Campos usados pela aplicação:

- `nome_disciplina`
- `bloco`
- `ano`
- `codigo_disciplina`
- `status` (`Ativa` ou `Inativa`)
- `data`

Apenas `codigo_disciplina` e `status` são realmente necessários. Documentos que tenham somente esses dois campos continuam aparecendo na listagem, podem ser selecionados e ativados/desativados normalmente — os campos ausentes (nome, bloco, ano) são exibidos como `—`.

O portal também consegue buscar, filtrar e exibir documentos que usem alguns nomes legados com maiúsculas, como `Nome da disciplina`, `Bloco`, `Código da Disciplina` e `Status`, mas as atualizações gravam nos campos canônicos `status` e `data`.

### `fmu_user_control`

Campos usados para login:

- `usuario` (ou `username` / `email`)
- `nome`
- `senha_hash` (ou `password_hash`)
- `ativo` (ou `active`) — **obrigatório**: usuários sem esse campo (ou com valor falso) não conseguem entrar
- `data`

O campo `senha_hash` **deve** ser gerado com `password_hash`. Senhas em texto puro não são aceitas — documentos legados com os campos `senha`/`password` em texto puro precisam ser migrados para hash antes do login funcionar.

## Acesso por painel (logins fixos)

O portal tem dois painéis e três logins fixos, com acesso segregado:

| Login      | Painel FMU (`index.php` / `admin.php`) | Painel Canvas/Afya (`canvas.php`) |
|------------|:--------------------------------------:|:---------------------------------:|
| `fmu`      | ✅                                     | ❌                                |
| `afya`     | ❌                                     | ✅                                |
| `gomining` | ✅                                     | ✅                                |

- Ao entrar, o usuário é levado ao seu painel inicial (`fmu`/`gomining` → Disciplinas; `afya` → Canvas).
- Quem tenta abrir um painel sem permissão é redirecionado para o painel a que tem acesso; o menu só mostra os painéis liberados.
- `gomining` acessa os dois com o **mesmo login** e alterna pelos links de navegação (Disciplinas ↔ Canvas).
- O upload de planilha (`admin.php`) continua restrito ao `gomining`.

O mapeamento de painéis é fixo em `config/config.php` (chave `panels`). Os três usuários são criados por `scripts/add-users.php` (senhas por `FMU_USER_PASSWORD`, `GOMINING_USER_PASSWORD`, `AFYA_USER_PASSWORD`).

## Segurança

- O login é limitado por tentativas: após 5 falhas para o mesmo usuário+IP (ou 30 falhas por IP) o acesso fica bloqueado por 15 minutos. Configurável via `LOGIN_MAX_ATTEMPTS`, `LOGIN_IP_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_SECONDS` e `LOGIN_THROTTLE_DIR` (diretório gravável onde o estado do bloqueio é salvo; padrão: diretório temporário do sistema).
- O cookie de sessão é emitido com `HttpOnly`, `SameSite=Lax` e `Secure` (quando servido por HTTPS). Em produção, sirva o portal **sempre por HTTPS**.
- As respostas incluem `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options` e `Referrer-Policy`. Detalhes de erros internos são gravados no log do servidor (`error_log`) e nunca exibidos ao usuário.
- Não use o usuário de teste `admin/admin123` em produção — ele existe apenas para a massa de teste local.

## Cadastro dos usuários de acesso (fmu, gomining e afya)

Para criar (ou atualizar) os três usuários oficiais do portal, rode no servidor (Linux, com a extensão `mongodb` habilitada):

```bash
php scripts/add-users.php
```

No Windows (com a DLL do repositório):

```powershell
php -d extension=.\vendor\php-ext\mongodb\php_mongodb.dll scripts\add-users.php
```

O script cria os usuários `fmu`, `gomining` e `afya` na collection `fmu_user_control`, pedindo a senha de cada um de forma interativa (mínimo 8 caracteres, com confirmação). A senha é gravada apenas como hash (`password_hash`) e o campo `ativo` é definido como `true`. Se o usuário já existir, a senha e os dados são atualizados — o script também serve para redefinir senhas.

Para uso não interativo (automação), defina as senhas por variáveis de ambiente antes de rodar:

```bash
FMU_USER_PASSWORD='...' GOMINING_USER_PASSWORD='...' AFYA_USER_PASSWORD='...' \
  php scripts/add-users.php
```

## Cadastro manual de usuários direto no MongoDB

Se preferir criar os usuários direto no banco (via `mongosh`, Compass ou o Data Explorer do Atlas), siga os dois passos abaixo.

### 1. Gerar o hash da senha

O portal só aceita senhas com hash compatível com o `password_hash` do PHP — senha em texto puro é recusada no login. Gere o hash em qualquer máquina com PHP:

```bash
php -r "echo password_hash('SenhaEscolhidaAqui', PASSWORD_DEFAULT), PHP_EOL;"
```

A saída será algo como `$2y$12$k8jFqZ0iX9mYw3pL5cQnCu...`.

> **Importante:** use o PHP para gerar o hash. Ferramentas online ou bibliotecas de outras linguagens (como o `bcrypt` do Node) geram hashes com prefixo `$2a$`/`$2b$`, que o portal rejeita — o hash precisa começar com `$2y$` (ou ser Argon2 gerado pelo PHP).

### 2. Inserir o documento na collection `fmu_user_control`

Com `mongosh`:

```javascript
use activity

db.fmu_user_control.insertOne({
  usuario: "fmu",
  nome: "FMU",
  senha_hash: "$2y$12$coleAquiOHashGeradoNoPasso1",
  ativo: true,
  data: new Date()
})
```

Se o usuário já existir e você quiser apenas trocar a senha, use update com upsert (evita documento duplicado):

```javascript
db.fmu_user_control.updateOne(
  { usuario: "fmu" },
  { $set: { senha_hash: "$2y$12$novoHash...", ativo: true, data: new Date() } },
  { upsert: true }
)
```

No Compass ou no Atlas Data Explorer é equivalente: abra a collection `fmu_user_control` (banco `activity`), clique em "Insert Document" e cole o JSON com esses campos.

### Regras que o documento precisa cumprir

- `usuario` — é o login digitado no portal (os campos `username` ou `email` também são aceitos).
- `senha_hash` — obrigatoriamente um hash gerado pelo `password_hash` (o nome de campo `password_hash` também é aceito). Nunca grave a senha em texto puro.
- `ativo: true` — obrigatório. Usuário sem esse campo (ou com valor falso) não consegue entrar.
- `nome` — opcional; é o que aparece no topo do portal após o login.

Atenções práticas:

- Cuidado com o `$` ao copiar o hash: dentro do `mongosh` entre aspas está seguro, mas em um shell bash com aspas duplas o `$2y$...` pode ser expandido e corromper o hash — use aspas simples.
- Cada usuário deve ter seu próprio hash, mesmo que as senhas sejam iguais.
- O script `scripts/add-users.php` (seção anterior) faz esses dois passos de uma vez; o caminho manual é útil quando há acesso direto ao banco, mas não é possível rodar o script.

## Massa de teste

Com MongoDB e a extensão PHP `mongodb` habilitados, rode:

```powershell
php scripts/seed-sample.php
```

O script cria 45 disciplinas fictícias em `fmu_activity_control` e um usuário em `fmu_user_control`.

Também há arquivos JSON prontos para importação:

- `data/fmu_activity_control.seed.json`
- `data/fmu_user_control.seed.json`
- `data/fmu_seed_data.json`

Para importar com `mongoimport`:

```powershell
mongoimport --uri $env:MONGODB_URI --db activity --collection fmu_activity_control --file data/fmu_activity_control.seed.json --jsonArray
mongoimport --uri $env:MONGODB_URI --db activity --collection fmu_user_control --file data/fmu_user_control.seed.json --jsonArray
```

Login de teste:

- usuário: `admin`
- senha: `admin123`

## Executar localmente

```powershell
$env:MONGODB_URI="mongodb+srv://USUARIO:SENHA@HOST.mongodb.net/?appName=Atividades"
.\scripts\run-dev.ps1
```

Depois acesse `http://localhost:8000`.
