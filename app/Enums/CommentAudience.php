<?php

namespace App\Enums;

enum CommentAudience: string
{
    case Client = 'client';
    case Internal = 'internal';
}
