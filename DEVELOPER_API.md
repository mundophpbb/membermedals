# Member Medals Provider API Guide (0.7.2)

Este guia descreve a API mínima de integração externa do `mundophpbb/membermedals`.

## Objetivo

A API permite que outras extensões adicionem **novos critérios de medalhas** sem alterar o core do Member Medals.

Exemplos de critérios externos:
- pontos
- doações
- curtidas recebidas
- tópicos resolvidos
- amigos / inimigos
- anexos enviados

## Como a arquitetura funciona

O core trabalha com três peças:

1. **Provider**
   - sabe como calcular um valor para um usuário
   - define nome, família, operadores e normalização da regra

2. **Registry**
   - reúne providers nativos e externos

3. **Rules manager**
   - usa o provider para avaliar a regra
   - delega a concessão da medalha ao grant manager

## Evento público de registro

O core dispara o evento:

- `mundophpbb.membermedals.collect_rule_providers`

Extensões externas devem ouvir esse evento e anexar seus providers no array `providers`.

## Interface obrigatória

Todo provider externo deve implementar:

- `\mundophpbb\membermedals\contract\rule_provider_interface`

### Métodos

#### `get_key(): string`
Chave única do critério.

Exemplo:
- `friends_count`
- `likes_received`
- `donations_total`

#### `get_label_lang_key(): string`
Lang key usada no ACP para o nome do critério.

#### `get_description_lang_key(): string`
Lang key usada para a descrição do critério.

#### `get_supported_operators(): array`
Operadores aceitos.

Exemplo comum:

```php
['>=', '>', '=', '<=', '<']
```

#### `get_family(): string`
Família lógica da progressão.

Use a mesma família para medalhas que devem se substituir dentro da mesma trilha.

Exemplo:
- regra 10 posts
- regra 50 posts
- regra 100 posts

Todas podem usar a família `posts`.

#### `is_progressive(): bool`
Define se a família usa progressão.

Se retornar `true`, o core tenta manter apenas a medalha automática mais alta daquela família.

#### `get_user_value(int $user_id, array $rule, array $context = [])`
Retorna o valor atual do usuário para o critério.

Pode retornar:
- `int`
- `float`
- `string`
- `bool`
- `null`

Na prática, para critérios de medalha, prefira `int`.

#### `normalize_rule_data(array $data): array`
Normaliza dados salvos no ACP.

Use para:
- converter `rule_value` em inteiro
- aplicar mínimo/máximo
- garantir `rule_options`

#### `get_rule_options_schema(): array`
Reservado para opções extras do critério.

**Importante:** na versão atual, o ACP ainda não renderiza dinamicamente esse schema. O método já existe para manter a API preparada, mas hoje ele é mais uma reserva de compatibilidade futura.

#### `get_value_input_attributes(): array`
Define atributos do campo numérico no ACP.

Exemplo:

```php
[
    'min' => 0,
    'max' => 999999999,
    'step' => 1,
]
```

## Estrutura mínima de uma extensão externa

```text
ext/
└── vendor/
    └── myprovider/
        ├── composer.json
        ├── ext.php
        ├── config/
        │   └── services.yml
        ├── event/
        │   └── listener.php
        ├── provider/
        │   └── my_provider.php
        └── language/
            ├── en/common.php
            └── pt_br/common.php
```

## Exemplo mínimo de provider

