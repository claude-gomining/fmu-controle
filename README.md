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
| `API_TOKEN` | *(vazio)* | Token da API de consulta (`api-afya.php`). Vazio = só sessão autenticada é aceita. |
| `LOGIN_MAX_ATTEMPTS` | `5` | Tentativas de login por usuário+IP antes do bloqueio. |
| `LOGIN_IP_MAX_ATTEMPTS` | `30` | Tentativas de login por IP antes do bloqueio. |
| `LOGIN_LOCKOUT_SECONDS` | `900` (15 min) | Duração do bloqueio de login. |
| `LOGIN_THROTTLE_DIR` | diretório temporário do sistema | Pasta gravável onde o estado do bloqueio é salvo. |
| `APP_TIMEZONE` | `America/Sao_Paulo` | Fuso horário da aplicação. |
| `APP_SESSION_NAME` | `fmu_auto_grading_portal` | Nome do cookie de sessão. |

Variáveis usadas **apenas** pelo `scripts/add-users.php` (criação de usuários): `FMU_USER_PASSWORD`, `GOMINING_USER_PASSWORD`, `AFYA_USER_PASSWORD`.

## Instalação e configuração em servidor Ubuntu

### Deploy automático com Apache (recomendado)

O script `scripts/deploy.sh` faz todo o deploy num servidor Ubuntu novo, de forma interativa:

```bash
cd /caminho/onde/clonou/o-projeto
sudo bash scripts/deploy.sh
```

Ele:

1. **Pergunta os dados de configuração** (MongoDB URI, banco, host/token do Canvas, fuso horário). Segredos são lidos ocultos. **Não pede usuários nem senhas de login** — os usuários já existem no MongoDB.
2. **Instala só o que faltar**: Apache, PHP (mod_php) e as extensões `mongodb`/`mbstring`/`curl`, via PPA `ondrej/php`.
3. **Publica apenas os arquivos que o usuário acessa** (o conteúdo de `public/`) em **`/var/www/html`**, e o **backend (`src/`, `config/`) em `/var/www`**, fora do diretório servido.
4. **Grava as variáveis em `/etc/fmu-portal.env`** (permissão `640`, `root:www-data`) — lidas pelo próprio app; e cria a pasta de throttle.
5. Deixa o **código como somente-leitura** para o usuário do servidor web.

**Reimplantar sem reconfigurar** (servidor já configurado — atualizar só o código):

```bash
sudo bash scripts/deploy.sh --reuse-config
```

Nesse modo o script **não pergunta nada**: mantém o `/etc/fmu-portal.env` intacto, apenas republica os arquivos e garante as dependências. Se ainda não houver configuração (ou faltar a `MONGODB_URI`), ele avisa e aborta sem alterar nada — use o modo interativo ou o `configure-env.sh` antes. Aliases: `--no-config`, `-y`.

Características importantes:

- Roda no **Apache usando o site padrão** (`/var/www/html`) com **mod_php** — **não cria virtual host, não mexe em portas nem em serviços**, e **não configura SSL**.
- As variáveis chegam ao app porque o `config/config.php` **carrega o `/etc/fmu-portal.env` sozinho** (funciona sob mod_php sem qualquer configuração de servidor). O caminho pode ser trocado por `FMU_ENV_FILE`.
- Estrutura publicada:

  ```
  /var/www/html/     ← DocumentRoot: index.php, admin.php, canvas.php, assets/  (o que o usuário vê)
  /var/www/src/      ← backend (NÃO servido)
  /var/www/config/   ← configuração (NÃO servido)
  /etc/fmu-portal.env← variáveis/segredos (640 root:www-data)
  ```

### Habilitar HTTPS (Let's Encrypt)

O `deploy.sh` não mexe em SSL. Para servir por HTTPS num domínio já apontado (DNS/Route53) para o servidor, rode depois:

```bash
sudo bash scripts/setup-ssl.sh controle.gomining-lti.com
```

O `setup-ssl.sh` instala o `certbot` (plugin do Apache), cria o virtual host do domínio apontando para o mesmo `/var/www/html`, obtém/instala o certificado do Let's Encrypt e ativa o **redirecionamento HTTP→HTTPS**. Pré-requisitos: o domínio já resolvendo para o IP do servidor e as **portas 80 e 443 abertas** no Security Group / Lightsail. A partir daí, o cookie de sessão recebe a flag `Secure` e o HSTS é enviado automaticamente (a aplicação detecta o HTTPS sozinha — nada a mudar no código).

### Completar a configuração depois (só o que falta)

Se você já tem um `/etc/fmu-portal.env` parcial e quer preencher apenas as configurações/credenciais que **ainda não existem**, use:

```bash
sudo bash scripts/configure-env.sh
```

Ele verifica o que já está definido (no arquivo **ou** no ambiente), lista essas como "já configurada" e **pergunta apenas as que faltam**, acrescentando-as ao arquivo **sem alterar** as existentes (faz backup antes). Não instala nada nem publica arquivos — é só para a configuração. Se nada estiver faltando, não faz alterações.

### Passo a passo manual

