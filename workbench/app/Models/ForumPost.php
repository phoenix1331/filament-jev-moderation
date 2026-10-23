<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Phoenix1331\JevModeration\Attributes\Moderate;
use Phoenix1331\JevModeration\Concerns\Moderatable;
use Workbench\Database\Factories\ForumPostFactory;

/**
 * A forum post on the community policy. The author is the `user` relation,
 * the core's default.
 *
 * @property int $id
 * @property int $user_id
 * @property string $thread
 * @property string $title
 * @property string $body
 */
#[Moderate(fields: ['title', 'body'], policy: 'community')]
class ForumPost extends Model
{
    /** @use HasFactory<ForumPostFactory> */
    use HasFactory;

    use Moderatable;

    protected $fillable = ['user_id', 'thread', 'title', 'body'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moderationContext(): ?string
    {
        return 'Thread: '.$this->thread;
    }

    protected static function newFactory(): ForumPostFactory
    {
        return ForumPostFactory::new();
    }
}
