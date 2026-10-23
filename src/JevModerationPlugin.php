<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use LogicException;

class JevModerationPlugin implements Plugin
{
    use EvaluatesClosures;

    public const string ID = 'jev-moderation';

    public static function make(): static
    {
        return self::ensureInstance(app(static::class));
    }

    public static function get(): static
    {
        return self::ensureInstance(filament(self::ID));
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        // Pages, resources and widgets are registered on the panel here.
    }

    public function boot(Panel $panel): void
    {
        // Runs on every request the panel serves; keep it cheap.
    }

    private static function ensureInstance(mixed $plugin): static
    {
        if (! $plugin instanceof static) {
            throw new LogicException('Plugin ['.self::ID.'] is not an instance of '.static::class.'.');
        }

        return $plugin;
    }
}
