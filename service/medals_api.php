<?php

namespace mundophpbb\membermedals\service;

use mundophpbb\membermedals\contract\medals_api_interface;

class medals_api implements medals_api_interface
{
    protected grant_manager $grant_manager;
    protected rules_manager $rules_manager;

    public function __construct(grant_manager $grant_manager, rules_manager $rules_manager)
    {
        $this->grant_manager = $grant_manager;
        $this->rules_manager = $rules_manager;
    }

    public function award_medal(int $medal_id, int $user_id, array $context = []): array
    {
        $source = (string) ($context['source'] ?? 'integration');
        $reason = (string) ($context['reason'] ?? '');
        $actor_id = (int) ($context['actor_id'] ?? 0);
        $notify = array_key_exists('notify', $context) ? (bool) $context['notify'] : true;
        $award_family = (string) ($context['award_family'] ?? '');
        $rule_id = (int) ($context['rule_id'] ?? 0);

        return $this->grant_manager->grant_medal_to_user(
            $user_id,
            $medal_id,
            $rule_id,
            $source,
            $reason,
            $actor_id,
            $notify,
            $award_family,
            $context
        );
    }

    public function revoke_medal(int $medal_id, int $user_id, array $context = []): array
    {
        return $this->grant_manager->revoke_medal_from_user($user_id, $medal_id, $context);
    }

    public function has_medal(int $medal_id, int $user_id): bool
    {
        return $this->grant_manager->has_medal($user_id, $medal_id);
    }

    public function sync_user(int $user_id): array
    {
        return [
            'user_id' => $user_id,
            'awards' => $this->rules_manager->evaluate_all_rules_for_user($user_id),
        ];
    }

    public function sync_rule(int $rule_id): array
    {
        return [
            'rule_id' => $rule_id,
            'awards' => $this->rules_manager->sync_rule($rule_id),
        ];
    }
}
