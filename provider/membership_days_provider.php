<?php

namespace mundophpbb\membermedals\provider;

use mundophpbb\membermedals\contract\rule_provider_interface;

class membership_days_provider implements rule_provider_interface
{
    public function get_key(): string
    {
        return 'membership_days';
    }

    public function get_label_lang_key(): string
    {
        return 'ACP_MEMBERMEDALS_RULE_TYPE_MEMBERSHIP_DAYS';
    }

    public function get_description_lang_key(): string
    {
        return 'ACP_MEMBERMEDALS_RULE_TYPE_MEMBERSHIP_DAYS';
    }

    public function get_supported_operators(): array
    {
        return ['>=', '>', '=', '<=', '<'];
    }

    public function get_family(): string
    {
        return 'membership_days';
    }

    public function is_progressive(): bool
    {
        return true;
    }

    public function get_user_value(int $user_id, array $rule, array $context = [])
    {
        $user_regdate = (int) ($context['user_regdate'] ?? 0);
        if ($user_regdate <= 0) {
            return 0;
        }

        return (int) floor((time() - $user_regdate) / 86400);
    }

    public function normalize_rule_data(array $data): array
    {
        $data['rule_value'] = min(999999999, max(0, (int) ($data['rule_value'] ?? 0)));
        $data['rule_options'] = (array) ($data['rule_options'] ?? []);

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
