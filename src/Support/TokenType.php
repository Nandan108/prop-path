<?php

namespace Nandan108\PropPath\Support;

enum TokenType: string
{
    case Arrow = '=>';
    case At = '@';
    case Bang = '!';
    case Colon = ':';
    case Comma = ',';
    case Dollar = '$';
    case Dot = '.';
    case DblBang = '!!';
    case EOF = 'EOF';
    case DblQstn = '??';
    case Identifier = 'IDENT';
    case BracketOpen = '[';
    case Qstn = '?';
    case BracketClose = ']';
    case Star = '*';
    case String = 'STRING';
    case Integer = 'INTEGER';
}
