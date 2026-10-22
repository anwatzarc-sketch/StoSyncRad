<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Schema\Grammars\MySqlGrammar;

/**
 * Writes a new table's indexes and foreign keys inside its CREATE TABLE statement. Laravel otherwise
 * adds each one with its own ALTER TABLE, which InnoDB executes as a separate table operation; with
 * ~165 tables that dominated migration time. Primary keys are already inlined by Laravel the same way.
 * Only definitions Laravel would emit as a plain `alter table … add …` are inlined; anything with
 * algorithm/lock options keeps its own statement.
 */
final class MySqlSchemaGrammar extends MySqlGrammar
{
    protected function compileCreateTable($blueprint, $command)
    {
        $sql = parent::compileCreateTable($blueprint, $command);
        $prefix = 'alter table '.$this->wrapTable($blueprint).' add ';
        $inline = [];

        foreach ($blueprint->getCommands() as $definition) {
            if ($definition->shouldBeSkipped || ! in_array($definition->name, ['index', 'unique', 'foreign'], true)) {
                continue;
            }
            $alter = $this->{'compile'.ucfirst($definition->name)}($blueprint, $definition);
            if (! is_string($alter) || ! str_starts_with($alter, $prefix) || str_contains($alter, ', algorithm=') || str_contains($alter, ', lock=')) {
                continue;
            }
            $inline[] = substr($alter, strlen($prefix));
            $definition->shouldBeSkipped = true;
        }

        return $inline === [] ? $sql : substr($sql, 0, -1).', '.implode(', ', $inline).')';
    }
}
