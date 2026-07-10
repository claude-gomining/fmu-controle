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

Campos sugeridos para login:

- `usuario`
- `nome`
- `senha_hash`
- `ativo`
- `data`

O campo `senha_hash` deve ser gerado com `password_hash`. O portal também reconhece `username`, `email`, `password_hash`, `senha` e `password` para facilitar migrações.

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
