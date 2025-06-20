<?php

namespace Nandan108\PropPath\Support;

enum TokenType: string
{
    case Arrow = '=>';
    case At = '@';
    case Bang = '!';
    case BracketClose = ']';
    case BracketOpen = '[';
    case Colon = ':';
    case Comma = ',';
    case DblBang = '!!';
    case DblQstn = '??';
    case Dollar = '$';
    case Dot = '.';
    case EOF = 'EOF';
    case Identifier = 'IDENT';
    case Integer = 'INTEGER';
    case Qstn = '?';
    case Star = '*';
    case String = 'STRING';
    case Tilde = '~';
}