Caso prefira configurar manualmente com **Nginx + PHP-FPM**, siga os passos abaixo (Ubuntu 22.04/24.04). Ajuste a versão do PHP (`8.3` nos exemplos) conforme a instalada. Neste modo, o projeto pode ficar em `/var/www/fmu-controle` com o DocumentRoot em `public/`.

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
- O `CRT` deve ser um texto **sem espaços** (nem espaço em branco nem separação entre palavras). Linhas com CRT contendo espaço — ou vazio — **não são importadas**, e ao final da importação (e na pré-visualização) é exibida a lista das linhas não importadas, com o CRT e o motivo.
- Disciplinas cujo `CRT` (codigo_disciplina) **ainda não existe** são adicionadas com status **`Ativa`**.
- Disciplinas já cadastradas (mesmo `CRT`) **não são alteradas**.
- Linhas em branco são ignoradas; linhas sem `CRT` são reportadas como ignoradas; `CRT` repetido no próprio arquivo é reportado como duplicado (só a primeira ocorrência é considerada).
- Arquivos salvos em Windows-1252 (Latin-1) e com BOM UTF-8 são tratados automaticamente.

Limites configuráveis: `UPLOAD_MAX_BYTES` (padrão 5 MB) e `UPLOAD_MAX_ROWS` (padrão 10000).

### Pré-visualização antes de importar

O envio **não grava nada de imediato**: primeiro é exibida uma pré-visualização (o sistema consulta o banco para saber o que é novo). Nela aparecem:

- **linhas com dados novos** (serão adicionadas);
- **IDs já cadastrados** no banco (não serão adicionados);
- **IDs repetidos no próprio arquivo** e **linhas sem CRT** (ignorados);
- uma **amostra do mapeamento** coluna → campo (CRT→`codigo_disciplina`, DISCIPLINA→`nome_disciplina`, BLOCO→`bloco`, ANO→`ano`), para conferir se alguém não inverteu, por exemplo, `BLOCO` e `ANO`.

Só depois de clicar em **Confirmar** é que as novas disciplinas são gravadas (e registradas/ativadas no serviço LTI). O botão **Cancelar** descarta a pré-visualização. A pré-visualização fica na sessão e expira em 15 minutos.

### Modal de progresso (envio em lotes)

Ao confirmar uma importação, abre-se um **modal bloqueante** com barra de progresso que envia **um lote por requisição** (o mesmo tamanho de lote do serviço LTI, padrão 100). A cada lote concluído o contador mostra: **lote enviado**, **processados / total (%)**, a **velocidade em itens/s**, o tempo decorrido e a estimativa restante.

Como o modal cobre a tela e os botões são desabilitados, não há como enviar a mesma importação duas vezes. Além disso, cada lote é idempotente (só insere o que ainda não existe), então um reenvio acidental não duplica nada. Se o navegador não suportar `fetch`, o formulário cai no envio tradicional (tudo de uma vez).

### Cadastrar uma disciplina individual

A tela **Nova disciplina** (`nova-disciplina.php`, só para administradores) cadastra uma disciplina por vez. **Apenas o CRT é obrigatório** — nome, bloco e ano são opcionais e só são gravados quando preenchidos (em branco, aparecem como `—` na listagem). O CRT não pode conter espaços, e é possível escolher o status inicial (**Ativa** ou **Inativa**); o serviço LTI é notificado com **criar → ativar/desativar**. Se o CRT já existir, nada é alterado e o portal avisa.

### Cadastrar códigos como Inativa (lista de CRT)

Há um segundo modo de importação que recebe **apenas uma lista de CRT** (um código por linha; também aceita separados por `;` ou `,`, e um cabeçalho `CRT` opcional). Os códigos ainda não cadastrados são adicionados com status **`Inativa`**, gravando **somente `codigo_disciplina` + `status`** — sem nome, bloco ou ano. Na listagem, esses registros aparecem com nome/bloco/ano como `—`. Também passa pela pré-visualização (novos × já existentes) antes de gravar, e no serviço LTI é feito **criar → desativar** para os novos.

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

- **Cadastrar blueprint:** informe o código (apenas números). O portal chama a API do Canvas (`/api/v1/courses/{id}/blueprint_templates/default/associated_courses`), seguindo automaticamente a paginação (header `Link`). Durante a busca, uma tela de **"Aguarde"** é exibida. Antes de gravar, é mostrada uma **pré-visualização** com a lista dos cursos que serão **cadastrados e ativados** (e quantos já existem); nada é gravado até o usuário clicar em **Aceitar**. O botão **Cancelar** descarta. A pré-visualização fica na sessão e expira em 15 minutos.
- **Listagem:** as blueprints aparecem paginadas, cada uma com seus cursos encadeados (nome, ID do curso, SIS, termo, data de coleta e status). Há um campo de **filtro por ID ou nome** de curso.
- **Ativar/desativar:** por curso (seleção múltipla) ou a blueprint inteira (selecionar todos). O status é gravado apenas no banco (sem chamadas externas).
- **Atualizar:** cada blueprint tem um botão que rebusca no Canvas os cursos — os novos entram como `Ativa`, os já existentes mantêm o status atual, e os que saíram da blueprint são preservados.

