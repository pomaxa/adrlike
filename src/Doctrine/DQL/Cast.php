<?php

declare(strict_types=1);

namespace App\Doctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL CAST function: CAST(expression AS type)
 *
 * Usage: CAST(u.roles AS TEXT)
 */
final class Cast extends FunctionNode
{
    private Node $value;
    private string $type;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER); // CAST
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->value = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_AS);
        $parser->match(TokenType::T_IDENTIFIER);
        $this->type = $parser->getLexer()->token->value;
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf('CAST(%s AS %s)', $this->value->dispatch($sqlWalker), $this->type);
    }
}
