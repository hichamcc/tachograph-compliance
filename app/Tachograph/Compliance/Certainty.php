<?php

namespace App\Tachograph\Compliance;

enum Certainty: string
{
    case CONFIRMED = 'CONFIRMED';
    case POTENTIAL = 'POTENTIAL'; // depends on CAN / card-out / otherwise uncertain data
}