```php
<?php

namespace vendor\myprovider\provider;

use mundophpbb\membermedals\contract\rule_provider_interface;
use phpbb\db\driver\driver_interface;

class likes_received_provider implements rule_provider_interface
{
    protected driver_interface $db;

    public function __construct(driver_interface $db)
    {
        $this->db = $db;
    }

    public function get_key(): string
    {
        return 'likes_received';
    }

    public function get_label_lang_key(): string
    {
        return 'MEMBERMEDALS_RULE_TYPE_LIKES_RECEIVED';
    }

    public function get_description_lang_key(): string
    {
        return 'MEMBERMEDALS_RULE_TYPE_LIKES_RECEIVED_EXPLAIN';
    }

    public function get_supported_operators(): array
    {
        return ['>=', '>', '=', '<=', '<'];
    }

    public function get_family(): string
    {
        return 'likes_received';
    }

    public function is_progressive(): bool
    {
        return true;
    }

    public function get_user_value(int $user_id, array $rule, array $context = [])
    {
        $sql = 'SELECT COUNT(*) AS total
            FROM ' . LIKES_TABLE . '
            WHERE liked_user_id = ' . (int) $user_id;
        $result = $this->db->sql_query($sql);
        $row = (array) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return (int) ($row['total'] ?? 0);
    }

    public function normalize_rule_data(array $data): array
    {
        $data['rule_value'] = max(0, (int) ($data['rule_value'] ?? 0));
        $data['rule_options'] = $data['rule_options'] ?? [];

        return $data;
    }

    public function get_rule_options_schema(): array
    {
        return [];
    }

    public function get_value_input_attributes(): array
    {
        return [
            'min' => 0,
            'max' => 999999999,
            'step' => 1,
        ];
    }
}
```

## Listener de registro

```php
<?php

namespace vendor\myprovider\event;

use mundophpbb\membermedals\contract\rule_provider_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
    protected rule_provider_interface $provider;

    public function __construct(rule_provider_interface $provider)
    {
        $this->provider = $provider;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'core.user_setup' => 'load_language_on_setup',
            'mundophpbb.membermedals.collect_rule_providers' => 'collect_rule_providers',
        ];
    }

    public function load_language_on_setup($event): void
    {
        $lang_set_ext = $event['lang_set_ext'];
        $lang_set_ext[] = [
            'ext_name' => 'vendor/myprovider',
            'lang_set' => 'common',
        ];
        $event['lang_set_ext'] = $lang_set_ext;
    }

    public function collect_rule_providers($event): void
    {
        $providers = $event['providers'];
        if (!is_array($providers)) {
            $providers = [];
        }

        $providers[] = $this->provider;
        $event['providers'] = $providers;
    }
}
```

## services.yml mínimo

```yml
services:
    vendor.myprovider.provider.likes_received:
        class: vendor\myprovider\provider\likes_received_provider
        arguments:
            - '@dbal.conn'

    vendor.myprovider.listener:
        class: vendor\myprovider\event\listener
        arguments:
            - '@vendor.myprovider.provider.likes_received'
        tags:
            - { name: event.listener }
```

## Lang keys

O provider deve fornecer as chaves de idioma usadas no ACP.

Exemplo:

```php
'MEMBERMEDALS_RULE_TYPE_LIKES_RECEIVED' => 'Curtidas recebidas',
'MEMBERMEDALS_RULE_TYPE_LIKES_RECEIVED_EXPLAIN' => 'Conta quantas curtidas o membro recebeu.',
```

## Progressão por família

A progressão depende de dois pontos:

1. `is_progressive()` retornar `true`
2. providers relacionados usarem a mesma família

Exemplo correto:
- `posts >= 10`
- `posts >= 50`
- `posts >= 100`
- família: `posts`
- progressivo: `true`

Resultado esperado:
- ao subir de faixa, a medalha automática anterior da mesma família é removida

## Compatibilidade prática

Hoje a API já cobre muito bem critérios simples baseados em:
- `COUNT(*)`
- `SUM(...)`
- flags booleanas
- comparação numérica simples

Ela é ideal para métricas como:
- amigos
- inimigos
- anexos
- curtidas
- pontos
- doações
- tópicos resolvidos

## Limitações atuais honestas

Na versão atual:
- `rule_options_schema` ainda não é renderizado dinamicamente no ACP
- a API é ótima para métricas simples e progressivas, mas ainda não é uma DSL complexa de regras compostas
- a concessão continua sendo baseada na avaliação do core, não em jobs externos independentes

## Checklist de teste para um provider externo

