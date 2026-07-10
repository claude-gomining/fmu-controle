# Portal FMU - Correção Automática

Portal em PHP para ativar ou desativar disciplinas de correção automática armazenadas em MongoDB.

## Requisitos

- PHP 8.1+
- Extensão PHP `mongodb` habilitada
- MongoDB acessível pela aplicação

## Configuração

A aplicação lê as configurações por variáveis de ambiente:

```powershell
$env:MONGODB_URI="mongodb+srv://USUARIO:SENHA@HOST.mongodb.net/?appName=Atividades"
$env:MONGODB_DATABASE="activity"
$env:MONGODB_ACTIVITY_COLLECTION="fmu_activity_control"
$env:MONGODB_USER_COLLECTION="fmu_user_control"
```

Use a URI real apenas no ambiente local/servidor. Não grave credenciais reais no código ou no README.

## Collections

### `fmu_activity_control`

Campos usados pela aplicação:

- `nome_disciplina`
- `bloco`
- `ano`
- `codigo_disciplina`
- `status` (`Ativa` ou `Inativa`)
- `data`

O portal também consegue buscar, filtrar e exibir documentos que usem alguns nomes legados com maiúsculas, como `Nome da disciplina`, `Bloco`, `Código da Disciplina` e `Status`, mas as atualizações gravam nos campos canônicos `status` e `data`.

### `fmu_user_control`

Campos usados para login:

- `usuario` (ou `username` / `email`)
- `nome`
- `senha_hash` (ou `password_hash`)
- `ativo` (ou `active`) — **obrigatório**: usuários sem esse campo (ou com valor falso) não conseguem entrar
- `data`

O campo `senha_hash` **deve** ser gerado com `password_hash`. Senhas em texto puro não são aceitas — documentos legados com os campos `senha`/`password` em texto puro precisam ser migrados para hash antes do login funcionar.

## Segurança

- O login é limitado por tentativas: após 5 falhas para o mesmo usuário+IP (ou 30 falhas por IP) o acesso fica bloqueado por 15 minutos. Configurável via `LOGIN_MAX_ATTEMPTS`, `LOGIN_IP_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_SECONDS` e `LOGIN_THROTTLE_DIR` (diretório gravável onde o estado do bloqueio é salvo; padrão: diretório temporário do sistema).
- O cookie de sessão é emitido com `HttpOnly`, `SameSite=Lax` e `Secure` (quando servido por HTTPS). Em produção, sirva o portal **sempre por HTTPS**.
- As respostas incluem `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options` e `Referrer-Policy`. Detalhes de erros internos são gravados no log do servidor (`error_log`) e nunca exibidos ao usuário.
- Não use o usuário de teste `admin/admin123` em produção — ele existe apenas para a massa de teste local.

## Cadastro dos usuários de acesso (FMU e Gomining)

Para criar (ou atualizar) os usuários oficiais do portal, rode:

```powershell
php -d extension=.\vendor\php-ext\mongodb\php_mongodb.dll scripts\add-users.php
```

No Linux/macOS (com a extensão `mongodb` habilitada):

```bash
php scripts/add-users.php
```

O script cria os usuários `fmu` e `gomining` na collection `fmu_user_control`, pedindo a senha de cada um de forma interativa (mínimo 8 caracteres, com confirmação). A senha é gravada apenas como hash (`password_hash`) e o campo `ativo` é definido como `true`. Se o usuário já existir, a senha e os dados são atualizados — o script também serve para redefinir senhas.

Para uso não interativo (automação), defina as senhas por variáveis de ambiente antes de rodar:

```powershell
$env:FMU_USER_PASSWORD="..."
$env:GOMINING_USER_PASSWORD="..."
php -d extension=.\vendor\php-ext\mongodb\php_mongodb.dll scripts\add-users.php
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
