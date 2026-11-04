# Deploy do Beauty Pet Web no Render

## Configuração do serviço

- Tipo: Web Service
- Runtime/Language: Docker
- Branch: main
- Root Directory: `beautypet-web` (quando o repositório for `Projeto-BeautyPet`)
- Dockerfile: `Dockerfile` (detectado dentro do Root Directory)

## Variável de ambiente

No Render, em **Environment**, cadastre:

- `DATABASE_URL` = string PostgreSQL do Supabase (Session Pooler/porta 5432 é adequada)

Não envie `config/.env` para o GitHub. O arquivo é apenas para uso local.

## Atualização

Com Auto Deploy habilitado, um `git push origin main` publica a nova versão automaticamente.
Também é possível usar **Manual Deploy > Deploy latest commit**.

## Fotos enviadas pelo sistema

O filesystem padrão do Render é efêmero. Arquivos novos gravados em `uploads/` podem ser perdidos em reinícios/deploys. Para produção, prefira Supabase Storage ou um Persistent Disk.
