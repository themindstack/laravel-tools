<?php

namespace Quangphuc\LaravelTools\Commands\Model;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Printer\Printer;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use Quangphuc\LaravelTools\Helpers\Dir;

class ModelGenerateProperties extends Command
{
    public const DESC_PREFIX = 'database column ';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model:generate:properties';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate properties for all models';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): int
    {
        $this->info("Generating model properties");

        $base = base_path("/app/Models");
        $modelFiles = Dir::scan($base);

        $config = new ParserConfig(usedAttributes: []);
        $lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
        $printer = new Printer();

        foreach ($modelFiles as $modelFile) {
            $Model = "App\\Models\\" . pathinfo($modelFile, PATHINFO_FILENAME);

            if (!class_exists($Model) || !is_subclass_of($Model, Model::class)) {
                continue;
            }

            $modelReflection = new \ReflectionClass($Model);

            $commentsText = $modelReflection->getDocComment();

            $tokens = new TokenIterator($lexer->tokenize($commentsText ?: "/**\n */"));
            $docblockNode = $phpDocParser->parse($tokens); // PhpDocNode

            // Get Columns
            $columns = Schema::getColumns((new $Model)->getTable());

            // remove duplicated & old columns
            $columnNames = Arr::pluck($columns, 'name');
            $docblockNode->children = Arr::where($docblockNode->children, static fn ($item) => !(
                ($item instanceof PhpDocTagNode)
                && ($item->value instanceof PropertyTagValueNode)
                && (
                    in_array(substr($item->value->propertyName, 1), $columnNames, true)
                    || Str::startsWith($item->value->description, self::DESC_PREFIX)
                )
            ));

            // Generate columns
            $newColumns = [];
            foreach ($columns as $column) {
                $doc = new PhpDocTagNode(
                    name: '@property',
                    value: New PropertyTagValueNode(
                        type: new IdentifierTypeNode(name: $this->getType($column, $Model)),
                        propertyName: '$' . $column['name'],
                        description:  self::DESC_PREFIX . "{$column['name']}" . ($column['comment'] ? ", {$column['comment']}" : '')
                    )
                );
                $newColumns[] = $doc;
            }
            $docblockNode->children = [...$newColumns, ...$docblockNode->children];


            // Print out
            $newCommentsText = $printer->print($docblockNode);
            $fileContent = file_get_contents("$base/$modelFile");
            if ($commentsText) {
                $fileContent = Str::replace($commentsText, $newCommentsText, $fileContent);
            } else {
                $startLine = $modelReflection->getStartLine() - 1;
                $currentLine = 0;
                $startLineIndex = 0;

                while ($currentLine < $startLine) {
                    $startLineIndex = strpos($fileContent, PHP_EOL, $startLineIndex + 1);
                    $currentLine++;
                }

                $fileContent = Str::substrReplace($fileContent, $newCommentsText, $startLineIndex, 0);
            }

            file_put_contents("$base/$modelFile", Str::replace($commentsText, $newCommentsText, $fileContent));
        }
        $this->info("✅ Completed");
        return self::SUCCESS;
    }

    /**
     * @param array{name: string, type_name: string, nullable: boolean} $column
     * @throws Exception
     */
    protected function getType(array $column, string $Model): string
    {
        /**
         * @var Model $model
         */
        $model = new $Model();

        ['name' => $name, 'type_name' => $db_type, 'nullable' => $nullable] = $column;

        if ($name === $model->getCreatedAtColumn() || $name === $model->getUpdatedAtColumn()) {
            $type = 'Carbon';
        } elseif ($model->hasCast($name)) {
            $casted_type = $model->getCasts()[$name];

            if (enum_exists($casted_type)) {
                if (Str::startsWith($casted_type, 'App\\Models\\')) {
                    $type = Str::replace('App\\Models\\', "", $casted_type);
                } else {
                    $type = '\\' . $casted_type;
                }
            } else {
                $type = match ($casted_type) {
                    'encrypted', 'hashed' => 'string',
                    'json' => 'mixed',
                    'array' => 'array<string, mixed>',
                    'date', 'datetime' => 'Carbon',
                    'collection' => 'Collection<string, mixed>',
                    'string', 'int', 'float', 'boolean', 'bool' => $casted_type,
                    default => throw new Exception('Unexpected $casted_type '. $casted_type . ' ' . $Model)
                };
            }
        } else if (in_array($db_type, ['json', 'jsonb', 'date', 'datetime', 'timestamp'], true)) {
            $type = 'string';
            $shouldUseCast = match ($db_type) {
                'json', 'jsonb' => 'json',
                'date' => 'date',
                'timestamp', 'datetime' => 'datetime'
            };
            $this->warn("Consider to use cast $shouldUseCast for column $name in model $Model");
        } else {
            $type = match ($db_type) {
                'varchar', 'text', 'uuid' => 'string',
                'int2', 'int4', 'int8', 'int', 'smallint', 'tinyint', 'bool' => 'integer',
                'float8', 'numeric', 'bigint', 'decimal', 'double' => 'float',
                default => throw new Exception('Unexpected type '. $db_type . ' ' . $Model)
            };
        }

        if ($nullable) {
            return "null|$type";
        }

        return $type;
    }
}
