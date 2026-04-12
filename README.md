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
