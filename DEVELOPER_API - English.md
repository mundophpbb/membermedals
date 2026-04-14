# Member Medals Provider API Guide (0.7.2)

This guide describes the minimum external integration API for `mundophpbb/membermedals`.

## Objective

The API allows other extensions to add **new medal criteria** without modifying the Member Medals core.

Examples of external criteria:
- Points
- Donations
- Likes received
- Solved topics
- Friends / Foes
- Uploaded attachments

## How the Architecture Works

The core operates with three main pieces:

1.  **Provider**
    * Knows how to calculate a value for a user.
    * Defines the name, family, operators, and rule normalization.
2.  **Registry**
    * Gathers native and external providers.
3.  **Rules Manager**
    * Uses the provider to evaluate the rule.
    * Delegates medal granting to the grant manager.

## Public Registration Event

The core triggers the following event:

* `mundophpbb.membermedals.collect_rule_providers`

External extensions must listen to this event and attach their providers to the `providers` array.

## Required Interface

Every external provider must implement:

* `\mundophpbb\membermedals\contract\rule_provider_interface`

### Methods

#### `get_key(): string`
Unique key for the criterion.

Example:
* `friends_count`
* `likes_received`
* `donations_total`

#### `get_label_lang_key(): string`
Language key used in the ACP (Administration Control Panel) for the criterion name.

#### `get_description_lang_key(): string`
Language key used for the criterion description.

#### `get_supported_operators(): array`
Accepted operators.

Common example:
```php
['>=', '>', '=', '<=', '<']
```

#### `get_family(): string`
Logical progression family.

Use the same family for medals that should replace each other within the same track.

Example:
* Rule: 10 posts
* Rule: 50 posts
* Rule: 100 posts

All of these can use the `posts` family.

#### `is_progressive(): bool`
Defines if the family uses progression.

If it returns `true`, the core attempts to keep only the highest automatic medal of that family.

#### `get_user_value(int $user_id, array $rule, array $context = [])`
Returns the user's current value for the criterion.

Can return:
* `int`
* `float`
* `string`
* `bool`
* `null`

In practice, for medal criteria, `int` is preferred.

#### `normalize_rule_data(array $data): array`
Normalizes data saved in the ACP.

Use this to:
* Convert `rule_value` to an integer.
* Apply minimum/maximum limits.
* Ensure `rule_options` are set.

#### `get_rule_options_schema(): array`
Reserved for extra criterion options.

**Important:** In the current version, the ACP does not yet dynamically render this schema. The method exists to keep the API future-proof, but currently serves as a compatibility placeholder.

#### `get_value_input_attributes(): array`
Defines attributes for the numeric field in the ACP.

Example:
```php
[
    'min' => 0,
    'max' => 999999999,
    'step' => 1,
]
```

---

## Minimum External Extension Structure

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

## Minimum Provider Example

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

## Registration Listener

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

## Minimum services.yml

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

---

## Language Keys

The provider must provide the language keys used in the ACP.

Example:
```php
'MEMBERMEDALS_RULE_TYPE_LIKES_RECEIVED' => 'Likes received',
'MEMBERMEDALS_RULE_TYPE_LIKES_RECEIVED_EXPLAIN' => 'Counts how many likes the member has received.',
```

## Family Progression

Progression depends on two points:
1. `is_progressive()` returning `true`.
2. Related providers using the same family.

Correct example:
* `posts >= 10`
* `posts >= 50`
* `posts >= 100`
* Family: `posts`
* Progressive: `true`

Expected result:
* When moving up a tier, the previous automatic medal from the same family is removed.

## Practical Compatibility

Currently, the API handles simple criteria based on:
* `COUNT(*)`
* `SUM(...)`
* Boolean flags
* Simple numerical comparisons

It is ideal for metrics such as friends, foes, attachments, likes, points, donations, and solved topics.

## Honest Current Limitations

In the current version:
* `rule_options_schema` is not yet dynamically rendered in the ACP.
* The API is great for simple and progressive metrics, but it is not yet a complex DSL for composite rules.
* Granting continues to be based on the core evaluation, not independent external jobs.

## Testing Checklist for an External Provider

1.  The extension activates without errors.
2.  The new criterion appears in the Member Medals ACP.
3.  The rule saves without errors.
4.  Evaluation correctly grants the medal.
5.  Progression correctly removes the previous medal of the same family.
6.  `award_family` is correctly recorded in the awards table.

## Recommendations for Authors

If you are creating an integration with another extension:
* Keep the provider small.
* Keep the query logic inside the provider.
* Avoid modifying the Member Medals core.
* Use a stable and predictable family.
* Clearly document what the metric counts.

## Summary

The current API is already sufficient to transform Member Medals into an extensible base for automatic criteria.

In one sentence:
> The core evaluates rules; the provider resolves the metric.

## Public Award / Revoke Facade

In addition to provider registration, the core now exposes a public service for external integrations:

- Service: `mundophpbb.membermedals.api`
- Interface: `\mundophpbb\membermedals\contract\medals_api_interface`

This service acts as a stable layer over `grant_manager` and `rules_manager`, so integrations do not need direct access to the extension tables.

### Public methods

#### `award_medal(int $medal_id, int $user_id, array $context = []): array`
Awards a medal to a user.

Useful context keys:
- `source` → for example `integration`, `manual`, `vendor.reactions`
- `reason` → optional textual reason
- `actor_id` → user ID that originated the action
- `notify` → `true` / `false`
- `award_family` → optional logical family
- `rule_id` → if the integration is linked to a rule

#### `revoke_medal(int $medal_id, int $user_id, array $context = []): array`
Revokes a medal from a user.

#### `has_medal(int $medal_id, int $user_id): bool`
Checks whether the user already has the medal.

#### `sync_user(int $user_id): array`
Re-evaluates all automatic rules for a specific user.

#### `sync_rule(int $rule_id): array`
Runs the synchronization for a specific rule.

## Public award / revoke events

In addition to the provider collection event, the core now triggers:

- `mundophpbb.membermedals.before_award`
- `mundophpbb.membermedals.after_award`
- `mundophpbb.membermedals.before_revoke`
- `mundophpbb.membermedals.after_revoke`

### before_award
Lets listeners inspect or cancel the award before the INSERT.

Main variables:
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
Triggered immediately after the award is created.

Main variables:
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
Lets listeners cancel the removal before the DELETE.

Main variables:
- `user_id`
- `medal_id`
- `context`
- `user_row`
- `medal_row`
- `cancel`
- `cancel_message`

### after_revoke
Triggered immediately after the revocation.

Main variables:
- `user_id`
- `medal_id`
- `context`
- `user_row`
- `medal_row`
- `result`

## Public API usage example

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

## Example external services.yml

```yaml
services:
    vendor.myprovider.service.rewards:
        class: vendor\myprovider\service\rewards_service
        arguments:
            - '@mundophpbb.membermedals.api'
```
