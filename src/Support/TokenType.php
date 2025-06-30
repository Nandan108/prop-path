<?php

namespace Nandan108\PropPath\Support;

enum TokenType: string
{
    case Arrow = '=>';
    case At = '@';
    case Bang = '!';
    case BlockComment = '/*block*/';
    case BracketClose = ']';
    case BracketOpen = '[';
    case Carret = '^';
    case Colon = ':';
    case Comma = ',';
    case DblBang = '!!';
    case DblQstn = '??';
    case Dollar = '$';
    case Dot = '.';
    case EOF = 'EOF';
    case Identifier = 'IDENT';
    case Integer = 'INTEGER';
    case LineComment = '//';
    case Qstn = '?';
    case RegExp = '/pattern/';
    case Star = '*';
    case String = 'STRING';
    case Tilde = '~';
}
