<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * What a stack request asks for: bring one up, take one down, read its logs.
 *
 * One endpoint with three actions rather than three paths, for the same reason
 * the other request objects are single typed shapes: the action is the first
 * thing that must be valid, and everything else in the request is read in the
 * context of it.
 */
enum StackAction: string
{
    case Up = 'up';
    case Down = 'down';
    case Logs = 'logs';
}
