<?php

namespace mundophpbb\membermedals\service;

use mundophpbb\membermedals\contract\rule_provider_interface;
use phpbb\event\dispatcher_interface;

class rule_provider_registry
{
    protected dispatcher_interface $dispatcher;

    /** @var rule_provider_interface[] */
    protected array $providers = [];

    protected bool $external_loaded = false;

    public function __construct(dispatcher_interface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function register(rule_provider_interface $provider): void
    {
        $this->providers[$provider->get_key()] = $provider;
    }

    public function get(string $key): ?rule_provider_interface
    {
        $this->load_external_providers();

        return $this->providers[$key] ?? null;
    }

    public function exists(string $key): bool
    {
        $this->load_external_providers();

        return isset($this->providers[$key]);
    }

    /**
     * @return rule_provider_interface[]
     */
    public function all(): array
    {
        $this->load_external_providers();
        ksort($this->providers);

        return $this->providers;
    }

    public function get_default_key(): string
    {
        $providers = $this->all();
        if (isset($providers['posts'])) {
            return 'posts';
        }

        $keys = array_keys($providers);

        return (string) ($keys[0] ?? 'posts');
    }

    protected function load_external_providers(): void
    {
        if ($this->external_loaded) {
            return;
        }

        $this->external_loaded = true;
        $providers = [];
        extract($this->dispatcher->trigger_event('mundophpbb.membermedals.collect_rule_providers', compact('providers')));

        if (!is_array($providers)) {
            return;
        }

        foreach ($providers as $provider) {
            if ($provider instanceof rule_provider_interface) {
                $this->register($provider);
            }
        }
    }
}
