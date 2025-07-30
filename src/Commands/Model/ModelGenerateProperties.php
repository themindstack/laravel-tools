<?php

namespace Quangphuc\LaravelTools\Commands\Model;

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
     * @throws \Exception
     */
    public function handle()
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
                    || Str::startsWith($item->value->description, 'db column')
                )
            ));

            // Generate columns
            $newColumns = [];
            foreach ($columns as $column) {
                $doc = new PhpDocTagNode(
                    name: '@property',
                    value: New PropertyTagValueNode(
                        type: new IdentifierTypeNode(name: self::getType($column)),
                        propertyName: '$' . $column['name'],
                        description: "db column {$column['name']}, {$column['comment']}"
                    )
                );
                $newColumns[] = $doc;
            }
            $docblockNode->children = [...$newColumns, ...$docblockNode->children];


            // Print out
            $newCommentsText = $printer->print($docblockNode);
            $fileContent = file_get_contents("$base/$modelFile");
            if ($commentsText) {
                file_put_contents("$base/$modelFile", Str::replace($commentsText, $newCommentsText, $fileContent));
            } else {
                throw new Exception("Please add a simple empty docblock to $base/$modelFile");
            }

        }
        $this->info("✅ Completed");
    }

    /**
     * @throws Exception
     */
    public static function getType(array $column): string
    {
        $type = match ($column['type_name']) {
            'varchar', 'text' => 'string',
            'int2', 'int4', 'int8', 'int' => 'integer',
            'float8', 'numeric' => 'float',
            'timestamp' => 'Carbon',
            'json', 'jsonb' => 'mixed',
            default => throw new \Exception('Unexpected type '. $column['type_name'])
        };

        if ($column['nullable']) {
            return "null|$type";
        }

        return $type;
    }
}
