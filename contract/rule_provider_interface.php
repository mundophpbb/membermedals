<?php

namespace mundophpbb\membermedals\contract;

interface rule_provider_interface
{
    public function get_key(): string;

    public function get_label_lang_key(): string;

    public function get_description_lang_key(): string;

    public function get_supported_operators(): array;

    public function get_family(): string;

    public function is_progressive(): bool;

    /**
     * @param int   $user_id
     * @param array $rule
     * @param array $context
     *
     * @return int|float|string|bool|null
     */
    public function get_user_value(int $user_id, array $rule, array $context = []);

    public function normalize_rule_data(array $data): array;

    public function get_rule_options_schema(): array;

    public function get_value_input_attributes(): array;
}
