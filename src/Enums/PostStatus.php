<?php

namespace OneMediaLabs\MixpostMcp\Enums;

enum PostStatus: int
{
    case DRAFT = 0;
    case SCHEDULED = 1;
    case PUBLISHED = 2;
    case FAILED = 3;
}
