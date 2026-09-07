<?php

namespace OneMediaLabs\MixpostMcp\Enums;

enum PostScheduleStatus: int
{
    case PENDING = 0;
    case PROCESSING = 1;
    case PROCESSED = 2;
}
