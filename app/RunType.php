<?php

namespace App;

enum RunType: string
{
    case FETCH = 'fetch';
    case IMPORT = 'import';
    case EVALUATE = 'evaluate';
    case CROSSCHECK = 'crosscheck';
}