Os dados ficam em uma collection própria (`canvas_blueprints`), com cada documento representando uma blueprint e seus cursos embutidos. A página exige login (qualquer usuário autenticado do portal).

## Integração com o serviço LTI de controle de atividades

O portal conversa com o serviço LTI de controle em três endpoints (payload sempre `{"activityId": "id1,id2,...", "institution": "..."}`):

- Criar: `POST {base_url}/v1/control/list`
- Ativar: `PUT {base_url}/v1/control/enable/list`
- Desativar: `PUT {base_url}/v1/control/disable/list`

Quando são **criadas/importadas** novas atividades, o serviço é chamado na ordem **criar → ativar**: primeiro `POST /v1/control/list` para registrar, depois `PUT .../enable/list` para ativar (as novas entram como `Ativa`). Isso vale para os dois painéis:

- **FMU** (import de CSV): `activityId` = `codigo_disciplina` das disciplinas novas; `institution` = `fmu`.
- **AFYA** (cadastro/atualização de blueprint): `activityId` = `course_id` (ID do curso no Canvas) dos cursos novos; `institution` = `afya`.

O **ativar/desativar manual** do painel FMU envia `PUT enable/disable`. O toggle manual do painel **AFYA** grava apenas no banco (não notifica o serviço).

Configuração por variáveis de ambiente (valores padrão já apontam para produção):

```bash
LTI_CONTROL_BASE_URL=http://prd-lti-activity-control.eba-ikyyadp3.us-east-2.elasticbeanstalk.com
LTI_CONTROL_INSTITUTION=fmu          # institution do painel FMU
LTI_CONTROL_INSTITUTION_AFYA=afya    # institution do painel AFYA
LTI_CONTROL_TIMEOUT_SECONDS=5
```

### Envio em lotes

As listas são enviadas em **lotes de 100 IDs por requisição** (configurável por `LTI_CONTROL_BATCH_SIZE`), evitando timeout e payloads grandes demais em importações com centenas/milhares de códigos. Cada lote é uma requisição independente: se um lote falha, os demais continuam.

Se o serviço LTI estiver indisponível, a alteração no banco **é mantida** e o portal exibe um aviso com **exatamente quais IDs falharam** (os do lote com erro), além de um botão **"Tentar enviar novamente"** que reenvia só esses IDs. O detalhe do erro (método, status HTTP e o intervalo do lote) fica no `error_log` do servidor. O envio usa `allow_url_fopen` (habilitado por padrão no PHP).

> No fluxo de criação, apenas os IDs **registrados com sucesso** seguem para o `enable`/`disable` — se o `POST` de um lote falhar, aqueles IDs não são ativados/desativados e entram na lista de falhas.

## API de consulta (AFYA)

Endpoints somente leitura, em JSON, para consultar blueprints e cursos da AFYA.

| O que | Chamada |
|---|---|
| Apenas o que está **ativo** | `GET /api-afya.php?status=ativa` |
| Apenas o que está **inativo** | `GET /api-afya.php?status=inativa` |
| **Todos**, com o status de cada um | `GET /api-afya.php` (ou `?status=todos`) |

**Autenticação** — uma das duas:

- `Authorization: Bearer <API_TOKEN>` (defina `API_TOKEN` no `/etc/fmu-portal.env`); ou
- sessão do portal já autenticada e com acesso ao painel AFYA.

Se `API_TOKEN` não estiver definido, **só a sessão é aceita** — o endpoint nunca fica público por descuido.

```bash
curl -sS -H "Authorization: Bearer $API_TOKEN" \
  'https://controle.gomining-lti.com/api-afya.php?status=ativa'
```

Resposta:

```json
{
  "ok": true,
  "institution": "afya",
  "filter": "Ativa",
  "generated_at": "2026-07-24T01:30:00+00:00",
  "summary": {
    "blueprints": 2, "courses_returned": 2,
    "courses_active": 2, "courses_inactive": 1
  },
  "blueprints": [
    {
      "blueprint_id": "130764",
      "updated_at": "24/07/2026 01:20",
      "active_count": 1, "inactive_count": 1,
      "is_active": true, "course_count": 1,
      "courses": [
        {
          "course_id": 136272, "name": "DIREITO CIVIL",
          "course_code": "DIR-01", "sis_course_id": "194554",
          "term_name": "2025/2", "status": "Ativa",
          "collected_at": "24/07/2026 01:20"
        }
      ]
    }
  ]
}
```

Notas:

- O status é **por curso** — não existe status no documento da blueprint. Por isso `is_active` de uma blueprint significa **"tem ao menos um curso ativo"**, e `active_count`/`inactive_count` mostram a composição.
- `active_count` e `inactive_count` sempre refletem o **total** da blueprint, mesmo com filtro aplicado; já `courses` e `course_count` respeitam o filtro.
- Com filtro, blueprints sem nenhum curso naquele status são omitidas.
- Erros seguem o mesmo formato: `{"ok": false, "error": "..."}` com HTTP 400 (parâmetro inválido), 401 (não autorizado), 405 (método) ou 500.

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
