<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Workbench\App\Models\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** @var class-string<User> */
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Demo User',
            'email' => Str::lower(Str::random(10)).'@example.com',
            'email_verified_at' => now(),
            'password' => 'password',
            'remember_token' => Str::random(10),
        ];
    }
}
