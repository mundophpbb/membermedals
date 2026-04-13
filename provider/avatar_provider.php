<?php

namespace mundophpbb\membermedals\provider;

use mundophpbb\membermedals\contract\rule_provider_interface;

class avatar_provider implements rule_provider_interface
{
    public function get_key(): string
    {
        return 'avatar';
    }

    public function get_label_lang_key(): string
    {
        return 'ACP_MEMBERMEDALS_RULE_TYPE_AVATAR';
    }

    public function get_description_lang_key(): string
    {
        return 'ACP_MEMBERMEDALS_RULE_TYPE_AVATAR';
    }

    public function get_supported_operators(): array
    {
        return ['>=', '>', '=', '<=', '<'];
    }

    public function get_family(): string
    {
        return 'avatar';
    }

    public function is_progressive(): bool
    {
        return false;
    }

    public function get_user_value(int $user_id, array $rule, array $context = [])
    {
        $user_avatar = trim((string) ($context['user_avatar'] ?? ''));
        $user_avatar_type = trim((string) ($context['user_avatar_type'] ?? ''));
        if ($user_avatar === '' || $user_avatar_type === '') {
            return 0;
        }

        $group_avatar = trim((string) ($context['default_group_avatar'] ?? ''));
        $group_avatar_type = trim((string) ($context['default_group_avatar_type'] ?? ''));
        if ($group_avatar !== '' && $group_avatar_type !== ''
            && $user_avatar === $group_avatar
            && $user_avatar_type === $group_avatar_type) {
            return 0;
        }

        return 1;
    }

    public function normalize_rule_data(array $data): array
    {
        $data['rule_value'] = min(1, max(0, (int) ($data['rule_value'] ?? 0)));
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
            'max' => 1,
            'step' => 1,
        ];
    }
}
