<?php

namespace Nandan108\PropPath\Support;

enum ThrowMode: int
{
    case NEVER = 0;         // always return null if path fails
    case MISSING_KEY = 1;   // throw if a key is missing
    case NULL_VALUE = 2;    // throw if value is null or key is missing
}
