<?php

namespace mundophpbb\membermedals\contract;

interface medals_api_interface
{
    public function award_medal(int $medal_id, int $user_id, array $context = []): array;

    public function revoke_medal(int $medal_id, int $user_id, array $context = []): array;

    public function has_medal(int $medal_id, int $user_id): bool;

    public function sync_user(int $user_id): array;

    public function sync_rule(int $rule_id): array;
}
