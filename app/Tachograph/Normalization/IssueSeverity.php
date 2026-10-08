<?php

namespace App\Tachograph\Normalization;

enum IssueSeverity: string
{
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}
