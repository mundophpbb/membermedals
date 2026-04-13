# mundophpbb/membermedals

Esqueleto inicial da extensão **Member Medals** para phpBB 3.3.15 e PHP 8+.

## O que já está incluído

- estrutura básica da extensão
- migrations iniciais
- módulo ACP
- cadastro de medalhas
- concessão manual de medalhas
- página pública `/membermedals`
- idiomas `pt_br` e `en`

## O que ficou propositalmente para a próxima etapa

- upload nativo de imagem para medalhas
- regras automáticas no ACP
- exibição no viewtopic
- exibição no perfil público
- notificações
- cron de ressincronização

## Instalação

1. Extraia a pasta `ext/mundophpbb/membermedals`
2. Ative a extensão no ACP
3. Limpe o cache do phpBB
4. Acesse:
   - ACP > Extensões > Member Medals
   - Página pública: `/membermedals`

## Observação importante

Neste primeiro pacote, o campo de imagem aceita **caminho existente** ou **URL absoluta**.
Isso foi mantido assim para reduzir atrito e erros enquanto o fluxo de upload ainda não foi fechado.

## Próximo passo recomendado

Implementar:
1. upload de imagens
2. regras automáticas (`posts`, `avatar`, `signature`, `membership_days`)
3. exibição compacta no `viewtopic`

0.5.3: frontend reorganizado e bots do ACP excluídos das regras, contagens e páginas públicas.

## API de providers de regras (0.7.2)

Guia detalhado para autores: `DEVELOPER_API.md`


A extensão agora usa uma arquitetura de **rule providers**.

### Providers nativos

- `posts`
- `topics`
- `avatar`
- `signature`
- `membership_days`

### Como terceiros podem integrar

O core dispara o evento:

- `mundophpbb.membermedals.collect_rule_providers`

Extensões terceiras podem anexar objetos que implementem:

- `\mundophpbb\membermedals\contract\rule_provider_interface`

### O que um provider define

- chave do critério
- label do critério
- operadores suportados
- família lógica
- se a família é progressiva
- como calcular o valor do usuário
- como normalizar a regra salva no ACP

### Progressão

Providers progressivos usam a noção de **família**. Quando um usuário sobe de faixa na mesma família, a medalha automática anterior pode ser removida e substituída pela superior.
