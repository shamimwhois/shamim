<?php

namespace App\Actions\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;

/**
 * Hides a comment permanently.
 *
 * The row is retained rather than deleted so the moderation view can show what
 * the author already dismissed, and so a rejection is never repeated by mistake.
 */
class RejectComment
{
    /**
     * Reject the comment.
     */
    public function handle(Comment $comment): Comment
    {
        $comment->forceFill(['status' => CommentStatus::Rejected])->save();

        return $comment->refresh();
    }
}
