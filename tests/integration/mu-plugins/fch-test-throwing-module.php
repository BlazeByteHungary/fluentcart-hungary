<?php
/**
 * Test-only mu-plugin: registers a module whose registerHooks() throws, to prove module isolation.
 * Loaded before plugins_loaded; the filter is attached at priority 15 so our autoloader is available.
 */
add_action('plugins_loaded', static function (): void {
    add_filter('fch/register_modules', static function ($modules) {
        $modules[] = new class implements \FluentCartHungary\Core\ModuleInterface {
            public function id(): string
            {
                return 'boom';
            }

            public function label(): string
            {
                return 'Exploding test module';
            }

            public function requires(): array
            {
                return ['core'];
            }

            public function enabledByDefault(): bool
            {
                return true;
            }

            public function registerHooks(): void
            {
                throw new \RuntimeException('intentional module boot failure');
            }

            public function probe(): array
            {
                return ['ok' => true, 'message' => 'unreachable'];
            }
        };
        return $modules;
    });
}, 15);
