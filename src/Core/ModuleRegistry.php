<?php
declare(strict_types=1);

namespace FluentCartHungary\Core;

/**
 * Module registry with fail-safe boot (ADR-001; 05_ARCHITECTURE §2). States:
 * active | inactive | degraded | blocked. A throwing module is caught and degraded; siblings keep running.
 * Double registration is idempotent by module id.
 */
final class ModuleRegistry
{
    public const STATE_ACTIVE = 'active';
    public const STATE_INACTIVE = 'inactive';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_BLOCKED = 'blocked';

    private static ?ModuleRegistry $instance = null;

    /** @var array<string,ModuleInterface> */
    private array $modules = [];

    /** @var array<string,string> */
    private array $states = [];

    /** @var array<string,string> */
    private array $messages = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Idempotent: re-registering the same id returns the existing module. */
    public function register(ModuleInterface $module): ModuleInterface
    {
        $id = $module->id();
        if (isset($this->modules[$id])) {
            return $this->modules[$id];
        }
        $this->modules[$id] = $module;
        $this->states[$id] = self::STATE_INACTIVE;
        return $module;
    }

    public function get(string $id): ?ModuleInterface
    {
        return $this->modules[$id] ?? null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->modules);
    }

    /** @return array<string,ModuleInterface> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return array<string,string> */
    public function states(): array
    {
        return $this->states;
    }

    public function state(string $id): string
    {
        return $this->states[$id] ?? self::STATE_INACTIVE;
    }

    public function message(string $id): string
    {
        return $this->messages[$id] ?? '';
    }

    /** Boot every enabled module inside a guard. Core is always active. */
    public function boot(): void
    {
        $enabled = $this->enabledMap();

        foreach ($this->modules as $id => $module) {
            if ($id === 'core') {
                // Core is always active (ADR-001) but is still boot-guarded.
                $this->bootOne($module);
                continue;
            }

            if ($enabled[$id] === false) {
                $this->states[$id] = self::STATE_INACTIVE;
                continue;
            }

            if (!$this->requirementsMet($module)) {
                $this->states[$id] = self::STATE_BLOCKED;
                $this->messages[$id] = 'missing_requirement';
                continue;
            }

            $this->bootOne($module);
        }
    }

    private function bootOne(ModuleInterface $module): void
    {
        $id = $module->id();
        try {
            $module->registerHooks();
            $probe = $module->probe();
            if (($probe['ok'] ?? false) === true) {
                $this->states[$id] = self::STATE_ACTIVE;
                $this->messages[$id] = (string) ($probe['message'] ?? '');
            } else {
                $this->states[$id] = self::STATE_DEGRADED;
                $this->messages[$id] = (string) ($probe['message'] ?? 'probe_failed');
            }
        } catch (\Throwable $e) {
            $this->states[$id] = self::STATE_DEGRADED;
            $this->messages[$id] = 'boot_failed: ' . $e->getMessage();
            do_action('fch/module_boot_failed', $id, $e);
        }
    }

    private function requirementsMet(ModuleInterface $module): bool
    {
        foreach ($module->requires() as $requirement) {
            if (!isset($this->modules[$requirement])) {
                return false;
            }
            if ($this->states[$requirement] !== self::STATE_ACTIVE) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,bool> */
    private function enabledMap(): array
    {
        $stored = get_option('fch_modules', []);
        $stored = is_array($stored) ? $stored : [];
        $map = [];
        foreach ($this->modules as $id => $module) {
            if (array_key_exists($id, $stored)) {
                $map[$id] = (bool) $stored[$id];
            } else {
                $map[$id] = $module->enabledByDefault();
            }
        }
        return $map;
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $stored = get_option('fch_modules', []);
        $stored = is_array($stored) ? $stored : [];
        $stored[$id] = $enabled;
        update_option('fch_modules', $stored, false);
    }

    public function isEnabled(string $id): bool
    {
        return $this->enabledMap()[$id] ?? false;
    }
}
