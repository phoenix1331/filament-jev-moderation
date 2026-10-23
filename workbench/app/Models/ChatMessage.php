<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Phoenix1331\JevModeration\Attributes\Moderate;
use Phoenix1331\JevModeration\Concerns\Moderatable;
use Workbench\Database\Factories\ChatMessageFactory;

/**
 * A game chat message on the chat policy. The author is `sender`, not
 * `user`, so the plugin's per-model author relation is exercised.
 *
 * @property int $id
 * @property int $sender_id
 * @property string $room
 * @property string $body
 */
#[Moderate(fields: ['body'], policy: 'chat')]
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    use Moderatable;

    protected $fillable = ['sender_id', 'room', 'body'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function moderationAuthor(): ?Model
    {
        return $this->sender;
    }

    public function moderationContext(): ?string
    {
        return 'Room: '.$this->room;
    }

    protected static function newFactory(): ChatMessageFactory
    {
        return ChatMessageFactory::new();
    }
}
