<?php

namespace App\Actions\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;

class ApproveComment
{
    /**
     * Make a comment publicly visible.
     */
    public function handle(Comment $comment): Comment
    {
        $comment->forceFill(['status' => CommentStatus::Approved])->save();

        return $comment->refresh();
    }
}
