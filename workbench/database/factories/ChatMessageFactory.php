<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\User;
use Workbench\App\Support\DemoRandom;

/**
 * Game chat messages. Content is synthetic and picked with DemoRandom, which the
 * seeder seeds, so every build is identical.
 *
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /** @var class-string<ChatMessage> */
    protected $model = ChatMessage::class;

    /** @var list<string> */
    private const array ROOMS = ['general', 'ranked', 'trading', 'clan-recruitment'];

    /** @var array<string, list<string>> */
    private const array CONTENT = [
        'fine' => [
            'gg everyone, that last round was close',
            'anyone up for ranked in 10?',
            'lol you got me there, rematch?',
            'trading two rare skins for the dragon banner',
            'new patch notes are out, the shotgun nerf is huge',
            'you absolute potato, how did you miss that 😂',
        ],
        'spam' => [
            'FREE COINS at free-coins.example, enter your login to claim!!!',
            'selling accounts cheap, dm me, paypal only',
        ],
        'harassment' => [
            'uninstall the game, you are worthless and everyone here hates you',
            'keep talking and I will find out where you live',
        ],
        'hate' => [
            'people like you should not be allowed to play, go back where you came from',
        ],
        'sexual' => [
            'send me pics of yourself and I will give you my skins',
        ],
        'personal_data' => [
            'his real name is Sam Example and he goes to Example High, add him on socials',
        ],
        'uncertain' => [
            'you are going to regret queueing against us tonight',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'room' => DemoRandom::pick(self::ROOMS),
            'body' => self::pick('fine'),
        ];
    }

    /**
     * Content that reads like the given category (`uncertain` for borderline).
     */
    public function about(string $category): self
    {
        return $this->state(['body' => self::pick($category)]);
    }

    private static function pick(string $category): string
    {
        $pool = self::CONTENT[$category] ?? self::CONTENT['fine'];

        return DemoRandom::pick($pool);
    }
}
