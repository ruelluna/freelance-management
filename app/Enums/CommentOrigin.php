<?php

namespace App\Enums;

enum CommentOrigin: string
{
    case Local = 'local';
    case Remote = 'remote';
}