1. a extensão ativa sem erro
2. o novo critério aparece no ACP do Member Medals
3. a regra salva sem erro
4. a avaliação concede a medalha corretamente
5. a progressão remove a medalha anterior da mesma família
6. `award_family` é gravado corretamente na tabela de awards

## Recomendação para autores

Se estiver criando integração com outra extensão:
- mantenha o provider pequeno
- deixe a lógica de consulta dentro do provider
- evite alterar o core do Member Medals
- use uma família estável e previsível
- documente claramente o que a métrica conta

## Resumo

A API atual já é suficiente para transformar o Member Medals em uma base extensível de critérios automáticos.

Em uma frase:

> o core avalia regras; o provider resolve a métrica.


## Fachada pública de concessão / revogação

Além do registro de providers, o core agora expõe um serviço público para integrações externas:

- Serviço: `mundophpbb.membermedals.api`
- Interface: `\mundophpbb\membermedals\contract\medals_api_interface`

Esse serviço funciona como uma camada estável sobre `grant_manager` e `rules_manager`, sem exigir acesso direto às tabelas da extensão.

### Métodos públicos

#### `award_medal(int $medal_id, int $user_id, array $context = []): array`
Concede uma medalha a um usuário.

Contextos úteis:
- `source` → ex.: `integration`, `manual`, `vendor.reactions`
- `reason` → motivo textual opcional
- `actor_id` → ID do usuário que originou a ação
- `notify` → `true` / `false`
- `award_family` → família lógica opcional
- `rule_id` → se a integração estiver vinculada a uma regra

#### `revoke_medal(int $medal_id, int $user_id, array $context = []): array`
Revoga uma medalha de um usuário.

#### `has_medal(int $medal_id, int $user_id): bool`
Verifica se o usuário já possui a medalha.

#### `sync_user(int $user_id): array`
Reavalia todas as regras automáticas para um usuário específico.

#### `sync_rule(int $rule_id): array`
Executa a sincronização de uma regra específica.

## Eventos públicos de concessão / revogação

Além do evento de coleta de providers, o core agora dispara:

- `mundophpbb.membermedals.before_award`
- `mundophpbb.membermedals.after_award`
- `mundophpbb.membermedals.before_revoke`
- `mundophpbb.membermedals.after_revoke`

### before_award
Permite inspecionar ou cancelar a concessão antes do INSERT.

Variáveis principais:
- `user_id`
- `medal_id`
- `rule_id`
- `source`
- `reason`
- `actor_id`
- `notify`
- `award_family`
- `context`
- `user_row`
- `medal_row`
- `cancel`
- `cancel_message`

### after_award
Disparado logo após a criação da concessão.

Variáveis principais:
- `award_id`
- `user_id`
- `medal_id`
- `rule_id`
- `source`
- `reason`
- `actor_id`
- `notify`
- `award_family`
- `context`
- `user_row`
- `medal_row`
- `result`

### before_revoke
Permite cancelar a remoção antes do DELETE.

Variáveis principais:
- `user_id`
- `medal_id`
- `context`
- `user_row`
- `medal_row`
- `cancel`
- `cancel_message`

### after_revoke
Disparado logo após a revogação.

Variáveis principais:
- `user_id`
- `medal_id`
- `context`
- `user_row`
- `medal_row`
- `result`

## Exemplo de uso da API pública

```php
<?php

namespace vendor\myprovider\service;

use mundophpbb\membermedals\contract\medals_api_interface;

class rewards_service
{
    protected medals_api_interface $member_medals_api;

    public function __construct(medals_api_interface $member_medals_api)
    {
        $this->member_medals_api = $member_medals_api;
    }

    public function reward_user(int $user_id, int $medal_id): array
    {
        return $this->member_medals_api->award_medal($medal_id, $user_id, [
            'source' => 'vendor.rewards',
            'reason' => 'goal_reached',
            'notify' => true,
        ]);
    }
}
```

## Exemplo de services.yml em extensão externa

```yaml
services:
    vendor.myprovider.service.rewards:
        class: vendor\myprovider\service\rewards_service
        arguments:
            - '@mundophpbb.membermedals.api'
```
