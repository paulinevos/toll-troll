<?php

namespace Vos\TollTroll;

enum Verdict: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Unknown = 'unknown';
    case Error = 'error';
    case Skipped = 'skipped';
}
