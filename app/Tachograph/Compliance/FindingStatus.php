<?php

namespace App\Tachograph\Compliance;

enum FindingStatus: string
{
    case COMPLIANT = 'COMPLIANT';
    case WARNING = 'WARNING';
    case VIOLATION = 'VIOLATION';
    case INCOMPLETE_DATA = 'INCOMPLETE_DATA';
    case DATA_ERROR = 'DATA_ERROR';
}
