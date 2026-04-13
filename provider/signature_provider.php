<?php

namespace mundophpbb\membermedals\provider;

use mundophpbb\membermedals\contract\rule_provider_interface;

class signature_provider implements rule_provider_interface
{
    public function get_key(): string
    {
        return 'signature';
    }

    public function get_label_lang_key(): string
    {
        return 'ACP_MEMBERMEDALS_RULE_TYPE_SIGNATURE';
    }

    public function get_description_lang_key(): string
    {
        return 'ACP_MEMBERMEDALS_RULE_TYPE_SIGNATURE';
    }

    public function get_supported_operators(): array
    {
        return ['>=', '>', '=', '<=', '<'];
    }

    public function get_family(): string
    {
        return 'signature';
    }

    public function is_progressive(): bool
    {
        return false;
    }

    public function get_user_value(int $user_id, array $rule, array $context = [])
    {
        return !empty(trim((string) ($context['user_sig'] ?? ''))) ? 1 : 0;
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
