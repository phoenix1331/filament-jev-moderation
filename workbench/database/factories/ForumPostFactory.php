<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;
use Workbench\App\Support\DemoRandom;

/**
 * Watch forum posts. Content is synthetic and picked with DemoRandom, which the
 * seeder seeds, so every build is identical.
 *
 * @extends Factory<ForumPost>
 */
class ForumPostFactory extends Factory
{
    /** @var class-string<ForumPost> */
    protected $model = ForumPost::class;

    /** @var list<string> */
    private const array THREADS = [
        'Best automatic under 300?',
        'Show your weekend wrist shot',
        'Is the Seiko 5 still the go-to beginner watch?',
        'Servicing costs: worth it or replace?',
        'Bracelet vs strap for a diver',
    ];

    /** @var array<string, list<array{string, string}>> title and body per category */
    private const array CONTENT = [
        'fine' => [
            ['Re: beginner watch', 'The Seiko 5 is still a solid pick, but the Orient Bambino gets you a nicer dial for the money.'],
            ['Service quote', 'Paid 180 for a full service on my Tudor. Took six weeks but it keeps time within two seconds a day now.'],
            ['Strap advice', 'Rubber on a diver for summer, then back to the bracelet in autumn. Quick release pins make it painless.'],
            ['Wrist shot', 'Took the Speedmaster hiking this weekend. Not a scratch, although the strap needs a clean.'],
            ['Disagree on the Bambino', 'I think the Bambino is overrated: the crystal scratches easily and the lume is weak.'],
            ['Movement question', 'Does anyone know whether the NH35 hacks? Mine seems to stop when I pull the crown.'],
        ],
        'spam' => [
            ['Replica deals', 'Best replica watches 90% off, free shipping worldwide, visit cheap-watches.example now!!!'],
            ['Earn from home', 'I make 500 a day from home trading watches, DM me for the secret method.'],
            ['Limited offer', 'Luxury watches at wholesale prices, only today, click the link in my profile.'],
        ],
        'harassment' => [
            ['Re: servicing', 'Only an idiot would pay that much for a service. You clearly know nothing about watches.'],
            ['Re: wrist shot', 'Nobody wants to see your cheap junk. Do everyone a favour and stop posting.'],
            ['Re: strap advice', 'You again? Every thread you post in gets worse. Log off.'],
        ],
        'hate' => [
            ['Re: dealers', 'Never buy from people like them, that whole group are cheats and should be kept off this forum.'],
        ],
        'personal_data' => [
            ['Seller warning', 'The seller lives at 14 Example Road and his number is 07700 900123, go and ask him yourself.'],
        ],
        'off_topic' => [
            ['Football tonight', 'Anyone watching the match tonight? Thinking of getting tickets for the next home game.'],
            ['Car for sale', 'Selling my hatchback, 60k miles, full history. Message me if interested.'],
        ],
        'uncertain' => [
            ['Re: beginner watch', 'Anyone still recommending that brand in 2026 has no idea what they are talking about.'],
            ['Re: dealers', 'Interesting how that dealer always seems to have stock nobody else can find.'],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$title, $body] = self::pick('fine');

        return [
            'user_id' => User::factory(),
            'thread' => DemoRandom::pick(self::THREADS),
            'title' => $title,
            'body' => $body,
        ];
    }

    /**
     * Content that reads like the given category (`uncertain` for borderline).
     */
    public function about(string $category): self
    {
        [$title, $body] = self::pick($category);

        return $this->state(['title' => $title, 'body' => $body]);
    }

    /**
     * @return array{string, string}
     */
    private static function pick(string $category): array
    {
        $pool = self::CONTENT[$category] ?? self::CONTENT['fine'];

        return DemoRandom::pick($pool);
    }
}
